<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Divisions;

use Illuminate\Database\Eloquent\Model;

/**
 * Division lookups for the User model, the divisions counterpart of
 * HasMirroredQualifications. One division per person per company, read from
 * the membership pivot the mirror writes.
 *
 * @mixin Model
 */
trait HasMirroredDivision
{
    public function companyDivision(int $companyId): ?object
    {
        return DivisionCatalog::forUser((int) $this->getKey(), $companyId);
    }

    /**
     * The local `divisions.id`, for apps whose schedules and filters
     * reference the local key.
     */
    public function companyDivisionId(int $companyId): ?int
    {
        $division = $this->companyDivision($companyId);

        return $division === null ? null : (int) $division->id;
    }

    public function companyDivisionName(int $companyId): ?string
    {
        $division = $this->companyDivision($companyId);

        return $division === null ? null : (string) $division->name;
    }

    /**
     * An int is a local division id; a string is a case-insensitive name.
     */
    public function isInDivision(int|string $division, int $companyId): bool
    {
        $held = $this->companyDivision($companyId);

        if ($held === null) {
            return false;
        }

        if (is_int($division)) {
            return (int) $held->id === $division;
        }

        return mb_strtolower(trim((string) $held->name)) === mb_strtolower(trim($division));
    }
}
