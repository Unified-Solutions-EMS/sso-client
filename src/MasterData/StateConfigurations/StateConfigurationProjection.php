<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\StateConfigurations;

use Unified\SsoClient\MasterData\Contracts\RecordProjection;

/**
 * How one app stores SSO's state configurations (Settings > Agency lists >
 * State configuration: the non-formulary part of NEMSIS dConfiguration,
 * one row per state the agency reports to). StateConfigurationMirror owns
 * the link, stale guard, pending adoption, kept values, turn off never
 * delete and the push mapping; the projection owns the app's shape.
 *
 * fromSso() reads one row: `state` (FIPS), `protocols` (codes),
 * `specialty_service_capabilities` (codes), `billing_status`,
 * `emd_performed`, `emd_vendor`, `patient_monitoring_capabilities`
 * (codes), `crew_call_signs` (strings), `dispatch_center`,
 * `dispatch_center_phone`, `<field>_nv`. matchColumns() should name the
 * state column: SSO matches on state. Medications, procedures and
 * certification levels are NOT here; they stay with their own mirrors.
 * toSso() sends the same fields for `POST .../state_configurations/import`.
 *
 * The package binds StandardStateConfigurationProjection
 * (`sso_state_configurations`). CloudPCR binds one over
 * `dem_configurations` (dconfiguration_01 state, .10-.17 only).
 */
interface StateConfigurationProjection extends RecordProjection {}
