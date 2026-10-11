<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Medications;

use Unified\SsoClient\MasterData\CodeLists\CodeListMirror;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;

/**
 * Mirrors SSO's medications (the codes crews chart and the state accepts,
 * RxNorm or SNOMED for blood products, each with the levels permitted to
 * give it) into `sso_medications`, and through the bound
 * MedicationProjection into the app (CodeListMirror has the rules).
 *
 * SSO owns the controlled flag and DEA schedule (decision S18): the mirror
 * stores what SSO sends; an app may be stricter for its own products, never
 * looser. A medication without levels is stock only.
 */
class MedicationMirror extends CodeListMirror
{
    public const TABLE = 'sso_medications';

    public const SSO_ID_COLUMN = 'sso_medication_id';

    public function __construct(LocalTenantResolver $tenants, MasterDataClient $client, MedicationProjection $projection)
    {
        parent::__construct($tenants, $client, $projection);
    }

    public function entity(): string
    {
        return 'medications';
    }

    protected static function prefix(): string
    {
        return 'medication';
    }

    public function table(): string
    {
        return self::TABLE;
    }

    public function ssoIdColumn(): string
    {
        return self::SSO_ID_COLUMN;
    }

    protected function extraColumns(array $record): array
    {
        $controlled = (bool) ($record['is_controlled'] ?? false);

        return [
            'ingredient_rxcui' => self::text($record['ingredient_rxcui'] ?? null),
            'is_controlled' => $controlled,
            'dea_schedule' => $controlled ? self::text($record['dea_schedule'] ?? null) : null,
            'is_blood_product' => (bool) ($record['is_blood_product'] ?? false),
        ];
    }

    protected function extraPushFields(array $item): array
    {
        $controlled = (bool) ($item['is_controlled'] ?? false);

        return [
            'is_controlled' => $controlled,
            'dea_schedule' => $controlled ? self::text($item['dea_schedule'] ?? null) : null,
        ];
    }

    protected function extraItemFields(object $row): array
    {
        return [
            'ingredient_rxcui' => $row->ingredient_rxcui,
            'is_controlled' => (bool) $row->is_controlled,
            'dea_schedule' => $row->dea_schedule,
            'is_blood_product' => (bool) $row->is_blood_product,
        ];
    }

    protected function localExtraColumns(array $item): array
    {
        return $this->extraPushFields($item) + ['is_blood_product' => false];
    }

    protected function merge(array $first, array $second): array
    {
        $merged = parent::merge($first, $second);
        $merged['is_controlled'] = ($first['is_controlled'] ?? false) || ($second['is_controlled'] ?? false);
        $merged['dea_schedule'] = self::strictest($first['dea_schedule'] ?? null, $second['dea_schedule'] ?? null);

        return array_filter($merged, fn (mixed $value): bool => $value !== null);
    }

    /** Schedule II is stricter than V. */
    private static function strictest(?string $first, ?string $second): ?string
    {
        $order = ['II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5];
        $known = array_filter([$first, $second], fn (?string $schedule): bool => $schedule !== null && isset($order[strtoupper($schedule)]));
        usort($known, fn (string $a, string $b): int => $order[strtoupper($a)] <=> $order[strtoupper($b)]);

        return $known[0] ?? $first ?? $second;
    }
}
