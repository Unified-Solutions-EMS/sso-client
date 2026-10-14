<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Support;

/**
 * What a sync does with a local child row whose SSO child is gone (a licence
 * removed from a person, a crew level from a vehicle, a phone from a
 * facility).
 *
 * - `unlink`: clear the SSO child id and stamp `sso_removed_at`; the row and
 *   everything the app keeps on it (HR's approval history and card images)
 *   stays. The default for any child table that declares nothing.
 * - `deactivate`: set the app's own column to its inactive value and stamp
 *   `sso_removed_at`, keeping the SSO link, so SSO listing the same child
 *   again turns it back on.
 * - `delete`: remove the row. Only for a pure mirror table: one the package
 *   owns, or an app table whose every column is written from SSO and that
 *   holds nothing of the app's own (CloudPCR's dem_* children once its
 *   editors are read-only). Never for a table that carries app data.
 *
 * A row stamped `sso_removed_at` is left out of the push, so a child SSO
 * removed is not sent back up as new (and removed again, and sent again).
 * SSO listing a child with the same key later adopts the row again and
 * clears the stamp.
 */
final class ChildRowPolicy
{
    /** Stamped on a kept row whose SSO child was removed; added by the child-row migration. */
    public const REMOVED_AT_COLUMN = 'sso_removed_at';

    private function __construct(
        public readonly ChildRemoval $removal,
        public readonly ?string $column = null,
        public readonly mixed $inactiveValue = null,
        public readonly mixed $activeValue = null,
    ) {}

    public static function unlink(): self
    {
        return new self(ChildRemoval::Unlink);
    }

    /**
     * @param  string  $column  the app's own on/off (or status) column on the child table
     * @param  mixed  $inactiveValue  written when SSO removes the child
     * @param  mixed  $activeValue  written when SSO lists the child again, only over $inactiveValue
     */
    public static function deactivate(string $column, mixed $inactiveValue = false, mixed $activeValue = true): self
    {
        return new self(ChildRemoval::Deactivate, $column, $inactiveValue, $activeValue);
    }

    public static function delete(): self
    {
        return new self(ChildRemoval::Delete);
    }

    /** The policy a child-table definition declares; unlink when it declares none. */
    public static function for(object $definition): self
    {
        return $definition instanceof DeclaresChildRowPolicy ? $definition->onRemoved() : self::unlink();
    }

    public function isInactive(object $row): bool
    {
        return $this->removal === ChildRemoval::Deactivate && self::same($row->{$this->column} ?? null, $this->inactiveValue);
    }

    public static function same(mixed $stored, mixed $value): bool
    {
        if ($stored === null || $value === null) {
            return $stored === null && $value === null;
        }

        if (is_bool($value)) {
            return (bool) $stored === $value;
        }

        return (string) $stored === (string) $value;
    }
}
