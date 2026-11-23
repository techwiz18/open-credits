<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Service\ContentCharge;
use PHPUnit\Framework\TestCase;

/**
 * Price rules for [charge] premium content: one purchase unlocks the whole
 * post, so the post costs its highest valid block at the currency's
 * precision. Non-positive, over-precise, and malformed blocks are ignored.
 * Pure — the parser-based extraction lives in the integration suite.
 */
class ContentChargeTest extends TestCase
{
    public function testHighestValidBlockWins(): void
    {
        $this->assertSame(50.0, ContentCharge::priceOf(['5', '50', '2.50'], 2));
        $this->assertSame(20.0, ContentCharge::priceOf(['20', '1.5'], 0));
        $this->assertSame(2.5, ContentCharge::priceOf(['2.50'], 2));
    }

    public function testNonPositivePricesAreIgnored(): void
    {
        $this->assertNull(ContentCharge::priceOf(['0', '-10'], 2));
        $this->assertSame(5.0, ContentCharge::priceOf(['0', '5'], 2));
    }

    public function testOverPrecisePricesAreIgnored(): void
    {
        $this->assertNull(ContentCharge::priceOf(['1.5'], 0));
        $this->assertSame(1.5, ContentCharge::priceOf(['1.5'], 2));
        $this->assertNull(ContentCharge::priceOf(['10.999'], 2));
    }

    public function testMalformedPricesAreIgnored(): void
    {
        $this->assertNull(ContentCharge::priceOf(['abc', ''], 2));
        $this->assertSame(5.0, ContentCharge::priceOf(['abc', '5'], 2));
    }

    public function testOverflowingPricesAreIgnored(): void
    {
        // A digits-only option regex still admits a value too large for a float.
        $this->assertNull(ContentCharge::priceOf([str_repeat('9', 400)], 2));
    }

    public function testNoBlocksHasNoPrice(): void
    {
        $this->assertNull(ContentCharge::priceOf([], 2));
    }
}
