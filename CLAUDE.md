# unified/sso-client

**This package is installed in every Unified app.** The platform-wide conventions live at
`docs/DEV_GUIDELINES.md` in THIS repo — that file is the canonical copy synced into every app
repo root. Read it before any change here.

`/Sites/DEV_GUIDELINES.md` is a symlink to this repo's `docs/DEV_GUIDELINES.md`, so editing the
doc here changes what every workspace agent reads. Treat it with the same care as code.

---

## What this package provides

Laravel package (`Unified\SsoClient\`, PHP 8.2+, Laravel 11/12/13, Spatie permission 6/7).
Auto-discovered via `SsoServiceProvider`; config published as `config/sso.php` + `config/metrics.php`.

- **OAuth2 login flow** — `SsoClient` (authorize URL + PKCE, code exchange, refresh, `/api/user`
  fetch, logout URL) driving `SsoCallbackController` on `/auth/sso/{redirect,callback,logout}`.
  `EnsureSsoSessionIsFresh` (`sso.session`) and `SsoApiAuthenticate` (`sso.api`) middleware.
  **The callback URL is single-use.** `consumeOAuthState()` pulls the OAuth state and the PKCE
  verifier together at the top of `callback()`, so the pair authorizes exactly one trip. They used
  to be plain reads, and the success path only calls `session()->regenerate()`, which keeps every
  attribute — so a browser re-navigating to a callback URL it had already used (iOS Safari tab
  restore, back button, reload) matched the stale state and went on to re-redeem a code SSO had
  already spent, drawing a 400 `invalid_grant` (UNI-438). A replay now stops at the state check and
  redirects to login, which SSO answers from its own live session, so the user never sees it. Token
  exchange and refresh failures name the OAuth `error` and `hint` in the exception message rather
  than only the status code — four different causes all surface as "HTTP 400" otherwise.
  **Token requests retry transport errors and 5xx only, never 4xx** (UNI-539). A connection failure
  or 5xx means Passport never processed the grant, so `exchangeCode()`/`refreshToken()` retry twice
  (250ms apart) instead of burning a one-shot code on a blip. A 4xx means Passport DID process the
  grant and refused — retrying would re-present a possibly-consumed credential (double-redeem), so
  4xx returns immediately. `SsoClientException` carries the OAuth error identifier
  (`oauthError()` / `isInvalidGrant()`); the callback's catch treats `invalid_grant` as the ordinary
  tail of a duplicate callback or aged-out code: it logs at info, does **not** `report()` (this was
  the daily CLOUDPCR-6E / CREW-SCHEDULING-10 Sentry noise), and redirects back through the authorize
  flow — while still counting toward the loop breaker so a deterministic invalid_grant loop breaks.
  **Consuming the state only stops a SEQUENTIAL replay.** A session cannot make read-then-write
  atomic: Laravel loads the payload at the start of a request and writes it back at the end, so two
  callbacks that OVERLAP both find the state intact, both pass the state check, and both redeem the
  same authorization code. SSO's own oauth tables showed it — a successful access token issued one to
  three seconds before every logged failure (UNI-438, reopened). `SsoSingleFlight::claimOAuthState()`
  settles it with `Cache::add()`, one conditional write the store resolves atomically, so it holds
  across the several instances Laravel Cloud and Vapor run. The loser stands down: it redirects to
  login, does **not** `report()` (a duplicate callback is ordinary browser behaviour, not a fault),
  and does **not** count toward the loop breaker — otherwise a browser that duplicates its callback
  three times would be shown the sign-in-failed page for a login that actually worked.
- **`SsoSingleFlight`** — the shared coordination point for credentials that may be spent exactly
  once, cache-backed because the session cannot do it. `claimOAuthState()` for the callback above;
  `refreshTokens()` for Passport's ROTATING refresh tokens, where a polling dashboard has several
  requests in flight when the access token ages out, they all read the same refresh token, and the
  losers used to present one SSO had already revoked — read as "signed out", session cleared, and the
  cleared copy written over the winner's freshly refreshed one, ending a session mid-shift over a
  perfectly good token (UNI-455). One request now runs the exchange under a lock and publishes the
  result for the short window the others need, so every caller stores the same live pair and whichever
  write lands last is still correct. A genuinely revoked token still signs the user out, so SSO logout
  keeps propagating. Everything in the class **fails open**: an unreachable cache degrades to the old
  uncoordinated behaviour rather than locking the platform out of logging in. Config lives under
  `sso.coordination_store` / `state_claim_ttl_seconds` / `refresh_*`; leave the store null unless the
  app's default cache is per-instance (`array`, `file`), which cannot coordinate anything.
  **Callback failures never redirect forever.** Redirecting a failed callback to the login route
  re-enters the SSO flow, SSO answers instantly from its own live session, and any deterministic
  failure loops until the browser gives up (UNI-416). Every failure exit runs through
  `failCallback()`: it `report()`s the exception so Sentry sees it, counts consecutive failures in
  the session inside a rolling window (`sso.callback_failure_window_seconds`, 120s), and at
  `sso.callback_failure_threshold` (3) renders the `sso::sign-in-failed` view with a 500 instead of
  redirecting. A success clears the counter. If the session store itself is broken the counter can't
  persist and the breaker can't trip — accepted, and documented in the method.
- **`SsoUserSynchronizer`** — the heart of the package. Idempotently upserts the user, resolves/creates
  local companies (match order: `sso_company_id` → `core_tenant_id` → name), attaches memberships,
  syncs per-company roles into `company_user_roles`, syncs staff roles into `users.staff_roles`, and
  syncs enabled modules. Apps override by binding `SsoUserSynchronizerContract`.
  **Link-key collisions never break login.** Before stamping `sso_company_id` or `core_tenant_id`
  onto the matched row it checks whether a different local row already holds that value; if one does
  the stamp is skipped and a `CompanyLinkCollisionException` is logged and `report()`ed for a human
  to merge. An app provisioned before SSO linked the agency's legacy tenant id ends up with exactly
  this shape, and the unique-key violation used to escape the login transaction (UNI-416). Rows are
  never merged automatically.
- **Webhook / provisioning endpoint** — `POST /api/sso/provision` (`SsoWebhookController`), HMAC-verified
  in the controller, no CSRF/auth middleware. Handles `user.created/updated/deleted`,
  `company.updated/activated`, `user.role.changed`, `user.app_role.changed`, `user.staff_role.changed`,
  `impersonation.started/ended`, `user.logged_out`, `trial.seed_data`, `trial.purge_data`,
  `cad.migrate_data`. Unknown events ack rather than 500.
  **`trial.purge_data` is verified before it destroys anything.** The handler asks SSO's
  authoritative `GET /api/internal/companies/trials` (CORE_APP_API_KEY) whether the company is a
  current trial or in the response's `purge_pending` id list (the conversion flow flips
  `companies.status` to active before the queued purge webhook is delivered, so the trials list
  alone would reject every legitimate convert-and-erase purge). `TrialPurgeVerifier` fails closed:
  missing config, SSO unreachable, an error response, or a company on neither list skips the purge,
  logs a warning, and records a `trial.purge_blocked` security event — a malformed, replayed, or
  mistargeted delivery can no longer wipe a live customer. Against an SSO that predates
  `purge_pending`, conversion purges are skipped (data retained) until SSO ships the intent marker.
- **Agency-status route** — `GET /api/internal/agency-status/{ssoCompanyId}` behind `ValidateCoreApiKey`,
  registered by the package so apps never add the route. Apps implement `Contracts\AgencyStatusProvider`
  and bind it. See DEV_GUIDELINES §2a for the response contract and the HIPAA redaction boundary
  (redaction happens in the SSO MCP server, not in apps).
- **Settings rail** — `GET` / `PATCH /api/internal/settings/{ssoCompanyId}` behind `ValidateCoreApiKey`
  (`routes/settings.php`), read and written by SSO's central Settings page and the AI setup assistant.
  Apps implement `Contracts\SettingsProvider` (`schema()`, `values()`, `apply()`) and bind it; with
  nothing bound both routes answer 200 `supported: false`. GET returns `{app_slug, supported,
  provisioned, schema, values}` with schema defaults filled in for keys the provider leaves out.
  **Tenancy:** these routes have no session user, so `HasCompanyScope` protects nothing; the provider
  resolves the company from `companies.sso_company_id` itself and filters by that local id (§4a).
  `values()` returns `null` when the app has no such company: GET answers `provisioned: false,
  values: null` and PATCH answers `provisioned: false, results: {}` without calling `apply()`.
  PATCH takes `{patch: {key: value}, actor: {sso_user_id, name, source: sso|app|ai}}`
  (`sso_user_id` required for every source; an AI change names its approver) and always answers 200 with
  per-key `results` (`saved`, `invalid` + message, `blocked` + reason, `unknown_key`); 422 means the
  body shape is wrong. The package validates every key against the schema before calling `apply()`
  (values are validated alone, so cross-field rules don't apply; use `requires()` + `blocked()`), so
  `apply()` only sees known, valid keys and only the provider ever answers `blocked`. A key the
  provider forgets to report comes back `blocked`, never assumed saved. Values are never logged.
  `secret()` settings (integration tokens, passwords) are never returned: GET answers
  `{value: null, has_value: bool}` for them, and their `default` is never emitted or counted, so
  `has_value` means a stored value. On PATCH a non-empty string sets one, `null` clears it,
  and `""` or an absent key leaves it unchanged (dropped before validation, no result entry), so a form
  that round-trips the masked field can't blank a credential. The patch is read from the raw JSON body
  because apps' global `ConvertEmptyStringsToNull` would otherwise turn `""` into a clear.
  `atomic()` on a group (pay-period frequency + start date) tells the renderer to save the group's keys
  in one PATCH behind an explicit Save instead of per-field autosave; package validation stays per key.
  `apply()` must call the app's own services so observers, webhooks and metrics still fire.

  ```php
  // app/Settings/Registry.php
  class Registry implements SettingsProvider
  {
      public function __construct(private AgencyPreferences $preferences) {}

      public function schema(): SettingsSchema
      {
          return SettingsSchema::make()
              ->group('alerts', 'Dispatch alerts')
              ->toggle('alerts.pre_pickup', 'Pre-pickup alert')->default(false)
              ->number('alerts.pre_pickup_minutes', 'Minutes before pickup')->rules('integer|min:1|max:120')
                  ->default(15)->requires('alerts.pre_pickup')
              ->group('numbering', 'Incident numbering')
              ->select('numbering.reset', 'Reset numbering', ['yearly' => 'Every year', 'never' => 'Never'])->danger()
              ->entity('dispatch.default_station', 'Default station', SettingEntity::Station)->requires('station')
              ->group('integrations', 'Integrations')
              ->text('integrations.bryx_token', 'Bryx API token')->rules('min:8')->secret()
              ->group('pay', 'Pay period')->atomic()
              ->select('pay.frequency', 'Pay period frequency', ['weekly' => 'Weekly', 'biweekly' => 'Every two weeks'])
              ->text('pay.start_date', 'Pay period start date')->rules('date');
      }

      public function values(int $ssoCompanyId): ?array
      {
          // No session user here: resolve by sso_company_id, then filter by that local id (§4a).
          $company = Company::query()->where('sso_company_id', $ssoCompanyId)->first();

          return $company ? $this->preferences->forCompany($company->id) : null;
      }

      public function apply(int $ssoCompanyId, array $patch, SettingsActor $actor): SettingsResult
      {
          $companyId = Company::query()->where('sso_company_id', $ssoCompanyId)->value('id');

          return $this->preferences->update($companyId, $patch, $actor); // app service returns per-key results
      }
  }

  // AppServiceProvider::register()
  $this->app->bind(SettingsProvider::class, \App\Settings\Registry::class);
  ```

  **Smoke test template.** Every app that binds a provider copies this into
  `tests/Feature/Settings/SettingsRailSmokeTest.php` (adjust the company factory and the patched key):

  ```php
  class SettingsRailSmokeTest extends TestCase
  {
      use RefreshDatabase;

      public function test_settings_rail_serves_a_valid_schema_and_applies_a_patch(): void
      {
          config(['app.core_api_key' => 'test-core-key']);
          Company::factory()->create(['sso_company_id' => 4242]);

          $get = $this->withToken('test-core-key')->getJson('/api/internal/settings/4242')
              ->assertOk()->assertJson(['supported' => true, 'provisioned' => true]);

          $settings = collect($get->json('schema.groups'))->flatMap(fn (array $group): array => $group['settings']);
          $this->assertSame($settings->count(), $settings->pluck('key')->unique()->count(), 'Setting keys must be unique');
          foreach ($settings as $setting) {
              if (in_array($setting['type'], ['select', 'multi_select'], true)) {
                  $this->assertNotEmpty($setting['options'], "{$setting['key']} has no options");
              }
              if ($setting['secret']) {
                  $this->assertNull($setting['default'], "{$setting['key']} is secret and must not carry a default");
              }
          }

          $key = $settings->firstWhere('type', 'toggle')['key']; // any key that is safe to write in tests
          $this->withToken('test-core-key')->patchJson('/api/internal/settings/4242', [
              'patch' => [$key => true],
              'actor' => ['sso_user_id' => 1, 'name' => 'Smoke test', 'source' => 'sso'],
          ])->assertOk()->assertJsonPath('results', [$key => ['status' => 'saved']]);

          $this->withToken('test-core-key')->getJson('/api/internal/settings/999999')
              ->assertOk()->assertJson(['provisioned' => false, 'values' => null]);
      }
  }
  ```
- **`Concerns\SyncsCompanyRoles`** — `loadRolesForCompany()`, `hasRoleInCompany()`, `companyRoleNames()`
  plus staff helpers `isStaff()`, `hasStaffRole()`, `isGlobalAdmin()` reading the package-managed
  `users.staff_roles` column. Apps must delete hand-rolled copies of these.
- **Metrics** — `Metrics` facade (aliased globally) for domain emits and `Metrics\Middleware\TrackSessionMetric`
  (`metrics.session`) for `session.start` heartbeats, appended to an app's `web` group. Context keys
  `local_company_id` / `local_user_id` are translated to SSO ids by `EloquentMetricContextResolver`
  via `companies.sso_company_id` / `users.sso_id`. `METRICS_APP_KEY` must be the SSO registry slug.
- **Security events** — `SecurityEvents` facade (aliased globally) records attack-signal events to SSO's
  `/api/internal/security-events/ingest` (CORE_APP_API_KEY auth, endpoint derived from `SSO_BASE_URL`,
  app key falls back to `METRICS_APP_KEY` → `SSO_APP_SLUG`, so already-wired apps need zero config).
  Auto-recorded with no per-app wiring: failed logins / lockouts / password resets (Laravel auth events),
  `internal_api.auth_failed` (`ValidateCoreApiKey`), and `webhook.signature_failed` (the package's HMAC
  endpoints, now verified via the shared `VerifiesSsoWebhookSignature` trait). Honeytokens:
  `SECURITY_HONEYTOKEN_KEYS` (decoy API keys) and `SECURITY_CANARY_EMAILS` (decoy accounts) — any use
  records a critical event. Severities info/warning/critical; critical alerts immediately on the SSO side.
  Best-effort like Metrics: unconfigured apps log-and-noop, the send job swallows all failures (on a
  sync queue it runs inside the calling request, so it must never raise), and recording defaults to
  OFF under unit tests — pipeline tests opt back in with `config(['security.enabled' => true])`.
- **Dashboard + action endpoints** — `POST /api/sso/dashboard` (`config('sso.dashboard_provider')`
  implementing `DashboardDataProvider`) and `POST /api/sso/actions/{action}`
  (`config('sso.action_handlers')` map to `SsoActionHandler`), both HMAC-verified.
  Action handlers return `array|ActionResponse`. A plain array is a 200, as it always was. To answer
  with another status, either put an integer `http_status` (200-599) in the array (the controller
  uses it as the status and strips the key from the body) or return
  `Unified\SsoClient\Http\ActionResponse` — `ok(array)`, `notFound($message)` (404),
  `tooManyRequests($message, ?$retryAfterSeconds)` (429, sets `Retry-After`),
  `notImplemented($message)` (501, e.g. a missing third-party key), `error($status, $body)` (4xx/5xx
  only; `ok()` is the only 2xx path). Prefer `ActionResponse` in new handlers. Apps must not bind their
  own controller over `SsoActionController` to get statuses (CAD's `CadSsoActionController` existed
  only because this was missing).
  Every response a handler produced, whatever its status, carries `X-SSO-Action-Handled: 1`. The
  controller's own "unknown action" 404 and "handler class missing" 501 do not. Callers (SSO) must read
  a 404/501 WITHOUT that header as "this app doesn't offer the action" and WITH it as the handler's
  answer (e.g. "no such run", "not configured"). A handler that throws gets a generic
  `{"error": "Action failed"}` 500 (no header); the log records the action name, `sso_company_id` /
  `company_id` when present, and the exception, never the payload (it can carry PHI).
- **Session actions** — `EnforceSsoSessionActions` is auto-appended to the `web` group in every app,
  so impersonation/forced-logout land on the next request without per-app wiring.
- **Legacy cookie scrub** — `Middleware\PurgeLegacyApexCookies` (`sso.purge-legacy-cookies`), also
  auto-appended to the `web` group. The legacy ASP.NET app set several cookies on `.unified-apps.com`,
  so browsers still send them to every app on the platform; its own logout only wrote host-only
  deletions that never matched the parent-domain copies. When a request carries a name from
  `config('sso.legacy_cookies.names')` the middleware expires it in both the host-only and
  parent-domain form. Clean requests are a no-op. Every listed name is one Unified.Base provably sets
  (evidence is in the config comment). The middleware hard-refuses `XSRF-TOKEN`, `config('session.cookie')`,
  anything ending `_session`, and anything starting `remember_web` regardless of the config list —
  scrubbing those would sign every user out on every request. Empty list disables it.
- **Roster reconcile** — `sso:sync-users` command, scheduled hourly from the provider via
  `config('sso.roster_sync')`, `withoutOverlapping()`. Pulls each locally-known company's full roster
  from SSO and runs every member through `SsoUserSynchronizer`, so apps see users who never logged in.
- **Timezone propagation** — SSO owns `companies.timezone`; the package mirrors it onto the app's local
  `companies.timezone` in both the login sync and the `company.updated` webhook, guarded by
  `Schema::hasColumn()` so apps that haven't adopted the column ignore it. Apps must NOT ship their
  own timezone selector.
- **Migrations** — `sso_session_actions` table and `users.staff_roles` column, loaded from the package.
- **Master data mirrors** (`src/MasterData/`) — SSO is canonical for shared master data; apps hold
  a read-only mirror per entity, opted into with `config('sso.master_data.<entity>')` (env
  `SSO_MASTER_DATA_QUALIFICATIONS`, `SSO_MASTER_DATA_DIVISIONS`, `SSO_MASTER_DATA_LOCATIONS`, all default false). Pieces:
  `Contracts\EntityMirror` (one class per entity: table, SSO id column, publish tag, webhook apply,
  resync, link-by-name), `CatalogMirror` (the shared base: linking, name adoption, `sso_link_pending`,
  the `sso_updated_at` stale guard, deactivate-never-delete, link-by-name, the push mapping; each
  entity adds its columns and its assignment rules), `MasterDataRegistry`
  (known entities + opt-in check; mirrors resolve through the container so an app can bind a
  subclass), `MasterDataWebhookHandler` (the webhook controller's `match` sends `qualification.*`,
  `user.qualifications_changed`, `division.*`, `user.division_changed` and `location.*` here; a disabled entity, an unmigrated mirror, or an unknown
  company is a 200 ack), `MasterDataClient` (`GET {SSO_BASE_URL}/api/internal/companies/{id}/{entity}`,
  bearer `CORE_APP_API_KEY`), `sso:resync-master-data {entity} {--company=} {--link-by-name}` and the
  one-time upward seed `sso:push-master-data {entity} {--company=} {--dry-run}` (mirrors that
  implement `Contracts\SeedsSso`).
  Every mirror write is query-builder SQL pinned to the local company resolved from the
  authoritative SSO company id (§4a); rows SSO deleted or dropped are deactivated, never deleted.
  **Qualifications** (first entity) mirror into the app's existing `qualifications` +
  `company_user_qualifications` tables. Events: `qualification.created|updated` (upsert),
  `qualification.deleted` (`is_active=false`), `user.qualifications_changed` (the user's full set
  in that company). The payload's `updated_at` is stored (UTC) in `sso_updated_at`; a
  `qualification.created|updated` delivery older than the stored value is ignored and acked
  `"status": "stale"` (SSO queues deliveries, so they can arrive out of order). A resync always wins.
  SSO gives no ordering guarantee across events either: a `user.qualifications_changed` naming an
  SSO id the mirror lacks pulls the company's catalog once from the internal endpoint, inserts the
  missing rows, then applies the assignment (if SSO is unreachable, unknown ids are skipped and
  logged; the next resync heals). `applies_to` is a hint for readers, not a delivery filter: SSO
  sends every qualification event to every app that has the entity enabled, and the mirror stores
  them all; `HasMirroredQualifications` / `QualificationCatalog` apply the filter when reading.
  A `user.qualifications_changed` for a user this app has not provisioned yet is acked `skipped`;
  the nightly resync (below) heals it for people who never log in.
  `/api/user` `companies[].qualifications: [{id, name}]` (also on the roster endpoint
  `sso:sync-users` reads) is mirrored on login by `SsoUserSynchronizer` (no-op when the key is
  absent). That payload carries only ACTIVE qualifications, while webhooks and the internal
  endpoint carry the full set, so the login sync only adds/removes assignments to active rows and
  never touches assignments to inactive ones; a login cannot undo a webhook. Assignment replacement only
  touches confirmed SSO-linked rows, so unlinked pre-cutover rows keep their assignments.
  **Name adoption is non-destructive.** An SSO row with no linked local row adopts a single unlinked
  local row of the same name (case-insensitive) instead of inserting a duplicate. In the webhook
  and login paths that only sets `sso_qualification_id` and `sso_link_pending=true`: the local
  name, description, `applies_to` and `is_active` are kept, later deliveries for the row
  (update/delete) are acked `pending` and skipped, and assignments to it are never removed (only
  added). SSO's set for an adopted row does not yet include this app's holders, so replacing from
  it would delete them (the PR #17 review reproduced exactly that for the second app to cut over).
  The push mapping, `--link-by-name` and a full resync confirm the link (`sso_link_pending=false`);
  only a resync overwrites the local fields. Read side: `MasterData\Qualifications\HasMirroredQualifications` on the User model
  (`companyQualifications()`, `companyQualificationNames()`, `companyQualificationIds()`,
  `hasQualificationInCompany()`) and `QualificationCatalog::usableForCompany()` for pickers; both
  return only active rows whose `applies_to` is empty or contains `sso.app_slug`.
  Resync fetches and the push import use `sso.master_data.timeout` (`SSO_MASTER_DATA_TIMEOUT`,
  default 120 s, not the 10 s login timeout); a timeout prints a clear message and changes nothing
  locally. With `sso.master_data.schedule_resync` (default true) the package schedules
  `sso:resync-master-data <entity>` daily at 03:15 (`withoutOverlapping`) for every enabled entity.
  **Per-app cutover order** (the second and later apps lose data if the mirror is enabled before
  the push; see "Name adoption" above):
  1. `php artisan vendor:publish --tag=sso-master-data` and `php artisan migrate` (adds
     `sso_qualification_id`, `applies_to`, `is_active`, `sso_updated_at`, `sso_link_pending`,
     skipping any column already present). Leave `SSO_MASTER_DATA_QUALIFICATIONS` unset/false.
  2. `php artisan sso:push-master-data qualifications --dry-run`, then without `--dry-run`. The
     push requires only the migration and is meant to run with the entity still disabled. It seeds
     SSO with the app's local catalog + assignments via
     `POST /api/internal/companies/{id}/qualifications/import` and links each local row to the SSO
     id in the response `mapping` (`{local_id: sso_id}` or `{local_id: {sso_id, updated_at}}`;
     `sso_updated_at` is taken from SSO, never the app clock, so the import's own webhooks are not
     stale). It prints SSO's created / matched / assignments_added / conflicts (name, both
     descriptions, and whether SSO cut the incoming description to 500 characters; the summary
     counts those as truncated_descriptions) / unknown_users, plus local users skipped for having no `sso_id` and any mapping
     it refused to apply (a row already linked elsewhere, or two local spellings SSO folded into
     one row). Names longer than SSO's 255-character limit are sent cut, with a warning (the local name is
     unchanged). Rerunning creates nothing new in SSO and re-applies the same mapping.
  3. Review the conflicts in SSO `/system`, **and resolve every `unknown_users` and skipped local
     user** (no `sso_id`, or not a member of the company in SSO) before step 5: the full resync
     treats SSO as authoritative and clears the linked assignments of every local user SSO does not
     list, so those users' assignments are lost locally and SSO never had them.
  4. Set `SSO_MASTER_DATA_QUALIFICATIONS=true`. Webhooks, logins and the nightly resync start.
  5. `php artisan sso:resync-master-data qualifications` (full) pulls the catalog + assignments and
     confirms any pending links. Idempotent; rerun any time as the healer. (`--link-by-name`
     remains for an app whose rows were never pushed: it links by name without creating, merging
     or deleting.)
  6. Switch the app's qualifications editor to SSO (delete the local settings panel) and read
     through `HasMirroredQualifications`.

  **Divisions** (second entity, `MasterData\Divisions\DivisionMirror`, branch
  `feature/divisions-mirror`; SSO side on `feature/divisions-sync`). A division is an agency list
  row; whatever an agency already calls a division in Crew or HR is imported as-is (James,
  2026-10-09: platoon-style names such as "Alpha Shift" included, no review gate). Local storage:
  a `divisions` table (`company_id`, `name`, plus `sso_division_id`, `code`, `is_active`,
  `sort_order`, `sso_updated_at`, `sso_link_pending`) and **one division per person per company**
  in `company_user.division_id`. The migration (`vendor:publish --tag=sso-master-data-divisions`)
  extends Crew's existing table and pivot column and creates both for an app that has none (HR);
  existing rows start active. An app whose table/column names differ binds a `DivisionMirror`
  subclass overriding `table()`, `assignmentTable()` or `assignmentColumn()`.
  - SSO contract (the qualifications shapes): `GET /api/internal/companies/{id}/divisions` ->
    `{company: {id, division_label}, divisions: [{id, name, code, is_active, sort_order, updated_at}],
    assignments: [{user_id, division_id}]}` (whole list, turned-off rows included, not paged);
    `POST .../divisions/import` `{app_slug, divisions: [{local_id, name, code?, is_active?}],
    assignments: [{user_sso_id, local_division_id}]}` -> `{created, matched, assignments_added,
    conflicts: [{name, reason}], assignment_conflicts: [{user_sso_id, sso_division_id,
    incoming_division_id}], unknown_users, invalid: [{local_id, name, message}], mapping: {local_id:
    {sso_id, updated_at}}}`. Names are capped at 100 in SSO; the push cuts longer local names and
    warns (the local name is unchanged until a resync).
  - Events: `division.created|updated|deactivated` (full row; all three upsert, deactivated carries
    `is_active: false`; SSO never deletes divisions) and `user.division_changed`
    `{company: {id}, user: {id}, division_id: int|null}`. `/api/user` and the roster endpoint carry
    `companies[].division: {id, name} | null`, sent whether or not the division is turned on (the
    same value the webhook and snapshot carry), so the login sync simply applies it.
  - **Never deleted.** Crew's `locations`/`resources` cascade-delete with their division, so SSO
    removing or a resync dropping a row only sets `is_active = false`; people stay recorded in it.
  - **Assignment writes only replace a confirmed linked division (or none).** A person whose local
    division is unlinked (pre-cutover) or pending keeps it (`kept_unconfirmed`) until the push
    mapping or a resync confirms the link, so the second app to cut over cannot lose anyone.
    `assignment_conflicts` on the push are people SSO already places in a different division; SSO
    keeps its value and the resync applies it locally, so settle those in SSO before step 4.
  - Read side: `MasterData\Divisions\HasMirroredDivision` on the User model (`companyDivision()`,
    `companyDivisionId()`, `companyDivisionName()`, `isInDivision()`) and
    `DivisionCatalog::usableForCompany()` (active rows, SSO order) for pickers.
  - Cutover order is the qualifications one with the entity swapped: publish the
    `sso-master-data-divisions` migration and migrate; `sso:push-master-data divisions --dry-run`,
    then for real (flag still off); settle conflicts / unknown users / skipped users; set
    `SSO_MASTER_DATA_DIVISIONS=true`; `sso:resync-master-data divisions`; move the app's division
    editor to SSO's Settings > Divisions page and filter pickers by `is_active`.

  **Locations** (third entity, formerly "stations"; `MasterData\Locations\LocationMirror`, branch
  `feature/locations-mirror`; SSO side on `feature/locations-sync`). James 2026-10-09: the platform
  word is Locations (Crew, CloudPCR and CAD already said it); "Station" is only a location type now.
  An agency's own places: stations, headquarters, staging posts. **No person assignment.**
  - **Shape is a projection, not an assumption.** `Locations\LocationProjection` says which table,
    name / number / division columns, how an SSO record lands on the row (`toColumns()`), what lives
    outside it (`written()`, e.g. phones in a child table; must be idempotent, it also runs for every
    row on a full resync) and what the push sends (`pushColumns()`, `toImport()`). The package binds
    `TextAddressLocationProjection` (Crew's `locations`: `name`, `phone_number`, one free-text Google
    address, `division_id`; `UsAddress` formats SSO's address as one line and splits a line back into
    fields for the push). An app with another shape binds its own in `AppServiceProvider::register()`
    BEFORE running the migration; `tests/Stubs/DemLocationProjection.php` is the CloudPCR-shaped
    template (`dem_locations`: dlocation_01 type, 02 name, 03 number, 04 GPS "lat,lng", 06/06b
    street, 07 city GNIS + `mailing_city`, 08 state FIPS, 09 ZIP, 10 county FIPS, 11 country;
    phones in `dem_location_phones`; `deleted_at` forced null because SSO's off switch is `is_active`).
  - `CatalogMirror` gained `nameColumn()`, `activeColumn()`, `attributesFor($company, $record)` and
    `findAdoptableFor($company, $record)` hooks (defaults keep qualifications and divisions as they
    were). Locations adopt an unlinked local row by **number first** (when the projection has a number
    column and exactly one row has it), then by name; `--link-by-name` links by number first too.
  - Migration (`vendor:publish --tag=sso-master-data-locations`) adds `sso_location_id`, `is_active`
    (existing rows start on), `sort_order`, `sso_updated_at`, `sso_link_pending` to the projection's
    table, each skipped when present, unique `(company_id, sso_location_id)`; creates a minimal table
    only for an app with none. It changes no existing column: Crew must make `locations.division_id`
    nullable with restrict-on-delete itself (design PR 6) before enabling.
  - SSO contract: `GET /api/internal/companies/{id}/locations` -> `{company: {id, division_label},
    locations: [{id, name, number, location_type, location_type_name, division_id, division_name,
    address: {street, street2, city_gnis, city_name, state, state_name, zip, county, county_name,
    country}, latitude, longitude, phones: [{id, number (E.164), type}], is_active, sort_order,
    updated_at}]}` (whole list, not paged); `POST .../locations/import` `{app_slug, locations:
    [{local_id, name, is_active, division_local_id?, division_sso_id?, number?, location_type?,
    address?, latitude?, longitude?, phones?}]}` -> `{created, matched, filled, partial, conflicts,
    unresolved_divisions, refused, invalid, mapping}`. SSO matches by number then name, never
    overwrites, fills only empty fields on a match, records the seeding app. An address SSO cannot
    file but that has words (Crew's free text) is kept as the street with `address.incomplete: true`
    and reported in `partial`, so the first resync gives the app its text back. The push prints
    `filled`, `partial`, `unresolved_divisions` and `refused` counts plus tables for the last three.
  - Events `location.created|updated|deactivated`, full row, all upsert (deactivated carries
    `is_active: false`); stale and pending guards as for every catalog. Never deleted: Crew's resources
    and shift templates, CAD units and CloudPCR scenes point at these rows.
  - **Division translation** goes through the divisions mirror's links (`sso_division_id`): SSO's
    `division_id` becomes the local division id; `null` clears it; an id this app has not linked yet
    leaves the local value alone (new rows get none). The push sends `division_local_id` always and
    `division_sso_id` when the local division's link is confirmed; SSO also maps local ids through the
    links its divisions import recorded. So push **divisions before locations**.
  - Read side: `LocationCatalog::usableForCompany()` (active rows, SSO order).
  - Cutover: bind the projection (if not Crew-shaped); publish `sso-master-data-locations` and migrate;
    `sso:push-master-data locations --dry-run`, then for real (flag off); fix `refused` /
    `unresolved_divisions` in SSO Settings > Locations; `SSO_MASTER_DATA_LOCATIONS=true`;
    `sso:resync-master-data locations`; make the app's location editor read-only with an "Edit in
    Settings" link and filter pickers by `is_active`.

## Release discipline

Apps consume this package from GitHub **`dev-main`** (in `Unified-Solutions-EMS`), not Packagist.
Nothing here reaches an app until it is pushed and the app updates.

1. Change + test here.
2. Push the branch/merge to `main`.
3. In each consuming app: `composer update unified/sso-client --prefer-dist`.
4. Verify the app's `composer.lock` entry for `unified/sso-client` references the **GitHub dist**,
   not a local path repo. A path-repo lock entry deploys as a broken/missing package on Vapor and
   Laravel Cloud.

**Lock-fix recipe** when a lock file is stuck on a path repo or an old commit: temporarily remove the
path repository from the app's `composer.json` → `composer update unified/sso-client --prefer-dist` →
confirm the lock now shows the GitHub dist + expected commit → restore the path repository entry.

When wiring a new app to the SSO dashboard widget, also confirm: `SSO_WEBHOOK_SECRET` in the app's
`.env` matches the app's row in SSO's `applications` table, `config/sso.php` has both `webhook_secret`
and `dashboard_provider`, and the installed package version actually contains the dashboard route.

## Blast radius

Every one of the ~14 platform apps depends on this package. There is no per-app fork.

- Breaking the `/api/user` payload contract or the webhook handling breaks all of them at once.
- Test against at least one real consuming app (CloudPCR or Crew-Scheduling) before pushing —
  package tests alone do not prove the synchronizer still works against a real app schema.
- Schema assumptions are load-bearing: `companies.sso_company_id`, `companies.core_tenant_id`,
  `users.sso_id`, `users.staff_roles`, `company_user_roles`. Guard anything newer with
  `Schema::hasColumn()` the way timezone does, so apps that haven't migrated degrade quietly.
- Adding a webhook event is additive and safe; renaming or changing the shape of an existing one is not.
- The package has no pre-push test gate. Run `phpunit` (`composer test`) and
  `vendor/bin/pint --dirty --format agent` yourself.

## Checkouts and in-flight work (as of 2026-08-05)

There are two working copies of this repo on this machine, on different branches. Docs written in one
do not appear in the other until the branches merge.

- `/Sites/sso-client` — this checkout, on **`feature/roster-reconcile`**, paired with the branch of the
  same name in `/Sites/sso` (SSO adds `GET /api/internal/companies/{company}/users?app={slug}`; the
  package adds the scheduled pull). Merge SSO first, then the package, then update apps.
  **This branch is behind `main`** — it does not contain the timezone propagation that is already
  shipped in apps' vendor copies. Rebase on `main` before merging or you will regress it.
- `/Sites/packages/unified/sso-client` — on **`feature/device-authorization`** (Phase 2 of the
  device-authorization program). Ships the `sso.device-lock` middleware, the
  `POST /sso/device/{challenge,verify,register}` handshake proxy, `DeviceGuard`, `DeviceSessionState`,
  the `sso_device_bypasses` table, `SyncsCompanyRoles::hasDeviceBypass()`, and consumption of the
  `device.revoked` webhook. Until that branch merges, every app's shipped package acks and ignores
  `device.revoked`, so revocation only blocks new logins. Go-live merge order is
  sso-client → SSO → CloudPCR (which currently pins the package branch and must be reverted to `@dev`).
