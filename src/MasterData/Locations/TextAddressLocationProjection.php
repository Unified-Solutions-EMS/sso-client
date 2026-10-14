<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Locations;

/**
 * The default projection: Crew-Scheduling's `locations` table (`name`,
 * `phone_number`, one free-text Google Autocomplete `address`,
 * `division_id`). SSO's structured address is written as one line and the
 * first phone number as the phone; the push splits the line back into
 * fields. No number, type or GPS columns.
 *
 * An app with the same idea but other column names extends this and
 * overrides table(), phoneColumn() or addressColumn().
 */
class TextAddressLocationProjection implements LocationProjection
{
    public function table(): string
    {
        return 'locations';
    }

    public function nameColumn(): string
    {
        return 'name';
    }

    public function numberColumn(): ?string
    {
        return null;
    }

    public function divisionColumn(): ?string
    {
        return 'division_id';
    }

    protected function phoneColumn(): string
    {
        return 'phone_number';
    }

    protected function addressColumn(): string
    {
        return 'address';
    }

    public function toColumns(array $location): array
    {
        $phones = is_array($location['phones'] ?? null) ? array_values($location['phones']) : [];
        $first = is_array($phones[0] ?? null) ? ($phones[0]['number'] ?? null) : null;

        return [
            $this->phoneColumn() => UsAddress::formatPhone(is_string($first) ? $first : null),
            $this->addressColumn() => UsAddress::format(is_array($location['address'] ?? null) ? $location['address'] : null),
        ];
    }

    public function written(int $localCompanyId, int $localId, array $location): void {}

    public function pushColumns(): array
    {
        return [$this->phoneColumn(), $this->addressColumn()];
    }

    public function toImport(int $localCompanyId, object $row): array
    {
        $phone = trim((string) ($row->{$this->phoneColumn()} ?? ''));
        $line = trim((string) ($row->{$this->addressColumn()} ?? ''));
        $address = $line === '' ? null : UsAddress::parse($line);

        return array_filter([
            'address' => $address ?? ($line === '' ? null : ['street' => $line]),
            'phones' => $phone === '' ? null : [['number' => $phone]],
        ], fn (mixed $value): bool => $value !== null);
    }
}
