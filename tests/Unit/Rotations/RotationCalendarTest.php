<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Unit\Rotations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Unified\SsoClient\Rotations\RotationCalendar;

/**
 * The package's copy of SSO's rotation expander against SSO's own golden
 * cases (tests/Fixtures/rotations/golden.json, copied byte for byte from
 * SSO's tests/Fixtures/rotations/golden.json). A failure here means the two
 * expanders disagree about which days a unit or person is on duty.
 */
class RotationCalendarTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<array<string, mixed>>}>
     */
    public static function goldenCases(): iterable
    {
        $golden = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/rotations/golden.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($golden['cases'] as $case) {
            foreach ($case['assignments'] as $index => $entry) {
                yield "{$case['name']} #{$index}" => [$case, $entry['assignment'], $entry['expected']];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array{start_day_index: int, starts_on?: string|null, ends_on?: string|null}  $assignment
     * @param  list<array<string, mixed>>  $expected
     */
    #[DataProvider('goldenCases')]
    public function test_golden_case(array $case, array $assignment, array $expected): void
    {
        $this->assertSame($expected, RotationCalendar::windows($case['rotation'], $assignment, $case['from'], $case['to'], $case['timezone']));
    }

    public function test_start_day_index_is_the_cycle_day_at_the_anchor_and_runs_backwards_before_it(): void
    {
        $this->assertSame(0, RotationCalendar::cycleDay(3, '2026-01-10', 0, '2026-01-10'));
        $this->assertSame(1, RotationCalendar::cycleDay(3, '2026-01-10', 1, '2026-01-10'));
        $this->assertSame(2, RotationCalendar::cycleDay(3, '2026-01-10', 0, '2026-01-09'));
        $this->assertSame(1, RotationCalendar::cycleDay(3, '2026-01-10', 0, '2026-01-08'));
        $this->assertSame(0, RotationCalendar::cycleDay(3, '2026-01-10', 1, '2026-01-09'));
        $this->assertSame(8, RotationCalendar::cycleDay(9, '2026-02-02', 0, '2026-02-01'));
    }

    public function test_a_24_hour_block_over_fall_back_keeps_its_wall_clock_end(): void
    {
        $windows = RotationCalendar::windows(
            ['cycle_days' => 1, 'anchor_date' => '2026-10-01', 'blocks' => [['day_index' => 0, 'start_time' => '07:00', 'duration_minutes' => 1440]]],
            ['start_day_index' => 0],
            '2026-10-31',
            '2026-10-31',
            'America/New_York',
        );

        $last = $windows[count($windows) - 1];
        $this->assertSame(['2026-10-31T07:00:00-04:00', '2026-11-01T07:00:00-05:00', 1440, 1500], [$last['starts_at'], $last['ends_at'], $last['scheduled_minutes'], $last['elapsed_minutes']]);
    }
}
