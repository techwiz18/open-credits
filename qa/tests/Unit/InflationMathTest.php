<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\TestCase;

/**
 * Pure inflation-control math (#8). No XenForo boot: these helpers never
 * touch app state, matching the DayBoundaryTest approach.
 */
class InflationMathTest extends TestCase
{
    public function testNoCapOrFaucetCreditsFullAmount(): void
    {
        $this->assertSame(10.0, Transact::calculateCredit(10.0, 90.0, 0.0, PHP_FLOAT_MAX, 2));
    }

    public function testBalanceCapCreditsOnlyHeadroom(): void
    {
        $this->assertSame(10.0, Transact::calculateCredit(20.0, 90.0, 100.0, PHP_FLOAT_MAX, 2));
    }

    public function testBalanceCapAtLimitCreditsNothing(): void
    {
        $this->assertSame(0.0, Transact::calculateCredit(10.0, 100.0, 100.0, PHP_FLOAT_MAX, 2));
    }

    public function testFaucetCreditsRemainingAllowance(): void
    {
        $this->assertSame(3.5, Transact::calculateCredit(10.0, 0.0, 0.0, 3.5, 2));
    }

    public function testCapAndFaucetTakeTheSmaller(): void
    {
        $this->assertSame(5.0, Transact::calculateCredit(20.0, 90.0, 100.0, 5.0, 2));
    }

    public function testRoundingToCurrencyDecimals(): void
    {
        $this->assertSame(1.0, Transact::calculateCredit(1.4, 0.0, 0.0, PHP_FLOAT_MAX, 0));
        $this->assertSame(1.24, Transact::calculateCredit(1.238, 0.0, 0.0, PHP_FLOAT_MAX, 2));
    }

    public function testNonPositiveRequestCreditsNothing(): void
    {
        $this->assertSame(0.0, Transact::calculateCredit(0.0, 0.0, 0.0, PHP_FLOAT_MAX, 2));
        $this->assertSame(0.0, Transact::calculateCredit(-5.0, 0.0, 0.0, PHP_FLOAT_MAX, 2));
    }

    public function testDemurrageIsRateOfBalance(): void
    {
        $this->assertSame(10.0, Transact::calculateDemurrage(100.0, 10.0, 2));
        $this->assertSame(0.5, Transact::calculateDemurrage(5.0, 10.0, 2));
    }

    public function testDemurrageIsFlooredAtZero(): void
    {
        $this->assertSame(0.0, Transact::calculateDemurrage(0.0, 10.0, 2));
        $this->assertSame(0.0, Transact::calculateDemurrage(-5.0, 10.0, 2));
    }

    public function testDemurrageNeverExceedsBalance(): void
    {
        $this->assertSame(10.0, Transact::calculateDemurrage(10.0, 100.0, 2));
        $this->assertSame(10.0, Transact::calculateDemurrage(10.0, 150.0, 2));
    }

    public function testDemurrageRoundingToZeroSkips(): void
    {
        $this->assertSame(0.0, Transact::calculateDemurrage(4.0, 10.0, 0));
    }

    public function testZeroRateDoesNothing(): void
    {
        $this->assertSame(0.0, Transact::calculateDemurrage(100.0, 0.0, 2));
    }
}
