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
- **`SsoUserSynchronizer`** — the heart of the package. Idempotently upserts the user, resolves/creates
  local companies (match order: `sso_company_id` → `core_tenant_id` → name), attaches memberships,
  syncs per-company roles into `company_user_roles`, syncs staff roles into `users.staff_roles`, and
  syncs enabled modules. Apps override by binding `SsoUserSynchronizerContract`.
- **Webhook / provisioning endpoint** — `POST /api/sso/provision` (`SsoWebhookController`), HMAC-verified
  in the controller, no CSRF/auth middleware. Handles `user.created/updated/deleted`,
  `company.updated/activated`, `user.role.changed`, `user.app_role.changed`, `user.staff_role.changed`,
  `impersonation.started/ended`, `user.logged_out`, `trial.seed_data`, `trial.purge_data`,
  `cad.migrate_data`. Unknown events ack rather than 500.
- **Agency-status route** — `GET /api/internal/agency-status/{ssoCompanyId}` behind `ValidateCoreApiKey`,
  registered by the package so apps never add the route. Apps implement `Contracts\AgencyStatusProvider`
  and bind it. See DEV_GUIDELINES §2a for the response contract and the HIPAA redaction boundary
  (redaction happens in the SSO MCP server, not in apps).
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
  Best-effort like Metrics: unconfigured apps log-and-noop, sends never raise into the request.
- **Dashboard + action endpoints** — `POST /api/sso/dashboard` (`config('sso.dashboard_provider')`
  implementing `DashboardDataProvider`) and `POST /api/sso/actions/{action}`
  (`config('sso.action_handlers')` map to `SsoActionHandler`), both HMAC-verified.
- **Session actions** — `EnforceSsoSessionActions` is auto-appended to the `web` group in every app,
  so impersonation/forced-logout land on the next request without per-app wiring.
- **Roster reconcile** — `sso:sync-users` command, scheduled hourly from the provider via
  `config('sso.roster_sync')`, `withoutOverlapping()`. Pulls each locally-known company's full roster
  from SSO and runs every member through `SsoUserSynchronizer`, so apps see users who never logged in.
- **Timezone propagation** — SSO owns `companies.timezone`; the package mirrors it onto the app's local
  `companies.timezone` in both the login sync and the `company.updated` webhook, guarded by
  `Schema::hasColumn()` so apps that haven't adopted the column ignore it. Apps must NOT ship their
  own timezone selector.
- **Migrations** — `sso_session_actions` table and `users.staff_roles` column, loaded from the package.

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
