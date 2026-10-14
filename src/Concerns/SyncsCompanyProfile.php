<?php

declare(strict_types=1);

namespace Unified\SsoClient\Concerns;

use Illuminate\Database\Eloquent\Model;
use Unified\SsoClient\Agency\AgencyProfile;
use Unified\SsoClient\Agency\ProfileColumns;

/**
 * Copies SSO's agency information onto the local company row
 * (`companies.sso_profile` JSON + `companies.sso_profile_updated_at`), for
 * apps that ran the `sso-company-profile` migration. Apps that did not are
 * skipped silently, the same way the timezone column is.
 *
 * Two sources:
 * - login (`/api/user` companies[].profile): a compact block, merged over
 *   what is stored so the fields only the webhook carries (tax id, mailing
 *   address, NEMSIS organization codes) survive a sign-in;
 * - `agency_profile.updated`: SSO's full record, which replaces the copy.
 *
 * SSO queues deliveries, so a block whose `updated_at` is older than the
 * stored one is ignored ('stale'). A block without a timestamp (the compact
 * login block) is applied and keeps the stored timestamp.
 *
 * Apps must not edit these fields locally: SSO's Agency information page
 * owns them.
 */
trait SyncsCompanyProfile
{
    /**
     * @return 'stored'|'unchanged'|'stale'|'unsupported'|'empty'
     */
    protected function applyCompanyProfile(Model $company, mixed $profile, bool $replace = false, mixed $updatedAt = null): string
    {
        if (! is_array($profile)) {
            return 'empty';
        }

        if (! ProfileColumns::supported($company->getTable())) {
            return 'unsupported';
        }

        $raw = $company->getAttributes();
        $storedAt = AgencyProfile::normalizeTimestamp($raw[ProfileColumns::UPDATED_AT] ?? null);
        $incomingAt = AgencyProfile::normalizeTimestamp($updatedAt ?? ($profile['updated_at'] ?? null));

        if ($storedAt !== null && $incomingAt !== null && $incomingAt < $storedAt) {
            return 'stale';
        }

        $stored = self::decodeStoredProfile($raw[ProfileColumns::PROFILE] ?? null);
        $incoming = AgencyProfile::normalize($profile);
        $profile = $replace ? $incoming : array_replace($stored, $incoming);
        $timestamp = $incomingAt ?? $storedAt;

        if ($profile == $stored && $timestamp === $storedAt && ($raw[ProfileColumns::PROFILE] ?? null) !== null) {
            return 'unchanged';
        }

        $company->forceFill([
            // A model that casts the column takes the array; otherwise
            // write the JSON text ourselves.
            ProfileColumns::PROFILE => $company->hasCast(ProfileColumns::PROFILE) ? $profile : json_encode($profile),
            ProfileColumns::UPDATED_AT => $timestamp,
        ])->save();

        return 'stored';
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeStoredProfile(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
