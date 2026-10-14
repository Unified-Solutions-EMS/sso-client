<?php

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\Facilities\FacilityProjection;
use Unified\SsoClient\MasterData\Support\ChildRows;

/**
 * CloudPCR's shape, as a CloudPCR projection would bind it: one
 * `dem_facility_entries` row per facility (dfacility_02 name, 03 code, 04
 * designations JSON, 05 NPIs JSON, 06 suite, 07 / 07b street, 08 city GNIS
 * + `mailing_city`, 09 state FIPS, 10 ZIP, 11 county FIPS, 12 country, 13
 * GPS "lat,lng") under a per-company type group in `dem_facilities`
 * (company_id, dfacility_01 type), phones in `dem_facility_entry_phones`
 * (phone_number, phone_type; 9913001 is fax).
 *
 * The entry has no company_id, so scope() goes through the group, and
 * toColumns() names the group for SSO's type (found or created), which
 * moves an entry whose type changed while keeping its id. SSO's `fax` is
 * kept as a fax-typed phone and sent back as `fax`. A starting point for
 * the real one, which also owns the `_nv` columns and USNG (dfacility_14).
 */
class DemFacilityProjection implements FacilityProjection
{
    public const FAX = '9913001';

    public const OTHER = '1701009';

    public function table(): string
    {
        return 'dem_facility_entries';
    }

    public function nameColumn(): string
    {
        return 'dfacility_02';
    }

    public function codeColumn(): ?string
    {
        return 'dfacility_03';
    }

    public function zipColumn(): ?string
    {
        return 'dfacility_10';
    }

    public function scope(Builder $query, int $localCompanyId): Builder
    {
        return $query->whereIn('dem_facility_id', fn (Builder $groups) => $groups->select('id')->from('dem_facilities')->where('company_id', $localCompanyId));
    }

    public function insertColumns(int $localCompanyId): array
    {
        return [];
    }

    public function toColumns(int $localCompanyId, array $facility): array
    {
        $address = is_array($facility['address'] ?? null) ? $facility['address'] : [];
        $gps = isset($facility['latitude'], $facility['longitude']) ? $facility['latitude'].','.$facility['longitude'] : null;

        return [
            'dem_facility_id' => $this->group($localCompanyId, (string) ($facility['type'] ?? self::OTHER)),
            'dfacility_03' => $facility['location_code'] ?? null,
            'dfacility_04' => ($facility['designations'] ?? []) === [] ? null : json_encode(array_values($facility['designations'])),
            'dfacility_05' => ($facility['npis'] ?? []) === [] ? null : json_encode(array_values($facility['npis'])),
            'dfacility_06' => $address['suite'] ?? null,
            'dfacility_07' => $address['street'] ?? null,
            'dfacility_07b' => $address['street2'] ?? null,
            'dfacility_08' => $address['city_gnis'] ?? null,
            'mailing_city' => $address['city_name'] ?? null,
            'dfacility_09' => $address['state'] ?? null,
            'dfacility_10' => $address['zip'] ?? null,
            'dfacility_11' => $address['county'] ?? null,
            'dfacility_12' => $address['country'] ?? null,
            'dfacility_13' => $gps,
        ];
    }

    /**
     * Phones, plus SSO's fax as a fax-typed phone. The entry is already
     * pinned to the company (§4a).
     */
    public function written(int $localCompanyId, int $localId, array $facility): void
    {
        $phones = array_map(fn (array $phone): array => [
            'phone_number' => (string) $phone['number'],
            'phone_type' => $phone['type'] ?? null,
        ], array_values(array_filter((array) ($facility['phones'] ?? []), 'is_array')));

        if (is_string($facility['fax'] ?? null) && $facility['fax'] !== '') {
            $phones[] = ['phone_number' => $facility['fax'], 'phone_type' => self::FAX];
        }

        ChildRows::sync('dem_facility_entry_phones', 'dem_facility_entry_id', $localId, $phones);
    }

    public function pushColumns(): array
    {
        return ['dem_facility_id', 'dfacility_03', 'dfacility_04', 'dfacility_05', 'dfacility_06', 'dfacility_07', 'dfacility_07b', 'dfacility_08', 'mailing_city', 'dfacility_09', 'dfacility_10', 'dfacility_11', 'dfacility_12', 'dfacility_13'];
    }

    public function toImport(int $localCompanyId, object $row): array
    {
        [$latitude, $longitude] = array_pad(array_map('trim', explode(',', (string) $row->dfacility_13)), 2, null);
        $phones = DB::table('dem_facility_entry_phones')->where('dem_facility_entry_id', $row->id)->orderBy('sort_order')->orderBy('id')->get();
        $fax = $phones->firstWhere('phone_type', self::FAX);

        return array_filter([
            'type' => DB::table('dem_facilities')->where('company_id', $localCompanyId)->where('id', $row->dem_facility_id)->value('dfacility_01'),
            'location_code' => $row->dfacility_03,
            'designations' => $row->dfacility_04 === null ? null : json_decode($row->dfacility_04, true),
            'npis' => $row->dfacility_05 === null ? null : json_decode($row->dfacility_05, true),
            'fax' => $fax?->phone_number,
            'address' => array_filter([
                'suite' => $row->dfacility_06,
                'street' => $row->dfacility_07,
                'street2' => $row->dfacility_07b,
                'city_gnis' => $row->dfacility_08,
                'city_name' => $row->mailing_city,
                'state' => $row->dfacility_09,
                'zip' => $row->dfacility_10,
                'county' => $row->dfacility_11,
                'country' => $row->dfacility_12,
            ], fn (mixed $value): bool => $value !== null && $value !== '') ?: null,
            'latitude' => $latitude === null || $latitude === '' ? null : (float) $latitude,
            'longitude' => $longitude === null || $longitude === '' ? null : (float) $longitude,
            'phones' => $phones->where('phone_type', '!=', self::FAX)->map(fn (object $phone): array => array_filter([
                'number' => $phone->phone_number,
                'type' => $phone->phone_type,
            ], fn (mixed $value): bool => $value !== null))->values()->all() ?: null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * The company's group for a type, created once.
     */
    private function group(int $localCompanyId, string $type): int
    {
        $id = DB::table('dem_facilities')->where('company_id', $localCompanyId)->where('dfacility_01', $type)->orderBy('id')->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('dem_facilities')->insertGetId([
            'company_id' => $localCompanyId,
            'dfacility_01' => $type,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
