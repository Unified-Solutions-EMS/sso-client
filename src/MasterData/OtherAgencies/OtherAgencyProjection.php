<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\OtherAgencies;

use Unified\SsoClient\MasterData\Contracts\RecordProjection;

/**
 * How one app stores SSO's "Other agencies" list (Settings > Agency lists >
 * Other agencies): the agencies an agency's crews work with, picked from the
 * state's agency list or added by hand. OtherAgencyMirror owns the link,
 * stale guard, pending adoption, turn off never delete and the push
 * mapping; the projection owns the app's shape.
 *
 * fromSso() reads one row of SSO's OtherAgency::toContractArray(): `uuid`,
 * `name`, `state` (2-letter USPS or null), `state_agency_id` (the state's
 * unique agency id), `agency_number`, `service_type` (the scene responder
 * service type code charts default to), `source` ('state_list' | 'manual').
 * matchColumns() should name the name and state columns: SSO's import
 * dedupes on lower(name) + state, so adoption here does the same. toSso()
 * sends the same fields for `POST .../other_agencies/import`.
 *
 * The package binds StandardOtherAgencyProjection (`sso_other_agencies`),
 * which is what CloudPCR uses.
 */
interface OtherAgencyProjection extends RecordProjection {}
