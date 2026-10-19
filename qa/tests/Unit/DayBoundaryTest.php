<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\TestCase;

/**
 * Pure boundary math for the configurable earning day (#11). No XenForo boot:
 * the bootstrap stubs the service base class and Transact::dayStartAt() never
 * touches app state.
 *
 * The "no double-award" guarantee comes from Transact's ledger check
 * (`log_date >= dayStart()`); these tests prove the boundary is stable for a
 * whole local day and moves exactly once across DST transitions.
 */
class DayBoundaryTest extends TestCase
{
    private function ts(string $utc): int
    {
        return strtotime($utc . ' UTC');
    }

    public function testUtcBoundaryIsMidnight(): void
    {
        $now = $this->ts('2026-06-15 13:37:21');
        $this->assertSame($now - ($now % 86400), Transact::dayStartAt($now, 'utc'));
    }

    public function testUnknownModeFallsBackToUtc(): void
    {
        $now = $this->ts('2026-06-15 13:37:21');
        $this->assertSame(
            Transact::dayStartAt($now, 'utc'),
            Transact::dayStartAt($now, 'nonsense')
        );
    }

    public function testFixedPositiveOffsetBoundary(): void
    {
        // UTC+5: local midnight is 19:00 UTC the previous day.
        $before = $this->ts('2026-06-15 18:59:59');
        $at = $this->ts('2026-06-15 19:00:00');

        $this->assertSame($this->ts('2026-06-14 19:00:00'), Transact::dayStartAt($before, 'fixed', 5));
        $this->assertSame($this->ts('2026-06-15 19:00:00'), Transact::dayStartAt($at, 'fixed', 5));
    }

    public function testFixedNegativeOffsetBoundary(): void
    {
        // UTC-5: local midnight is 05:00 UTC.
        $before = $this->ts('2026-06-15 04:59:59');
        $at = $this->ts('2026-06-15 05:00:00');

        $this->assertSame($this->ts('2026-06-14 05:00:00'), Transact::dayStartAt($before, 'fixed', -5));
        $this->assertSame($this->ts('2026-06-15 05:00:00'), Transact::dayStartAt($at, 'fixed', -5));
    }

    public function testFixedOffsetIsClamped(): void
    {
        $now = $this->ts('2026-06-15 13:37:21');

        $this->assertSame(
            Transact::dayStartAt($now, 'fixed', 14),
            Transact::dayStartAt($now, 'fixed', 100)
        );
        $this->assertSame(
            Transact::dayStartAt($now, 'fixed', -12),
            Transact::dayStartAt($now, 'fixed', -100)
        );
    }

    public function testBoardBoundaryWithoutDst(): void
    {
        // America/New_York is UTC-5 in January.
        $now = $this->ts('2026-01-15 12:00:00');
        $this->assertSame(
            $this->ts('2026-01-15 05:00:00'),
            Transact::dayStartAt($now, 'board', 0, 'America/New_York')
        );
    }

    public function testBoardBoundarySpringForwardIsSingleStableDay(): void
    {
        // DST starts 2026-03-08 02:00 EST; local midnight is still EST (UTC-5).
        $early = $this->ts('2026-03-08 06:00:00'); // 01:00 EST
        $noon = $this->ts('2026-03-08 12:00:00');  // 08:00 EDT
        $late = $this->ts('2026-03-08 23:00:00');  // 19:00 EDT

        $expected = $this->ts('2026-03-08 05:00:00');
        $this->assertSame($expected, Transact::dayStartAt($early, 'board', 0, 'America/New_York'));
        $this->assertSame($expected, Transact::dayStartAt($noon, 'board', 0, 'America/New_York'));
        $this->assertSame($expected, Transact::dayStartAt($late, 'board', 0, 'America/New_York'));

        // Next local day is 23 hours long (EDT midnight at 04:00 UTC).
        $next = $this->ts('2026-03-09 12:00:00');
        $this->assertSame(
            $this->ts('2026-03-09 04:00:00'),
            Transact::dayStartAt($next, 'board', 0, 'America/New_York')
        );
    }

    public function testBoardBoundaryFallBackIsSingleStableDay(): void
    {
        // DST ends 2026-11-01 02:00 EDT; local midnight is EDT (UTC-4).
        $early = $this->ts('2026-11-01 05:00:00'); // 01:00 EDT
        $noon = $this->ts('2026-11-01 12:00:00');  // 07:00 EST
        $late = $this->ts('2026-11-01 23:00:00');  // 18:00 EST

        $expected = $this->ts('2026-11-01 04:00:00');
        $this->assertSame($expected, Transact::dayStartAt($early, 'board', 0, 'America/New_York'));
        $this->assertSame($expected, Transact::dayStartAt($noon, 'board', 0, 'America/New_York'));
        $this->assertSame($expected, Transact::dayStartAt($late, 'board', 0, 'America/New_York'));

        // Next local day is 25 hours long (EST midnight at 05:00 UTC).
        $next = $this->ts('2026-11-02 12:00:00');
        $this->assertSame(
            $this->ts('2026-11-02 05:00:00'),
            Transact::dayStartAt($next, 'board', 0, 'America/New_York')
        );
    }

    public function testInvalidBoardTimeZoneFallsBackToUtc(): void
    {
        $now = $this->ts('2026-06-15 13:37:21');
        $this->assertSame(
            Transact::dayStartAt($now, 'utc'),
            Transact::dayStartAt($now, 'board', 0, 'Not/AZone')
        );
    }

    public function testBoundaryIsMonotonicAcrossDst(): void
    {
        $start = $this->ts('2026-03-07 00:00:00');
        $end = $this->ts('2026-03-10 00:00:00');
        $previous = null;

        for ($now = $start; $now <= $end; $now += 3600) {
            $boundary = Transact::dayStartAt($now, 'board', 0, 'America/New_York');

            $this->assertLessThanOrEqual($now, $boundary);
            $this->assertLessThanOrEqual(90000, $now - $boundary, 'No local day may exceed 25 hours');

            if ($previous !== null) {
                $this->assertGreaterThanOrEqual($previous, $boundary);
            }
            $previous = $boundary;
        }
    }
}
