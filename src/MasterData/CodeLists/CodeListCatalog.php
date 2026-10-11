<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\CodeLists;

use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Read side of a code-list mirror for apps that read SSO's list directly
 * (Drug Tracking's product linking, a picker): the company's turned-on rows
 * in SSO's order, decoded. Apps that keep their own structure (CloudPCR's
 * configuration groups) read that instead; their projection writes it.
 */
abstract class CodeListCatalog
{
    /** The registry entity, `medications` or `procedures`. */
    abstract protected static function entity(): string;

    /**
     * @return list<array<string, mixed>>
     */
    public static function usableForCompany(int $companyId): array
    {
        return array_values(array_filter(static::mirror()->items($companyId), fn (array $item): bool => $item['is_active']));
    }

    /**
     * What crews may chart: turned on, with at least one level, and reported
     * in the given state (FIPS) when the agency limits it to some states.
     *
     * @return list<array<string, mixed>>
     */
    public static function chartableForCompany(int $companyId, ?string $stateFips = null): array
    {
        return array_values(array_filter(
            static::usableForCompany($companyId),
            fn (array $item): bool => $item['is_chartable'] && ($stateFips === null || $item['only_states'] === [] || in_array($stateFips, $item['only_states'], true)),
        ));
    }

    protected static function mirror(): CodeListMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror(static::entity());

        assert($mirror instanceof CodeListMirror);

        return $mirror;
    }
}
