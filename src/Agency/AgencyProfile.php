<?php

declare(strict_types=1);

namespace Unified\SsoClient\Agency;

use Carbon\CarbonImmutable;

/**
 * The agency's information as SSO owns it (SSO `agency_profiles`, one per
 * company), read from the local `companies.sso_profile` copy. The agency
 * name is not part of it: that stays `companies.name`.
 *
 * Values are stored flat, with SSO's own keys (`state`, `state_agency_id`,
 * `agency_number`, `npi`, `tax_id`, `address_street` .. `address_country`,
 * `mailing_*`, `phone`, `fax`, `email`, `website`, ...). The login payload's
 * compact block nests the physical address as `address: {street, ...}`;
 * normalize() flattens it into the same `address_*` keys, so both sources
 * land in one shape.
 */
final class AgencyProfile
{
    public const ADDRESS_KEYS = ['street', 'street2', 'city_gnis', 'city_name', 'state', 'zip', 'county', 'country'];

    public const MAILING_KEYS = ['street', 'street2', 'city_name', 'state', 'zip'];

    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private readonly array $values = []) {}

    /**
     * @param  array<string, mixed>  $profile
     */
    public static function fromSso(array $profile): self
    {
        return new self(self::normalize($profile));
    }

    /**
     * SSO's block in the stored shape: the nested `address` flattened into
     * `address_*`, and `updated_at` dropped (it lives in
     * `companies.sso_profile_updated_at`).
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function normalize(array $profile): array
    {
        $address = $profile['address'] ?? null;
        unset($profile['address'], $profile['updated_at']);

        if (is_array($address)) {
            foreach (array_intersect_key($address, array_flip(self::ADDRESS_KEYS)) as $key => $value) {
                $profile['address_'.$key] = $value;
            }
        }

        return $profile;
    }

    /**
     * SSO sends ISO 8601 with an offset; stored and compared as UTC
     * 'Y-m-d H:i:s', which also sorts correctly as a string.
     */
    public static function normalizeTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->format('Y-m-d H:i:s');
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function string(string $key): ?string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : null;
    }

    public function isEmpty(): bool
    {
        return array_filter($this->values, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []) === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /** Two-digit state FIPS code. */
    public function state(): ?string
    {
        return $this->string('state');
    }

    public function stateAgencyId(): ?string
    {
        return $this->string('state_agency_id');
    }

    public function agencyNumber(): ?string
    {
        return $this->string('agency_number');
    }

    public function npi(): ?string
    {
        return $this->string('npi');
    }

    public function phone(): ?string
    {
        return $this->string('phone');
    }

    public function fax(): ?string
    {
        return $this->string('fax');
    }

    public function email(): ?string
    {
        return $this->string('email');
    }

    public function website(): ?string
    {
        return $this->string('website');
    }

    /**
     * @return array<string, string|null> keyed by ADDRESS_KEYS
     */
    public function physicalAddress(): array
    {
        $address = [];

        foreach (self::ADDRESS_KEYS as $key) {
            $address[$key] = $this->string('address_'.$key);
        }

        return $address;
    }

    /**
     * The mailing address, or the physical one when the agency said they
     * are the same.
     *
     * @return array<string, string|null> keyed by MAILING_KEYS
     */
    public function mailingAddress(): array
    {
        $prefix = (bool) $this->get('mailing_same_as_physical', false) ? 'address_' : 'mailing_';
        $address = [];

        foreach (self::MAILING_KEYS as $key) {
            $address[$key] = $this->string($prefix.$key);
        }

        return $address;
    }
}
