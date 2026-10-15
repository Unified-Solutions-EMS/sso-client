<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contacts;

use Unified\SsoClient\MasterData\Contracts\RecordProjection;

/**
 * How one app stores SSO's agency contacts (Settings > Agency > Contacts,
 * NEMSIS dContact). ContactMirror owns the link, stale guard, pending
 * adoption, kept values, turn off never delete and the push mapping; the
 * projection owns the app's shape.
 *
 * fromSso() reads one contact: `type` (SSO's slug) and `nemsis_type`,
 * `first_name`, `middle_name`, `last_name`, `title`, `is_primary`,
 * `phones` [{number (E.164), type}], `emails` [{address, type}], `address`
 * {street, street2, city_gnis, city_name, state, zip, county, country},
 * `website`, `medical_director` {degree, board_certifications,
 * compensation, board_certified} | null, `fellowship_status`,
 * `sso_user_id`, `<field>_nv`. matchColumns() should name the last name,
 * first name and type columns: SSO's import matches on those three,
 * ignoring case, so adoption here does the same. toSso() sends the same
 * fields for `POST .../contacts/import`.
 *
 * The package binds StandardContactProjection (`sso_agency_contacts`).
 * CloudPCR binds one over `dem_contacts` (dcontact_01..16), whose ids
 * FaxageService keeps reading.
 */
interface ContactProjection extends RecordProjection {}
