<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial;

/**
 * Where a new trial is and what SSO seeded for it: the `master_data.context`
 * block of `trial.seed_data`. A seeder that needs a place or a name reads it
 * here instead of a literal, so a trial looks the same in every app whether
 * that app mirrors SSO's lists or keeps its own.
 *
 * Built leniently: a missing key reads as null or an empty list, unknown
 * keys are ignored, and list entries without their naming field are
 * dropped. User ids are SSO user ids (integers), not the SSO UUIDs the
 * seeder contract receives.
 *
 * @phpstan-type Station array{number: int, name: string, street: ?string, city: ?string, state: ?string, zip: ?string}
 * @phpstan-type Vehicle array{unit_number: string, call_sign: ?string, type: ?string}
 * @phpstan-type Facility array{name: string, type: ?string}
 * @phpstan-type Person array{user_id: int, first_name: ?string, last_name: ?string, licence: ?string, level: ?string}
 * @phpstan-type Address array{street: ?string, city: ?string, state: ?string, zip: ?string, county_fips: ?string}
 */
final class TrialContext
{
    /**
     * @param  list<int>  $fakeUserIds
     * @param  list<string>  $divisions
     * @param  list<Station>  $stations
     * @param  list<Vehicle>  $vehicles
     * @param  list<string>  $units
     * @param  list<Facility>  $facilities
     * @param  list<Person>  $people
     */
    private function __construct(
        private readonly ?string $state,
        private readonly ?string $stateFips,
        private readonly ?string $city,
        private readonly ?string $countyFips,
        private readonly ?string $zip,
        private readonly ?string $timezone,
        private readonly ?string $agencyName,
        private readonly ?string $phone,
        private readonly ?int $ownerUserId,
        private readonly array $fakeUserIds,
        private readonly array $divisions,
        private readonly array $stations,
        private readonly array $vehicles,
        private readonly array $units,
        private readonly array $facilities,
        private readonly array $people,
    ) {}

