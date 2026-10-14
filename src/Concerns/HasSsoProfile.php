<?php

declare(strict_types=1);

namespace Unified\SsoClient\Concerns;

use Carbon\CarbonImmutable;
use Unified\SsoClient\Agency\AgencyProfile;
use Unified\SsoClient\Agency\ProfileColumns;

/**
 * Read side of SSO's agency information for the app's Company model:
 *
 *     use \Unified\SsoClient\Concerns\HasSsoProfile;
 *
 *     $company->agencyProfile()->npi();
 *     $company->ssoProfileValue('state_agency_id');
 *
 * Read-only by design: SSO's Agency information page owns these fields, and
 * the login sync and `agency_profile.updated` overwrite the copy. Before the
 * `sso-company-profile` migration has run every accessor returns empty.
 */
trait HasSsoProfile
{
    public function initializeHasSsoProfile(): void
    {
        if (! $this->hasCast(ProfileColumns::PROFILE)) {
            $this->mergeCasts([ProfileColumns::PROFILE => 'array']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function ssoProfile(): array
    {
        $value = $this->getAttribute(ProfileColumns::PROFILE);

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    public function agencyProfile(): AgencyProfile
    {
        return new AgencyProfile($this->ssoProfile());
    }

    public function ssoProfileValue(string $key, mixed $default = null): mixed
    {
        return $this->agencyProfile()->get($key, $default);
    }

    /**
     * When SSO last changed the agency information, in UTC.
     */
    public function ssoProfileUpdatedAt(): ?CarbonImmutable
    {
        $value = AgencyProfile::normalizeTimestamp($this->getAttributes()[ProfileColumns::UPDATED_AT] ?? null);

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }
}
