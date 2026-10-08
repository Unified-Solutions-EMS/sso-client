<?php

namespace Unified\SsoClient\Settings\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Contracts\SettingsProvider;
use Unified\SsoClient\Settings\SettingsPatchValidator;
use Unified\SsoClient\Settings\SettingsResult;
use Unified\SsoClient\Settings\SettingsSchema;

class SettingsController extends Controller
{
    public function show(int $ssoCompanyId): JsonResponse
    {
        $provider = $this->provider();

        if ($provider === null) {
            return $this->unsupported(['schema' => null, 'values' => null]);
        }

        $schema = $provider->schema();
        $stored = $provider->values($ssoCompanyId);

        if ($stored === null) {
            return $this->notProvisioned(['schema' => $schema->toArray(), 'values' => null]);
        }

        $stored = array_intersect_key($stored, $schema->settings());

        return response()->json([
            'app_slug' => $this->appSlug(),
            'supported' => true,
            'provisioned' => true,
            'schema' => $schema->toArray(),
            'values' => $this->maskSecrets($schema, array_merge($schema->defaults(), $stored)),
        ]);
    }

    public function update(int $ssoCompanyId, UpdateSettingsRequest $request, SettingsPatchValidator $validator): JsonResponse
    {
        $provider = $this->provider();

        if ($provider === null) {
            return $this->unsupported(['results' => (object) []]);
        }

        if ($provider->values($ssoCompanyId) === null) {
            return $this->notProvisioned(['results' => (object) []]);
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
            'provisioned' => true,
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

    /**
     * Secrets have no default (Setting::default() is null for them), so
     * has_value reflects a stored value only.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function maskSecrets(SettingsSchema $schema, array $values): array
    {
        foreach ($schema->settings() as $key => $setting) {
            if ($setting->isSecret()) {
                $values[$key] = ['value' => null, 'has_value' => $values[$key] !== null && $values[$key] !== ''];
            }
        }

        return $values;
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notProvisioned(array $payload): JsonResponse
    {
        return response()->json([
            'app_slug' => $this->appSlug(),
            'supported' => true,
            'provisioned' => false,
            ...$payload,
        ]);
    }

    private function appSlug(): string
    {
        return (string) config('sso.app_slug');
    }
}
