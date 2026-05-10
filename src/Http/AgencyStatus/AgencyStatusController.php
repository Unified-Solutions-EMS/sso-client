<?php

namespace Unified\SsoClient\Http\AgencyStatus;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Contracts\AgencyStatusProvider;

class AgencyStatusController extends Controller
{
    public function __invoke(string $ssoCompanyId, AgencyStatusProvider $provider): JsonResponse
    {
        try {
            return response()->json(
                $provider->build($ssoCompanyId)->toArray()
            );
        } catch (\Throwable $e) {
            Log::error('agency-status build failed', [
                'app' => $provider->appSlug(),
                'sso_company_id' => $ssoCompanyId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'app_slug' => $provider->appSlug(),
                'is_active' => false,
                'last_activity_at' => null,
                'active_user_count' => 0,
                'app_version' => (string) config('app.version', 'unknown'),
                'health' => AgencyStatusHealth::down('Internal error computing status')->toArray(),
                'depends_on' => [],
                'extension' => [],
            ], 500);
        }
    }
}
