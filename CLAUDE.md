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
  `cad.migrate_data`. Unknown events ack `{"status":"ok","action":"ignored"}` with a 200 rather than 500,
  so SSO can ship new events before any app handles them (pinned by `SsoWebhookUnknownEventTest`; the
  retired `personnel.*` names and `person.created` stay unknown, `person.updated|deactivated` go to the
  personnel mirror).
  **`device.revoked`** `{company:{id}, user:{id}, device:{id}}` ends the named user's sessions in this
  app: on the database session driver it deletes that user's `sessions` rows (which carry the OAuth
  tokens), and on every driver it queues a `force_logout` session action so a request already past the
  session read is bounced through SSO logout. Other users' sessions are untouched, an unknown device or
  user is still a 200, and storage errors are logged, never thrown. Apps need no code. The device-lock
  middleware and handshake on `feature/device-authorization` are separate and not on main.
  **`company.modules_updated` is acknowledged silently on purpose.** It falls through to the unknown-event
  200 ack in every app: enabled modules reach the local company through the login sync
  (`SsoUserSynchronizer`) and `company.updated`, so there is nothing to act on, and SSO needs the 200 to
  record the delivery. Do not add a handler without adding the missing sync first.
  **`trial.purge_data` is verified before it destroys anything.** The handler asks SSO's
  authoritative `GET /api/internal/companies/trials` (CORE_APP_API_KEY) whether the company is a
  current trial or in the response's `purge_pending` id list (the conversion flow flips
  `companies.status` to active before the queued purge webhook is delivered, so the trials list
  alone would reject every legitimate convert-and-erase purge). `TrialPurgeVerifier` fails closed:
  missing config, SSO unreachable, an error response, or a company on neither list skips the purge,
  logs a warning, and records a `trial.purge_blocked` security event — a malformed, replayed, or
  mistargeted delivery can no longer wipe a live customer. Against an SSO that predates
  `purge_pending`, conversion purges are skipped (data retained) until SSO ships the intent marker.
  **A seeder that is safe to repeat says so (UNI-1754).** `Contracts\RepeatableTrialSeeder` is a marker an
  app's `App\Services\TrialDataSeeder` implements only when a second `trial.seed_data` writes nothing,
  two overlapping ones write one set (shared-cache lock + guard re-read in the seed's transaction under a
  row lock), a seed that failed part way is completed by the next, and a company SSO no longer lists as a
  trial is never seeded. Every `trial.seed_data` answer carries `seed_repeatable` (`AppTrialJobs::seederIsRepeatable()`;
  true for an app with no seeder); SSO records it per app and only then re-sends the seed after a timeout
  or a 502/504, not just after the app's own 429/500/503.
  **Trial sample lists come from SSO** (`src/Trial/`, design `/Sites/docs/design/trial-seed-data-design.md`).
  SSO seeds the new trial's shared lists itself, then fans out. Payload contract (both keys are optional;
  without them the handlers do exactly what they did before, so an older SSO keeps working):
  - `trial.seed_data` adds `master_data: {ready: bool, counts: {divisions, locations, vehicles, facilities,
    medications, procedures, qualifications, agency, contacts, devices, state_configurations, ...: int},
    context: {...}}` (`counts` = active rows in SSO's list for the company when the fan-out was sent, keyed by
    registry entity, so a new entity needs no package code). When the key is present **and** this app has at least one mirror turned on and
    migrated, the handler queues `Trial\Jobs\SeedTrialAfterMasterDataSync` instead of the app's seeder (ack
    `master_data: "syncing"`). The job resyncs EVERY enabled, installed mirror for the company
    (`TrialMasterDataSync`: `MasterDataClient::snapshots()` fetches every plain snapshot at once in one
    `Http::pool`, then the `FetchesSnapshot` mirrors page theirs; only then do the mirrors `resync` one by one
    in `mirrors()` order, since locations point at divisions and so on), then checks each resynced
    entity's active linked rows against `counts` (entities absent from `counts` are not checked; counted
    entities this app does not mirror are ignored, the app seeds those locally). `ready !== true`, SSO
    unreachable / erroring, or a short list throws `TrialMasterDataNotReadyException` (`ShouldntReport`), so
    the queue retries: 6 tries, backoff 5/15/60/120/480 s (each retry logs `SSO trial seed: shared lists
    not mirrored yet, trying again` with the reason; SSO now sends the seed after its lists are complete, so the first
    waits are short: CloudPCR lost 32 s to a 30 s first wait on 2026-11-14); `failed()` logs and reports once. Only after the check passes does it run the app's seeder **inside this job** (`AppTrialJobs::seedNow()`:
    `dispatch_sync(RunTrialSeeder)` then report `seeded`, or report `failed` and rethrow; without the job class
    it calls `App\Services\TrialDataSeeder`), so there is no second queue hop (2026-11-13; was ~1 s plus
    queue wait). The app job's own `$tries` no longer apply on this path: a seeder failure fails this job
    (`$this->fail()`) instead of retrying the mirror + seed on a half-seeded trial. `$timeout = 300` so a
    slow seeder outlives a worker's default 60 s. It fails closed: the seeder never runs
    on lists SSO has not delivered. On a sync queue there is no retry; the throw makes the webhook answer
    500 and SSO records the delivery as failed.
  - **Seed status for SSO's setup bar (2026-11-13).** A trial admin's first dashboard load comes while the
    apps are still seeding, so SSO's welcome modal shows a setup bar and stays open until every app has
    reported. The webhook answer carries `seed_status`: `pending` when a seeder was queued (both the
    `master_data: syncing` path and a direct `RunTrialSeeder` dispatch), `seeded` when the seeder ran in the
    request, `skipped` when the app has none. A queued seeder is dispatched as a chain
    (`AppTrialJobs::seed()`): `RunTrialSeeder` then `Trial\Jobs\ReportTrialSeedStatus`, with `Trial\Jobs\ReportTrialSeedFailure` as the
    `catch` (an invokable object, never a closure: a serialized closure carries the app key's signature, and Account
    Manager's worker could not read one on 2026-11-14, so neither the seed nor the failure report ran), so SSO hears the outcome when the job really finishes;
    `SeedTrialAfterMasterDataSync::failed()` reports `failed` too. The report is
    `Trial\TrialSeedStatusReporter::report($ssoCompanyId, seeded|failed|skipped)` ->
    `POST {SSO_BASE_URL}/api/internal/companies/{id}/trial-seed-status {app_slug, status}` (bearer
    `CORE_APP_API_KEY`, 10 s, fail-silent: a refusal or an unreachable SSO is a warning in the log). SSO counts
    a step as finished on its own after ten minutes, so a lost report only delays the bar. An app on an
    older package answers no `seed_status`, and SSO counts its delivery as done. `RunTrialSeeder` must use
    the `Queueable` trait (every app's does) or the job is dispatched unchained and only the timeout
    finishes its step.
  - **`master_data.context` and `Trial\TrialContext`.** SSO sends where the trial is and what it seeded:
    `{state ('NY'), state_fips ('36'), city, county_fips ('36001'), zip, timezone (IANA), agency_name, phone,
    owner_user_id, fake_user_ids: [int], divisions: [name], stations: [{number, name, street, city, state,
    zip}], vehicles: [{unit_number, call_sign, type (ALS|BLS|Engine|Command|Rescue)}], units: [name],
    facilities: [{name, type}], people: [{user_id, first_name, last_name, licence, level}]}` (user ids are
    SSO user ids, not the UUIDs the seeder contract receives; fixture `tests/Fixtures/trial_seed_context.php`).
    `TrialContext::fromPayload($masterData): ?self` builds an immutable value object (null when the block is
    absent; missing keys read as null / `[]`, unknown keys ignored, entries without their name dropped, FIPS
    and ZIP keep leading zeros). Accessors `state()`, `stateFips()`, `city()`, `countyFips()`, `zip()`,
    `timezone()`, `agencyName()`, `phone()`, `ownerUserId()`, `fakeUserIds()`, `divisions()`, `stations()`,
    `vehicles()`, `units()`, `facilities()`, `people()`, plus `vehicleNames()`, `stationNames()`,
    `station(int $number): ?array` and `address(int $stationNumber = 1)` (`{street, city, state, zip,
    county_fips}`, blanks filled from the trial's own city / state / zip).
  - **A seeder opts in with `Contracts\ReceivesTrialContext`** (`withTrialContext(?TrialContext $context):
    void`); `TrialDataSeederContract::seed($company, $userSsoIds, $adminSsoId)` is unchanged and seeders that
    do not implement it run exactly as before. The context travels on every path (the webhook without
    mirrors, and `SeedTrialAfterMasterDataSync`, which carries the raw `master_data` and builds the context
    when it runs) into `AppTrialJobs::seed()`. Without `App\Jobs\RunTrialSeeder` it calls
    `withTrialContext()` (null when SSO sent none), then `seed()`. With it, it dispatches
    `RunTrialSeeder::dispatch($company, $userSsoIds, $adminSsoId, $context)`: a job whose constructor takes
    three arguments silently drops the fourth (PHP ignores extra arguments), so nothing changes until the app
    opts in. `TrialContext` holds only strings, ints and arrays, so it serializes with the job. **The opt-in in
    the app's job** (plus `use Unified\SsoClient\Trial\TrialContext;` and
    `use Unified\SsoClient\Contracts\ReceivesTrialContext;`):

    ```php
    // constructor: add a fourth parameter
    public ?TrialContext $context = null,

    // handle(), before $seeder->seed(...)
    if ($seeder instanceof ReceivesTrialContext) {
        $seeder->withTrialContext($this->context);
    }
    ```
  - **The rule for seeders: a seeder that needs a place or a name reads `TrialContext`, never a literal.**
    No hard-coded Columbus / Springfield, no local "Medic 1/2/3" list: cities, stations, vehicle and unit
    names, people come from the context. With an entity's mirror flag on, the seeder adopts the mirrored
    rows and never creates that list; with it off it creates its local rows with the context's names and
    place, so a trial looks the same in every app either way. A null context (older SSO) falls back to the
    app's own defaults.
  - `trial.purge_data` adds `master_data: {seeded: {divisions: [sso ids], locations: [...], vehicles: [...],
    qualifications: [...]}}` = the sample rows SSO actually hard-deleted (seeded and never edited). After
    `TrialPurgeVerifier` passes, a non-empty `seeded` queues `Trial\Jobs\PurgeTrialThenCleanMirrors` (ack
    `master_data: "cleaning"`; 3 tries, backoff 10/60 s): it runs the app's purger **in-process**
    (`dispatch_sync` of `App\Jobs\RunTrialPurger`, or `TrialDataPurger::purge()`), so extension rows are gone
    first, then `TrialMirrorCleaner` removes the mirrored rows. A purger that throws stops the job before the
    cleaner. The cleaner touches only the sample lists, in the order rotations, units, devices, contacts,
    vehicles, locations, divisions, qualifications (`TrialMirrorCleaner::ENTITIES`: what points at a row goes
    before the row); only entities this app mirrors (flag on, installed; others report `not_mirrored`); only
    rows linked to the listed SSO ids **in the one company the payload named**. Facilities, medications,
    procedures, the agency information and state configurations are never deleted (design D1), even if listed
    (they are left out of the report). **Personnel is not cleaned either**: the fake users' mirrored
    personnel rows are turned off (never deleted) when SSO sends `person.deactivated` or a complete personnel
    resync no longer lists them, because charts, eCrew and shift members hold those row ids. `user.deleted`
    deletes the local user; what happens to a personnel row then is the app's own foreign key. So after a
    purge the fake users' personnel rows remain, inactive, unless the app's purger removes them with the
    charts that name them. Vehicle crew-level / yearly-usage child rows go
    with their vehicle. Each row goes through `Trial\Contracts\TrialMirrorCleanupPolicy::decide()`
    (`Delete` / `Deactivate` / `Keep`; `beforeDelete()` runs inside the delete's transaction to remove
    app-owned child rows such as `dem_location_phones`). The bound `DefaultTrialMirrorCleanupPolicy` deletes,
    except a division a person (`company_user.division_id`), location or vehicle of the company still uses,
    a location a vehicle still calls home, or a vehicle a device of the company is still on, which is deactivated (Crew's locations cascade-delete with their
    division). Any delete the database refuses (foreign key) is deactivated instead. Apps bind their own policy
    (usually extending the default) in `AppServiceProvider::register()` for references the database cannot
    see, e.g. CloudPCR deactivating a `dem_vehicles` row a chart still names. App purgers must delete
    extension rows only, never `sso_*`-linked list rows.
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
  **`table` settings** (`SettingType::Table`) are a list of rows the agency manages from SSO (CloudPCR's
  Non-Agency Crew): `->table('non_agency_crew', 'Non-Agency Crew')->columns([['key' => 'last_name',
  'label' => 'Last name', 'type' => 'text'|'select'|'toggle', 'required' => bool, 'max' => int (text, default
  255), 'options' => [{value, label}] | 'options_from_values' => true], ...])` (`Settings\TableColumn`; keys
  are `[A-Za-z0-9_]`, `id` is reserved). Schema `toArray()` adds `columns: [{key, label, type, required,
  options, options_from_values, max}]` and `description` (the AI assistant's text: "A list of rows. Each row
  has an id and these columns: ...") to the usual fields; other types are unchanged. The default is
  `{rows: [], options: []}`. `values()` returns `{rows: [{id, <column>...}], options: {<column>: [{value,
  label}]}}` (`options` only for `options_from_values` columns: that company's choices) and GET passes it
  through. PATCH `{<key>: {rows: [{id?, <column>...}]}}` sends only the rows that change: the package reads
  `values()` once, validates each row (a row without `id` is a create and needs every `required` column; a
  row with `id` is an update and only the columns it sends are checked; selects must be in the static or
  that company's options; toggles boolean; text max), and `apply()` gets `{rows: [...]}` with `id` as int,
  only declared columns, `""` as null and toggles as bool. One bad row makes the whole key `invalid`
  ("Row 2: The selected Level is invalid.") and none of it is applied; other keys still apply. The package
  never deletes rows: turning one off is the app's `is_active` toggle column, if it declares one.
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
- **Unified Assistant** — the one platform-wide assistant: it *does tasks* for agency admins, answers
  how-do-I questions for everyone from the wiki, and hands a conversation to our team (**Talk to our
  team**). Intercom was removed from every app on 2026-11-19 (James: "rely solely on this new assistant
  for both chat and assistant"); nothing in the widget references it. The model runs in SSO; this package gives every app three
  things (`routes/assistant.php`, `src/Assistant/*`, config `sso.assistant`):
  1. **The drawer.** `<x-unified-assistant />` renders
     `<script src="/assistant/widget.js?v=…" defer data-unified-assistant='{…proxy paths, csrf}'>` when
     `sso.assistant.enabled` and a user is signed in; nothing otherwise. Inertia/React apps put it in the
     root `app.blade.php`; Blade apps in the main layout; Filament panels via a `panels::body.end` render
     hook. Optional `setup-app="crew-scheduling"`
     prop makes every turn carry `setup_app`. The bundle is `dist/assistant.js` (vanilla TypeScript,
     shadow DOM, one runtime dependency (pusher-js, bundled, for our team's live replies), committed; rebuild with `npm ci && npm run build`, `npm run typecheck`
     first). **SSO is the bundle's origin.** An app's `GET /assistant/widget.js`
     (`AssistantWidgetController`) fetches `{SSO_BASE_URL}/assistant/widget.js`, keeps body + ETag in the
     app cache for `sso.assistant.bundle_cache_seconds` (3600) and serves it with
     `Cache-Control: public, max-age=3600` + ETag/304; when SSO is unreachable or answers non-200 it serves
     the committed `dist/assistant.js` instead (cached 60 s so a down SSO is not asked on every load). SSO
     sets `'bundle_source' => 'local'` (and `proxy => false` implies it) and serves its own dist. So
     **widget fixes ship by deploying SSO; apps pick them up within an hour; the committed dist is only the
     fallback and SSO's copy.** The tag's URL carries `?v=<12-char hash>` from `AssistantBundle::version()`
     (md5 of the committed dist in local mode; the cached SSO bundle's ETag in SSO mode, else the dist hash
     until the first widget request warms the cache), so a changed bundle is a new URL and browsers refetch. The widget boots from
     `GET /assistant/bootstrap`: a **204** (no `sso_id`, no selected company) or `{enabled: false}` (SSO
     says this person is not a member of the agency, or the Assistant is off) leaves the page untouched. `window.UnifiedAssistant = {open, close, ask(text),
     setSetupApp(slug)}` for an "Ask the assistant" button.
     **Launcher layout + page padding (2026-11-19):** the closed-state launcher is a round 44px icon-only
     button (sparkles, `aria-label` and `title` "Assistant", hover tooltip with the word; neutral gray-900,
     brand accent on hover) fixed in the bottom-right corner at right 20px / bottom 20px; under 640px it is
     40px at right 16px / bottom 16px. (Until 2026-11-19 it sat at bottom 92px / 80px, stacked above the
     Intercom bubble.) While the launcher is showing (bootstrap enabled, drawer closed) the widget sets
     `html[data-unified-assistant="docked"]` and injects one `<style id="unified-assistant-dock">` giving
     **the element that scrolls the page** 90px of extra bottom padding (70px under 640px) so a page's own
     bottom-right buttons can scroll above the launcher; the attribute is removed while the drawer is open
     (it hides the launcher) and never set when the widget does not boot. `BarAvoidance` marks that element with
     `data-unified-assistant-scroller` on every measure: the body when html and body scroll, otherwise the
     outermost `overflow-y: auto|scroll` ancestor under the launcher, then a scrolling `<main>`, then
     the body. The rule is `calc(var(--ua-scroller-padding) + 90px) !important`, the variable holding the
     element's own computed padding (app shells set safe-area insets inline), and a swapped `<main>`
     (wire:navigate, Inertia) is re-marked on the next measure. It was body padding until 2026-11-01: every
     Unified app shell is a fixed-height `overflow-hidden` body with its own scrolling `<main>`, so the
     padding shrank the shell instead and cut every list page off above the fold (CloudPCR, James).
     **Pinned-bar avoidance** (`avoid.ts`, `BarAvoidance`): the page padding only helps content that
     scrolls, so a bar pinned to the bottom of the viewport (fixed or sticky form action bars, Filament /
     Livewire footers, modal footers) would still sit under the launcher. While the launcher is showing
     the widget measures at start, on `resize` (150 ms), after scrolling (150 ms, capture, so inner
     scrollers count) and on body mutations (`MutationObserver`, childList + class/style/hidden, 250 ms,
     ignoring our own host). `measure()` probes `document.elementsFromPoint()` in a strip under the
     launcher's corner (x = right edge minus inset, inset + size/2, inset + size, i.e. 20 / 42 / 64, or
     16 / 36 / 56 under 640px; y every 40px from the bottom up to 160px) and, for every element that is
     not ours and not html/body, walks up to the nearest `position: fixed|sticky` ancestor and takes the
     outermost element on that path whose box reaches the bottom 200px and is no taller than 40% of the
     viewport; the smallest `rect.top` among them is the bar top. Found: the launcher lifts so its bottom
     edge sits 12px above the bar (`--ua-lift` on the host element = viewport height - bar top + 12 - the
     launcher's inset; `bottom: calc(20px + var(--ua-lift))`, 16px under 640px; the first-run callout
     inherits the same variable), and the html attribute becomes `data-unified-assistant="lifted"`, which
     carries no page padding (the bar already keeps the launcher off the content). Not found: lift 0 and
     the attribute back to `docked`. The drawer deactivates measuring; closing re-measures. A lift that
     would exceed 40% of the viewport height (a full-screen scrim, a right-hand slide-over) is a layer, not
     a bar: the default stays and nothing is logged. Verified headless at 1280 and 390 (2026-11-19):
     launcher at 20/20 (16/16 at 390), dock padding 90px / 70px, and against a 64px fixed bar the
     launcher's bottom edge sits 12px above it.
     **Handoff:** when the Assistant cannot finish, its reply carries `handoff: {question}`. With our team
     behind the Assistant (`support.enabled`) the card offers **Talk to our team** (below). Without it the
     card reads "I can't do that one, but our team can help.", quotes the question, and offers a **Copy**
     button (copies the question) and an "Email support@unified-solutions.io" mailto link with the question
     as the body. There is no Help / Ask Help path any more.
     **Threads + suggestions (2026-10-30):** bootstrap answers `{enabled, conversation: {id, title,
     started_at, messages}, conversations: [{id, title, last_activity_at, message_count}], suggestions:
     [{label, prompt}], review, notice}`; `GET /assistant/bootstrap?conversation=ID` reopens a previous
     thread and `DELETE /assistant/conversation` ends the current one and answers the same shape with a
     new empty thread. Every turn carries `conversation_id` (the open thread). A header button (clock,
     `aria-label` "Conversations") opens an in-drawer panel under the header: **New conversation** on top
     (the DELETE call, no confirm), then the previous threads (title, "Today" / "Yesterday" / "Oct 28",
     message count; the current thread is left out) or "No previous conversations yet." Picking one loads
     it and shows a thin "Reopened: <title>" bar under the header until the next new thread. On an empty
     thread the `suggestions` render as wrap-around pill buttons under the intro bubble, titled "Things I
     can do"; a pill sends its `prompt` as the user's message. **Ideas** in the footer row (hidden when
     SSO sends no suggestions, reads "Hide ideas" while on) toggles the pills at
     any time; after a reply they stay hidden unless toggled. Verified headless at 1280 and 390 against a
     mocked proxy.
     **First-run callout (2026-10-30):** bootstrap may carry `intro: {show, title, body, cta, badge}`. With
     `show === true` and the drawer closed, the launcher gets a brand badge ("1", or `badge`) at once and,
     1.5 s later, a 300px speech-bubble callout (`.callout`, arrow at the launcher, title + 13px body +
     primary **Try it** + an X with `aria-label` "Dismiss") slides in above the launcher column (fade/slide
     gated by `prefers-reduced-motion`; under 640px it spans the width minus 16px margins each side). It
     follows the pinned-bar lift because `--ua-lift` is now set on the HOST (`avoid.ts`), not the launcher,
     so launcher and callout inherit it together. The button reads SSO's `intro.cta` (SSO sends "Click
     here"; "Try it" is only the fallback when none is sent). **The callout comes back on every login until
     the admin actually does something** (James, 2026-10-30): the widget never calls `intro/dismiss`. SSO
     keeps `intro.show` true until it records the admin's first real turn, and the widget only hides per
     tab: the X, or the first message sent in this tab, runs `dismissIntro()` (callout + badge go,
     `sessionStorage['unified-assistant:<viewer>:intro-dismissed']` keeps them away for the tab). **Every
     sessionStorage key (`intro-dismissed`, `open`, `tour:<conversation id>`, `draft:<conversation id>`) is scoped to the bootstrap's
     `viewer`** (SSO's opaque hash of the user id + their latest login, 2026-10-30): a new login or another
     user in the same tab starts clean, boot prunes keys left by other viewers, and an SSO that sends no
     `viewer` falls back to the unscoped names. Opening the drawer
     hides the callout (the drawer covers that corner) and closing it before any message brings the callout
     back at once; the badge stays on the launcher until the first message. `intro.show === false` shows
     nothing, and only the boot bootstrap is read for it (a reopened thread or a new conversation never
     re-shows it). `window.UnifiedAssistant.dismissIntro()` is the session-hide helper. The proxy route
     `POST /assistant/intro/dismiss` and the component's `intro_dismiss` setting still exist (nothing in
     the widget sends it). Verified headless at 1280 and 390 against a mocked proxy.
     **Walkthrough (2026-10-30):** with `intro.show`, bootstrap may also carry `tour: {steps: [{text,
     suggestions: [{label, prompt}]}], closing} | null` (SSO curates 3 steps, 2 pills each, per app). Only
     the callout button starts it (never the launcher, never on its own; `tour: null` means the button just
     opens the drawer as before). Each step is a local assistant-style bubble (`.turn.assistant.tour`, same
     bubble, secondary text, a small sparkles "Walkthrough" label above, never persisted) with the step's
     pills under it (`.tour-pills`); the regular "Things I can do" pills and the Ideas link are hidden while
     the tour is active. A tour pill, a typed message, or anything else that reaches `send()` is a normal
     turn. **Pacing (2026-10-30):** a pill click removes the step's pills at once (one click per step) and
     never advances by itself; `advanceTour(turn)` runs after the reply renders and moves on only when the
     exchange is finished: a `question` step (`steps[].kind`, SSO sends `question` for steps 1 and 3,
     `action` for step 2; missing = question) needs `turn_state === "complete"` (SSO's verdict on the
     turn: `awaiting_user` when the reply asks something, a tool stopped to confirm, or a review list is
     open; missing = complete) and no open review; an `action` step needs a reply with at least one action
     chip or an approved review (approving a card counts), whatever `turn_state` says. Otherwise the step
     waits, the admin's typed answers flow as normal turns, and the next reply is checked again. The next
     step is appended 600 ms after the reply (`TOUR_ADVANCE_DELAY_MS`); after the last step the `closing`
     bubble is appended and the tour ends (Ideas works again). Progress lives in `sessionStorage['unified-assistant:<viewer>:tour:<conversation
     id>']` as `{step, tour}` (the steps too, because the bootstrap stops carrying `tour` once
     `intro.show` is false) so a page change inside the app resumes at the same step; it is cleared when the
     tour ends and by **New conversation** (`clearStoredTour()` before the new thread is applied). Verified
     headless at 1280 and 390 against a mocked proxy: step 1 + 2 pills + no regular pills, pill and typed
     message each advance, reload resumes at the same step, closing bubble then Ideas reveals the pills, New
     conversation clears it, `tour: null` and the launcher never start it.
     **Host-page event:** after a turn whose response carries actions, and after a successful undo, the
     widget dispatches `window.dispatchEvent(new CustomEvent('unified-assistant:action', {detail:
     {actions, undone?: id}}))` (`undone` is the action id that was just reversed) so pages refetch what
     changed (Crew's Scheduling page listens for it). An action whose `summary` is empty is not an
     action: no chip, not announced.
     **"Did I handle that correctly?" (2026-11-08, James: a simple survey for a while, not forever):** when
     SSO's bootstrap carries `feedback: {ask: true}` (SSO's `ASSISTANT_FEEDBACK_ENABLED`, default on) and the
     component sends the `feedback` / `feedback_follow_up` settings, every completed task (a `complete` turn
     whose reply made a change, i.e. real action chips or an applied review; approving a review card counts)
     is followed by a small `.card.feedback-card` with Yes / No. Yes → `POST /assistant/feedback {conversation_id,
     message_id?, helpful: true}`, "Thanks for letting me know." and the card goes after 4 s. No → the same
     POST with `helpful: false` (SSO snapshots the conversation and queues the review email to the staff
     address), then "Sorry about that. Our team will look at this conversation." and one more question,
     "Would you like someone from our team to follow up with you?" with Yes, please / No thanks →
     `POST /assistant/feedback/{id}/follow-up {follow_up}` → "Thanks. We'll be in touch." / "Thanks for
     letting us know.". One card at a time: the next message or the next task replaces an unanswered one;
     a reopened thread or new conversation shows none; any failure removes the card silently, the question
     must never block the work. An older SSO (no `feedback` in bootstrap) or an older component (no
     settings) never shows it. SSO keeps the score and the review list at `/admin/assistant-feedback`.
     **Rating at the end of a conversation (2026-11-17, Jay: "thumbs up / down, and on a thumbs down offer a
     call"; final shape from Jay and James: 1 to 5 faces; contract `RATING-CALLBACK-CONTRACT.md`).** One rating
     per conversation, given at its end, for threads the Assistant handled and threads our team handled; a later
     rating overwrites. Bootstrap (and clear, escalate, callback, updates) carry `rating: {enabled, score, note,
     rated_at, phone}` (`phone` = the person's number on file, formatted, to prefill the call form). `rating.ts`
     renders the closing card (`.card.rating-card`, a light inline card at the bottom of the thread, never a
     modal): "Thanks for talking with us. If you can, rate this conversation and leave a note." (the sentence
     named the scale, "from 1 to 5", until 2026-11-21; James: the numbers belong under the faces), five emoji
     faces as a radio group, each with its number 1 to 5 under it (`.face-number`, `aria-hidden`; arrow keys move
     and pick, `aria-label` "1 of 5, very poor" … "5 of 5, great"),
     an optional note, **Send** and **Skip**. 4 or 5 → "Thanks, glad it helped." for 3 s, then "You rated this n
     of 5."; 3 or less → "Sorry about that. Want someone from our team to call you?" **Yes, call me** / **No
     thanks** (only with `support.enabled` and the `callback` route; otherwise "Thanks for telling us. Our team
     will read this conversation."). The call form: Phone number (prefilled, checked as a US number in the widget
     and again by SSO, whose 422 field error is shown under the field), Best time chips Now / This morning / This
     afternoon / Tomorrow morning, **Request a call** / **Cancel**. `POST {callback} {conversation_id, phone,
     best_time, note}` answers the bootstrap shape (now `needs_teammate`, with "Call requested: (814) 555-0100,
     this afternoon") plus `callback.message`, shown as a local line "Our team will call you at … Replies also
     show up here.". **When:** eligible = route + `rating.enabled` + unrated + an assistant or staff reply with a
     numeric id + not `needs_teammate` + not skipped for that thread in this tab (`rating-skipped:<id>`). Shown
     when the status turns `resolved` (after "Our team marked this resolved."), on a bootstrap of a resolved
     thread, on reopening an earlier thread, 2 minutes after a reply with no typing (Assistant-only; while the
     drawer is closed it waits for the next open), when the drawer is closed, and when New conversation is
     pressed (the card shows first; Skip, No thanks or a 4-5 then starts the new thread, a second press goes
     straight on). A new message drops an unanswered card; a handover drops it. Routes: proxy `POST
     /assistant/rating {conversation_id, score 1..5, note?}` and `POST /assistant/callback {conversation_id, phone,
     best_time, note?}` (component settings `rating`, `callback`); an older component sends neither and never
     shows the card. Tests: `resources/assistant/tests/widget-rating.test.ts`,
     `widget-rating-older-component.test.ts`, `AssistantProxyTest`.
     **Review rows that need a choice** (2026-11-14): a row with `choice: {candidates: [{code, label, detail}]}`
     (a medication name that matched several codes, or none) renders the candidates as a radio group
     (`choice.ts`; arrow keys move focus, Enter / Space / click picks, because each pick is sent at once) plus
     "Leave it out". A pick posts `review/{plan}/choose` `{plan_hash, index, code|null}` (null = remove) and
     re-renders from the answer like a removal; busy and errors stay on that row. Approve stays disabled with
     "{n} still need a choice" while any row has a `choice`. Widget unit tests: `npm test` (vitest + happy-dom,
     `resources/assistant/tests`).
     **A message that got no answer (2026-11-14, Jordan: "not reachable, unable to resend, refresh clears
     it"):** `retry.ts`. A failed `POST /assistant/messages` (network error, 5xx / 502 / 504 from the proxy,
     or a 200 that carries only `error`, such as "not available right now") keeps the admin's text back in
     the composer and shows an error bubble (`.turn.failed`, drawer only, never in the saved thread) with
     **Try again** (removes the unanswered bubble and re-sends the same text) and **Dismiss** (text stays in
     the composer to edit). The composer is saved per thread as `draft:<conversation id>` on every keystroke
     and restored when a thread is applied, so a refresh loses nothing; it is cleared only once that text
     got an answer. A **409** (SSO's `SetupSessionBusy`: another request is still on this thread) shows the
     message with "Checking again…" and re-reads the thread through `GET /assistant/bootstrap?conversation=ID`
     every 5 s for up to 60 s; when the thread's last message is an assistant message the drawer does not
     have, the thread is applied (notice gone, the admin's text still in the composer); after 60 s the bubble
     offers Try again / Dismiss. A turn in flight moves its waiting line from "Working on it" to "Still
     working… this one is taking a little longer" at 8 s and "Almost there" at 20 s (`activity[]` only
     arrives with the final answer today).
     **A file is read in the background (2026-11-16: SSO used to read it inside the upload request, and two
     uploads died at 31.8 s and 33.5 s under Octane's 30 s limit, answering 500 = "not reachable").** SSO now
     answers the upload at once with `upload: {id, status: 'reading'}`; `upload.ts` shows the file as a chip
     (`.chip.file.reading`, "Reading file…", composer locked as for a turn) and polls `GET {uploads}/{id}` (the
     status URL is derived from the `uploads` setting, so no component change) every 2 s for 30 s, then every
     5 s, giving up at 5 minutes with "That file took too long to read. Try a smaller file." A network blip or
     5xx is asked again; a 404 ends it ("That file is no longer here. Add it again."). `ready` removes the chip
     and sends the same "I added a file (file N): name" message as before; `failed` turns the chip red with
     SSO's `error` and sends nothing. An upload answer without `status: 'reading'` (an older SSO that read the
     file in the request) sends the message at once, as before. Tests: `resources/assistant/tests/upload.test.ts`,
     `widget-upload.test.ts`.
     **Our team behind the Assistant (2026-11-16, contract `SUPPORT-CONTRACT.md`; SSO side
     `feature/assistant-support-handoff`, CRM inbox in the `crm` repo).** Bootstrap and turn answers carry
     `support: {enabled, status: assistant | needs_teammate | resolved, last_message_id}` (absent from an older
     SSO). With `support.enabled` the handoff card reads "I can't finish this one, but our team can." with
     **Talk to our team** (`POST {escalate} {conversation_id, question}`; the answer is the bootstrap shape with
     the "Handed to our team" line, applied in place); with it off (or absent) the card offers Copy and the
     support email address (see **Handoff** above). While `status === 'needs_teammate'` the widget polls
     `GET {updates}?conversation=ID&after=<newest numeric message id it has>` every 5 s while the drawer is open
     and the page is showing, every 20 s otherwise (`visibilitychange` and open/close reschedule it), appends
     the staff / assistant / system messages it does not have (by id), badges the launcher for a staff reply
     while the drawer is closed, and stops once the status is `resolved` or `assistant`. A thread change
     (`applyBootstrap`: New conversation, reopening one, escalate) starts it over; a turn that hands the
     conversation over polls at once to fetch the line. Messages: `role: 'staff'` renders on the left with the
     author's initials, name and a small "Unified" tag in a brand-bordered bubble; `role: 'system'` is a
     centered muted line; `attachments [{id?, name, mime?, size?, url?}]` render as chips (name + size), a link
     opening in a new tab only for an `http(s)` `url`. **Files go with the next message:** with an SSO that
     sends `support`, an added file (read as before, or only carried while our team has the thread) becomes a
     chip above the composer (`.pending-files`, X to take it off) and the next message posts `attachments:
     [ids]` (a message with only files sends "I added a file: name"); an older SSO keeps the old "I added a
     file (file N): name" message. The component and SSO's own settings send `escalate` and `updates`; a
     component without them gets the paths derived from `messages`. Tests:
     `resources/assistant/tests/widget-support.test.ts`, `widget-support-off.test.ts`.
     **Talk to our team at any time (2026-11-16, James: "chat messages must be able to reach the team").**
     With `support.enabled` and the thread not already with our team, the Conversations panel carries a
     **Talk to our team** button under New conversation (`.team-entry .talk-to-team`) and the "Things I can do"
     pills always end with a **Talk to our team** pill (`.pill.team`, shown even when SSO sends no other
     suggestions, so Ideas shows too). Choosing either escalates nothing: it appends one local assistant bubble
     "Tell me what you need and I will pass it to our team." (never persisted) and puts the composer in team
     mode, a removable "To our team" chip above the input (`.team-mode`). The next message goes to
     `POST {escalate} {conversation_id, question: text, message: text, attachments: [ids]}` instead of a turn;
     SSO stores it as the administrator's message, hands over (`asked_for_person`), syncs once and answers the
     bootstrap shape with "Our team has your message. Replies show up here.", which is applied in place and
     polling starts as for any handover. A failed send keeps the text, the chip and Try again (which escalates
     again). While the thread is `needs_teammate` the button is replaced by the line "Our team has this
     conversation" and the pill is left out; a poll or turn that changes the status updates both. **Older
     components:** the widget is served by SSO to every app, but the proxy that forwards `message` is this
     package, so the component sends `escalate_message: true`; without it (an app not yet on this version) the
     widget escalates with `question` only and then sends the text as an ordinary turn, which SSO keeps for our
     team, so nothing typed is lost. Tests: `widget-team.test.ts`, `widget-team-older-component.test.ts`.
     **Every member of the agency (2026-11-16, James: "we want to replace Intercom").** SSO now answers
     `enabled: true` for any signed-in member of the company, not only administrators, and the bootstrap
     carries `reach: full | apps | none` (SSO's `AssistantReach`; absent from an older SSO = `full`). For
     `reach: none` (no admin role anywhere: crews, dispatchers, billers) SSO only answers how-do-I questions
     and passes everything else to our team, and the widget follows it: subtitle "Ask how something works, or
     talk to our team." ("Ask how something works in your apps." when `support.enabled` is
     off), an intro bubble that never says "tell me what to do", placeholder "Ask a question", and the file
     button (`.composer` paperclip) hidden until the thread is `needs_teammate` (files are then for our team;
     the same rule hides it for `reach: apps`, whose uploads SSO refuses). A suggestion with `kind: 'team'`
     (SSO sends "Talk to our team" with an empty prompt as the third member pill) renders as the team pill in
     its place and the widget does not append a second one; an older widget drops it for the empty prompt and
     appends its own, so either way there is one. The first-run callout text is SSO's (`intro.title` "Need a
     hand? Ask here." for members); the widget has no per-reach callout copy. Tests: `widget-reach.test.ts`.
     **Our team's replies live (2026-11-16, contract `REALTIME-CONTRACT.md`; SSO side `feature/assistant-realtime`,
     broadcasting in the `crm` repo).** `support.realtime` (bootstrap, turn, escalate, and the `support` block SSO
     now adds to the updates answer) is `{host, port, scheme, key, channel, auth_url, token, name}` or null. While
     the thread is `needs_teammate` and a block is present, `realtime.ts` (`SupportSocket`, pusher-js 8.6.0 pinned,
     bundled) connects to the CRM's Reverb cluster (`wsHost`/`wsPort`/`wssPort`, `forceTLS` for https,
     `enabledTransports: ['ws','wss']`, stats off) and subscribes to `private-{channel}`, authorized by
     `POST auth_url` with `X-Support-Token` (sent through `channelAuthorization.headersProvider`, so the fresher
     token from each answer is used on any later re-subscribe; tokens last an hour). `message.created`: staff and
     system lines render (customer and assistant lines are already on screen); the id is `sso_message_id` when the
     CRM has it, else `crm-{id}`, the message keeps `crm_message_id` and is marked `live`, which keeps it out of
     the poll's `after`; a poll that brings the same line (same id, or a staff message with the same
     `crm_message_id`, which SSO now includes) takes SSO's id instead of adding a second bubble.
     `conversation.updated`: a new `assignee_name` adds the local line "{name} from our team has your
     conversation", a `support_status` change applies like a polled one (resolved also adds "Our team marked this
     resolved." and leaves the channel). `client-typing` from staff shows "{name} is typing" below the last message
     (6 s, or until their message lands); typing in the composer sends `client-typing {who: customer, name}` at
     most every 3 s while connected. Polling never stops: connected and subscribed it runs every 60 s (one poll at
     once on each (re)connect to catch anything missed), and on a drop or a refused subscription it is back to
     5 s / 20 s. A refused channel is not retried until SSO names another one. A new conversation, a resolved one,
     or one back with the Assistant disconnects. No socket for threads the Assistant has (keeps the cluster's
     connection count to people actually waiting on our team). Bundle 78.6 KB -> 145.4 KB minified (22 KB -> 42 KB
     gzip). Tests: `resources/assistant/tests/widget-realtime.test.ts` (fake pusher via `vi.mock`).
     The realtime cluster must list every app host in its Reverb `allowed_origins`: the drawer connects from
     each app's own host, and an empty list admits only the CRM's (`pusher:error 4009 Origin not allowed`, so
     the drawer quietly polls and typing never shows; that was the CRM production cluster until 2026-11-17).
     **Message sound (2026-11-17).** `sound.ts` (`MessageSound`) plays `resources/assistant/sounds/message.wav`
     (original synthesized E5 then A5 two-tone, generator `scripts/make-message-sound.py`; the same bytes ship in
     the CRM and Unified-Mobile, so regenerate all three or none), bundled as a data URL
     (`--loader:.wav=dataurl`), when a staff message arrives that the widget did not already have: a new
     `message.created` staff line or a new staff message from `/assistant/updates`. Never for history, an
     opened thread, the person's own lines, Assistant replies or status lines. Silent (no Audio element) until
     the first `pointerdown`/`keydown`/`touchstart` on the page, at most once per 2 s (so the socket copy and
     the poll copy chime once), volume 0.6, a refused `play()` is swallowed. The **Sounds** switch
     (`.sound-switch`, `role="switch"`) sits in the Conversations panel header; localStorage
     `unified-assistant.sounds` = `off` mutes (default on). Tests: `sound.test.ts`, `widget-sound.test.ts`
     (they replace `HTMLMediaElement.prototype.play` with a plain function, not a spy: a spy handles the
     returned promise and would hide an unhandled rejection).
     **Links (2026-11-17, James saw "Open in Analytics: https://unifiedreporting.unified-apps.com/dashboard?analyst=95"
     as plain text).** An assistant message (bootstrap / updates `messages[]`) and a turn answer may carry
     `links: [{label, url}]` (SSO fills it from a tool result's `open_url` / `open_label` and keeps the address out
     of the reply; absent from an older SSO). They render as `.links` > `a.link-chip` under the message (external
     icon + label, `target="_blank"`, `rel="noopener"`, http(s) only, anything else dropped), and a turn that is
     only links still renders. In the Assistant's own text (`paragraphs(text, {links: true})`, assistant role
     only; the person's messages and our team's stay plain) `[label](https://...)` becomes a link with the label
     and a bare `http(s)://` address becomes a link whose text is the host plus path, no scheme, no query, a path
     over 32 characters cut with an ellipsis ("unifiedreporting.unified-apps.com/dashboard"), with the sentence's
     closing punctuation and an unmatched `)` left outside; never the raw address spelled out. Anchors are built
     as elements with `textContent`, never `innerHTML`. Tests: `links.test.ts`, `widget-links.test.ts`.
     **Read receipts for our team (2026-11-17, James: "can we show whether or not a message was seen by the
     user"; SSO, CRM and Unified Mobile on `feature/seen-receipts`).** `seen.ts` (`SeenTracker`) watches every
     staff turn with an `IntersectionObserver` rooted on the message list (a reply counts once half of it, or
     half the list's height, is showing). When one is on screen while the drawer is open, the page is showing
     and the Conversations menu is not over the thread, it posts `POST {seen} {conversation_id, last_message_id}`
     with the newest such reply's id, 1 s after the last one came into view (`SEEN_DEBOUNCE_MS`), and only when
     that id is newer than anything already reported (SSO's `support.seen.message_id` seeds it on every thread
     change). Ids are read from `data-id` when the check runs, so a reply heard live (`crm-{id}`) counts once a
     poll gives it SSO's id. A failed post lets the same reply be reported again. Opening the drawer, the page
     becoming visible and closing the menu re-check. No observer (old browsers): a rendered reply counts as
     on screen. The person never sees a receipt; our team sees "Seen" / "Delivered" in the CRM. Proxy
     `POST /assistant/seen` (throttle 60/min, forwards `conversation_id` + `last_message_id` with the stamped
     identity; SSO ignores receipts while impersonating) and component setting `seen`; an older component sends
     neither and the widget sends no receipts. Tests: `seen.test.ts`, `widget-seen.test.ts`, `AssistantProxyTest`.
     **Badge for an unopened reply (2026-11-20; SSO #312 added `support.unseen_from_team`).** SSO's bootstrap,
     turn and updates `support` block carries `unseen_from_team: true` when a staff message is newer than the
     person's seen pointer (a conversation our team started from the CRM, or a reply they have not opened). With
     the drawer closed the launcher badges on load and on an updates poll that reports it; opening the drawer
     clears the badge for this page (`threadOpened`), and because SSO keeps reporting the flag until the receipt
     lands (forever, from an app whose component sends none), a later poll badges again only for a new staff
     reply (`badgeFor`). Tests: `widget-badge-unseen.test.ts`, `widget-badge-unseen-poll.test.ts`,
     `widget-badge-unseen-open.test.ts`.
     **Automated updates from our team (2026-11-20, UNI-1672, contract `HALLIGAN-CONTRACT.md`; CRM sends, SSO
     relays, Unified Mobile mirrors).** When a fix ships, the CRM posts one message into the person's
     conversation. On the wire it stays a teammate message (`role: 'staff'`, `author` = the sender's name, which
     the CRM chooses; the widget never hardcodes it) with two optional keys: `kind: 'automated'` and `confirm:
     {state: pending | fixed | not_fixed, answered_at}`. `renderStaffTurn` gives it `.turn.staff.automated`
     (teal tokens `--ua-auto-*` in both token blocks of `styles.ts`, never the teammate blue), the first letter of
     the name as the avatar, and the tag "AI agent" in place of "Unified" (`COPY.automatedTag`; "Automated
     update" until 2026-11-21, when James decided Halligan is named plainly as an AI agent: the one exception to
     the never-"AI" wording rule, and only for Halligan). Under the text
     (`renderConfirm`, state read from the message, so a reload shows the recorded answer): while `pending`,
     **Yes, it's fixed** (filled) and **Not yet** (outlined); after `fixed`, "You said: Yes, it's fixed. Thanks
     for letting us know."; after `not_fixed`, "You said: Not yet. A person from our team will follow up with you
     here."; any other state, or no `confirm`, nothing. A tap disables both buttons and posts the EXISTING
     feedback route, `POST {feedback} {conversation_id, message_id, helpful}` (`helpful: true` = fixed), so no
     app needs a package bump; SSO answers `{ok, confirm, status, rating}` (no `id`), the widget stores
     `confirm` on the message, takes `rating` and `status`, and runs `supportChanged()`: `resolved` brings the
     existing rating card, `needs_teammate` puts the thread back with our team (no rating). Any failure
     re-enables the buttons under "That did not go through. Try again.". A component without the `feedback`
     setting shows "Reply here to tell us if it is working." and no buttons. An update heard on the socket
     before SSO has given it an id (`crm-{id}`) is polled once before the answer is sent. **Compatibility
     rules:** `kind` missing, null or unknown = an ordinary teammate message ("Unified" tag, no question); the
     message is never dropped. `liveToMessage` copies `kind` and `confirm`; `confirmLive` now merges both from
     the polled row into a message first heard live (it used to swap the id only, so anything the socket copy
     lacked was lost); the socket's `message.updated` `{message: {id (CRM's), confirm}}` updates the matching
     turn by `crm_message_id` (an answer given on another device or from the link in the email), and one
     without `confirm` (a delivery status) is ignored. An answer is final: a recorded one never goes back to
     pending (`mergeConfirm`). **Rating:** while the newest message in the thread is an update still waiting
     for its answer, the rating card is held back (the rating comes after "Yes, it's fixed"); once the person
     writes anything after it the usual rules apply. The update never triggers "Did I handle that correctly?".
     Tests: `widget-automated.test.ts`, `widget-automated-older-component.test.ts`, `AssistantProxyTest`
     (the feedback proxy passes SSO's answer through unchanged).
     **Website visitors (2026-11-19, James: "get it installed on the public website; those users will not be
     logged in"; contract `VISITOR-CONTRACT.md`, SSO / Public_Website / CRM on `feature/visitor-assistant`).**
     `sso.assistant.visitor` (env `ASSISTANT_VISITOR_MODE`, default false; only Public_Website turns it on).
     With it on, `<x-unified-assistant />` also renders for a guest and adds `"visitor": true` to the settings,
     and the proxy stamps a guest as `{visitor_id, app_slug, impersonating: false}` (no `sso_user_id` /
     `sso_company_id`). `visitor_id` is a UUID v4 from the cookie `unified_visitor` (`AssistantVisitor::COOKIE`),
     encrypted by the web group's EncryptCookies; missing or not a UUID → a new one is minted and queued for one
     year (HttpOnly, SameSite=Lax). Never read from the body. The proxy group's `auth` is swapped for
     `AuthenticateAssistant`: a signed-in user passes as before (same identity, same 204 without a selected
     company); a guest passes only in visitor mode, at most 60 requests a minute per IP (429 after); with
     visitor mode off a guest goes through Laravel's own `auth` exactly as before. Uploads work for visitors
     with the same 20 MB limit. The widget switches when the settings say `visitor` or SSO's bootstrap says
     `reach: 'visitor'`: title "Unified Assistant", subtitle "Ask about our products, pricing, or a test
     drive.", intro bubble "Hi. Ask me about our products, pricing, or a test drive, or talk to our team.",
     placeholder "Ask a question", file button shown, no review cards, no Undo on action chips (the chip and
     its summary still show), no `setup_app` on turns or uploads; Conversations, suggestions, Talk to our
     team, rating, realtime and SSO's `intro` callout work as for members. SSO should send its "Talk to
     someone on your team" pill as `kind: 'team'` so the widget does not add a second team pill. Tool calls:
     SSO sends `visitor_id` instead of `sso_user_id` / `sso_company_id`; see Visitor actors under item 3.
     Verified headless at 1280 and 390 against a mocked proxy. Tests: `AssistantVisitorTest`,
     `widget-visitor.test.ts`, `widget-visitor-reach.test.ts`.
  2. **The same-origin proxy** `/assistant/{bootstrap, messages, escalate, updates, seen, uploads, uploads/{id}, actions/{id}/undo,
     review/{plan}/{approve|remove|choose|set-aside}, intro/dismiss, feedback, feedback/{id}/follow-up, DELETE conversation}` under `sso.assistant.middleware`
     (`['web','auth']`, with `auth` swapped for `AuthenticateAssistant`, which lets website visitors through
     in visitor mode), registered only when `assistant.enabled && assistant.proxy`. It forwards to
     `SSO_BASE_URL/api/internal/assistant/{conversation, turn, escalate, updates, seen, uploads, uploads/{id}, actions/…, review/…, intro/dismiss, feedback, feedback/{id}/follow-up}` with
     `Http::withToken(config('app.core_api_key'))` and stamps `sso_user_id` (auth user's `sso_id`),
     `sso_company_id` (`session('selected_company_id')` → `companies.sso_company_id`), `app_slug`
     (`sso.app_slug`) and `impersonating` (`session()->has('impersonator_id')`). **Identity never comes
     from the request body**; anything the body says about those keys is dropped. Bootstrap forwards the
     optional `conversation` query parameter (integer, 422 otherwise) and a turn passes `conversation_id`
     through; both are thread selectors, not identity. A turn also passes `attachments` (up to 10 integer file
     ids); `POST escalate` forwards `conversation_id`, `question` (≤2000), `message` (≤20000) and
     `attachments` (up to 10 file ids), `GET updates` forwards
     `conversation` (required) and `after`, nothing else from the browser. An upload forwards `setup_app` and the page as the
     flat multipart fields `page[url]` / `page[title]` (nested arrays become `page[]` in Laravel's multipart
     encoder), and the widget sends them with every file, so SSO files the upload under the app whose setup
     page is open rather than the agency's first app (Val's 2026-11-01 roster test: files landed on the
     Analytics session while the turns ran for CloudPCR, so the model was told no file existed). The widget's
     follow-up message carries the file number from the upload reply ("I added a file (file 17): name.csv")
     so the model never guesses it. SSO's status and JSON
     pass straight back; an unreachable SSO is a 502 with a customer-facing line. SSO itself sets
     `'proxy' => false` and serves the same paths in-process.
  3. **This app's tools for SSO.** `GET /api/internal/assistant/tools?sso_user_id&sso_company_id&impersonating[&include_hidden=1]`
     → `{app, tools: [{name, kind, definition}]}` and `POST /api/internal/assistant/tools/{name}`
     `{sso_user_id, sso_company_id, impersonating, args}` → the tool's result, both behind
     `ValidateCoreApiKey`. Implement `Contracts\AssistantToolProvider::tools(AssistantActor): list<AssistantTool>`
     and bind it (`$this->app->bind(AssistantToolProvider::class, \App\Assistant\CrewAssistantTools::class)`
     or `'tool_provider' => FQCN` in config). Nothing bound → `{app, tools: []}` (normal answer: SSO's own
     tools, how-do-I answers and Talk to our team still work everywhere).
     - **Actor preparation (package, before any provider call):** `AssistantActorResolver` finds the local
       company by `companies.sso_company_id` and the user by `users.sso_id` (the Metrics model/column
       config in `config/metrics.php`), checks membership (`$user->companies()` if the model has it, else
       the `company_user` pivot), signs the user in on the default guard (`Auth::setUser`), calls
       `loadRolesForCompany($company->id)`, sets `session('selected_company_id')`, then calls the
       provider's `prepare(AssistantActor)` if it implements `Assistant\PreparesAssistantActor` (for apps
       whose login establishes the tenant differently). Company or user missing locally → empty catalog /
       404 on execute; user present but **not a member** → 404 + `SecurityEvents::warning('assistant.tool_denied')`.
       `HasCompanyScope` therefore works inside tools exactly as it does for the signed-in UI.
     - **Visitor actors (public website):** `GET tools?visitor_id=` / `POST tools/{name} {visitor_id, args}`
       (`visitor_id` a UUID, `required_without:sso_user_id`; the sso ids become `required_without:visitor_id`)
       build `AssistantActor::visitor($id)`: `isVisitor()` true, `visitorId` set, `user`, `company`,
       `ssoUserId`, `ssoCompanyId` null, `impersonating` false. Nobody is signed in, no
       `selected_company_id` is set and the provider's `prepare()` is not called. Only tools implementing
       `Assistant\VisitorAssistantTool` (marker interface extending `AssistantTool`) are listed for a visitor;
       calling any other tool as a visitor answers `failed('Sign in to do that.')` without running it, so
       app tools that read `$actor->user` are safe. With `sso.assistant.visitor` off in the app, a visitor
       gets an empty catalog and a 404 on execute. A provider's `tools()` receives the visitor actor, so a
       provider in visitor mode must not assume `$actor->user` there.
     - **`AssistantTool`:** `name()` (snake_case, unique in the app), `definition()` (`{description,
       parameters}` JSON Schema, same shape as ai-core `Tool::definition()`; description written for the
       model: what it does, needs, returns; fixed lists as `enum`), `kind()` (`AssistantTool::READ|WRITE`),
       `execute(array $args, AssistantActor $actor): AssistantToolResult`. Authorize with
       `$actor->user->can('…')` as the UI does; not allowed →
       `AssistantToolResult::failed('You do not have permission to do that here.')`.
     - **Results:** `AssistantToolResult::ok($content, $summary = null, $undo = null)` (`content` is what the
       model sees, no secrets, PHI only when the job needs it; `summary` is the one customer-facing line on
       the action chip, "Assigned John Smith to C shift (Paramedic), Sat Nov 1"; `undo` is
       `['tool' => …, 'args' => […]]`, a single tool call that reverses the write, or null).
       `::confirm($question, $content = [])` when the change is one the app's UI treats as needing extra
       care (bulk, reaches every user, deletions): the model asks, then re-calls with `confirmed: true`, so
       **every write tool's schema accepts an optional boolean `confirmed`**. `::failed($message)` with a
       plain message, never exception text. Write tools act immediately otherwise. A tool that throws is
       answered as `failed('That did not go through. Try again in a minute.')` and logged without `args`.
     - **Hidden tools** (the one mechanism): implement `Assistant\HiddenAssistantTool` (a marker interface
       extending `AssistantTool`). Hidden tools are left out of the catalog unless `?include_hidden=1` and
       are always executable, which is how SSO replays a stored `undo` call. Use them for reverse
       operations the model should not pick itself (`unassign_by_assignment_id`).
     - **Never log `args`** (may carry PHI). The wire format: ok → `{ok: true, content, summary, undo}`;
       confirm → `{ok: false, needs_confirmation: true, question, content}`; failed → `{ok: false, message}`;
       unknown tool / not a member → 404 `{ok: false, message}`; malformed body → 422.
  - **Wording (rendered UI, binding, DEV_GUIDELINES §2d):** the feature is **Assistant**; never "chat",
    "bot", "AI", "support" (the address support@unified-solutions.io is fine; the Halligan tag below is the one
    exception). There is no "Help" any more.
    Subtitle "Tell me what to do, or ask how something works." Handoff with our team behind the Assistant
    "I can't finish this one, but our team can." / **Talk to our team**; without it "I can't do that one,
    but our team can help." / **Copy** + "Email support@unified-solutions.io"; a teammate's reply carries a "Unified" tag; an automated update carries the sender's name and an
    "AI agent" tag, never "Unified", with the buttons **Yes, it's fixed** / **Not yet** (Halligan is labelled
    "AI agent" by James's decision of 2026-11-21; the never-"AI" rule still stands for the Assistant itself). The standing entry: **Talk to our team** (menu item
    and last pill), "Tell me what you need and I will pass it to our team.", chip "To our team", and "Our team
    has this conversation". Chips "Undo" / "Undone". No em dashes, US English, no developer words, no tenant-isolation
    narration. Tool `summary` and `failed()` text are rendered UI too.
  - **Tests:** `tests/Feature/Assistant/*` with `tests/Fixtures/FakeAssistantToolProvider.php` (catalog +
    execute auth/membership/hidden/confirm/failed/exception, proxy stamping from the session never the
    body, 204 cases, pass-through, 502, multipart upload, widget headers, component hidden when disabled /
    signed out). An app's own test: bind its provider, seed a member, hit both routes with the core key.
  - **Install sweep per app:** `composer update unified/sso-client --prefer-dist`; add
    `<x-unified-assistant />` (and remove any `<x-intercom-widget />`); confirm `SSO_APP_SLUG` and
    `CORE_APP_API_KEY`; nothing else. Tools are optional and come later per app.
- **`Concerns\SyncsCompanyRoles`** — `loadRolesForCompany()`, `hasRoleInCompany()`, `companyRoleNames()`
  plus staff helpers `isStaff()`, `hasStaffRole()`, `isGlobalAdmin()` reading the package-managed
  `users.staff_roles` column. Apps must delete hand-rolled copies of these.
- **Metrics** — `Metrics` facade (aliased globally) for domain emits and `Metrics\Middleware\TrackSessionMetric`
  (`metrics.session`) for `session.start` heartbeats, appended to an app's `web` group. Context keys
  `local_company_id` / `local_user_id` are translated to SSO ids by `EloquentMetricContextResolver`
  via `companies.sso_company_id` / `users.sso_id`. `METRICS_APP_KEY` must be the SSO registry slug.
- **AI usage** — `AiUsage::record($feature, $purpose, $model, $inputTokens, $outputTokens, $cachedTokens,
  $audioSeconds, $durationMs, $outcome, $surface, $attribution)` (`Metrics\AiUsage`, facade aliased globally)
  is the one place the `ai.request` event is shaped: one call per model call, forwarded through
  `Metrics::increment()` so id translation and queueing stay there. Feature keys are sanitised to
  `[a-z0-9_-]{1,40}`; null/zero optionals are dropped (token counts always sent); surface defaults to
  `mobile` on `api/mobile/*`, `queue` in console without a routed request, else `web`. Fail-silent.
  Context must stay PHI-free: never prompt text, transcripts or file names. ai-core forwards here.
- **Security events** — `SecurityEvents` facade (aliased globally) records attack-signal events to SSO's
  `/api/internal/security-events/ingest` (CORE_APP_API_KEY auth, endpoint derived from `SSO_BASE_URL`,
  app key falls back to `METRICS_APP_KEY` → `SSO_APP_SLUG`, so already-wired apps need zero config).
  Auto-recorded with no per-app wiring: failed logins / lockouts / password resets (Laravel auth events),
  `internal_api.auth_failed` and, during a key rotation, `internal_api.previous_key_used`
  (`ValidateCoreApiKey`; see "Rotating CORE_APP_API_KEY"), and `webhook.signature_failed` (the package's HMAC
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
  **A sign-out instruction never outlives the sign-in that follows it (2026-11-14).** `user.logged_out`
  queues a `force_logout` row with `payload.issued_at` (SSO's `timestamp`) and `expires_at` 15 min; a
  successful callback deletes the user's pending `force_logout` rows and stamps the session with
  `SESSION_SIGNED_IN_AT`; the middleware drops expired rows first and applies a sign-out only when it was
  issued at or after the session's sign-in (no `issued_at`, or no stamp, still applies, as before). CloudPCR
  had 352 stale rows: every trial admin's first click after signing in (New PCR) was bounced to `/login`,
  which also lost the intended URL because `sso.server_url` is unset there, so a second OAuth trip landed
  on the dashboard 10-15 s later. The fallback now reads `sso.server_url` then `sso.base_url`, and with
  neither sends the person back through the SSO redirect route with `intended` set.
- **Legacy cookie scrub** — `Middleware\PurgeLegacyApexCookies` (`sso.purge-legacy-cookies`), also
  auto-appended to the `web` group. The legacy ASP.NET app set several cookies on `.unified-apps.com`,
  so browsers still send them to every app on the platform; its own logout only wrote host-only
  deletions that never matched the parent-domain copies. When a request carries a name from
  `config('sso.legacy_cookies.names')` the middleware expires it in both the host-only and
  parent-domain form. Clean requests are a no-op. Every listed name is one Unified.Base provably sets
  (evidence is in the config comment). The middleware hard-refuses `XSRF-TOKEN`, `config('session.cookie')`,
  anything ending `_session`, and anything starting `remember_web` regardless of the config list —
  scrubbing those would sign every user out on every request. Empty list disables it.
- **Roster reconcile** — there is no `sso:sync-users` command and no scheduled pull in this package
  (an earlier doc described one; it was never written, checked 2026-11-22). The heal runs in SSO:
  its nightly `webhooks:reconcile-rosters` (UNI-1735) asks every app
  `GET /api/internal/roster/{ssoCompanyId}` (`Http\RosterMembersController`, `ValidateCoreApiKey`)
  -> `{provisioned: bool, sso_user_ids: [int]}` = the `users.sso_id` of every `company_user` member of
  the local company with that `sso_company_id` (read with the query builder, no model scopes, so a
  soft-deleted local user still counts as present and is never re-created), compares it with SSO's
  members and re-sends `user.created` only for the ones missing, on SSO's backfill queue. Ids only, no
  names. A company the app does not have answers `provisioned: false` (200). An app on an older package
  answers 404 there, and SSO skips it. Apps need no code: `composer update unified/sso-client
  --prefer-dist` is all. Tests: `RosterMembersEndpointTest`.
- **Timezone propagation** — SSO owns `companies.timezone`; the package mirrors it onto the app's local
  `companies.timezone` in both the login sync and the `company.updated` webhook, guarded by
  `Schema::hasColumn()` so apps that haven't adopted the column ignore it. Apps must NOT ship their
  own timezone selector.
- **Migrations** — `sso_session_actions` table and `users.staff_roles` column, loaded from the package.
- **Master data mirrors** (`src/MasterData/`) — SSO is canonical for shared master data; apps hold
  a read-only mirror per entity, opted into with `config('sso.master_data.<entity>')` (env
  `SSO_MASTER_DATA_QUALIFICATIONS`, `SSO_MASTER_DATA_DIVISIONS`, `SSO_MASTER_DATA_LOCATIONS`, `SSO_MASTER_DATA_VEHICLES`,
  `SSO_MASTER_DATA_FACILITIES`, `SSO_MASTER_DATA_MEDICATIONS`, `SSO_MASTER_DATA_PROCEDURES`,
  `SSO_MASTER_DATA_PERSONNEL`, `SSO_MASTER_DATA_AGENCY`, `SSO_MASTER_DATA_CONTACTS`, `SSO_MASTER_DATA_DEVICES`,
  `SSO_MASTER_DATA_STATE_CONFIGURATIONS`, `SSO_MASTER_DATA_UNITS`, `SSO_MASTER_DATA_ROTATIONS`,
  `SSO_MASTER_DATA_OTHER_AGENCIES`, all default false).
  `mergeConfigFrom()` is shallow, so an app whose own `config/sso.php` defines `master_data` replaces the
  package's whole array. That is safe: every reader defaults per key (`MasterDataRegistry::enabled()` reads
  `config("sso.master_data.{$entity}", false)`, `schedule_resync` defaults true, `timeout` 120), so an app may
  list only the entities it uses and the rest stay off (pinned by `PartialMasterDataConfigTest`). Any new
  `master_data` key must be read with an explicit default for the same reason. Pieces:
  `Contracts\EntityMirror` (one class per entity: table, SSO id column, publish tag, webhook apply,
  resync, link-by-name), `CatalogMirror` (the shared base: linking, name adoption, `sso_link_pending`,
  the `sso_updated_at` stale guard, deactivate-never-delete, link-by-name, the push mapping; each
  entity adds its columns and its assignment rules), `MasterDataRegistry`
  (known entities + opt-in check; mirrors resolve through the container so an app can bind a
  subclass), `MasterDataWebhookHandler` (the webhook controller's `match` sends `qualification.*`,
  `user.qualifications_changed`, `division.*`, `user.division_changed`, `location.*`, `vehicle.*`, `facility.*`, `medication.*`,
  `procedure.*`, `person.updated|deactivated`, `agency.*`, `contact.*`, `device.created|updated|deactivated|batch_updated`
  (not `device.revoked`, which is the sessions handler), `state_configuration.*`, `unit.*`, `rotation.*` and
  `other_agency.*` here; a disabled entity, an unmigrated mirror, or an unknown company is a 200 ack, except that an event a
  mirror lists in `Contracts\ContinuesPush::pushEvents()` (the code lists' `review_completed`) is applied while the entity is
  still off, because it finishes the push), `MasterDataClient` (`GET {SSO_BASE_URL}/api/internal/companies/{id}/{entity}`,
  bearer `CORE_APP_API_KEY`), `sso:resync-master-data {entity} {--company=} {--link-by-name}` and the
  one-time upward seed `sso:push-master-data {entity} {--company=} {--dry-run} {--review=} {--batch=}` (mirrors that
  implement `Contracts\SeedsSso`; `--review=ID --company=X` links a finished code-list review instead of pushing;
  `--batch=N` sends N rows per import request instead of the entity's `importBatchSize()`, 1 up to that cap, for an
  SSO import that runs close to its 30 s limit).
  **A 404 on a company's collection** (the snapshot GET or `/import`) means SSO no longer has that company
  (deleted/merged, stale local `sso_company_id`): `MasterDataClient` throws `Exceptions\CompanyNotInSsoException`
  (a `MasterDataSyncException`), and both commands count it as `skipped (not in SSO)`, never `report()` it, log one
  warning per run listing the local/SSO id pairs, and exit 0 when skips are the only problem. A 404 on one record
  or review, and every other 4xx/5xx, still fails and reports as before.
  Every mirror write is query-builder SQL pinned to the local company resolved from the
  authoritative SSO company id (§4a); rows SSO deleted or dropped are deactivated, never deleted.
  **Deliveries for one company and entity are applied one at a time, and each row is retried on a
  deadlock (UNI-1668).** SSO's queue POSTs a bulk change's `{entity}.batch_updated` events (200 rows
  each) in parallel, and a facility import is followed seconds later by a second wave announcing the
  resolved state codes. On 2026-11-20 CloudPCR applied eight such batches for one company at once; its
  phone write (delete then insert, one transaction) took the same gap lock for every new entry, the
  inserts deadlocked, five deliveries answered 500 and SSO never resends a 5xx, so the agency had 440 of
  618 facilities until a manual resync. `MasterDataWebhookHandler` now runs `applyWebhook()` inside
  `Support\MirrorApplyLock` (`Cache::lock("sso-master-data-apply:{entity}:{local company}")` in
  `sso.coordination_store`, waits `sso.master_data.apply_lock_wait_seconds` (20, under SSO's 30 s
  timeout), TTL `apply_lock_ttl_seconds` (120)). It **fails open**: a lock still held after the wait, or
  a cache that cannot be reached, lets the delivery run unserialized with a log line. And
  `CatalogMirror::upsertDelivery()` (used by `applyBatch()`, `applyUpsertWebhook()` and
  `fetchMissingCatalogRows()`, so every catalog and projected mirror) writes each delivered row and its
  projection's `written()` children in one `DB::transaction(..., CatalogMirror::DELIVERY_ATTEMPTS)` (5),
  which reruns the row on a deadlock or lock wait timeout. A row is now written whole or not at all:
  before, a row whose child write failed stayed inserted without its phones and every later delivery
  found it `unchanged`, so only a resync wrote them. Personnel's webhook path is not wrapped yet (it
  takes the lock, not the per-row retry). Test: `FacilityBatchConcurrencyTest`.
  **Child rows SSO removes are never hard-deleted unless the table says so** (`Support\ChildRowPolicy`).
  A child-table definition (`PersonnelChildTable`, `VehicleChildTable`) implements
  `Support\DeclaresChildRowPolicy::onRemoved()` to choose: `unlink()` (clear the SSO child id, stamp
  `sso_removed_at`, keep the row and everything the app keeps on it), `deactivate($column, $inactive = false,
  $active = true)` (set the app's own column, stamp `sso_removed_at`, keep the link so SSO listing the child
  again writes `$active` back over `$inactive`) or `delete()`. A definition that declares nothing is
  **unlinked**. `delete` is only for a pure mirror table: one the package owns, or an app table whose every
  column is written from SSO and that holds nothing of the app's own (CloudPCR's dem_* children once its
  editors are read-only, so a removed licence or crew level leaves the DEM export). **An app mapping SSO
  children onto its own tables (HR's licence rows carry approval history and card images) must choose
  `unlink` or `deactivate`.** Rows stamped `sso_removed_at` are left out of the push, so a removed licence
  HR still shows as pending is not sent back up as new (and removed, and sent again); they are not counted
  as `unlinked_children` either. SSO listing a child with the same key later adopts the kept row again and
  clears the stamp. The stamp column comes from
  `database/master-data/2026_10_13_200000_add_sso_removed_at_to_mirror_child_tables.php`, published with
  both `sso-master-data-personnel` and `sso-master-data-vehicles` (idempotent; it reads the bound
  projections' child tables, so bind them first). Without it, unlink still keeps the row but cannot keep
  it out of the push. The reconcile itself lives in `Support\LinkedChildRows`, shared by both mirrors.
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
  `/api/user` `companies[].qualifications: [{id, name}]` (also on SSO's roster endpoint
  `GET /api/internal/companies/{id}/users`) is mirrored on login by `SsoUserSynchronizer` (no-op when the key is
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
     one row). **Applying the mapping never throws on an SSO id two local rows claim**
     (`Support\MappingLinker`, used by every catalog mirror, personnel and the vehicle / personnel
     child maps): an agency's duplicates (the same facility code or VIN listed twice) come back
     from SSO mapped to ONE SSO row, so the first local row by id gets the link and every other row
     mapped to an SSO id already held is counted in `link_collisions` and printed in a table
     (local id, name, SSO id, the local id that holds the link, reason) for an operator to merge by
     hand; duplicates are never deleted or changed. "Held" is asked of the rows the link's unique
     index spans (`CatalogMirror::linkHolders()`), not the mirror's read scope: CloudPCR's
     `dem_facility_entries` has no company_id, so its index spans every company while the
     projection scope sees only the company's non-deleted type groups, and an entry outside that
     scope holding the id used to abort the push on the unique index (CloudPCR 2026-10-14,
     "Duplicate entry '6176'"). Each link is written in a savepoint, so a link another process
     writes between the check and the write is reported the same way. Names longer than SSO's 255-character limit are sent cut, with a warning (the local name is
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
    `is_active: false`; SSO never deletes divisions), `division.batch_updated` `{ids, divisions: [...]}` (an app's
    push, up to 200 rows, applied like a resync page, nothing turned off) and `user.division_changed`
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
    `is_active: false`); stale and pending guards as for every catalog. An app's push reaches the other apps
    as `location.batch_updated` `{ids, locations: [...]}` (up to 200 rows, applied like a resync page, nothing
    turned off). Never deleted: Crew's resources
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

  **Vehicles** (fourth entity, `MasterData\Vehicles\VehicleMirror`, branch `feature/vehicles-mirror`;
  SSO side on `feature/vehicles`). NEMSIS dVehicle; Fleet (`vehicles`) and CloudPCR (`dem_vehicles`)
  are the first consumers. Same projection idea as locations: `Vehicles\VehicleProjection` names the
  table and the unit number / VIN / call sign / home location / division columns, maps an SSO row to
  columns (`toColumns()`; columns the app owns alone, like Fleet's mileage and notes, never appear) and
  back for the push (`pushColumns()`, `toImport()`), and lists child tables (`childTables()`: a
  `Vehicles\VehicleChildTable` each for `crew_levels` and `yearly_usage`). The package binds
  `StandardVehicleProjection` (a `vehicles` table with the contract's column names, read and written
  only where the column exists, no children; the migration creates the full table for an app with
  none). `tests/Stubs/DemVehicleProjection.php` is the CloudPCR template (dvehicle_01 unit, 02 VIN,
  03 call sign, 04 type, 09 cost, 10 year, `export_vehicle_number` / `export_call_sign`, children
  `dem_vehicle_cert_levels` dvehicle_05..08 and `dem_vehicle_years` dvehicle_11..13);
  `tests/Stubs/FleetVehicleProjection.php` the Fleet one (standard + free-text `category`, a Fleet
  extension field that is push-only: SSO uses it to suggest a NEMSIS type at import, and nothing from
  SSO is ever written into it). `distance_unit` is left to the real CloudPCR projection.
  - **Child rows are updated in place**, keyed by SSO's stable child id in `sso_crew_level_id` /
    `sso_yearly_usage_id` (added by the migration). Never delete-and-recreate: CloudPCR derives NEMSIS
    group UUIDs from the local child row id. A linked child SSO stops listing is handled by the child
    table's `ChildRowPolicy` (unlink unless it declares otherwise; the CloudPCR template declares
    `delete`); an unlinked local child is adopted by level / year when exactly one fits, otherwise kept and
    counted as `unlinked_children` on the resync. A row whose only change is a child reports `updated`.
  - Adoption and `--link-by-name` go VIN first, then unit number, then call sign (adoption only),
    normalized like SSO (trim, uppercase, no spaces or hyphens). A unit number or call sign candidate
    whose VIN differs from SSO's is never adopted (a replacement rig that kept the unit number).
  - `home_location_id` / `division_id` are translated through the locations and divisions mirrors'
    links (null clears; an id not linked here leaves the local value). The push sends
    `home_location_sso_id` / `division_sso_id` when the local row's link is confirmed, otherwise
    `*_local_id`; push **divisions and locations before vehicles**.
  - SSO contract: `GET /api/internal/companies/{id}/vehicles` -> `{company, vehicles: [row]}` (row:
    `id, uuid, unit_number, call_sign, export_unit_number, export_call_sign, reported_*, vin,
    vehicle_type(_name), model_year, initial_cost, make, model, color, license_plate(_state)(_name),
    fuel_type(_name), status(_name), home_location_id(_name), division_id(_name), crew_levels: [{id,
    uuid, level, on_911_ambulance, on_911_non_transport, on_non_911_transport}], yearly_usage: [{id,
    uuid, year, hours, distance, distance_unit}], is_active, sort_order, updated_at`);
    `POST .../vehicles/import` `{app_slug, vehicles: [{local_id, unit_number, ..., crew_levels:
    [{local_id, ...}], yearly_usage: [{local_id, ...}]}]}` -> `{created, matched, filled, conflicts,
    unresolved_locations, unresolved_divisions, partial (with kept), refused, invalid, mapping:
    {local_id: {sso_id, updated_at, crew_levels: {child_local_id: sso_child_id}, yearly_usage: {...}}}}`.
    SSO matches VIN, then unit number, then call sign; never overwrites a match. Unit numbers are cut
    at 25 on push. Events `vehicle.created|updated|deactivated`, full row, all upsert; an app's push reaches the
    other apps as `vehicle.batch_updated` `{ids, vehicles: [...]}` (up to 200 rows, child rows included, applied
    like a resync page, nothing turned off).
  - Cutover: bind the projection (unless the standard one fits); publish `sso-master-data-vehicles` and
    migrate; push divisions and locations first; `sso:push-master-data vehicles --dry-run`, then for
    real (flag off); settle `conflicts` (a `unit_number_differs` means the resync will write SSO's unit
    number, so set the export override in SSO first if the state must keep seeing the old one),
    `unresolved_*`, `partial` and `refused` in SSO Settings > Vehicles; `SSO_MASTER_DATA_VEHICLES=true`;
    `sso:resync-master-data vehicles`; make the app's vehicle editor read-only. Read side:
    `VehicleCatalog::usableForCompany()`. Fleet is on Vapor (staging is live): James deploys it.

  **Facilities** (fifth entity, `MasterData\Facilities\FacilityMirror`, branch `feature/lists-mirrors`;
  SSO side on `feature/vehicles` and later). Destinations (hospitals, nursing homes, other receiving
  facilities, NEMSIS dFacility); facility-as-destination only, never facility-as-company. Same projection idea:
  `Facilities\FacilityProjection` names the table and the name / code / ZIP columns, **scopes a query to a
  company** (`scope()`: a table may reach its company through a parent, as CloudPCR's entries do through their
  type group), gives the owner columns of a new row (`insertColumns()`), maps an SSO row to columns
  (`toColumns($company, $facility)`, which may find-or-create the type group), writes what lives outside the
  row (`written()`: phones, fax, designations / NPIs kept as rows; `Support\ChildRows::sync()` compares and
  writes in place, by position: extras are deleted by default, which is only right for a pure copy of
  SSO's list; a table carrying the app's own data passes `onRemoved: ChildRowPolicy::deactivate(...)`, so
  extras are switched off and stamped and an inactive row is never reused; `unlink` is refused, there is no
  child id to clear) and maps back for the push (`pushColumns()`, `toImport()`). The package binds
  `StandardFacilityProjection` (a `facilities` table with company_id and the contract's column names,
  `designations` / `npis` as JSON text, written only where the column exists; the migration creates it for an
  app with none). `tests/Stubs/DemFacilityProjection.php` is the CloudPCR template (`dem_facility_entries`
  dfacility_02..13 + `dfacility_07b` + `mailing_city`, under `dem_facilities` dfacility_01 type groups, phones
  in `dem_facility_entry_phones` with SSO's `fax` kept as a 9913001 phone). Entry ids never change (CAD
  incidents, Billing claims, mobile bundles hold them); a type change moves the entry to another group.
  - Adoption mirrors SSO's import match: code (ignoring case), then exact name with the same ZIP, exactly one
    candidate each, pending until confirmed; no name-only adoption. Since SSO allows several facilities to
    share a code that does not name one place (a state's catch-all such as PA OTH00001, or a code the state's
    list uses for several places; UNI-1741, facilities are unique by code, not by name), a code already held
    by a linked local row no longer adopts by code: the next SSO facility with it adopts only by name + ZIP. `--link-by-name` links by code, then name.
  - Migration (`vendor:publish --tag=sso-master-data-facilities`) adds `sso_facility_id`, `is_active`,
    `sort_order`, `sso_updated_at`, `sso_link_pending` (each skipped when present); the SSO link is unique per
    company where the table has company_id, else on its own (`{table}_sso_facility_unique`).
  - SSO contract: `GET /api/internal/companies/{id}/facilities` -> `{company, facilities: [{id, type, name,
    location_code, registry: {state, code, synced_at}|null, designations, npis, address: {suite, street,
    street2, city_gnis, city_name, state(_name), zip, county(_name), country}, latitude, longitude, fax, phones:
    [{id, number, type}], pending: {field: {app_slug: value}}, is_active, sort_order, updated_at}]}`;
    `POST .../facilities/import` `{app_slug, facilities: [{local_id, name ≤100, type?, location_code?,
    designations?, npis?, fax?, address?, latitude?, longitude?, phones?, is_active?}]}` (500 max) -> `{created,
    matched, filled, conflicts (turned_off_in_sso | code_differs), partial [{local_id, name, fields, kept}],
    refused, invalid, mapping}`. SSO matches by this app's earlier `list_links`, then code, then name + ZIP;
    never overwrites a match but fills its empty fields (`filled`). A value SSO's rules refuse is kept on the
    row per field and per app and reported in `partial`; every row then carries it in `pending` (`gps` =
    `{latitude, longitude}`, `phones` = the refused phones) until the agency fixes or dismisses it, and
    `FacilityMirror::withOwnPending()` writes this app's own kept value over SSO's empty one, so a resync or
    delivery never blanks what the app sent (another app's kept values are never applied). Events
    `facility.created|updated|deactivated` (full row) and `facility.batch_updated` `{ids, facilities: [...]}` (state-list adds and an app's push, up to 200, applied like a resync page).
    **`facility.deleted`** `{company, ids: [sso id], facilities: [{id, uuid, name, location_code}], deleted_at}`
    (up to 200 ids, UNI-1723/1725): SSO deletes a facility from its list only after every app that can hold a
    reference (CloudPCR, CAD, Billing: their `POST /api/internal/facilities/usage`) said nothing uses it, and
    turns used ones off instead. The mirror checks again here: a projection that implements
    `Facilities\DeletesFacilities::deleteUnreferenced($company, $localIds)` deletes the rows it confirms nothing
    references (with their child rows) and returns their ids; every other linked row is turned off and
    unlinked (`sso_facility_id` null, `sso_link_pending` false), so records that hold its id keep it and a
    later SSO facility with the same code can adopt it again. A projection without the interface never loses
    a row. CloudPCR handles the event in its own `AppWebhookController` (it was built before this package
    version), so only apps that mirror facilities through the package need the update (Billing).
    Every list's batch goes through `CatalogMirror::applyBatch()` (the code lists add their projection on top).
  - Read side: `FacilityCatalog::usableForCompany()` (active rows, SSO order, through the projection's scope).

  **Medications and procedures** (sixth and seventh, `MasterData\Medications\MedicationMirror` and
  `MasterData\Procedures\ProcedureMirror` on the shared `CodeLists\CodeListMirror`; SSO side
  `feature/vehicles` + `feature/lists-import-direct`). No app keeps these lists flat (CloudPCR repeats each
  code per state configuration and per level, Drug Tracking hangs products off ingredients), so **the mirror
  is a package-owned table**, one row per code: `sso_medications` / `sso_procedures` (code verbatim,
  code_system, code_type, name, display_name, label, levels, member_levels, only_states as JSON text, source,
  is_active, sort_order, link columns; medications add ingredient_rxcui, is_controlled, dea_schedule,
  is_blood_product). `vendor:publish --tag=sso-master-data-medications` / `-procedures` creates them.
  - **The projection is the bridge** (`CodeLists\CodeListProjection`, bound as `MedicationProjection` /
    `ProcedureProjection`; package default `MirrorOnly*Projection` does nothing): `localItems($company)` is the
    app's list collapsed to one entry per code (`key?`, `code`, `label`, `levels`, `is_active?`,
    `only_states?`, medications `is_controlled`, `dea_schedule`) for the push; `project($company, $items)`
    writes the whole mirrored list into the app after every webhook that changed a row, every batch and every
    resync (never while the entity is off). `tests/Stubs/DemFormularyProjection.php` (+
    `DemMedicationProjection`, `DemProcedureProjection`) is the CloudPCR template;
    `tests/Stubs/DrugTrackingMedicationProjection.php` the Drug Tracking one.
  - Rows without levels stay on the list (a medication with none is stock only); `is_chartable` says which.
    "Only report in" (`only_states`, FIPS, empty = every state) limits a code to some reporting states.
  - **The push may wait for the agency.** The first app to send its list to an empty agency list gets
    `created` + mapping at once (SSO `feature/lists-import-direct`). A later app's new codes wait in the
    agency's review: the push prints `in_review` and the review id. When the agency finishes it, SSO sends
    `{entity}.review_completed` `{review: {id, entity, mapping: {app_slug: [{local_id, sso_id|null}]}}}`,
    applied **even while the entity is off** (`ContinuesPush`); a null sso_id is an entry the agency left out.
    `sso:push-master-data medications --company=X --review=ID` reads the same mapping from
    `GET .../medications/reviews/{id}` when the webhook was missed. Linking needs no local row: a mapped entry
    links the mirror row with that code or creates it from the app's entry (no `sso_updated_at`, so SSO's next
    delivery or the resync replaces it); the projection is not called before the flag is on. The whole list
    goes in one request (SSO takes 500), because a second request from the same app replaces its rows in the
    review; a longer list is refused locally.
  - SSO contract: `GET /api/internal/companies/{id}/{medications|procedures}` -> `{company, medications: [{id,
    code, code_system, code_type, name, display_name, label, source, levels, member_levels, is_chartable,
    only_states, (ingredient_rxcui, is_controlled, dea_schedule, is_blood_product), is_active, sort_order,
    updated_at}]}`; `POST .../import` `{app_slug, medications: [{local_id, code?, label, levels?,
    is_controlled?, dea_schedule?, only_states?}]}` -> `{created, matched, in_review, invalid, partial?
    [{local_id, name, fields, dropped}], review_id, mapping}`; events `{entity}.created|updated|deactivated`,
    `{entity}.batch_updated` `{medications|procedures: [...]}` and `{entity}.review_completed`.
  - Read side: `MedicationCatalog` / `ProcedureCatalog` (`usableForCompany()`, `chartableForCompany($company,
    $stateFips)`), for apps that read the mirror directly.
  - Cutover per company (facilities and code lists alike): publish the migration and migrate; bind the
    projection; `sso:push-master-data <entity> --dry-run`, then for real with the flag off; settle what it
    prints (`invalid`, `partial`, `refused`, conflicts) in SSO Settings and, for a code list sent second, wait
    for the agency to finish the review; set the flag; `sso:resync-master-data <entity>`; make the app's
    editor read-only with an "Edit in Settings" link (`{SSO_BASE_URL}/system#facilities`, `#medications`,
    `#procedures`).

  **Personnel** (eighth entity, `MasterData\Personnel\PersonnelMirror`, branch `feature/personnel-mirror`;
  design `/Sites/docs/design/personnel-design.md` §3b/§3c/§3e). NEMSIS dPersonnel, one record per person per
  company; CloudPCR (`dem_personnel` + children) and HR (`personnel_profiles` + children) are the consumers.
  SSO is the editor; apps mirror. Not a name-keyed catalog: the mirror implements `EntityMirror`,
  `SeedsSso` and `Contracts\FetchesSnapshot` itself (no `CatalogMirror`), keyed by SSO user id + company.
  - **The contract apps implement is `Personnel\PersonnelProjection`** (the design's
    `PersonnelMirrorWriter`): `table()` (one row per person per company, `id` + `company_id`),
    `activeColumn()`, `userColumn()` (local users.id or null), `emailColumn()` (match key or null),
    `toColumns($company, $snapshot)` (leave out columns whose snapshot section is absent; JSON as text),
    `written($company, $rowId, $snapshot)` (idempotent extras: CloudPCR's sign-in contact rows, HR's
    `users.name`), `pushColumns()` / `toImport($company, $row)` (snapshot-shaped `user` / `profile` /
    `membership`) and `childTables()`: a `Personnel\PersonnelChildTable` (`table`, `parentColumn`,
    `keyColumns`, `toColumns`, `pushColumns`, `toImport`; `toImport` returning `[]` keeps a row out of the
    push, e.g. HR's driver's licences) each for `licenses`, `practice_levels`, `phones`, `emails`,
    `immunizations`. Before calling it the mirror adds `user.local_id` and, when the divisions mirror can
    translate it, `membership.local_division_id` (absent = leave the local value). The package binds
    `StandardPersonnelProjection` (package-owned `sso_personnel` read model, licences and practice levels as
    JSON text, no children). Templates: `tests/Stubs/DemPersonnelProjection.php` (CloudPCR, incl. the
    `_nv = 7701003` rule for blank nillable recommended elements, D12) and `tests/Stubs/HrPersonnelProjection.php`.
  - The mirror owns: link `sso_user_id` (unique per company), `sso_updated_at` stale guard,
    `sso_link_pending`, the active column (`membership.is_member && is_active`), the user column (never
    taken from another local row that holds the user), division translation, and **child rows reconciled in
    place by SSO child ids** (`sso_license_id`, `sso_practice_level_id`, `sso_phone_id`, `sso_email_id`,
    `sso_immunization_id`; sort_order from SSO's order where the table has it). A linked child SSO stops
    listing is unlinked, deactivated or deleted by the child table's `ChildRowPolicy` (the CloudPCR
    template declares `delete`, the HR template `unlink`); an unlinked local child is adopted by `keyColumns` when exactly one fits,
    otherwise kept (`unlinked_children`). A child list SSO leaves out is not touched.
  - **Adoption:** local user link first (users.sso_id), then email (case-insensitive), exactly one unlinked
    candidate each, **never by name**; two candidates = `ambiguous` (nothing linked or created). A delivery
    only links + pending; a resync or the push mapping confirms. No local row: created for an active member
    (`user_not_found` while `userColumn()` is set and the user is not provisioned yet; `not_member` for
    someone who left). **Never deleted:** `person.deactivated`, an inactive membership in the snapshot, or a
    complete resync that no longer lists a confirmed person turns the active column off. Local people SSO
    never knew (CloudPCR's name-only crew stubs, D3) are never touched (`unlinked_local`).
  - **Per-field partial values:** the snapshot's `pending` `{path: {app_slug: value}}` (paths under
    `user.`, `profile.`, `membership.`) puts this app's kept value over SSO's EMPTY value
    (`PersonnelMirror::withOwnPending()`); SSO's own value wins once it has one; other apps' are never applied.
  - Events: `person.updated` `{company, personnel: <PersonnelSnapshot>, updated_at}` (upsert),
    `person.deactivated` `{company, user: {id}, updated_at}`. Resync walks `GET .../personnel?cursor=`
    (`{company, data, next_cursor}`, 200 a page); a page failing or a cursor not advancing throws and
    changes nothing, and only a complete set turns anyone off.
  - **Login staleness hint:** `/api/user` `companies[].personnel_updated_at` newer than the mirror's
    `sso_updated_at` (or no row yet) queues `Personnel\Jobs\PullPersonnelRecord` after the login commits
    (`GET .../personnel/{ssoUserId}`, login timeout, applied as a delivery). One pull per hint per 5 min
    (`Cache::add`, fails open); every failure is logged, never fails the login. A pending row never pulls.
  - **Write-through:** `PersonnelMirror::writeThrough($company, $ssoCompanyId, $ssoUserId, $patch,
    $actorSsoUserId)` PATCHes `.../personnel/{ssoUserId}` `{patch, source: app_slug, actor_sso_user_id}`
    (SSO's flat keys: `hire_date`, `licenses.{id|new}`, `address`, ...) and applies the answered snapshot at
    once; returns SSO's per-key `results`.
  - Push: `POST .../personnel/import` `{app_slug, personnel: [{local_id, user_sso_id?, is_active, user?,
    profile?, membership? (practice_levels with local_id), phones?, emails?, immunizations?, licenses?}]}` ->
    `{matched, filled, conflicts: [{local_id, name, field, local_value, sso_value}], partial: [{local_id,
    name, fields, kept}], unmatched: [{local_id, name, reason}], invalid, mapping: {local_id: {sso_id
    (SSO user id), updated_at, licenses|practice_levels|phones|emails|immunizations: {child_local_id:
    sso_child_id}}}}`. Every local row is sent; rows SSO cannot match wait in SSO's review.
    **Not on SSO main yet** (nor `personnel_updated_at`, nor snapshot `pending`): see the PR for the spec.
  - Cutover per company (design §3d): bind the projection; publish `sso-master-data-personnel` and migrate;
    push divisions first; CloudPCR `sso:push-master-data personnel --dry-run`, then for real (flag off);
    HR the same; settle `conflicts` / `partial` / `unmatched` in SSO; `SSO_MASTER_DATA_PERSONNEL=true` in
    CloudPCR; `sso:resync-master-data personnel`; DEM golden diff empty; then HR; then switch the editors to
    `writeThrough()`.

  **Agency information, contacts, devices and state configurations** (ninth to twelfth entities: the CloudPCR
  Demographics move to SSO Settings; build spec `/Sites/docs/design/demographics-sso-contracts.md`, plan
  `demographics-to-sso-plan.md`). Registry keys `agency`, `contacts`, `devices`, `state_configurations`; flags
  `SSO_MASTER_DATA_AGENCY|CONTACTS|DEVICES|STATE_CONFIGURATIONS`. **An app whose `config/sso.php` overrides
  `master_data` must list every key it uses**: the override replaces the package array, and a missing key is off.
  - **One shared base, `MasterData\ProjectedMirror`** (on `CatalogMirror`, `SeedsSso`): the row's shape comes
    entirely from a bound `Contracts\RecordProjection` (`table`, `nameColumn` (reports only), `matchColumns`,
    `scope`, `insertColumns`, `fromSso($company, $row)` (pure: adoption reads the match columns through it),
    `written($company, $localId, $row)` (children; idempotent, `Support\ChildRows` helps), `pushColumns`,
    `toSso($company, $row)`). Each entity has its own interface (`Agency\AgencyProjection`,
    `Contacts\ContactProjection`, `Devices\DeviceProjection` + `vehicleColumn()`,
    `StateConfigurations\StateConfigurationProjection`), and the package binds a `Standard*Projection` on a
    package-owned read model (`sso_agency_profiles`, `sso_agency_contacts`, `sso_devices`,
    `sso_state_configurations`: the contract's field names as columns, lists and objects as JSON text, UTC
    instants, every top-level `<field>_nv` in one `not_values` JSON column, only columns the table has).
    `Support\StandardRecordProjection` is that shared default. CloudPCR binds its own over `dem_agencies` (+
    `dem_agency_service_areas`, `dem_agency_year_stats`, `dem_custom_values`), `dem_contacts`, `dem_devices`,
    `dem_configurations` (.10-.17 only); `tests/Stubs/DemAgencyProjection.php` is the agency template.
  - The base owns: link column (`sso_agency_id`, `sso_contact_id`, `sso_device_id`,
    `sso_state_configuration_id`), adoption of an unlinked local row by the projection's match columns (trimmed,
    ignoring case, exactly one candidate; contacts last + first + type, devices serial, state configurations
    state), pending until the push / `--link-by-name` / a resync confirms it, the `sso_updated_at` stale guard,
    turn off never delete (a resync turns off linked rows SSO no longer lists), this app's kept values
    (`pending: {field: {app_slug: value}}` written over SSO's; a kept list is appended;
    `ProjectedMirror::withOwnPending()`), `{record}.created|updated|deactivated` (full row, upsert) and
    `{record}.batch_updated` `{company, ids, <entity>: [rows], updated_at}` (`CatalogMirror::applyBatch()`),
    and the push `{app_slug, <entity>: [{local_id, is_active, ...toSso()}]}` (500 a request) linked through
    `MappingLinker`. SSO's own `name` field reaches the projection untouched (the report name is kept aside).
  - **agency** is a singleton: snapshot `GET .../agency` -> `{company: {id, name, timezone}, agency: {...profile,
    service_areas: [...], year_stats: [...], custom_values: {element_id: {value, nv}}} | null}` (null changes
    nothing); every record carries `company`, because the agency name is `companies.name` and the time zone
    is SSO's IANA `companies.timezone` (the app maps it, e.g. CloudPCR's dAgency.23). Adoption takes the
    company's only local row when unlinked. No on/off column: nothing is turned off, resync reports
    `deactivated=0`. Events `agency.created|updated|batch_updated` (full payload). Push `POST .../agency/import`
    `{app_slug, agency: {local_id, ...}}`, one request (`ImportBatcher` never splits an object with `local_id`);
    SSO is fill-only and answers `conflicts: [{local_id, name, field, local_value, sso_value}]`, printed as the
    field table. A company with no agency row prints "no local agency to send" and sends nothing.
  - **contacts** `GET .../contacts` -> `{company, contacts: [{id, type, nemsis_type, name, first_name,
    middle_name, last_name, title, is_primary, phones: [{number, type}], emails: [{address, type}], address,
    website, medical_director, fellowship_status, sso_user_id, <field>_nv, pending?, is_active, sort_order,
    updated_at}]}`; events `contact.*`.
  - **devices** `GET .../devices` -> `{company, devices: [{id, serial, name, device_types, manufacturer, model,
    purchase_date, source (integration | manual), last_seen_at, vehicle_id, is_active, sort_order,
    updated_at}]}`; events `device.created|updated|deactivated|batch_updated`. `vehicle_id` is translated
    through the vehicles mirror's links like a location's division (null clears, an id not linked here leaves
    the local value); the push sends `vehicle_sso_id` for a confirmed link, else `vehicle_local_id`, so push
    vehicles first. **`Devices\DeviceWriteThrough::send($localCompanyId, $row)`** is the one place an app
    writes into SSO on its own: CloudPCR's Integrations page (Stryker / ZOLL monitor registered or edited)
    sends that one row to `POST .../devices/import` (`source` defaults to `integration`; `vehicle_local_id`
    becomes `vehicle_sso_id` when confirmed). Queued after commit (`Jobs\WriteThroughDevice`, 15 s timeout,
    one try), fail-silent (logs a warning, returns nothing, never holds up a transmission), and a no-op while
    `SSO_MASTER_DATA_DEVICES` is off (the push carries devices before the cutover). The mapping is not applied:
    the `device.*` webhook that follows brings the mirror row.
  - **state_configurations** `GET .../state_configurations` -> `{company, state_configurations: [{id, state,
    protocols, specialty_service_capabilities, billing_status, emd_performed, emd_vendor,
    patient_monitoring_capabilities, crew_call_signs, dispatch_center, dispatch_center_phone, <field>_nv,
    is_active, sort_order, updated_at}]}`; events `state_configuration.*`. Medications, procedures and
    certification levels stay with their own mirrors.
  - Migrations (`vendor:publish --tag=sso-master-data-agency|contacts|devices|state-configurations`) create the
    standard table for an app with none, else add the link column, `is_active` (not for the agency),
    `sort_order` (not for the agency), `sso_updated_at`, `sso_link_pending`, each skipped when present, and a
    unique `{table}_{link}_unique` per company (`Support\MirrorLinkColumns`). Bind the projection first.
  - Trial: the seed sync resyncs these like any enabled mirror and checks SSO's `counts.agency|contacts|devices|
    state_configurations` for the ones this app has on and migrated (`TrialDemographicsTest`); the purge never
    deletes the agency or state configurations, and cleans listed contacts / devices (SSO lists only the
    seeded ones; the owner's contact stays).
  - "Edit in Settings": `Settings\SettingEntity::{Agency, Contacts, Devices, StateConfigurations}` (values
    `agency`, `contacts`, `devices`, `state_configurations`) with `settingsPath()` `/system/agency`,
    `/system/contacts`, `/system/devices`, `/system/state-configuration` and `settingsUrl()` =
    `{SSO_BASE_URL}{path}`; the older cases name their tabs too. SSO's TS `SettingEntity` type must list the
    new values before a settings schema uses them.
  - Cutover per app and entity, in the order agency, contacts, devices (after vehicles), state configurations:
    bind the projection; publish the migration and migrate; `sso:push-master-data <entity> --dry-run`, then for
    real (flag off); settle conflicts in SSO Settings; set the flag; `sso:resync-master-data <entity>`; make
    the app's editor read-only with "Edit in Settings".

  **Units** (thirteenth entity, `MasterData\Units\UnitMirror` on `ProjectedMirror`; SSO side PR #175, plan
  `/Sites/docs/design/units-rotations-plan.md` phase 2). A unit is a staffed position (Medic 1, Engine 3),
  labelled **"Unit/Resource"** on screen (never "Call sign"); it is not a vehicle and links to its usual
  vehicle, home location and division. Registry key `units`, flag `SSO_MASTER_DATA_UNITS`
  (`config('sso.master_data.units')`), publish tag `sso-master-data-units`, "Edit in Settings"
  `SettingEntity::Units` -> `/system/units`.
  - **`Units\UnitProjection`** (extends `RecordProjection`) adds `vehicleColumn()`, `locationColumn()`,
    `divisionColumn()` (local ids the mirror translates through the vehicles / locations / divisions links:
    null clears, an id not linked here leaves the local value; the push sends `*_sso_id` for a confirmed
    link, else `*_local_id`, so push those lists first), `seatTable(): ?Units\UnitSeatTable` (crew seats),
    `accepts($company, $record)` (a unit with no linked or adoptable row that the app cannot hold is
    `skipped`; Fire keeps units under stations) and `pushable($company, $rows)` (rows the push sends; CAD
    leaves out its untouched trial sample). `written()` is redeclared with `int|string $localId`.
    The package binds `StandardUnitProjection` (`sso_units` + `sso_unit_seats`). Apps bind their own over
    their table (CAD `units`, Crew `resources`, Fire `neris_station_units`) before migrating.
  - **Local ids may be uuids** (CAD). `CatalogMirror::localKey()` keeps a non-numeric id as a string through
    `applyImportMapping`, `linkedMap`, `MappingLinker`, `ProjectedMirror::linkByName` and the push; numeric
    ids behave exactly as before. A seat table needs integer parent ids.
  - **Seats** are linked by SSO's seat id (`sso_unit_seat_id`), updated in place, adopted by position, and a
    seat SSO removed is unlinked and stamped `sso_removed_at` (never deleted unless the seat table declares
    `DeclaresChildRowPolicy`). The qualification is translated through the qualifications links (unknown =
    null); the push sends `qualification_sso_id` and SSO's `mapping.{local_id}.seats` links them. A unit
    whose only change was a seat reports `updated`; the resync adds `unlinked_seats`.
  - SSO contract: `GET .../units` -> `{company: {id, division_label, timezone}, units: [{id, uuid, name,
    display_name, unit_type(_name), level_of_care(_name), capabilities, vehicle_id, vehicle {id,
    unit_number, call_sign}, location_id, location {id, name}, division_id, division {id, name}, crew_size,
    seats: [{id, uuid, position, label, qualification_id, qualification_name, sort_order}], source_app,
    is_active, sort_order, updated_at}]}`; `POST .../units/import` `{app_slug, units: [{local_id, uuid?, name,
    display_name?, unit_type?, level_of_care?, capabilities?, crew_size?, is_active?, vehicle_sso_id? |
    vehicle_local_id?, location_*?, division_*?, seats?: [{local_id, position?, label,
    qualification_sso_id?}]}]}` (500 max) -> `{created, matched, filled, partial, refused, invalid,
    conflicts, unresolved_vehicles, unresolved_locations, unresolved_divisions, mapping: {local_id: {sso_id,
    updated_at, seats: {local: sso}}}}`. SSO matches this app's earlier link, then the name (trimmed, spaces
    collapsed, ignoring case), fill-only. Unit types are SSO's fixed list (ambulance, engine, ladder, rescue,
    supervisor, wheelchair_van, command, support, other); SSO maps common app words. Names are cut at 100 on
    push. Events `unit.created|updated|deactivated` (full row) and `unit.batch_updated` `{units: [...]}`.
  - Trial purge: `units` is cleaned first; the default policy deletes only `sso_units` rows (with their
    seats) and deactivates units in an app's own table; a vehicle, location or division a remaining unit
    names is deactivated, not deleted.
  - Cutover per app: bind the projection; publish `sso-master-data-units` and migrate; push divisions,
    locations, vehicles; `sso:push-master-data units --dry-run`, then for real (flag off); settle conflicts in
    SSO Settings > Units (Review differences); set the flag; `sso:resync-master-data units`; make the app's
    unit editor read-only with "Edit in Settings".
- **Rotations mirror** (`MasterData\Rotations`, units-rotations plan phase 4, SSO PR #176). A rotation is a
  repeating day cycle (`cycle_days`, `anchor_date` = company-local "day 1") with timed blocks (`day_index`,
  `start_time` HH:MM naive wall clock, `duration_minutes`), assigned to units and people
  (`assignable_type` unit|user, `start_day_index` = **the cycle day the assignee is on at the anchor date**,
  `starts_on`, `ends_on`). Registry key `rotations`, flag `SSO_MASTER_DATA_ROTATIONS`, publish tag
  `sso-master-data-rotations`, `SettingEntity::Rotations` -> `/system/rotations`.
  - `RotationMirror` on `ProjectedMirror` over the bound `RotationProjection` (default
    `StandardRotationProjection` on `sso_rotations`). Blocks and assignments always live in the package's
    `sso_rotation_blocks` / `sso_rotation_assignments` (`RotationChildTable`), linked by SSO's child ids,
    updated in place and **deleted** when SSO drops them (pure mirrors; `ChildRowPolicy::delete()`): a
    stale assignment would put a unit on duty that SSO took off. Assignments keep SSO's `sso_unit_id` /
    `sso_user_id`; readers match them to the units mirror link and `users.sso_id`.
  - Events `rotation.created|updated|deactivated` (full row with blocks and assignments; a block or
    assignment edit in SSO re-sends its rotation) and `rotation.batch_updated` `{rotations: [...]}`. The
    `company` block carries `timezone`; the mirror does not store it, readers pass the app's synced
    `companies.timezone`.
  - Push: `RotationProjection::pushRotations()` supplies the rows (not the mirror table; `sso_rotations` rows
    ARE SSO's) and `linkPushed()` records each mapping (Crew: `shift_templates.sso_rotation_id`). The
    standard projection pushes nothing. SSO's `unresolved_units` is printed as a table.
  - `Unified\SsoClient\Rotations\RotationCalendar` is SSO's `App\Support\Rotations\RotationCalendar` copied
    unchanged (D6: a block ends at start wall clock + duration as wall clock; `elapsed_minutes` is the real
    length). `tests/Fixtures/rotations/golden.json` is SSO's file byte for byte and
    `tests/Unit/Rotations/RotationCalendarTest.php` runs it: change both repos together or not at all.
  - `Rotations\MirroredRotations` reads the mirror for one local company: `options()` (picker rows with
    SSO's pattern summary), `rotation()`, `windows($company, $from, $to, $tz, unit|user|rotation)` (active
    rotations and assignments only, SSO ids on every window) and `patternWindows()` (a rotation run with a
    start day of the caller's own, Crew templates), plus `all($company)` (every rotation in `rotation()`'s
    shape in three queries, for callers that would otherwise loop `rotation()`). It returns nothing while
    the entity is off. `available()` probes the schema once per instance (UNI-1378: a per-rotation loop
    paid four information_schema queries per call).
  - Trial purge: `rotations` is cleaned before `units`; sample rotations are deleted with their children.
  - Calendar tint (UNI-1226): the contract carries `color` (`#rrggbb` or null; anything else is stored as
    null) and `show_on_calendar` (bool, absent = false). The standard projection maps both onto
    `sso_rotations`, so a change to either reports `updated`; `MirroredRotations::options()` / `rotation()`
    return them and a push row may carry them (Crew Scheduling reads them to tint its calendars). Fresh
    installs get the columns from the create stub; an app that already published
    `create_or_extend_rotations_mirror` (Crew, CAD, Fire) picks up the separate guarded stub
    `2026_10_27_100000_add_color_and_calendar_to_rotations_mirror` with
    `php artisan vendor:publish --tag=sso-master-data-rotations` then `php artisan migrate` (publishing
    skips files already present, so only the new stub lands), then `sso:resync-master-data rotations` to
    fill the new columns. A new rotation column always ships as a new stub under the same tag; never edit
    the published create stub expecting apps to see it.
- **Other agencies mirror** (`MasterData\OtherAgencies\OtherAgencyMirror` on `ProjectedMirror`, modeled on
  contacts; Non-Agency Crew work, Pine Grove 2026-11). SSO's agency-owned list of the agencies its crews work
  with (mutual aid, intercepts), picked from the state's agency list or added by hand, edited in SSO
  `/system` > Agency lists > Other agencies. Registry key `other_agencies`, flag
  `SSO_MASTER_DATA_OTHER_AGENCIES`, publish tag `sso-master-data-other-agencies`, link column
  `sso_other_agency_id`, `SettingEntity::OtherAgencies` -> `/system/other-agencies`.
  - Snapshot `GET .../other_agencies` -> `{company, other_agencies: [{id, uuid, name, state, state_agency_id,
    agency_number, service_type, is_active, sort_order, updated_at, source}]}` (`source` is `state_list` when
    `state_agency_id` is set, else `manual`; not stored). Events `other_agency.created|updated|deactivated`
    (`{company, other_agency: {...}, updated_at}`) and `other_agency.batch_updated` (`{company,
    other_agencies: [...], updated_at}`, like `facility.batch_updated`). Push `POST .../other_agencies/import` `{app_slug, other_agencies:
    [{local_id, is_active, name, state, state_agency_id, agency_number, service_type, uuid}]}`.
  - Adoption and SSO's import dedupe both key on name + state (trimmed, ignoring case): a row with the same
    name in another state is a different agency.
  - The package binds `StandardOtherAgencyProjection` on `sso_other_agencies` (`company_id`,
    `sso_other_agency_id`, `uuid`, `name` 200, `state` 2, `state_agency_id` 50, `agency_number` 50,
    `service_type` 10, `is_active`, `sort_order`, `sso_updated_at`, `sso_link_pending`, timestamps). CloudPCR
    uses it as is (no `dem_` table); its Non-Agency Crew rows point at it.
  - **`OtherAgencyMirror::pushRow(int $localCompanyId, int $localId): ?int`** pushes ONE row the app created
    itself (a chart picks an agency from the state list before SSO has it) through the same import endpoint
    and applies the returned mapping. Returns the SSO id (or the existing one when already linked, without a
    request), or null when the row is not that company's, the company has no `sso_company_id`, SSO is
    unreachable or answers an error (15 s timeout), or the link is refused (another row holds that SSO id).
    It never throws; it logs a warning and leaves the row unlinked, and the next resync adopts it by name +
    state. Call it after the local row is committed, never inside the chart save transaction.

## Security headers, HTTPS and CORS (UNI-1778)

Config `config/security-headers.php` (key `security-headers`, publish tag `security-headers-config`).
Nothing changes in an app until it adds the middleware, except forced HTTPS URLs (below).

**What production sent on 2026-11-23** (`curl -D -` on each app's `/login`): no app sends
`Strict-Transport-Security`, `Referrer-Policy` or `Permissions-Policy`. Laravel Cloud's edge adds
`X-Content-Type-Options: nosniff` and `X-Frame-Options` (DENY on SSO, Fire, Crew-Scheduling, CheckSheets,
account, CRM, Reporting, Transport-Portal, the website; SAMEORIGIN on CloudPCR and CAD; only nosniff on the wiki; nothing
on Billing or Drug-Tracking). The Vapor apps (HR, Fleet-Management, Truck-Checks) send none of them. App code
sets headers only on single routes: Billing's `frame.self` previews (`frame-ancestors 'self'` + SAMEORIGIN),
CloudPCR's PCR PDF (SAMEORIGIN), and file downloads in CRM, Reporting, Bridge and the website (nosniff, a
locked-down CSP on Bridge's document view and CRM's email preview).

**`Middleware\SecurityHeaders`** (alias `sso.security-headers`). Append it to the `web` group:
`->withMiddleware(fn (Middleware $m) => $m->appendToGroup('web', 'sso.security-headers'))`. It never
overwrites a header already on the response, so a route middleware that sets its own framing keeps it.

| Header | Default | Config / env |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | `SECURITY_REFERRER_POLICY` |
| `Content-Security-Policy` | `frame-ancestors 'self'` (+ extra parents) | `SECURITY_FRAME_PROTECTION`, `SECURITY_FRAME_ANCESTORS` (comma list) |
| `X-Frame-Options` | `SAMEORIGIN` (fallback for browsers without frame-ancestors) | `SECURITY_FRAME_PROTECTION` |
| `Permissions-Policy` | `camera=(self), microphone=(self), geolocation=(self), usb=(), serial=(), hid=(), midi=(), browsing-topics=()` | `SECURITY_PERMISSIONS_POLICY` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` when `APP_ENV=production`; never `preload` | `SECURITY_HSTS` (true/false overrides), `SECURITY_HSTS_INCLUDE_SUBDOMAINS`, `SECURITY_HSTS_MAX_AGE` |
| `Content-Security-Policy-Report-Only` | off | `SECURITY_CSP_REPORT_ONLY`, `SECURITY_CSP_REPORT`, `SECURITY_CSP_REPORT_SAMPLE_RATE` (0.1) |

`SECURITY_HEADERS_ENABLED=false` turns the middleware into a no-op.

- **Framing.** `'self'` because apps frame their own pages (Billing claim previews, CloudPCR QA review PDF,
  HR document preview, account terms, Reporting's document viewer). The only cross-origin embed is the public
  website framing the wiki's Unified University (`/university?embed=1`), so the **wiki** must set
  `SECURITY_FRAME_ANCESTORS=https://unified-solutions.io,https://www.unified-solutions.io` before enabling.
  SSO dashboard widgets are server-to-server JSON, and the Assistant drawer is a script on the app's own page,
  so neither is framed. SSO's trial welcome modal opens each app's sign-in in hidden frames (`SessionWarmUp`)
  only for its cookies; most apps already answer DENY at the edge today, so `'self'` changes nothing there.
- **Permissions.** Camera / microphone are used for chart photos, license and med scans, Billing's mail
  capture and dictation; geolocation by CAD, Fire hydrants, SSO dispatch and mobile web views. `payment` is
  left alone for Stripe (Billing, account).
- **HSTS and the Vapor apps.** HR, Truck-Checks and Fleet-Management run live in their Vapor `staging`
  environment, where `APP_ENV` is `staging`: set `SECURITY_HSTS=true` and `SECURITY_FORCE_HTTPS=true` there.
  `includeSubDomains` covers subdomains of the app's own host only (`*.cad2.unified-apps.com`), except on the
  website's apex `unified-solutions.io`, where it covers every subdomain: check that each one serves https
  before enabling it there, or set `SECURITY_HSTS_INCLUDE_SUBDOMAINS=false` for that app.
- **Report-only CSP.** `csp_report_only.directives` is a starting point covering Livewire / Alpine
  (`'unsafe-inline' 'unsafe-eval'`), Vite builds, Google Maps, Stripe, Sentry, PostHog, Google Fonts / Bunny,
  YouTube / Vimeo frames, websockets, and `{sso}` (the origin of `sso.base_url`, needed in `form-action`
  because sign-out posts then redirects to SSO). Report-only never blocks anything (and browsers ignore
  `frame-ancestors` in it, which is why framing has its own enforced header). Violations post to
  `POST /api/security/csp-report` (`sso.csp-report`, no session, throttle 120/min per IP, 204 always, 64 KB
  cap, up to 10 reports a request) and `sample_rate` of them become info `csp.violation` events with context
  `{blocked, directive}` only: the blocked host or keyword (`inline`, `eval`, `data`, `other`), never the
  page URL, referrer or script sample. Enforcing a CSP is a later, per-app step after tuning from the events.
- **Rollout order** (lowest risk first; deploy one, look at the app in a browser, then the next): CRM (staff
  only) → Reporting → Crew-Scheduling → Fire → CheckSheets → account → Transport-Portal → Drug-Tracking → SSO → CAD →
  Billing → CloudPCR → HR, Fleet-Management, Truck-Checks (Vapor: also the two env flags above) → wiki (needs
  `SECURITY_FRAME_ANCESTORS` first) → Public_Website last (apex HSTS). Turn on `SECURITY_CSP_REPORT_ONLY` per
  app after its headers have settled.

**Forced HTTPS URLs.** The service provider calls `URL::forceScheme('https')` at boot when
`security-headers.force_https` (`SECURITY_FORCE_HTTPS`) is true, or is unset and `APP_ENV=production`. This
does apply to every app on its next package update. It is safe on both hosts: every live app already answers
with https URLs (redirect `Location` headers checked 2026-11-23), so trusted-proxy detection works there and
signed-URL checks keep matching; forcing only covers URLs built without a forwarded scheme. Only wiki and
Bridge call `trustProxies()` explicitly; the rest rely on the host's defaults. An app served over plain http
in production would get https links: set `SECURITY_FORCE_HTTPS=false` there.

**CORS.** Eight apps (CloudPCR, Billing, HR, Fire, CheckSheets, Truck-Checks, Fleet-Management, account)
have `config/cors.php` with `supports_credentials => true` on `api/*` and
`allowed_origins` from `WIDGET_ALLOWED_ORIGINS` defaulting to `'*'`. With credentials on, Laravel answers
`'*'` by echoing whatever `Origin` asked, so any website can read credentialed `api/*` responses. Replace
the line with the package helper, which returns a named list (SSO's origin, the app's own, plus whatever
`WIDGET_ALLOWED_ORIGINS` lists) and drops `'*'`, wildcards and non-http(s) values:

```php
'allowed_origins' => \Unified\SsoClient\Security\CorsOrigins::allowed(
    env('WIDGET_ALLOWED_ORIGINS'),
    env('SSO_BASE_URL', 'https://sso.unified-apps.com'),
    env('APP_URL'),
),
```

Apps without a `config/cors.php` use Laravel's default (`'*'` without credentials) and SSO and CRM have
their own non-credentialed settings; none of those needs the change.

**Session cookies** are per-app env; recommended in production: `SESSION_SECURE_COOKIE=true`,
`SESSION_HTTP_ONLY=true` (Laravel's default), `SESSION_SAME_SITE=lax` (not `strict`: the SSO callback is a
cross-site top-level navigation and must see the session), `SESSION_ENCRYPT=true` (turning it on makes
existing unencrypted sessions unreadable, so everyone signs in once more; do it out of hours). Vapor apps also
keep `SESSION_DRIVER=database` and a distinct `SESSION_COOKIE` (DEV_GUIDELINES §12).

## Rotating CORE_APP_API_KEY (UNI-1777)

`CORE_APP_API_KEY` is one symmetric key shared by every app, and every app both sends it and checks it.
Changing it everywhere at the same instant is impossible, so the package's `ValidateCoreApiKey` accepts a
second key during a rotation window:

- `config('app.core_api_key')` (`CORE_APP_API_KEY`) is the current key. It is the only key the package
  ever **sends** (Assistant proxy, master data, trial checks, metrics, security events).
- `config('sso.core_api_key_previous')` (`CORE_APP_API_KEY_PREVIOUS`) is a second key that is
  **accepted** too, compared with `hash_equals`. Empty or unset never matches anything, including a request
  with no key or an empty one.
- A request that authenticates with the second key passes and records an info
  `internal_api.previous_key_used` security event (app key + route, nothing else in context), at most once
  per route per five minutes (cache-gated; a cache failure records anyway). A wrong key still records
  `internal_api.auth_failed`; a honeytoken still records the critical `honeytoken.api_key_used`.

This is a permanent rotation mechanism, not a compatibility shim (DEV_GUIDELINES §21).

**Prerequisite.** Every receiving endpoint must check the key through this middleware (or the same
two-key rule). App-local validators that compare only `CORE_APP_API_KEY` reject the second key and break
the rotation: as of 2026-11-23 these are the `core.api` aliases in CloudPCR, Billing, CAD, HR, Fire,
Crew-Scheduling, CheckSheets, Drug-Tracking, Truck-Checks, Fleet-Management and account (each points
at its own `App\Http\Middleware\ValidateCoreApiKey`), SSO's `ValidateInternalApiKey`, CloudPCR's
`OdataAccess`, and Reporting's inline check in `SavedReportController::importFromBilling`. The simplest fix
is pointing each app's `core.api` alias at `Unified\SsoClient\Middleware\ValidateCoreApiKey` and deleting
the local class. SSO signs the CRM drawer's channel token with the key (`CrmRealtime`) and CRM verifies it
with its own (`SupportChannelToken`): during the window a token minted by one side under the other key is
refused until both sides hold the same current key, so switch SSO and CRM back to back.

**Runbook.** Each app both sends and receives, so the new key must be accepted everywhere before anyone
sends it:

1. **Accept the new key everywhere.** On every app set `CORE_APP_API_KEY_PREVIOUS` = NEW and leave
   `CORE_APP_API_KEY` = OLD; redeploy (Laravel Cloud and Vapor both need a deploy for an env change to
   land). Every app still sends OLD, every app now accepts both. Expect no `previous_key_used` events yet.
2. **Switch every app to send the new key.** On every app set `CORE_APP_API_KEY` = NEW and
   `CORE_APP_API_KEY_PREVIOUS` = OLD; redeploy. Apps switched so far send NEW (accepted everywhere since
   step 1); apps not yet switched send OLD (accepted everywhere, and the receiver records
   `internal_api.previous_key_used`).
3. **Watch the events.** In SSO's security events, filter `internal_api.previous_key_used`. Each one names
   the receiving app and route that still saw OLD, which points at a caller that has not been switched (or
   is running with a stale config cache or an old queue worker). Fix those until no event has arrived for
   at least a day.
4. **Remove the previous key.** Unset `CORE_APP_API_KEY_PREVIOUS` on every app and redeploy. Optionally add
   OLD to `SECURITY_HONEYTOKEN_KEYS` afterwards, so any later use of the exposed key raises a critical
   event; only do that once step 3 has been quiet, or a missed straggler will page.
5. **Local development** gets its own dev-only key, never the production value: set a fresh random
   `CORE_APP_API_KEY` in every local `.env` (the same one across local apps, so they can still call each
   other) and keep it out of `.env.example`.

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
- `/Sites/sso-client-wt-assistant` — worktree on **`feature/unified-assistant`** (2026-10-30), paired with
  branches of the same name in `/Sites/sso` (the assistant service + internal API) and
  `/Sites/Crew-Scheduling` (first `AssistantToolProvider`). Ships the Unified Assistant contract, proxy,
  widget bundle and `<x-unified-assistant />`. Merge order: this package → SSO → Crew → the install
  sweep across every other app (`composer update unified/sso-client --prefer-dist` + mount the component).
- `/Sites/packages/unified/sso-client` — on **`feature/device-authorization`** (Phase 2 of the
  device-authorization program). Ships the `sso.device-lock` middleware, the
  `POST /sso/device/{challenge,verify,register}` handshake proxy, `DeviceGuard`, `DeviceSessionState`,
  the `sso_device_bypasses` table, `SyncsCompanyRoles::hasDeviceBypass()`, and consumption of the
  `device.revoked` webhook. Until that branch merges, every app's shipped package acks and ignores
  `device.revoked`, so revocation only blocks new logins. Go-live merge order is
  sso-client → SSO → CloudPCR (which currently pins the package branch and must be reverted to `@dev`).
