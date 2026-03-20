<?php

namespace Unified\SsoClient\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Contracts\DashboardDataProvider;

class SsoDashboardController extends Controller
{
    /**
     * Return dashboard widget data for the SSO dashboard.
     *
     * Authenticates via the same HMAC signature used for webhooks.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->verifySignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $ssoUserId = (int) $request->input('sso_user_id');
        $ssoCompanyId = (int) $request->input('sso_company_id');

        if (! $ssoUserId || ! $ssoCompanyId) {
            return response()->json(['error' => 'sso_user_id and sso_company_id are required'], 422);
        }

        $providerClass = config('sso.dashboard_provider');

        if (! $providerClass || ! class_exists($providerClass)) {
            return response()->json(['error' => 'No dashboard provider configured'], 501);
        }

        try {
            $provider = app($providerClass);

            if (! $provider instanceof DashboardDataProvider) {
                return response()->json(['error' => 'Invalid dashboard provider'], 500);
            }

            $data = $provider->getData($ssoUserId, $ssoCompanyId);

            return response()->json($data);
        } catch (\Throwable $e) {
            Log::error('SSO dashboard data provider failed', [
                'error' => $e->getMessage(),
                'sso_user_id' => $ssoUserId,
                'sso_company_id' => $ssoCompanyId,
            ]);

            return response()->json(['error' => 'Provider failed'], 500);
        }
    }

    protected function verifySignature(Request $request): bool
    {
        $secret = config('sso.webhook_secret');

        if (! $secret) {
            return false;
        }

        $signature = $request->header('X-SSO-Signature');

        if (! $signature) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
