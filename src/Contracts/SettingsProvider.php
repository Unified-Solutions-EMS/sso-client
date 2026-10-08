<?php

namespace Unified\SsoClient\Contracts;

use Unified\SsoClient\Settings\SettingsActor;
use Unified\SsoClient\Settings\SettingsResult;
use Unified\SsoClient\Settings\SettingsSchema;

/**
 * Lets SSO read and change this app's agency settings through one shared rail.
 * Bind an implementation in AppServiceProvider::register():
 *
 *   $this->app->bind(SettingsProvider::class, \App\Settings\Registry::class);
 *
 * The package registers GET and PATCH /api/internal/settings/{ssoCompanyId}
 * behind CORE_APP_API_KEY and resolves the bound provider. Apps that bind
 * nothing answer `supported: false`, which is a normal answer.
 *
 * The schema is declared in code because it doubles as the description the
 * AI setup assistant reads, so keys, labels and help text are a contract.
 *
 * Tenancy: these routes run with no session user, so HasCompanyScope gives
 * no protection (and Billing-style scopes are a no-op). Providers resolve
 * the company themselves from companies.sso_company_id and filter every
 * read and write by that local company id (DEV_GUIDELINES §4a: the route's
 * ssoCompanyId is the authoritative source; withoutGlobalScopes() only when
 * the very next clause is ->where('company_id', $companyId)).
 */
interface SettingsProvider
{
    public function schema(): SettingsSchema;

    /**
     * Current values for the company, keyed by setting key. Keys the provider
     * leaves out are answered with the schema default.
     *
     * Return null when this app has no record of the company (no local row
     * with that sso_company_id). The package then answers provisioned=false
     * and never calls apply() for it, the settings counterpart of
     * AgencyStatusResponse::notProvisioned().
     *
     * @return array<string, mixed>|null
     */
    public function values(int $ssoCompanyId): ?array;

    /**
     * Applies an already-validated partial patch for a company values() has
     * just confirmed exists. The package has checked every
     * key against the schema and its rules before calling this, so $patch only
     * holds known keys with valid values.
     *
     * This is the one place a settings change enters the app: call the app's
     * own services/actions here (the ones its settings page uses) so observers,
     * webhooks, audit logs and metrics still fire. Never write tables directly.
     *
     * Must be idempotent: re-applying the same patch is a no-op success.
     * Return a result for every key in $patch: saved(), or blocked() with a
     * reason the admin can act on (e.g. a dependency is missing). Keys left
     * out of the result are reported to SSO as blocked.
     *
     * @param  array<string, mixed>  $patch
     */
    public function apply(int $ssoCompanyId, array $patch, SettingsActor $actor): SettingsResult;
}
