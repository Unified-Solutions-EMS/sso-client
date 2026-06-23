# Unified Solutions — Dev Team Guidelines

Living document. Conventions for how we build across the Unified Solutions platform. Everyone working in `/Sites/*` should follow these unless there's a deliberate, documented exception.

Owner: James Kenworthy. Amend via PR to this file when a pattern changes or something new is decided.

---

## 1. Platform map

- **SSO** (`/sso`) — hub. Users, companies, roles, OAuth (Passport), webhook dispatch. Laravel 13 + Filament 5 + Livewire 4.
- **CloudPCR** (`/cloudpcr`) — ePCR. Laravel 11 + Filament 3 + Livewire 3 + Alpine. Owns the legacy SQL Server connection.
- **HR** (`/HR`) — personnel system of record. Deployed on Vapor (no `pdo_sqlsrv`).
- **CAD** (`/CAD`) — dispatch.
- **Fleet-Management**, **Crew-Scheduling**, **Fire**, **CheckSheets**, **Drug-Tracking**, **Truck-Checks** — other downstream apps.

SSO is the source of truth for users + companies. Every other app is a consumer.

---

## 2. Auth and integration

- **User auth across apps:** OAuth2 Authorization Code via Passport in SSO. Apps use the `unified/sso-client` Composer package; verify its version is pinned in every app's `composer.json`.
- **Server-to-server:** Shared symmetric keys, not OAuth.
    - `CORE_APP_API_KEY` — same value in every app. Used to authenticate internal cross-app HTTP calls (e.g. SSO → CloudPCR's `/api/internal/*`).
    - `SSO_WEBHOOK_SECRET` — same value in every app. HMAC-signs every webhook SSO dispatches.
- **New internal endpoint checklist:**
    1. Route under `/api/internal/*` (or `/api/integrations/*` for long-standing integrations).
    2. Middleware that validates `X-API-KEY` / `Authorization: Bearer` against `CORE_APP_API_KEY`.
    3. Document the endpoint in the receiving app's CLAUDE.md and in `MIGRATION_TOOLS.md` if it's part of migration.

### 2a. Agency-status contract (Fin / coding-agent surface)

Every app exposes one shared endpoint that lets SSO ask "what's the state of company X inside your app?" The SSO MCP server composes responses from all 14 apps to give Fin and the coding agent a unified view of a customer.

- **Route (provided by `unified/sso-client`):** `GET /api/internal/agency-status/{ssoCompanyId}` — `CORE_APP_API_KEY` auth via the package's `ValidateCoreApiKey` middleware. Apps do not register the route themselves.
- **Per-app work:**
    1. Implement `Unified\SsoClient\Contracts\AgencyStatusProvider` in `App\Sso\<App>AgencyStatusProvider`.
    2. Bind it in `AppServiceProvider::register()`:
       `$this->app->bind(AgencyStatusProvider::class, <App>AgencyStatusProvider::class);`
    3. Confirm `config('app.core_api_key')` is wired to `CORE_APP_API_KEY` env.
- **Response shape:** `app_slug`, `is_active`, `last_activity_at` (ISO 8601), `active_user_count` (30-day), `app_version`, `health` (`ok` / `degraded` / `down` / `unknown` + `open_incidents`), `depends_on` (array of app slugs), `extension` (app-specific blob). Use the `AgencyStatusResponse::active(...)` and `::notProvisioned(...)` factories — don't hand-construct.
- **`is_active=false` is a normal answer**, not an error. If the company has no presence in this app, return `AgencyStatusResponse::notProvisioned(...)` with HTTP 200.
- **`depends_on` matters.** Truck-Checks depends on Crew-Scheduling and Fleet-Management; CloudPCR depends on HR and Billing; etc. Declare it so the SSO MCP can auto-pull related apps' status when Fin asks about one. Don't lie about dependencies — under-declared means Fin gives partial answers, over-declared means wasted fanout.
- **HIPAA:** the `extension` blob may contain PHI/PII (patient counts, run summaries, etc. are fine; patient identifiers, run sheet text, attachments are not). Apps return raw data; the SSO MCP server is the redaction boundary before anything reaches Fin or an external coding agent. **Don't redact at the app level** — that loses information SSO might legitimately need.
- **Tenancy:** see §4a — looking up the local company by `sso_company_id` is the canonical "authoritative source" case. `withoutGlobalScopes()` is allowed as long as the very next clause filters by `company_id`.
- **Vapor:** the endpoint is a sync MySQL read with no `pdo_sqlsrv` requirement, so it works fine on every app regardless of host.

### 2b. Roles & permissions (RBAC)

Platform-wide RBAC has two axes. **SSO is authoritative for identity, role assignment, and the role-name catalog; each app owns what a role *means*.**

- **Universal role-name catalog** lives in SSO (`roles` rows with `company_id IS NULL`: Admin, User, Billing, …). Managed by Global Admins in `/admin`. Apps do not invent role names; agencies do not either (yet).
- **Per-app assignment:** SSO stores one role per `(user, company, application)` in `user_application_roles`. Agency admins assign these in SSO's `/system` user manager (per-app selectors + "set all" shortcut). So a user can be Admin in HR but User in CloudPCR.
- **Role → permission mapping is app-owned.** Each app maps role names → Spatie permissions in its own code (`database/seeders/RolesAndPermissionsSeeder.php` from an app-local `app/Authorization/RoleMap.php`), right next to the `$user->can('…')` checks it protects. SSO never stores permissions.
- **Staff roles** (Global Admin, Liaison, Support, Billing Ops, Read-only) are global, stored in SSO (`staff_roles` / `user_staff_roles`), assigned to any user in `/admin`. They **replace email-domain gating**: `canAccessPanel()` and any "is this Unified staff?" check use `isStaff()` / `hasStaffRole()`, never an `@unified-solutions.io` string match. **Staff role ≠ cross-tenant data access** — it grants admin-panel reach only; it must NOT bypass `HasCompanyScope` in user-facing HTTP paths (see §4a).

**Per-app consumer rules (`unified/sso-client`):**
- Set `SSO_APP_SLUG` (or `config('sso.app_slug')`) to this app's registry slug.
- `use Unified\SsoClient\Concerns\SyncsCompanyRoles` on the `User` model (requires Spatie `HasRoles`). It provides `loadRolesForCompany()`, `hasRoleInCompany()`, `companyRoleNames()`, and staff helpers `isStaff()/hasStaffRole()/isGlobalAdmin()` reading the package-managed `users.staff_roles` column. Delete any hand-rolled copies.
- Call `$user->loadRolesForCompany($companyId)` after login / company switch / impersonation so Spatie answers for the active tenant.
- **Check permissions, not role names** in new code: `$user->can('schedule.manage')`, not `hasRoleInCompany('Admin', …)`. Role names are an assignment detail; permissions are the contract.
- `/api/user` sends `companies[].roles` (this app's role names), `companies[].appRole`, and top-level `staffRoles`. The package syncs roles into `company_user_roles` and staff roles into `users.staff_roles`. The `['Admin','User']` whitelist is gone — any role name SSO sends flows through.

---

## 3. Webhooks

- SSO is the only app that dispatches webhooks. Downstream apps implement handlers.
- Every webhook is HMAC-signed with `SSO_WEBHOOK_SECRET`. Downstream apps MUST verify the signature.
- In CloudPCR-style apps, add new event handlers to `AppWebhookHandler::handle()` via the existing `match` expression. Keep each handler method small; move import logic into a service.
- When you add a new event name, add it to `MIGRATION_TOOLS.md` under "Webhook events".

---

## 4. Multi-tenancy (companies)

- Every tenant-scoped table has `company_id`. Always filter by it.
- Use the existing `HasCompanyScope` trait (CloudPCR, Fire, Billing) or equivalent when adding new Eloquent models. **The trait is the floor, not a nice-to-have.** Any new model with `company_id` must use it before being merged.
- For legacy IDs:
    - SSO: `companies.legacy_tenant_id`
    - CloudPCR: `companies.core_tenant_id` + `companies.sso_company_id` (distinct from legacy)
    - Never conflate the two. SSO's ID ≠ legacy ID.

### 4a. The `withoutGlobalScopes()` rule (HIPAA boundary)

`Model::withoutGlobalScopes()` removes the `HasCompanyScope` filter and turns the next query into a cross-tenant query. That's a leak waiting to happen. Two-rule discipline:

1. **In user-facing HTTP code (controllers, Livewire components, Blade views, web routes), do not use `withoutGlobalScopes()`.** Use `Model::query()->find(...)` and let the scope filter by `session('selected_company_id')`. A cross-tenant id will resolve to null / 404, which is the correct behavior.
2. **In webhook / internal-API / job / console code, `withoutGlobalScopes()` is allowed BUT the very next thing you write must be `->where('company_id', $authoritativeCompanyId)` or equivalent.** "Authoritative" means the company id came from a verified source — the HMAC payload, the import batch, the parent record — not user input. If you can't name the authoritative source, you have a leak.

If you need to bypass `HasAgencyScope` (e.g. to load a claim regardless of which agency is in the picker), use the named form: `->withoutGlobalScopes(['agency'])`. That preserves company isolation.

### 4b. Fail-closed scope behavior (Billing)

Billing's `CompanyScope` is hardened to fail closed during HTTP requests:

- **Authenticated user with `selected_company_id`** → scope filters by that company.
- **Authenticated user with no `selected_company_id`** → scope returns ZERO rows. Picking a company is a precondition to seeing any tenant data.
- **No authenticated user (webhook, internal API, public route)** → scope is a no-op. The controller is responsible for filtering by the authoritative company id from the request payload (token, HMAC, etc.).
- **CLI / queue / unit-test contexts** (no bound HTTP route) → scope is a no-op. Jobs and commands manage their own scope.

If you copy Billing's scope into another app, copy this contract too. Anything looser is a leak.

### 4c. Defense tests

Each app should keep a `tests/Feature/Tenancy/CrossTenantIsolationTest.php` that:

- Seeds two companies, two users, and one of each PHI/tenant-scoped model in each.
- Asserts every public dashboard route logged in as User A only renders Tenant A data.
- Asserts every model-bound action route returns 404 (not 403, not 200) when User A tries to act on a record id that belongs to Tenant B.

Treat this test as load-bearing: never delete or weaken it; if it fails, fix the code, not the test.

---

## 5. Admin vs agency UI

- `/admin` routes in any app are for **Unified Solutions staff only**, not agency staff.
- Agency-level management (users, settings for one company) goes in the app's regular Filament panel / Livewire pages behind agency auth, never under `/admin`.

---

## 6. Filament

- Match the Filament major version of the app you're editing. Do not upgrade silently.
- Namespace reference (Filament 5):
    - Form fields: `Filament\Forms\Components\`
    - Layout: `Filament\Schemas\Components\`
    - Actions: `Filament\Actions\` (never `Filament\Tables\Actions\` etc. in v5)
    - Icons: `Filament\Support\Icons\Heroicon` enum
- Use `static::make()` factories and `Closure` configuration. Use `Get $get` for conditional visibility.
- Always call `php artisan make:` to scaffold resources/pages — don't hand-write.
- For relation managers, add `searchable()` and `sortable()` to every column that maps to a real column. For computed / relationship-aggregate columns, implement the `searchable(query: fn)` and `sortable(query: fn)` callbacks rather than dropping them.

---

## 7. CloudPCR-specific frontend conventions

- **Never use native `<input>` / `<select>` with `wire:model`** for ePCR / CAD data fields. Use `x-pcr-text` and `x-pcr-select` components. These wrap the styling, validation, and Alpine behavior we rely on.
- **Never use `x-searchable-select`** for PCR fields — deprecated in favor of `x-pcr-select`.
- All forms use the custom `x-nemsis.*` modal/field components, not Filament's native forms.
- City fields store GNIS `feature_id` (integer kept as string). County fields store 5-digit ANSI/FIPS.
- Alpine + Google Maps: keep map instances in closure variables, NOT in Alpine reactive `x-data`. Maps break when proxied.

---

## 7a. ePCR form parity (web ↔ mobile)

This rule is about the **ePCR form specifically** — not other CloudPCR forms (settings, admin, etc.). The ePCR form lives in two places: the web app (`/Sites/cloudpcr`) and the Unified Mobile app (`/Sites/Unified-Mobile`). They are deliberately kept consistent but **not** auto-synced — some changes are genuinely platform-specific.

- Whenever you change an **ePCR form** field, layout, validation rule, or option set in one of these apps, **ask the user whether the same change should be applied to the other form** before considering the task done.
- Do **not** apply the change to the other app automatically. The user decides per change.
- This applies in both directions (web → mobile and mobile → web).

---

## 7b. Crew-Scheduling-specific notes

- **Stack:** Laravel 11 + Filament 3 + Livewire 3 + Alpine + FullCalendar 6 (`@fullcalendar/resource-timeline` is the primary scheduling view). Deployed on **Vapor** (`laravel/vapor-core`, `vapor.yml`).
- **Roles are per-company, not global.** Spatie `HasRoles` is present, but role assignments live in the `company_user_roles` pivot. After login / company switch you MUST call `$user->loadRolesForCompany($companyId)` to hydrate Spatie's role cache for the active tenant. Checking `$user->hasRole(...)` without this will give wrong answers after impersonation or company switch.
- **Session-scoped tenant.** The active tenant is `session('selected_company_id')`. All tenant-scoped queries must read from this (see `Preference::where('company_id', $companyId)` pattern in `routes/web.php`). Impersonation sets `impersonator_id` + `impersonated_company_id` in session — don't break those.
- **Schedule UIs are FullCalendar, not Filament tables.** `scheduling`, `new-admin-scheduling`, `myschedule`, `timeoff`, `time-off` views all render FullCalendar and hit JSON endpoints on `App\Http\Controllers\*Controller` (e.g. `ShiftController@index`, `MyScheduleController@index`, `TimeOffCalendarController@events`). Do not convert these to Filament resources.
- **Integration endpoints other apps hit** (all under `routes/api.php`, `core.api` middleware = `CORE_APP_API_KEY`):
    - `GET /api/integrations/truck-checks/crew-roster` and `/shift-templates` — consumed by Truck-Checks.
    - `GET /api/integrations/crew-scheduling/week` — generic weekly schedule feed.
    - `POST /api/integrations/crew-scheduling/punch`, `GET /punch-state` — punch clock API.
    - `POST /api/sso/punch` — HMAC-signed from SSO, **not** `core.api`. Uses `SSO_WEBHOOK_SECRET`-style signature; no auth/CSRF middleware.
- **Magic login:** `/company-login/token` (`CompanyMagicLoginController`) is how the SSO dashboard drops a user into a specific company. Don't route-guard it with `auth` — it *creates* the auth.
- **Trial tenants:** `TrialDataSeeder` / `TrialDataPurger` + `RunTrialSeeder` / `RunTrialPurger` jobs manage demo data. If you add new tenant-scoped tables, update the purger so trial resets stay clean.
- **Metrics:** `Services/Metrics/MetricClient` + `Jobs/SendMetricToUnified` + `TrackSessionMetric` middleware push usage metrics to the Unified hub. Don't inline new metric HTTP calls — extend `MetricClient`.

---

## 8. NEMSIS exports

- Never emit an `<?xml ?>` declaration on EMS/DEM XML output. NEMSIS consumers reject it.
- Use the `NEMSIS-v3.5.0-EMS-BILLING` template for full exports (includes signatures and file attachments).
- `dem_personnel.is_ems_personnel = false` rows are non-clinical attendants (volunteer-FD crew, dispatch, billing). They are selectable on the PCR crew picker for visibility but **must be excluded** from both the DEM `dPersonnel` registry and the per-PCR `eCrew` section. Don't reintroduce them into either export — see `cloudpcr/app/Services/Nemsis/DemXmlBuilder.php` and `EmsXmlBuilder::buildRepeatingSection()`.

---

## 9. Legacy migration

See `MIGRATION_TOOLS.md` for what exists. When adding a new migrator:

- Order rule: **SSO users before anything else.** Downstream apps link to `sso_id`; creating personnel/records before the SSO user exists creates orphans.
- Every importer that creates records owned by a user must either:
    - Listen to `user.created` / `user.updated` webhooks, or
    - Be runnable after-the-fact as a healer command (see `sso:relink-users`).
- Importers MUST be idempotent. Re-running with the same input should not create duplicates or corrupt state.
- Legacy SQL Server (`legacy_pcr` connection) lives only in CloudPCR. If another app needs legacy data, go through CloudPCR's `/api/internal/*` endpoints — do not add the SQL Server connection to other apps. HR is on Vapor and cannot add `pdo_sqlsrv` anyway.

---

## 10. Code style

- PHP 8.4 in SSO. PHP 8.2+ across the stack. Always declare return types and parameter types.
- Use `php artisan make:` for every new file type that has a generator.
- Form validation: dedicated Form Request classes, not inline `$request->validate()` in controllers. Check sibling Form Requests for array vs string rule convention per app.
- Eloquent over `DB::` for reads. `Model::query()` over raw queries. Eager-load to avoid N+1.
- `env()` is forbidden outside config files. Always `config('...')`.
- Never `git commit --no-verify` or skip hooks unless James explicitly says so.
- Run `vendor/bin/pint --dirty --format agent` before considering a PHP change done.

---

## 11. Testing

- Feature tests preferred over unit. `php artisan make:test --phpunit {Name}` in each app.
- For Filament: authenticate first, then `Livewire::test()` / `livewire()`.
- Do not remove or rename existing tests without approval. They're load-bearing.
- Before declaring a change done, run the relevant targeted test file, not the full suite.

---

## 12. Environment / deploy awareness

- **SSO** — Laravel Cloud.
- **CloudPCR** — Laravel Cloud. `pdo_sqlsrv` available.
- **HR** — Vapor. `pdo_sqlsrv` NOT available. Use API calls, not direct SQL Server access.
- Never add a dependency that changes deploy requirements without raising it.

---

## 13. What not to do

- Don't add feature flags / backwards-compat shims. Change the code.
- Don't add error handling or validation for things that can't happen. Trust internal guarantees.
- Don't leave commented-out old code. Delete it.
- Don't write code comments that explain *what* the code does — good names and small functions handle that. Comment only when the *why* is non-obvious.
- Don't create new markdown docs without an explicit ask.

---

## 14. When a rule conflicts with reality

If you find a convention here that's wrong, stale, or has an undocumented exception: fix the code AND update this doc in the same PR. Flagging it in a PR description is better than leaving the wrong rule in place.
