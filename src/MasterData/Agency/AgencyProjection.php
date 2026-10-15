<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Agency;

use Unified\SsoClient\MasterData\Contracts\RecordProjection;

/**
 * How one app stores SSO's agency information (NEMSIS dAgency, one profile
 * per company). AgencyMirror owns the link, stale guard, pending values and
 * the push mapping; the projection owns the app's shape.
 *
 * fromSso() reads the profile (`state`, `state_agency_id`, `agency_number`,
 * `npi`, `tax_id`, `tax_status`, `license_number`, `is_licensed_agency`,
 * `primary_service_type`, `other_service_types`, `level_of_service`,
 * `organization_status`, `organization_type`, `fire_department_id`,
 * `neris_department_id`, `statistical_year`, `total_calls`, `address`,
 * `mailing`, `latitude`, `longitude`, `phone`, `fax`, `email`, `website`,
 * `<field>_nv`), plus `company` {id, name, timezone}: the agency name is
 * the company name and the time zone is the company's IANA zone, which an
 * app maps to its own code (CloudPCR's dAgency.23). written() writes what
 * lives outside the row: `service_areas` [{id, state, county, zip,
 * sort_order}], `year_stats` [{id, year, ...the dAgency.15-.22 values}]
 * and `custom_values` {element_id: {value, nv}}. toSso() sends the same
 * fields back, children included, for `POST .../agency/import`.
 *
 * The package binds StandardAgencyProjection (`sso_agency_profiles`).
 * CloudPCR binds one over `dem_agencies` + `dem_agency_service_areas` +
 * `dem_agency_year_stats` + `dem_custom_values`, keeping its
 * DemAgencyObserver firing; tests/Stubs/DemAgencyProjection.php is the
 * template.
 */
interface AgencyProjection extends RecordProjection {}
