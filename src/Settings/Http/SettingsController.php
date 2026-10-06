<?php

namespace Unified\SsoClient\Settings\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Contracts\SettingsProvider;
use Unified\SsoClient\Settings\SettingsPatchValidator;
use Unified\SsoClient\Settings\SettingsResult;

class SettingsController extends Controller
{
    public function show(int $ssoCompanyId): JsonResponse
    {
        $provider = $this->provider();

        if ($provider === null) {
            return $this->unsupported(['schema' => null, 'values' => null]);
        }

        $schema = $provider->schema();
        $values = $provider->values($ssoCompanyId);

        return response()->json([
            'app_slug' => $this->appSlug(),
            'supported' => true,
            'schema' => $schema->toArray(),
            'values' => array_merge($schema->defaults(), array_intersect_key($values, $schema->settings())),
        ]);
    }

    public function update(int $ssoCompanyId, UpdateSettingsRequest $request, SettingsPatchValidator $validator): JsonResponse
    {
        $provider = $this->provider();

        if ($provider === null) {
            return $this->unsupported(['results' => (object) []]);
        }

        [$accepted, $results] = $validator->validate($provider->schema(), $request->settingsPatch());

        if ($accepted !== []) {
            $this->mergeProviderResults(
                $results,
                $accepted,
                $provider->apply($ssoCompanyId, $accepted, $request->settingsActor()),
            );
        }

        return response()->json([
            'app_slug' => $this->appSlug(),
            'supported' => true,
            'results' => (object) $results->toArray(),
        ]);
    }

    /**
     * Only keys the package handed over take the provider's answer; a key the
     * provider forgot to report is surfaced as blocked rather than assumed saved.
     *
     * @param  array<string, mixed>  $accepted
     */
    private function mergeProviderResults(SettingsResult $results, array $accepted, SettingsResult $applied): void
    {
        $unreported = [];

        foreach (array_keys($accepted) as $key) {
            $result = $applied->get($key);

            if ($result === null) {
                $unreported[] = $key;
                $results->blocked($key, 'The app did not confirm this change.');

                continue;
            }

            $results->put($key, $result);
        }

        if ($unreported !== []) {
            Log::warning('settings provider did not report a result for every applied key', [
                'app' => $this->appSlug(),
                'keys' => $unreported,
            ]);
        }
    }

    private function provider(): ?SettingsProvider
    {
        return app()->bound(SettingsProvider::class) ? app(SettingsProvider::class) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function unsupported(array $payload): JsonResponse
    {
        return response()->json([
            'app_slug' => $this->appSlug(),
            'supported' => false,
            ...$payload,
        ]);
    }

    private function appSlug(): string
    {
        return (string) config('sso.app_slug');
    }
}
