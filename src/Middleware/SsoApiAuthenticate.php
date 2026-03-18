<?php

namespace Unified\SsoClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;
use Unified\SsoClient\SsoClient;

class SsoApiAuthenticate
{
    public function __construct(
        protected SsoClient $ssoClient,
        protected SsoUserSynchronizerContract $synchronizer,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        // Allow local dev auth bypass
        if (config('sso.enable_local_dev_auth') && app()->environment('local')) {
            return $next($request);
        }

        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            // Validate the token by fetching user info from SSO
            $payload = $this->ssoClient->fetchUser($token);

            if (empty($payload['user']['id'])) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            // Synchronize user into local database
            [$user, $company] = $this->synchronizer->synchronize($payload);

            if (! $user) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            // Set the authenticated user for this request
            auth()->setUser($user);

            if ($company) {
                $request->attributes->set('company_id', $company->id);

                if (method_exists($user, 'loadRolesForCompany')) {
                    $user->loadRolesForCompany($company->id);
                }
            }

        } catch (\Throwable $e) {
            Log::warning('SSO API auth failed', ['message' => $e->getMessage()]);

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
