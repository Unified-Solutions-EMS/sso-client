# Unified Solutions — Dev Team Guidelines

Living document. Conventions for how we build across the Unified Solutions platform. Everyone working in `/Sites/*` should follow these unless there's a deliberate, documented exception.

Owner: James Kenworthy. Amend via PR to this file when a pattern changes or something new is decided.

**Canonical copy:** `docs/DEV_GUIDELINES.md` in the `Unified-Solutions-EMS/sso-client` repo. Every app repo carries a synced copy at its root (`DEV_GUIDELINES.md`, loaded from the repo's `CLAUDE.md`) so agents working from a single repo inherit these rules without the rest of the workspace. After editing the canonical copy, re-sync the app-repo copies (James's workspace has `/Sites/sync-dev-guidelines.sh` for this). On app-specific detail the repo's own `CLAUDE.md` wins; on platform-wide policy this file wins.

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
    - **Documented exception — clinical-roster import hygiene.** When keeping Unified internal accounts out of an *agency's clinical roster* (NEMSIS `dem_personnel`) during legacy/Core personnel imports, matching the internal email domains **is** allowed, because `staff_roles` is unreliable for legacy-imported users (it is only populated once SSO syncs a staff-role assignment, which many internal accounts never had). Use the single shared helper `User::isInternalStaffEmail()` (matches `@unified-solutions.io` / `@cloudpcr.net`), not ad-hoc `str_ends_with` calls. This exception is **import-time roster hygiene only** — it must never be used for access/authorization decisions, which still go through `isStaff()`.

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
- **Prefer impersonation over new `/admin` cross-tenant resources.** When staff need to look something up *for* a customer (support calls, "find X for this agency"), use the existing impersonation flow (SSO `/admin/companies/{id}` → Impersonate) and operate through the normal agency UI. Only build an `/admin/<entity>` resource when the task genuinely can't be done as the customer (platform config, cross-customer reports, billing reconciliation).

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

