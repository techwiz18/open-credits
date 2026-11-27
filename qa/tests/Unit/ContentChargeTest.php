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

    /**
     * Several [charge] blocks on one post share one prior-purchase read per
     * member, while a different member or content asks the database again.
     */
    public function testAccessReadsPaidOncePerContentAndMember(): void
    {
        $db = new class {
            /** @var int */
            public $reads = 0;

            public function fetchOne($sql, array $params = [])
            {
                $this->reads++;
                return null;
            }
        };
        $charge = new ContentCharge($db, function (): void {
        }, function (): void {
        });

        $content = new class {
            public $user_id = 2;

            public function getEntityContentType(): string
            {
                return 'post';
            }

            public function getEntityId(): int
            {
                return 10;
            }
        };
        $visitor = new class {
            public $user_id = 1;

            public function hasPermission($group, $permission): bool
            {
                return $permission === 'ocView';
            }
        };

        $this->assertSame('locked', $charge->access($content, $visitor));
        $this->assertSame(1, $db->reads, 'First access reads the charge row');
        $this->assertSame('locked', $charge->access($content, $visitor));
        $this->assertSame(1, $db->reads, 'A repeat access for the same content and member is memoised');

        $otherVisitor = new class {
            public $user_id = 3;

            public function hasPermission($group, $permission): bool
            {
                return $permission === 'ocView';
            }
        };
        $this->assertSame('locked', $charge->access($content, $otherVisitor));
        $this->assertSame(2, $db->reads, 'A different member reads the database');

        $otherContent = new class {
            public $user_id = 2;

            public function getEntityContentType(): string
            {
                return 'post';
            }

            public function getEntityId(): int
            {
                return 11;
            }
        };
        $this->assertSame('locked', $charge->access($otherContent, $visitor));
        $this->assertSame(3, $db->reads, 'A different content reads the database');
    }
}
