<?php

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\Locations\LocationProjection;

/**
 * CloudPCR's shape, as a CloudPCR projection would bind it: `dem_locations`
 * with NEMSIS columns (per the XSD: dlocation_01 type, 02 name, 03 number,
 * 04 GPS, 06 street, 06b street 2, 07 city GNIS, 08 state FIPS, 09 ZIP,
 * 10 county FIPS, 11 country; `mailing_city` the city name), phones in
 * `dem_location_phones`, soft deletes. A starting point for the real one,
 * which also owns the `_nv` columns and USNG.
 */
class DemLocationProjection implements LocationProjection
{
    public function table(): string
    {
        return 'dem_locations';
    }

    public function nameColumn(): string
    {
        return 'dlocation_02';
    }

    public function numberColumn(): ?string
    {
        return 'dlocation_03';
    }

    public function divisionColumn(): ?string
    {
        return null;
    }

    public function toColumns(array $location): array
    {
        $address = is_array($location['address'] ?? null) ? $location['address'] : [];
        $gps = isset($location['latitude'], $location['longitude']) ? $location['latitude'].','.$location['longitude'] : null;

        return [
            'dlocation_01' => $location['location_type'] ?? null,
            'dlocation_03' => $location['number'] ?? null,
            'dlocation_04' => $gps,
            'dlocation_06' => $address['street'] ?? null,
            'dlocation_06b' => $address['street2'] ?? null,
            'dlocation_07' => $address['city_gnis'] ?? null,
            'mailing_city' => $address['city_name'] ?? null,
            'dlocation_08' => $address['state'] ?? null,
            'dlocation_09' => $address['zip'] ?? null,
            'dlocation_10' => $address['county'] ?? null,
            'dlocation_11' => $address['country'] ?? null,
            // SSO owns the lifecycle: turned off is is_active, never a soft delete.
            'deleted_at' => null,
        ];
    }

    /**
     * Phones are child rows of the location, replaced from SSO's list. The
     * location is pinned to the company first (§4a).
     */
    public function written(int $localCompanyId, int $localId, array $location): void
    {
        if (! DB::table('dem_locations')->where('company_id', $localCompanyId)->where('id', $localId)->exists()) {
            return;
        }

        $wanted = array_map(fn (array $phone): array => [(string) $phone['number'], $phone['type'] ?? null], array_values((array) ($location['phones'] ?? [])));
        $stored = DB::table('dem_location_phones')->where('dem_location_id', $localId)->orderBy('sort_order')->orderBy('id')
            ->get(['phone_number', 'phone_type'])
            ->map(fn (object $phone): array => [(string) $phone->phone_number, $phone->phone_type])
            ->all();

        if ($stored === $wanted) {
            return;
        }

        DB::table('dem_location_phones')->where('dem_location_id', $localId)->delete();

        foreach (array_values((array) ($location['phones'] ?? [])) as $index => $phone) {
            DB::table('dem_location_phones')->insert([
                'dem_location_id' => $localId,
                'phone_number' => (string) $phone['number'],
                'phone_type' => $phone['type'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    public function pushColumns(): array
    {
        return ['dlocation_01', 'dlocation_03', 'dlocation_04', 'dlocation_06', 'dlocation_06b', 'dlocation_07', 'mailing_city', 'dlocation_08', 'dlocation_09', 'dlocation_10', 'dlocation_11'];
    }

    public function toImport(int $localCompanyId, object $row): array
    {
        [$latitude, $longitude] = array_pad(array_map('trim', explode(',', (string) $row->dlocation_04)), 2, null);

        return array_filter([
            'number' => $row->dlocation_03,
            'location_type' => $row->dlocation_01,
            'address' => [
                'street' => $row->dlocation_06,
                'street2' => $row->dlocation_06b,
                'city_gnis' => $row->dlocation_07,
                'city_name' => $row->mailing_city,
                'state' => $row->dlocation_08,
                'zip' => $row->dlocation_09,
                'county' => $row->dlocation_10,
                'country' => $row->dlocation_11,
            ],
            'latitude' => is_numeric($latitude) ? (float) $latitude : null,
            'longitude' => is_numeric($longitude) ? (float) $longitude : null,
            'phones' => DB::table('dem_location_phones')->where('dem_location_id', $row->id)->orderBy('sort_order')
                ->get(['phone_number', 'phone_type'])
                ->map(fn (object $phone): array => ['number' => $phone->phone_number, 'type' => $phone->phone_type])
                ->all() ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
