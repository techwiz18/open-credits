<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Service\InflationClamp;
use PHPUnit\Framework\TestCase;

/**
 * The shared inflation verdict: earn awards (partial credit) and redeem
 * codes (all-or-nothing) must agree, so the cap/faucet matrix lives here
 * in one interface instead of one copy per caller.
 */
class InflationClampTest extends TestCase
{
    public function testUnclampedRequestCreditsInFullAndFits(): void
    {
        $this->assertSame(10.0, InflationClamp::credited(10.0, 90.0, 0.0, 0.0, 0.0, 2));
        $this->assertTrue(InflationClamp::fitsFull(10.0, 90.0, 0.0, 0.0, 0.0, 2));
    }

    public function testCapPartiallyCreditsEarnButRejectsRedeem(): void
    {
        // A 20 award against 10 headroom credits 10; a 20 code is capped.
        $this->assertSame(10.0, InflationClamp::credited(20.0, 90.0, 0.0, 100.0, 0.0, 2));
        $this->assertFalse(InflationClamp::fitsFull(20.0, 90.0, 0.0, 100.0, 0.0, 2));
        $this->assertTrue(InflationClamp::fitsFull(10.0, 90.0, 0.0, 100.0, 0.0, 2));
    }

    public function testExactFitIsNotCapped(): void
    {
        // Headroom exactly covers the request: full credit, redeem fits.
        $this->assertSame(10.0, InflationClamp::credited(10.0, 90.0, 0.0, 100.0, 0.0, 2));
        $this->assertTrue(InflationClamp::fitsFull(10.0, 90.0, 0.0, 100.0, 0.0, 2));
    }

    public function testFaucetPartiallyCreditsEarnButRejectsRedeem(): void
    {
        // 3.5 of faucet left: earn takes 3.5, a 10 code is capped.
        $this->assertSame(3.5, InflationClamp::credited(10.0, 0.0, 4.5, 0.0, 8.0, 2));
        $this->assertFalse(InflationClamp::fitsFull(10.0, 0.0, 4.5, 0.0, 8.0, 2));
        $this->assertTrue(InflationClamp::fitsFull(3.5, 0.0, 4.5, 0.0, 8.0, 2));
    }

    public function testCapAndFaucetTakeTheSmaller(): void
    {
        $this->assertSame(5.0, InflationClamp::credited(20.0, 90.0, 3.0, 100.0, 8.0, 2));
        $this->assertFalse(InflationClamp::fitsFull(20.0, 90.0, 3.0, 100.0, 8.0, 2));
    }

    public function testSubUnitHeadroomFloorsToZeroOnZeroDecimalCurrency(): void
    {
        // Legacy 4.50 balance on a 0-decimal currency with cap 5: the 0.50
        // headroom floors to 0, so earn credits nothing and redeem is
        // capped. (The old redeem check floored the balance instead and
        // let this through — the drift this seam fixes.)
        $this->assertSame(0.0, InflationClamp::credited(1.0, 4.5, 0.0, 5.0, 0.0, 0));
        $this->assertFalse(InflationClamp::fitsFull(1.0, 4.5, 0.0, 5.0, 0.0, 0));
    }

    public function testNonPositiveRequestCreditsNothingAndNeverFits(): void
    {
        $this->assertSame(0.0, InflationClamp::credited(0.0, 0.0, 0.0, 0.0, 0.0, 2));
        $this->assertSame(0.0, InflationClamp::credited(-5.0, 0.0, 0.0, 0.0, 0.0, 2));
        $this->assertFalse(InflationClamp::fitsFull(0.0, 0.0, 0.0, 0.0, 0.0, 2));
        $this->assertFalse(InflationClamp::fitsFull(-5.0, 0.0, 0.0, 0.0, 0.0, 2));
    }

    public function testClaimMarkerMeansConfiguredButClamped(): void
    {
        // Awarded: nothing to mark.
        $this->assertFalse(InflationClamp::shouldMarkClaimed(true, true, true));
        // Configured and eligible but nothing credited: mark the day.
        $this->assertTrue(InflationClamp::shouldMarkClaimed(false, true, true));
        // Nothing configured: no marker, so enabling an event later the
        // same day finds no stale block.
        $this->assertFalse(InflationClamp::shouldMarkClaimed(false, false, true));
        // Ineligible: no marker, so unbanning later the same day finds no
        // stale block on the award.
        $this->assertFalse(InflationClamp::shouldMarkClaimed(false, true, false));
        $this->assertFalse(InflationClamp::shouldMarkClaimed(false, false, false));
    }

    public function testOnlyFaucetBoundClampsMarkTheDay(): void
    {
        // Faucet exhausted: every amount clamps on the faucet alone.
        $this->assertTrue(InflationClamp::allFaucetBound([10.0], 8.0, 8.0, 8.0, 2));
        // Cap-bound with the faucet off: an amount would credit without
        // the cap, so no marker — headroom can free later the same day.
        $this->assertFalse(InflationClamp::allFaucetBound([10.0], 5.0, 0.0, 0.0, 2));
        // Faucet room left: retry-worthy even though the cap also binds.
        $this->assertFalse(InflationClamp::allFaucetBound([10.0], 5.0, 3.0, 8.0, 2));
        // One retry-worthy amount vetoes the marker for the whole day.
        $this->assertFalse(InflationClamp::allFaucetBound([10.0, 1.0], 8.0, 7.0, 8.0, 2));
        // No primary amounts (dust or no primary event): nothing proved
        // faucet-bound, so leave no marker and let the day retry.
        $this->assertFalse(InflationClamp::allFaucetBound([], 5.0, 0.0, 0.0, 2));
        $this->assertTrue(InflationClamp::allFaucetBound([0.001], 0.0, 0.0, 0.0, 2));
    }

    public function testRoundingToCurrencyDecimals(): void
    {
        $this->assertSame(1.0, InflationClamp::credited(1.4, 0.0, 0.0, 0.0, 0.0, 0));
        $this->assertSame(1.24, InflationClamp::credited(10.0, 3.756, 0.0, 5.0, 0.0, 2));
    }
}
