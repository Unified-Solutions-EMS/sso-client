<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\CodeLists;

/**
 * How one app keeps an SSO code list (medications or procedures: the codes
 * crews may chart, each with the certification levels permitted to give or
 * perform it). The mirror itself is a package-owned flat table, one row per
 * code (`sso_medications` / `sso_procedures`), because no app stores the list
 * flat: CloudPCR repeats each code per state configuration and per level,
 * Drug Tracking hangs products off ingredients. The projection is the bridge
 * both ways:
 *
 * - localItems(): the app's own list collapsed to one entry per code, for
 *   the one-time push (`sso:push-master-data medications|procedures`);
 * - project(): write the mirrored list into the app's own structure
 *   (CloudPCR rebuilds its per-state, per-level groups; Drug Tracking links
 *   its products), called once after each change from SSO and on every
 *   resync, never while the entity is turned off.
 *
 * Bind MedicationProjection / ProcedureProjection in
 * AppServiceProvider::register(). The package default for both reads and
 * writes nothing (an app that reads the mirror table directly through
 * MedicationCatalog / ProcedureCatalog).
 *
 * Every method gets the local company id the mirror resolved from an
 * authoritative SSO id and must filter by it (DEV_GUIDELINES §4a).
 */
interface CodeListProjection
{
    /**
     * The app's list, one entry per code. Each entry:
     * - `key` (string, optional): the app's own id for the entry, echoed back
     *   in SSO's mapping; defaults to the code. Keep it stable across runs.
     * - `code` (string|null): the code exactly as charted (RxNorm RxCUI, or
     *   SNOMED for procedures and blood products). Null only for a row the
     *   app has no code for; SSO's review asks the agency to find one.
     * - `label` (string): the name crews see today.
     * - `levels` (list<string>): certification level codes permitted
     *   (NEMSIS 9917xxx or a state's value-append, e.g. it9917.134), the
     *   union across the app's groups. Empty means not chartable (for a
     *   medication: stock only).
     * - `is_active` (bool, optional, default true).
     * - `only_states` (list<string>|null, optional): state FIPS codes when
     *   the code is reported only in some of the agency's states; null or
     *   empty for every state.
     * - medications only: `is_controlled` (bool), `dea_schedule`
     *   ('II'..'V'|null).
     *
     * @return list<array<string, mixed>>
     */
    public function localItems(int $localCompanyId): array;

    /**
     * Write the company's mirrored list into the app. Gets every mirror row
     * of the company (turned-off ones included, `is_active` false), in SSO's
     * order, each as an array: `id` (mirror row id), `sso_id`, `code`,
     * `code_system`, `code_type` (NEMSIS 9924003 RxNorm / 9924005 SNOMED),
     * `name` (resolved), `display_name` (agency override|null), `label`
     * (what crews see), `levels`, `member_levels` (personnel license level
     * codes for the same levels), `is_chartable` (levels not empty),
     * `only_states` (empty = every state), `is_active`, `sort_order`, plus
     * for medications `ingredient_rxcui`, `is_controlled`, `dea_schedule`,
     * `is_blood_product`.
     *
     * Must be idempotent and should write only what differs: it runs after
     * every webhook that changed a row and on every resync.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function project(int $localCompanyId, array $items): void;
}
