<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Support;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Writes the links of one SSO import mapping (`{local_id: sso_id}`) into one
 * link column without ever giving an SSO id to two local rows.
 *
 * SSO answers an agency's duplicates (the same facility code or VIN listed
 * twice locally) with both local ids mapped to ONE SSO row. The first local
 * row of the mapping, in the order the push sent them (by id), gets the
 * link; every other row mapped to an SSO id already taken is refused and
 * reported, never deleted or changed. A rerun of the same mapping links the
 * same rows again and refuses the same rest, so it never throws.
 *
 * Who may already hold an SSO id is asked of `$holders`: the rows the link
 * column's unique index spans, which can be wider than the company scope
 * the mirror reads through (CloudPCR's facility entries have no company_id,
 * so their index spans every company). A link another process writes between
 * the check and the write (a webhook delivery) makes the index refuse the
 * write; that is caught inside a savepoint and reported the same way.
 */
final class MappingLinker
{
    public const LOCAL_ROW_MISSING = 'local_row_missing';

    public const LINKED_TO_ANOTHER_SSO_ID = 'local_row_linked_to_another_sso_id';

    public const HELD_BY_ANOTHER_LOCAL_ROW = 'sso_id_held_by_another_local_row';

    /** @var array<int, int|string> local id keyed by the SSO id this mapping linked it to */
    private array $linked = [];

    /**
     * @param  Closure(): Builder  $holders
     */
    public function __construct(
        private readonly string $column,
        private readonly Closure $holders,
    ) {}

    /**
     * Why this row must not take the SSO id, or null when it may.
     *
     * @return array{reason: string, held_by: int|string|null}|null
     */
    public function refusal(?object $row, int|string $localId, int $ssoId): ?array
    {
        if ($row === null) {
            return self::refuse(self::LOCAL_ROW_MISSING);
        }

        $current = $row->{$this->column};

        if ($current !== null && (int) $current !== $ssoId) {
            return self::refuse(self::LINKED_TO_ANOTHER_SSO_ID);
        }

        if (isset($this->linked[$ssoId]) && $this->linked[$ssoId] !== $localId) {
            return self::refuse(self::HELD_BY_ANOTHER_LOCAL_ROW, $this->linked[$ssoId]);
        }

        $holder = $this->holder($ssoId, $localId);

        return $holder === null ? null : self::refuse(self::HELD_BY_ANOTHER_LOCAL_ROW, $holder);
    }

    /**
     * Runs the link write in a savepoint and records the link, or answers
     * the refusal when the unique index turned it down.
     *
     * @param  Closure(): mixed  $write
     * @return array{reason: string, held_by: int|string|null}|null
     */
    public function write(int|string $localId, int $ssoId, Closure $write): ?array
    {
        try {
            DB::transaction($write);
        } catch (UniqueConstraintViolationException) {
            return self::refuse(self::HELD_BY_ANOTHER_LOCAL_ROW, $this->holder($ssoId, $localId));
        }

        $this->linked[$ssoId] = $localId;

        return null;
    }

    private function holder(int $ssoId, int|string $localId): int|string|null
    {
        $holder = ($this->holders)()->where($this->column, $ssoId)->where('id', '!=', $localId)->orderBy('id')->value('id');

        return is_numeric($holder) ? (int) $holder : (is_string($holder) ? $holder : null);
    }

    /**
     * @return array{reason: string, held_by: int|string|null}
     */
    private static function refuse(string $reason, int|string|null $heldBy = null): array
    {
        return ['reason' => $reason, 'held_by' => $heldBy];
    }
}