- **Stack (post-cutover 2026-08-05, PR #9):** Laravel 13 + Filament 5 (staff `/admin` only) + Inertia + React 19 + Octane + FullCalendar 6. Deployed on **Laravel Cloud** (push-to-deploy on `main`). The old Livewire/Alpine UI and Vapor deployment (`vapor.yml`, project `schedule`) are gone — do not resurrect either.
- **Roles are per-company, not global.** Spatie `HasRoles` is present, but role assignments live in the `company_user_roles` pivot. After login / company switch you MUST call `$user->loadRolesForCompany($companyId)` to hydrate Spatie's role cache for the active tenant. Checking `$user->hasRole(...)` without this will give wrong answers after impersonation or company switch.
- **Session-scoped tenant.** The active tenant is `session('selected_company_id')`. All tenant-scoped queries must read from this (see `Preference::where('company_id', $companyId)` pattern in `routes/web.php`). Impersonation sets `impersonator_id` + `impersonated_company_id` in session — don't break those.
- **Schedule UIs are FullCalendar (React), not Filament tables.** The Inertia pages render FullCalendar and hit the preserved JSON feed controllers (`ShiftController@index`, `MyScheduleController@index`, `OpenShiftsController`, `TimeOffCalendarController@events`). Do not convert these to Filament resources.
- **Integration endpoints other apps hit** (all under `routes/api.php`, `core.api` middleware = `CORE_APP_API_KEY`):
    - `GET /api/integrations/truck-checks/crew-roster` and `/shift-templates` — consumed by Truck-Checks.
    - `GET /api/integrations/crew-scheduling/week` — generic weekly schedule feed.
    - `POST /api/integrations/crew-scheduling/punch`, `GET /punch-state` — punch clock API.
    - `POST /api/sso/punch` — HMAC-signed from SSO, **not** `core.api`. Uses `SSO_WEBHOOK_SECRET`-style signature; no auth/CSRF middleware.
- **Magic login is gone (2026-08-05).** `/company-login/token` predated SSO and was removed after a production check found no real usage (one self-test token from 2025-03; no producer anywhere — SSO never minted these). Entering a company happens through the normal SSO OAuth redirect with an `intended` URL.
- **Trial tenants:** `TrialDataSeeder` / `TrialDataPurger` + `RunTrialSeeder` / `RunTrialPurger` jobs manage demo data. If you add new tenant-scoped tables, update the purger so trial resets stay clean.
- **Metrics:** usage metrics go through the `unified/sso-client` package — `Unified\SsoClient\Metrics\Facades\Metrics` for domain emits (pass `local_company_id` / `local_user_id` in context; the package translates to SSO ids via `companies.sso_company_id` / `users.sso_id`) and `Unified\SsoClient\Metrics\Middleware\TrackSessionMetric` appended to the `web` group for session heartbeats. Config is env-driven (`METRICS_ENDPOINT`, `METRICS_APP_KEY` = the SSO registry slug, auth via `CORE_APP_API_KEY`). The old app-local `Services/Metrics/MetricClient` stack is deleted — don't reintroduce it. This applies platform-wide, not just Crew: every app reports metrics, and SSO's nightly `RollupMetricEvents` job feeds the Reporting `/admin` usage grids.

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

- **Laravel Cloud:** SSO, CloudPCR (`pdo_sqlsrv` available), Billing, CAD, Fire, Reporting, CheckSheets, Drug-Tracking, Crew-Scheduling, account, wiki, Transport-Portal, Public_Website. Pushing the deploy branch **is** the deploy (push-to-deploy) — a push to `main` on these repos triggers a production build.
- **Vapor:** HR, Truck-Checks, Fleet-Management, University. (Crew-Scheduling moved to Laravel Cloud at its 2026-08-05 React cutover.) `pdo_sqlsrv` NOT available — use API calls, not direct SQL Server access. **HR, Truck-Checks, and Fleet-Management run out of their Vapor *staging* environments** (`*-staging.unified-apps.com`) — staging IS live; do not run `vapor deploy production` for them, and treat `vapor deploy staging` as a real production deploy requiring explicit go-ahead.
- **Vapor env rules:** every Vapor environment must set `SESSION_DRIVER=database` and a distinct `SESSION_COOKIE` name. Vapor silently defaults to the cookie session driver when unset, and the sso-client session payload overflows cookie limits → SSO redirect loops and "Request Header Or Cookie Too Large" 400s.
- **Destructive Vapor CLI commands need an explicit ask first.** `vapor database:password` ROTATES the password (there is no read-only show). `database:delete/scale/upgrade/restore`, `database:user/drop-user`, `jump:delete` are all destructive. Safe: `database:list/show/metrics/users`, `jump:list`, `team:current`. Treat unfamiliar Vapor subcommands as destructive until proven otherwise.
- **Verify which database you're pointed at before any write.** Convention across SSO / Billing / Reporting / CloudPCR: the default `mysql` connection is LOCAL; a named `mysql_prod` connection (env prefix `DB_PROD_*`) is for read-only prod inspection (plus the narrow changelog dual-write case, §17). Before migrations, seeders, or tinker writes, echo `config('database.default')` + host and stop if it isn't clearly local. Never flip the default connection to prod.
- **Sentry:** backend `SENTRY_TRACES_SAMPLE_RATE` is 0.1 (high-traffic apps) / 0.2 (others) with `SENTRY_TRACE_VIEWS_ENABLED=false` — never set 1.0. `config/sentry.php` nulls the DSN when `APP_ENV` is local/testing; keep that guard. CloudPCR and Billing have custom `traces_sampler` classes for chatty transactions.
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

---

## 15. Git workflow

- **Detailed multi-line commit messages.** What changed, why, cross-app coordination notes ("companion change in CloudPCR: …"), and anything subtle a reader needs months later (new env vars, migrations, packages). Git history doubles as the project timeline and changelog source. Trivial single-file tweaks can stay short.
- **Push every commit immediately**, same branch, same repo. An unpushed commit is unreleased work — CI and deploys hang off the remote. If push fails, report the exact error and stop; never retry with `--force`/`--force-with-lease` unless James explicitly asks. Never force-push `main`.
- **Never pipe `git push` through anything** (`| tail` returns tail's exit code, masking a rejected push). Redirect to a file: `git push > push.log 2>&1; echo "exit: $?"`. After background pushes, verify with `git ls-remote origin <branch>` before reporting success.
- **A running pre-push suite is a repo lock.** The hook tests the working tree, not the pushed commit — no edits, test runs, or commits in that repo until the push finishes.
- **Check `git branch --show-current` before committing.** Working trees often sit on feature branches; a "push to main" fix committed to a feature branch has to be cherry-picked back out.
- **Red mains:** several repos have pre-existing pre-push failures on a clean `origin/main` (see each repo's `CLAUDE.md` for the current list). Protocol: run your own change's targeted tests green, confirm the failing tests/phpstan errors reproduce on plain `origin/main` (i.e. they're pre-existing and unrelated), then push with `--no-verify`. That verification step is what authorizes `--no-verify` — never skip hooks just for speed (§10 still stands). Don't fold red-main cleanup into unrelated PRs.
- **Git worktrees need a real `composer install`.** A symlinked `vendor/` resolves `App\` to the *other* checkout's `app/` (Composer hardcodes `$baseDir`), so your worktree edits are silently ignored. `.env` and `public/build` may be symlinked; `vendor` may not.

---

## 16. QA tooling

Every app carries the same stack: Larastan (PHPStan level 5) + Rector + Lefthook, wired as composer scripts `lint`, `lint:test`, `analyse`, `refactor`, `refactor:fix`, `ci`.

- Legacy debt is snapshotted in `phpstan-baseline.neon`. New code must not add errors — fix them, don't baseline them. After refactors that shift line numbers, regenerate the baseline (`vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon --memory-limit=2G`) and confirm your own files contribute zero raw errors.
- Lefthook: pre-commit runs Pint on staged files; pre-push runs PHPStan + the test suite.
- **Rector must keep skipping the model-attribute rules** (`FillablePropertyToFillableAttributeRector`, `HiddenPropertyToHiddenAttributeRector`, `AppendsPropertyToAppendsAttributeRector`, `MigrateToSimplifiedAttributeRector`). Keep classic `$fillable` arrays and `getXAttribute()` accessors — the PHP-attribute style needs Laravel 12+ and breaks Larastan property inference.
- **Filament smoke tests:** every new or non-trivially modified Filament resource gets a feature test (`tests/Feature/Filament/{Resource}SmokeTest.php`) that authenticates as staff and asserts index, create, and edit (with a seeded record) all return 2xx, plus a no-data variant for embedded lists. Render-time wiring bugs (undefined Blade vars, broken Livewire embeds) are invisible to unit tests.

---

## 17. Changelog (lives in SSO)

Public changelog: SSO `/changelog`, admin CRUD `/admin/changelog-entries`, model `App\Models\ChangelogEntry`, types NewFeature / Enhancement / Fix.

- **Ask before logging** at the end of implementation work; only user-visible changes qualify. Never log admin-panel-only work, changelog-system meta-work, refactors, tests, or docs.
- **Classification:** if the work lives inside an existing app/panel/workflow it's an `enhancement` — `new_feature` is reserved for a genuinely new capability the user has to mentally locate ("there's now a Stryker tab"). When in doubt, `enhancement`.
- **Consolidate by ISO week:** before inserting, look for a current-week entry with the same `application_id` + `feature` slug and update it instead of creating a duplicate. The changelog reads as a weekly digest per feature, not a commit log.
- **Customer voice:** written for agency admins and EMS users. No internal concepts (tiers, validators, services), no dev jargon, use on-screen page names. Platform-driven behavior is "set by your state" / "managed by the platform".
- **Dual-write local + prod.** Every entry is written to the default (local) connection AND mirrored to `mysql_prod`. **Resolve `application_id` by slug per connection** (`Application::on($conn)->where('slug', …)->value('id')`) — IDs differ between local and prod. Use `updateOrCreate`-style idempotent writes. On `mysql_prod`: single-row writes only — never delete, truncate, mass-update, migrate, or seed.

---

## 18. Customer-facing content

Applies to UI copy, wiki articles, training/sales scripts, marketing pages, support docs, and the changelog.

- **No AI-sounding prose.** Customer- and public-facing writing follows the anti-slop rules: no banned filler vocabulary (delve, leverage, seamless, robust, comprehensive…), no "not just X but Y" constructions, varied sentence length, active voice, real names and numbers, no fabricated stats.
- **No developer jargon** in end-user content: no cron/endpoint/API/middleware/queue/database/repo talk — plain phrases the customer would say. Domain vocabulary (chief complaint, vitals, AR aging, hose lay) is fine.
- **No NEMSIS element IDs in UI labels** — "State ID", not "dAgency.01". Element IDs are acceptable only where they're the domain language (validation findings, schematron rule builder, XML editors), always paired with labels.
- **Never narrate tenant isolation** ("scoped to your agency", "you only see your own data") anywhere — it's the assumed floor, and saying it aloud creates doubt. Refer to "your data" and move on.
- **Em dashes are banned in rendered UI copy only** (labels, buttons, modals, errors — anything a customer sees in the browser). They're fine in docs, scripts, commits, and comments.
- **Logo naming:** `logo-light.png` = for light backgrounds (dark glyphs); `logo-dark.png` = for dark backgrounds (light glyphs). The suffix names the background, not the glyph color.
- **Script/article generators are binding:** training scripts follow `TRAINING_SCRIPT_GENERATION_PROMPT.md`, sales scripts follow `SALES_SCRIPT_GENERATION_PROMPT.md` (3:00 cap), wiki articles follow the wiki repo's `ARTICLE_GENERATION_PROMPT.md` (these live in James's workspace root and the wiki repo respectively). After changing wiki article data, always run the app seeder (`php artisan db:seed --class={App}Seeder --force`) — the data file alone doesn't update the rendered wiki.

---

## 19. Platform data semantics

- **Timezone discipline:** before touching any datetime code, establish how that specific column is stored — UTC-converted or naive-as-entered — by reading the write path, then match the whole pipeline. UTC-stored → serialize with offset and convert for display; naive-stored → serialize WITHOUT offset (`format('Y-m-d\TH:i:s')`) and never run through tz helpers. Verify with a round-trip (enter a time, read back the same wall-clock time).
- **Company timezone is SSO-owned** (`companies.timezone` on SSO, propagated via sso-client on login sync + `company.updated` webhook). Apps must NOT ship their own timezone selector; they read the synced local `companies.timezone` column.
- **Qualifications ≠ certifications — never merge or FK them.** Certification = externally-issued credential the person HAS (license, expiration, NEMSIS dPersonnel.24). Qualification = what they're PERMITTED to do in the apps at this company (drug checkout, shift eligibility, dPersonnel.38). App gates check qualifications; credential tracking lives on certifications.
- **SSO is authoritative for trial status** (`companies.status === 'trial'`; endpoint `GET /api/internal/companies/trials`, `CORE_APP_API_KEY` auth). Never identify trials from a downstream app's local `is_live` flag — it's also false for prospects/suspended, and stale in CloudPCR. Trial-seed commands must fail closed if SSO is unreachable.
- **Internal demo/test tenants pollute prod data.** Tenants matching `Gotham*`, `Metro Fire`, `Unified*`, `*Demo*`, `*Test*`, `*Liaison*`, and a set of seed-fixture "River Fire & Rescue"-style names are internal — exclude them from any customer/usage/migration analysis, and surface borderline names as "uncertain" rather than guessing. The maintained catalog lives in James's memory/notes; ask when unsure.
- **"Migrated" ≠ live.** Migration (legacy data imported), cutover (customer switched), and the customer call (scheduled around cutover) are three distinct steps. Never generate customer outreach lists keyed off migration status.

---

## 20. Cross-app UI conventions

- **Canonical app shell** for Inertia + React apps: shared `AppLayout` (h-12 gray-900 top bar, brand-500 avatar, "Back to Unified" + sign-out dropdown), ten `--color-brand-*` CSS variables, and the canonical `HandleInertiaRequests::share` shape (`auth.user{id,name,email,initials,is_app_admin}`, `company`, `selected_company_id`, `sso.base_url`, `flash`). Reference implementations: Reporting (richest) and Fire. Land shell changes in Reporting first, then propagate.
- **DevExtreme DataGrid for every data table** in React apps — never hand-rolled `<table>` markup. Fleet's `resources/js/Components/DataTable.tsx` wrapper is the pattern to mirror. Version caution: the license historically covers 23.1.x, but 23.1 crashes under React 19 — React 19 apps run 23.2 (W0019 console warning). Confirm license coverage with James before pinning a new app.
- **Form fields:** CloudPCR/CAD Blade surfaces use `x-pcr-text` / `x-pcr-select` (§7); React apps (Reporting, etc.) use the `PcrText` / `PcrSelect` / `PcrMultiSelect` components from `@/Components/PcrFields` — never bare native inputs. Extend the shared component rather than dropping back to native. Note `PcrSelect` in Reporting is a modal combobox, not a dropdown.
- **Alpine + Google Maps:** map instances live in closure variables, never Alpine reactive `x-data` (proxying breaks maps). With a Google `mapId`, inline `styles` are ignored.

---

## 21. Cross-app integration gotchas

- **Webhook receivers must live in `routes/api.php`.** A `POST /api/sso/provision` route registered in `routes/web.php` inherits CSRF, every HMAC-signed delivery 302s to login, and (historically) got logged as "delivered". If an app must keep it in web routes, CSRF-exempt it in `bootstrap/app.php`. Healthy endpoint check: `curl -X POST …/api/sso/provision -H 'X-SSO-Signature: bogus'` should return 403 JSON, not a 302.
- **`CORE_APP_API_KEY` is the only cross-app secret.** Never introduce per-feature tokens (`<FEATURE>_TOKEN`) — mount receivers under the existing internal-API-key middleware and send `Http::withToken(config(...))`.
- **sso-client package updates:** apps install `unified/sso-client` from GitHub. `composer.lock` must reference the GitHub dist (not a path repo) for Vapor/Cloud deploys. Package change flow: push package → `composer update unified/sso-client --prefer-dist` in each consuming app.
- **SSO dashboard widget setup checklist:** app's `SSO_WEBHOOK_SECRET` matches the SSO `applications.webhook_secret` row; `config/sso.php` has `webhook_secret` + `dashboard_provider`; installed sso-client version includes the `/api/sso/dashboard` route. Keep widget payloads small — an unbounded widget feed once broke the SSO dashboard.

---

## 22. Query performance

- **OR-of-`whereExists` is a MySQL planner trap.** Multiple `whereExists` branches OR'd together on a non-trivial base query can degenerate catastrophically (observed: <500ms per branch alone → 61s combined). Instead: pluck the bounded candidate ID set first, query each branch with `whereIn`, merge and `unique()->count()`. Triage rule for a slow metric: time each branch alone vs combined; if combined ≫ sum of parts, it's this trap.
- Eager-load to avoid N+1 (§10 stands); for aggregate dashboards prefer bounded ID-set queries over clever single-statement joins.

---

## 23. Work intake & flow (Linear + Sentry)

Task tracking lives in **Linear**, team `Unified-solutions` (issue prefix `UNI-`). Error intake comes from **Sentry**, org `unified-solutions`, one project per app.

- **States:** Triage → Backlog → Todo → In Progress → In Review → Done (plus Canceled / Duplicate). Working flow: pick from Todo → move to In Progress → implement → open PR → comment the PR link on the issue → move to In Review. Done only after merge (and deploy verification where relevant).
- **Labels:** one label per app (which repo the issue belongs to) + `Bug` / `Feature` / `Improvement`. Sentry-imported issues also carry the `Sentry` label. When updating labels programmatically, read the existing set first — label writes REPLACE the whole set.
- **Branch names:** use the Linear issue's `gitBranchName` so PRs auto-link to the issue.
- **Sentry short-ID dedupe key:** imported issues have the Sentry short ID in parentheses at the END of the Linear title, e.g. `Undefined variable $x in ClaimController (CLOUDPCR-4Y2)`. That suffix is the dedupe key for the daily triage sweep — never strip it when retitling.
- **Sentry is read-only for agents.** Never resolve, assign, or modify Sentry issues; state lives in Linear.
- **Regression rule:** a Done/Canceled Linear issue is reopened only on evidence — Sentry events with timestamps NEWER than the fix's completion/deploy date. Older events are pre-fix noise.
- **Never delete Linear issues** (move to Canceled); never rewrite another author's issue description — add comments.
- **Demo-tenant noise:** internal demo/test tenants (§19) must not inflate an error's priority — check whose data the events belong to before escalating.
- **PR contents:** detailed body (what changed, why, risk, test evidence), Linear issue link, and any item on the human-approval list (prod data, deploys, dependency changes, test deletions, ePCR parity mirroring) FLAGGED in the description rather than acted on.
- After user-visible work: offer a changelog entry (§17). If the change alters behavior documented in the wiki, update the wiki article data + reseed (§18) or flag it in the PR.

---

## 24. Definition of done

A change is done when every line below holds. This is the review bar for agent- and human-authored PRs alike.

- [ ] Repo `CLAUDE.md` + this file read; deviations justified in the PR
- [ ] Targeted feature tests green (not just the happy path you wrote); new behavior has test coverage; Filament resources touched → smoke test updated (§16)
- [ ] `vendor/bin/pint --dirty` clean; `composer analyse` adds zero errors over the baseline
- [ ] Tenancy: no `withoutGlobalScopes()` outside the §4a rules; `CrossTenantIsolationTest` untouched and green
- [ ] Datetime code: column storage semantics verified (§19), round-trip checked
- [ ] UI copy follows §18 (no em dashes in rendered UI, no dev jargon, no NEMSIS element IDs, no tenant-isolation narration)
- [ ] Eloquent over `DB::`, eager-loaded, Form Requests for validation, no `env()` outside config (§10)
- [ ] No secrets in code or docs; no commented-out code; no unrequested markdown files (§13)
- [ ] Detailed multi-line commit message (§15); pushed; PR opened with the Linear issue linked
- [ ] Anything on the human-approval list flagged, not acted on (deploys, prod data, dependency/deploy-requirement changes, deleting or weakening tests, force pushes, ePCR web↔mobile mirroring)
- [ ] Convention changed or found wrong? This file / repo `CLAUDE.md` updated in the same PR (§14)
- [ ] Changelog offered for user-visible changes (§17); wiki impact checked (§18)
