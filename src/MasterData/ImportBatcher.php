<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData;

/**
 * Sends a seed payload to SSO's import endpoint in chunks no larger than the
 * entity's row cap (SSO answers 422 above it) and folds the answers back into
 * the single response one request would have produced.
 *
 * An entity under the cap is one request, unchanged. Assignments (divisions,
 * qualifications) are resolved by SSO against the rows of the same request,
 * so each chunk carries only the assignments that point at its own rows.
 */
class ImportBatcher
{
    public function __construct(private readonly MasterDataClient $client) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function import(string $entity, int|string $ssoCompanyId, array $payload, int $batchSize): array
    {
        $rows = is_array($payload[$entity] ?? null) ? array_values($payload[$entity]) : [];
        $batchSize = max(1, $batchSize);

        if (count($rows) <= $batchSize) {
            return $this->client->import($entity, $ssoCompanyId, $payload);
        }

        $merged = [];

        foreach (array_chunk($rows, $batchSize) as $chunk) {
            $request = [$entity => $chunk] + $payload;

            if (is_array($payload['assignments'] ?? null)) {
                $request['assignments'] = self::assignmentsFor($payload['assignments'], $chunk);
            }

            $merged = self::merge($merged, $this->client->import($entity, $ssoCompanyId, $request));
        }

        return $merged;
    }

    /**
     * @param  array<int|string, mixed>  $assignments
     * @param  list<array<string, mixed>>  $chunk
     * @return list<array<string, mixed>>
     */
    private static function assignmentsFor(array $assignments, array $chunk): array
    {
        $ids = array_flip(array_map(fn (mixed $row): string => (string) (is_array($row) ? ($row['local_id'] ?? '') : ''), $chunk));
        $scoped = [];

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            if (array_key_exists('local_qualification_ids', $assignment)) {
                $own = array_values(array_filter((array) $assignment['local_qualification_ids'], fn (mixed $id): bool => isset($ids[(string) $id])));

                if ($own !== []) {
                    $scoped[] = ['local_qualification_ids' => $own] + $assignment;
                }

                continue;
            }

            if (isset($ids[(string) ($assignment['local_division_id'] ?? '')])) {
                $scoped[] = $assignment;
            }
        }

        return $scoped;
    }

    /**
     * Counts add up, lists concatenate, keyed maps (the mapping) merge, and a
     * scalar that is not a count (a review id) keeps its first value.
     *
     * @param  array<string, mixed>  $into
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private static function merge(array $into, array $response): array
    {
        foreach ($response as $key => $value) {
            if (! array_key_exists($key, $into)) {
                $into[$key] = $value;

                continue;
            }

            $current = $into[$key];

            if (is_int($current) && is_int($value) && ! str_ends_with((string) $key, '_id')) {
                $into[$key] = $current + $value;
            } elseif (is_array($current) && is_array($value)) {
                $into[$key] = array_is_list($current) && array_is_list($value)
                    ? array_merge($current, $value)
                    : $value + $current;
            } elseif ($current === null) {
                $into[$key] = $value;
            }
        }

        return $into;
    }
}
