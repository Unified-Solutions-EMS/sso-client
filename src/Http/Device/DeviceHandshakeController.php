<?php

namespace Unified\SsoClient\Http\Device;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Unified\SsoClient\Device\DeviceSessionState;
use Unified\SsoClient\SsoClient;

/**
 * Server side of the browser-extension handshake. The interstitial page calls
 * these (authenticated, same-origin) and the app proxies to SSO with the shared
 * CORE_APP_API_KEY — the extension never talks to SSO directly.
 */
class DeviceHandshakeController extends Controller
{
    public function __construct(
        private readonly SsoClient $client,
        private readonly DeviceSessionState $session,
    ) {}

    /**
     * Issue a single-use challenge for the extension to sign.
     */
    public function challenge(Request $request): JsonResponse
    {
        $this->ensureAuthenticated($request);

        $validated = $request->validate([
            'key_id' => ['nullable', 'string', 'max:255'],
        ]);

        $challenge = $this->client->requestDeviceChallenge($validated['key_id'] ?? null);

        if ($challenge === null) {
            return response()->json(['error' => 'Could not reach the authorization service.'], 502);
        }

        return response()->json($challenge);
    }

    /**
     * Verify a signed challenge and, on success, bind the device to this
     * session for the active company.
     */
    public function verify(Request $request): JsonResponse
    {
        $this->ensureAuthenticated($request);

        $validated = $request->validate([
            'key_id' => ['required', 'string', 'max:255'],
            'challenge' => ['required', 'string', 'max:255'],
            'signature' => ['required', 'string', 'max:1024'],
        ]);

        $ssoCompanyId = $this->activeSsoCompanyId($request);

        if ($ssoCompanyId === null) {
            return response()->json(['verified' => false], 409);
        }

        $result = $this->client->verifyDevice($validated['key_id'], $validated['challenge'], $validated['signature']);

        if ($result === null) {
            return response()->json(['error' => 'Could not reach the authorization service.'], 502);
        }

        $device = $result['device'] ?? null;

        // The device must belong to the same company the user is acting within;
        // a device authorized for another agency must not unlock this one.
        $verified = ($result['verified'] ?? false)
            && $device !== null
            && (string) ($device['sso_company_id'] ?? '') === (string) $ssoCompanyId;

        if (! $verified) {
            return response()->json(['verified' => false], 403);
        }

        $this->session->bind($ssoCompanyId, $device['id'], (int) config('sso.device.session_ttl', 1800));

        return response()->json(['verified' => true]);
    }

    /**
     * Register this device's public key — either by redeeming a one-time code
     * or, for a company admin, vouched by their authenticated session.
     */
    public function register(Request $request): JsonResponse
    {
        $this->ensureAuthenticated($request);

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:code,admin'],
            'key_id' => ['required', 'string', 'max:255'],
            'public_key' => ['required', 'string', 'max:2048'],
            'label' => ['nullable', 'string', 'max:255'],
            'code' => ['required_if:mode,code', 'string', 'max:64'],
        ]);

        $payload = [
            'mode' => $validated['mode'],
            'key_id' => $validated['key_id'],
            'public_key' => $validated['public_key'],
            'label' => $validated['label'] ?? null,
        ];

        if ($validated['mode'] === 'code') {
            $payload['code'] = $validated['code'];
        } else {
            // Admin mode: the app vouches for the authenticated user + company;
            // SSO re-verifies the user is actually an admin of that company.
            $ssoCompanyId = $this->activeSsoCompanyId($request);
            $ssoUserId = $request->user()->sso_id ?? null;

            if ($ssoCompanyId === null || $ssoUserId === null) {
                return response()->json(['error' => 'No active company.'], 409);
            }

            $payload['sso_company_id'] = $ssoCompanyId;
            $payload['sso_user_id'] = $ssoUserId;
        }

        $result = $this->client->registerDevice($payload);

        if ($result === null || ! isset($result['device'])) {
            return response()->json(['error' => 'Device authorization failed.'], 422);
        }

        return response()->json(['device' => $result['device']], 201);
    }

    private function ensureAuthenticated(Request $request): void
    {
        abort_if($request->user() === null, 401);
    }

    private function activeSsoCompanyId(Request $request): int|string|null
    {
        $companyId = $request->session()->get('selected_company_id');

        if (empty($companyId)) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = config('sso.company_model', config('metrics.company_model', 'App\\Models\\Company'));

        return $model::query()->find($companyId)?->sso_company_id;
    }
}
