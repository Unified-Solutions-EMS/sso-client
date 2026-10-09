<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Locations;

/**
 * Converts between SSO's structured location address (state as a 2-digit
 * FIPS code) and the one-line US address apps keep in a single text column,
 * such as Crew-Scheduling's Google Autocomplete `locations.address`
 * ("120 Lake Flower Ave, Saranac Lake, NY 12983, USA").
 */
final class UsAddress
{
    /** USPS abbreviation => FIPS state code (50 states, DC, territories). */
    public const STATES = [
        'AL' => '01', 'AK' => '02', 'AZ' => '04', 'AR' => '05', 'CA' => '06', 'CO' => '08', 'CT' => '09', 'DE' => '10',
        'DC' => '11', 'FL' => '12', 'GA' => '13', 'HI' => '15', 'ID' => '16', 'IL' => '17', 'IN' => '18', 'IA' => '19',
        'KS' => '20', 'KY' => '21', 'LA' => '22', 'ME' => '23', 'MD' => '24', 'MA' => '25', 'MI' => '26', 'MN' => '27',
        'MS' => '28', 'MO' => '29', 'MT' => '30', 'NE' => '31', 'NV' => '32', 'NH' => '33', 'NJ' => '34', 'NM' => '35',
        'NY' => '36', 'NC' => '37', 'ND' => '38', 'OH' => '39', 'OK' => '40', 'OR' => '41', 'PA' => '42', 'RI' => '44',
        'SC' => '45', 'SD' => '46', 'TN' => '47', 'TX' => '48', 'UT' => '49', 'VT' => '50', 'VA' => '51', 'WA' => '53',
        'WV' => '54', 'WI' => '55', 'WY' => '56', 'AS' => '60', 'GU' => '66', 'MP' => '69', 'PR' => '72', 'VI' => '78',
    ];

    /**
     * One line for display or a text column, or null when there is no street
     * or city. The state is written as its USPS abbreviation.
     *
     * @param  array<string, mixed>|null  $address  SSO's `address` object
     */
    public static function format(?array $address): ?string
    {
        $street = self::text($address['street'] ?? null);
        $city = self::text($address['city_name'] ?? null);

        if ($street === null && $city === null) {
            return null;
        }

        $state = self::text($address['state'] ?? null);
        $abbreviation = $state === null ? null : (array_search($state, self::STATES, true) ?: null);
        $stateZip = trim(($abbreviation ?? '').' '.(self::text($address['zip'] ?? null) ?? ''));

        return implode(', ', array_filter([
            $street,
            self::text($address['street2'] ?? null),
            $city,
            $stateZip === '' ? null : $stateZip,
        ], fn (?string $part): bool => $part !== null));
    }

    /**
     * Best-effort split of a one-line US address into SSO's address fields:
     * "street[, street2], city, ST 12345[, USA]". Returns null when the line
     * does not end in a state and ZIP; SSO then reports the address as not
     * saved and the agency fills it in Settings.
     *
     * @return array{street: string, street2: string|null, city_name: string, state: string, zip: string, country: string}|null
     */
    public static function parse(?string $line): ?array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $line)), fn (string $part): bool => $part !== ''));

        if ($parts !== [] && in_array(strtoupper((string) end($parts)), ['USA', 'US', 'UNITED STATES'], true)) {
            array_pop($parts);
        }

        if (count($parts) < 3 || preg_match('/^([A-Za-z]{2})\s+(\d{5}(?:-\d{4})?)$/', (string) array_pop($parts), $match) !== 1) {
            return null;
        }

        $state = self::STATES[strtoupper($match[1])] ?? null;

        if ($state === null) {
            return null;
        }

        $city = (string) array_pop($parts);
        $street = (string) array_shift($parts);

        return [
            'street' => $street,
            'street2' => $parts === [] ? null : implode(', ', $parts),
            'city_name' => $city,
            'state' => $state,
            'zip' => $match[2],
            'country' => 'US',
        ];
    }

    /**
     * A US E.164 number as (518) 555-0142; anything else unchanged.
     */
    public static function formatPhone(?string $e164): ?string
    {
        if ($e164 === null || $e164 === '') {
            return null;
        }

        return preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $match) === 1
            ? "({$match[1]}) {$match[2]}-{$match[3]}"
            : $e164;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