    /**
     * @param  array<string, mixed>  $masterData  the payload's `master_data`
     */
    public static function fromPayload(array $masterData): ?self
    {
        $context = $masterData['context'] ?? null;

        return is_array($context) ? self::fromArray($context) : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function fromArray(array $context): self
    {
        return new self(
            state: self::string($context['state'] ?? null),
            stateFips: self::digits($context['state_fips'] ?? null, 2),
            city: self::string($context['city'] ?? null),
            countyFips: self::digits($context['county_fips'] ?? null, 5),
            zip: self::digits($context['zip'] ?? null, 5),
            timezone: self::string($context['timezone'] ?? null),
            agencyName: self::string($context['agency_name'] ?? null),
            phone: self::string($context['phone'] ?? null),
            ownerUserId: self::id($context['owner_user_id'] ?? null),
            fakeUserIds: array_values(array_filter(array_map(self::id(...), self::list($context['fake_user_ids'] ?? null)))),
            divisions: self::names($context['divisions'] ?? null),
            stations: self::stationList($context['stations'] ?? null),
            vehicles: self::records($context['vehicles'] ?? null, fn (array $vehicle): ?array => ($unit = self::string($vehicle['unit_number'] ?? null)) === null ? null : [
                'unit_number' => $unit,
                'call_sign' => self::string($vehicle['call_sign'] ?? null),
                'type' => self::string($vehicle['type'] ?? null),
            ]),
            units: self::names($context['units'] ?? null),
            facilities: self::records($context['facilities'] ?? null, fn (array $facility): ?array => ($name = self::string($facility['name'] ?? null)) === null ? null : [
                'name' => $name,
                'type' => self::string($facility['type'] ?? null),
            ]),
            people: self::records($context['people'] ?? null, fn (array $person): ?array => ($userId = self::id($person['user_id'] ?? null)) === null ? null : [
                'user_id' => $userId,
                'first_name' => self::string($person['first_name'] ?? null),
                'last_name' => self::string($person['last_name'] ?? null),
                'licence' => self::string($person['licence'] ?? null),
                'level' => self::string($person['level'] ?? null),
            ]),
        );
    }

    /**
     * The normalized block, in the payload's shape; fromArray() reads it back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'state_fips' => $this->stateFips,
            'city' => $this->city,
            'county_fips' => $this->countyFips,
            'zip' => $this->zip,
            'timezone' => $this->timezone,
            'agency_name' => $this->agencyName,
            'phone' => $this->phone,
            'owner_user_id' => $this->ownerUserId,
            'fake_user_ids' => $this->fakeUserIds,
            'divisions' => $this->divisions,
            'stations' => $this->stations,
            'vehicles' => $this->vehicles,
            'units' => $this->units,
            'facilities' => $this->facilities,
            'people' => $this->people,
        ];
    }

    /** Two-letter postal code, e.g. "NY". */
    public function state(): ?string
    {
        return $this->state;
    }

    /** Two-digit state FIPS code, e.g. "36". */
    public function stateFips(): ?string
    {
        return $this->stateFips;
    }

    public function city(): ?string
    {
        return $this->city;
    }

    /** Five-digit county FIPS code, e.g. "36001". */
    public function countyFips(): ?string
    {
        return $this->countyFips;
    }

    public function zip(): ?string
    {
        return $this->zip;
    }

    /** IANA name, e.g. "America/New_York". */
    public function timezone(): ?string
    {
        return $this->timezone;
    }

    public function agencyName(): ?string
    {
        return $this->agencyName;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function ownerUserId(): ?int
    {
        return $this->ownerUserId;
    }

    /**
     * @return list<int>
     */
    public function fakeUserIds(): array
    {
        return $this->fakeUserIds;
    }

    /**
     * @return list<string>
     */
    public function divisions(): array
    {
        return $this->divisions;
    }

    /**
     * @return list<Station>
     */
    public function stations(): array
    {
        return $this->stations;
    }

    /**
     * @return list<Vehicle>
     */
    public function vehicles(): array
    {
        return $this->vehicles;
    }

    /**
     * @return list<string>
     */
    public function units(): array
    {
        return $this->units;
    }

    /**
     * @return list<Facility>
     */
    public function facilities(): array
    {
        return $this->facilities;
    }

    /**
     * @return list<Person>
     */
    public function people(): array
    {
        return $this->people;
    }

    /**
     * @return list<string>
     */
    public function vehicleNames(): array
    {
        return array_column($this->vehicles, 'unit_number');
    }

    /**
     * @return list<string>
     */
    public function stationNames(): array
    {
        return array_column($this->stations, 'name');
    }

    /**
     * @return Station|null
     */
    public function station(int $number): ?array
    {
        foreach ($this->stations as $station) {
            if ($station['number'] === $number) {
                return $station;
            }
        }

        return null;
    }

    /**
     * The station's street address, its blanks (or a missing station)
     * filled from the trial's own city, state and zip.
     *
     * @return Address
     */
    public function address(int $stationNumber = 1): array
    {
        $station = $this->station($stationNumber);

        return [
            'street' => $station['street'] ?? null,
            'city' => $station['city'] ?? $this->city,
            'state' => $station['state'] ?? $this->state,
            'zip' => $station['zip'] ?? $this->zip,
            'county_fips' => $this->countyFips,
        ];
    }

    private static function string(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A code whose leading zeros matter ("06" is California), padded back
     * when it arrives as a JSON number.
     */
    private static function digits(mixed $value, int $length): ?string
    {
        $value = self::string($value);

        return $value !== null && ctype_digit($value) ? str_pad($value, $length, '0', STR_PAD_LEFT) : $value;
    }

    private static function id(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $value): array
    {
        return array_values(array_filter(array_map(self::string(...), self::list($value)), fn (?string $name): bool => $name !== null));
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param  callable(array<mixed>): ?T  $map
     * @return list<T>
     */
    private static function records(mixed $value, callable $map): array
    {
        $records = [];

        foreach (self::list($value) as $entry) {
            if (is_array($entry) && ($record = $map($entry)) !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Stations without a number are numbered by position.
     *
     * @return list<Station>
     */
    private static function stationList(mixed $value): array
    {
        $stations = [];

        foreach (self::list($value) as $index => $station) {
            $name = is_array($station) ? self::string($station['name'] ?? null) : null;

            if ($name === null) {
                continue;
            }

            $stations[] = [
                'number' => self::id($station['number'] ?? null) ?? $index + 1,
                'name' => $name,
                'street' => self::string($station['street'] ?? null),
                'city' => self::string($station['city'] ?? null),
                'state' => self::string($station['state'] ?? null),
                'zip' => self::digits($station['zip'] ?? null, 5),
            ];
        }

        return $stations;
    }
}
