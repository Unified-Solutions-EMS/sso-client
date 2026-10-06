<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Qualifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Qualification lookups for the User model, the qualifications counterpart of
 * SyncsCompanyRoles::companyRoleNames(). Reads the SSO mirror and only answers
 * with qualifications that are active and apply to this app.
 *
 * @mixin Model
 */
trait HasMirroredQualifications
{
    /**
     * @return Collection<int, object>
     */
    public function companyQualifications(int $companyId): Collection
    {
        return QualificationCatalog::heldBy((int) $this->getKey(), $companyId);
    }

    /**
     * @return list<string>
     */
    public function companyQualificationNames(int $companyId): array
    {
        return $this->companyQualifications($companyId)->pluck('name')->map(fn ($name): string => (string) $name)->values()->all();
    }

    /**
     * Local `qualifications.id` values, for apps whose shifts and gates
     * reference the local key.
     *
     * @return list<int>
     */
    public function companyQualificationIds(int $companyId): array
    {
        return $this->companyQualifications($companyId)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * An int is a local qualification id; a string is a case-insensitive name.
     */
    public function hasQualificationInCompany(int|string $qualification, int $companyId): bool
    {
        $held = $this->companyQualifications($companyId);

        if (is_int($qualification)) {
            return $held->contains(fn (object $row): bool => (int) $row->id === $qualification);
        }

        $needle = mb_strtolower(trim($qualification));

        return $held->contains(fn (object $row): bool => mb_strtolower(trim((string) $row->name)) === $needle);
    }
}
