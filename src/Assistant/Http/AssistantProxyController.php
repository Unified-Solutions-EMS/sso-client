<?php

namespace Unified\SsoClient\Assistant\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Same-origin face of the assistant for the widget. Every request is
 * forwarded to SSO's internal assistant API with CORE_APP_API_KEY and the
 * identity stamped from this app's session: the signed-in user's sso_id,
 * the selected company's sso_company_id, this app's slug and whether staff
 * are impersonating. Nothing identifying is ever read from the request body.
 *
 * SSO itself turns these routes off (sso.assistant.proxy = false) and serves
 * the same paths in-process.
 */
class AssistantProxyController extends Controller
{
    /**
     * The current thread, or with ?conversation=ID a previous one reopened.
     */
    public function bootstrap(Request $request): JsonResponse|Response
    {
        $query = $request->validate([
            'conversation' => ['sometimes', 'nullable', 'integer'],
        ]);

        return $this->forward($request, 'get', '/conversation', array_filter($query, fn ($value): bool => $value !== null));
    }

    public function message(Request $request): JsonResponse|Response
    {
        $body = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            'page' => ['sometimes', 'array'],
            'page.url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'page.title' => ['sometimes', 'nullable', 'string', 'max:512'],
            'setup_app' => ['sometimes', 'nullable', 'string', 'max:64'],
            'conversation_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        return $this->forward($request, 'post', '/turn', $body);
    }

    public function upload(Request $request): JsonResponse|Response
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        $file = $request->file('file');

        return $this->forward(
            $request,
            'post',
            '/uploads',
            [],
            fn (PendingRequest $http): PendingRequest => $http->attach(
                'file',
                (string) file_get_contents($file->getRealPath()),
                $file->getClientOriginalName(),
            ),
        );
    }

    public function undo(Request $request, int $action): JsonResponse|Response
    {
        return $this->forward($request, 'post', "/actions/{$action}/undo");
    }

    public function approveReview(Request $request, string $plan): JsonResponse|Response
    {
        $body = $request->validate([
            'plan_hash' => ['required', 'string', 'max:128'],
            'confirmed_danger' => ['sometimes', 'array'],
            'confirmed_danger.*' => ['integer'],
        ]);

        return $this->forward($request, 'post', "/review/{$plan}/approve", $body);
    }

    public function removeFromReview(Request $request, string $plan): JsonResponse|Response
    {
        $body = $request->validate([
            'plan_hash' => ['required', 'string', 'max:128'],
            'index' => ['required', 'integer'],
        ]);

        return $this->forward($request, 'post', "/review/{$plan}/remove", $body);
    }

    public function setAsideReview(Request $request, string $plan): JsonResponse|Response
    {
        return $this->forward($request, 'post', "/review/{$plan}/set-aside");
    }

    /**
     * Ends the current thread. SSO answers with the bootstrap shape (a new
     * empty conversation plus the thread list), passed straight back.
     */
    public function clearConversation(Request $request): JsonResponse|Response
    {
        return $this->forward($request, 'delete', '/conversation');
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  (callable(PendingRequest): PendingRequest)|null  $attach
     */
    private function forward(Request $request, string $method, string $path, array $body = [], ?callable $attach = null): JsonResponse|Response
    {
        $identity = $this->identity($request);

        if ($identity === null) {
            return response()->noContent();
        }

        $http = Http::withToken((string) config('app.core_api_key'))
            ->acceptJson()
            ->timeout((int) config('sso.assistant.timeout', 120))
            ->baseUrl(rtrim((string) config('sso.base_url'), '/').'/api/internal/assistant');

        if ($attach !== null) {
            $http = $attach($http);
        }

        $payload = [...$body, ...$identity];

        try {
            /** @var ClientResponse $response */
            $response = match ($method) {
                'get' => $http->get($path, $payload),
                'delete' => $http->delete($path, $payload),
                default => $http->post($path, $payload),
            };
        } catch (ConnectionException $e) {
            Log::warning('assistant proxy could not reach SSO', ['path' => $path, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'The assistant is not reachable right now. Try again in a minute.'], 502);
        }

        return response()->json($response->json() ?? (object) [], $response->status());
    }

    /**
     * Null when this session cannot be vouched for: the widget then stays
     * hidden (204).
     *
     * @return array{sso_user_id: int, sso_company_id: int, app_slug: string, impersonating: bool}|null
     */
    private function identity(Request $request): ?array
    {
        $user = $request->user();
        $ssoUserId = $user?->getAttribute((string) config('metrics.user_sso_id_column', 'sso_id'));
        $companyId = $request->session()->get('selected_company_id');

        if ($user === null || ! $ssoUserId || ! $companyId) {
            return null;
        }

        $companyModel = (string) config('metrics.company_model');

        if (! class_exists($companyModel)) {
            return null;
        }

        $company = $companyModel::query()->withoutGlobalScopes()->whereKey($companyId)->first();
        $ssoCompanyId = $company?->getAttribute((string) config('metrics.company_sso_id_column', 'sso_company_id'));

        if (! $ssoCompanyId) {
            return null;
        }

        return [
            'sso_user_id' => (int) $ssoUserId,
            'sso_company_id' => (int) $ssoCompanyId,
            'app_slug' => (string) config('sso.app_slug'),
            'impersonating' => $request->session()->has('impersonator_id'),
        ];
    }
}
