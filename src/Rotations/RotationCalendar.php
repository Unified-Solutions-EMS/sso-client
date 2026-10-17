<?php

declare(strict_types=1);

namespace Unified\SsoClient\Rotations;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Expands a rotation into on-duty windows (units and rotations plan §2b,
 * decision D6). Pure and dependency-free (plain arrays in, plain arrays out,
 * PHP's own date classes only). Copied unchanged from SSO's
 * App\Support\Rotations\RotationCalendar: keep the two identical. The
 * golden cases in tests/Fixtures/rotations/golden.json are SSO's own file,
 * run by both test suites.
 *
 * Rotation: `{cycle_days, anchor_date (Y-m-d, day 1 of the cycle), blocks:
 * [{day_index (0-based), start_time (HH:MM), duration_minutes, label?}]}`.
 * Assignment: `{start_day_index, starts_on?, ends_on?}`; start_day_index is
 * the cycle day the assignee is on at the anchor date, so one rotation runs
 * several platoons at different start days. Null means "the bare pattern":
 * start day 0, no date limits.
 *
 * Time semantics: blocks are naive wall-clock times in the company's zone.
 * The cycle day of a date is pure date arithmetic,
 * `(date - anchor + start_day_index) mod cycle_days`, also before the
 * anchor (the cycle runs backwards). A block starts at its wall-clock time
 * on that date and ENDS at start wall clock + duration, also counted as wall
 * clock (D6): a 24-hour block over the spring-forward night ends at the
 * same 07:00 after 23 elapsed hours, and over the fall-back night after 25.
 * `elapsed_minutes` reports the real length. A start inside the
 * spring-forward gap (02:30 on that date) moves forward an hour, as PHP
 * resolves it; on the repeated fall-back hour the first (daylight) one is
 * used.
 */
final class RotationCalendar
{
    private const MINUTES_PER_DAY = 1440;

    /**
     * The windows that overlap the local dates $from..$to (inclusive),
     * including a block that started the day before and runs into $from.
     * Dates outside the assignment's starts_on..ends_on start no block.
     *
     * @param  array{cycle_days: int, anchor_date: string, blocks: list<array{day_index: int, start_time: string, duration_minutes: int, label?: string|null}>}  $rotation
     * @param  array{start_day_index: int, starts_on?: string|null, ends_on?: string|null}|null  $assignment
     * @return list<array{date: string, day_index: int, label: string|null, starts_at: string, ends_at: string, scheduled_minutes: int, elapsed_minutes: int}>
     */
    public static function windows(array $rotation, ?array $assignment, string $from, string $to, string $timezone): array
    {
        $zone = new DateTimeZone($timezone);
        $cycle = max(1, (int) $rotation['cycle_days']);
        $startDay = (int) ($assignment['start_day_index'] ?? 0);
        $startsOn = $assignment['starts_on'] ?? null;
        $endsOn = $assignment['ends_on'] ?? null;

        $byDay = [];
        $longest = 0;
        foreach ($rotation['blocks'] as $block) {
            $byDay[(int) $block['day_index']][] = $block;
            $longest = max($longest, (int) $block['duration_minutes']);
        }

        $rangeStart = self::local($from.' 00:00', $zone)->getTimestamp();
        $rangeEnd = self::local(self::addDays($to, 1).' 00:00', $zone)->getTimestamp();
        $lookBack = (int) ceil($longest / self::MINUTES_PER_DAY);
        $windows = [];

        for ($date = self::addDays($from, -$lookBack); $date <= $to; $date = self::addDays($date, 1)) {
            if (($startsOn !== null && $date < $startsOn) || ($endsOn !== null && $date > $endsOn)) {
                continue;
            }

            $dayIndex = self::cycleDay($cycle, (string) $rotation['anchor_date'], $startDay, $date);

            foreach ($byDay[$dayIndex] ?? [] as $block) {
                [$hour, $minute] = array_map(intval(...), explode(':', (string) $block['start_time']));
                $duration = (int) $block['duration_minutes'];
                $startMinutes = $hour * 60 + $minute;
                $endMinutes = $startMinutes + $duration;

                $start = self::local($date.' '.self::clock($startMinutes), $zone);
                $end = self::local(self::addDays($date, intdiv($endMinutes, self::MINUTES_PER_DAY)).' '.self::clock($endMinutes % self::MINUTES_PER_DAY), $zone);

                if ($end->getTimestamp() <= $rangeStart || $start->getTimestamp() >= $rangeEnd) {
                    continue;
                }

                $windows[] = [
                    'date' => $date,
                    'day_index' => $dayIndex,
                    'label' => isset($block['label']) && $block['label'] !== '' ? (string) $block['label'] : null,
                    'starts_at' => $start->format(DATE_ATOM),
                    'ends_at' => $end->format(DATE_ATOM),
                    'scheduled_minutes' => $duration,
                    'elapsed_minutes' => intdiv($end->getTimestamp() - $start->getTimestamp(), 60),
                ];
            }
        }

        usort($windows, fn (array $a, array $b): int => [$a['starts_at'], $a['day_index']] <=> [$b['starts_at'], $b['day_index']]);

        return $windows;
    }

    /**
     * The cycle day (0-based) an assignee with $startDay at the anchor is on
     * at local date $date.
     */
    public static function cycleDay(int $cycleDays, string $anchorDate, int $startDay, string $date): int
    {
        $cycle = max(1, $cycleDays);

        return ((self::dayNumber($date) - self::dayNumber($anchorDate) + $startDay) % $cycle + $cycle) % $cycle;
    }

    /** Days since 1970-01-01 of a Y-m-d date, without any time zone. */
    private static function dayNumber(string $date): int
    {
        return intdiv((int) self::utcDate($date)->getTimestamp(), 86400);
    }

    private static function addDays(string $date, int $days): string
    {
        return self::utcDate($date)->modify(($days >= 0 ? '+' : '').$days.' days')->format('Y-m-d');
    }

    private static function utcDate(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("Not a Y-m-d date: {$date}");
        }

        return $parsed;
    }

    private static function local(string $dateTime, DateTimeZone $zone): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $dateTime, $zone);

        if ($parsed === false) {
            throw new \InvalidArgumentException("Not a local date and time: {$dateTime}");
        }

        return $parsed;
    }

    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
