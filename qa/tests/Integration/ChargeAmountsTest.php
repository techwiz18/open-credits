<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\BbCode\Charge;
use PHPUnit\Framework\TestCase;

/**
 * The unlock endpoint derives the price from XenForo's own BBCode parse tree,
 * so it only accepts tags that actually render as charge blocks. This needs
 * the real parser/rules and therefore skips without OC_XF_ROOT.
 */
class ChargeAmountsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('XF', false) || !\XF::app()) {
            $this->markTestSkipped('Set OC_XF_ROOT to a licensed XenForo install to run integration tests.');
        }
    }

    public function testExtractsRenderedChargeTags(): void
    {
        $this->assertSame(['10'], Charge::amountsInMessage('[charge=10]secret[/charge]'));
        $this->assertSame(['5.5'], Charge::amountsInMessage('[CHARGE=5.5]x[/CHARGE]'));
        $this->assertSame(
            ['10', '2.50'],
            Charge::amountsInMessage('[charge=10]a[/charge] [charge=2.50]b[/charge] [charge=10]c[/charge]')
        );
    }

    public function testIgnoresMalformedTags(): void
    {
        $this->assertSame([], Charge::amountsInMessage('[charge = 3]x[/charge]'));
        $this->assertSame([], Charge::amountsInMessage('[charge]x[/charge]'));
        $this->assertSame([], Charge::amountsInMessage('[charge=abc]x[/charge]'));
        $this->assertSame([], Charge::amountsInMessage('[charge=10.999]x[/charge]'));
    }

    public function testIgnoresCodeAndPlain(): void
    {
        $this->assertSame([], Charge::amountsInMessage('[code][charge=10]x[/charge][/code]'));
        $this->assertSame([], Charge::amountsInMessage('[plain][charge=10]x[/charge][/plain]'));
    }

    public function testIncludesRichCode(): void
    {
        $this->assertSame(['10'], Charge::amountsInMessage('[code=rich][charge=10]x[/charge][/code]'));
    }

    public function testMaxAmountPicksHighestValidPrice(): void
    {
        $this->assertSame(50.0, Charge::maxAmount('[charge=5]a[/charge][charge=50]b[/charge]', 2));
        $this->assertSame(20.0, Charge::maxAmount('[charge=20]a[/charge][charge=1.5]b[/charge]', 0));
        $this->assertSame(2.5, Charge::maxAmount('[charge=2.50]a[/charge]', 2));
        $this->assertNull(Charge::maxAmount('[charge=0]a[/charge]', 2));
        $this->assertNull(Charge::maxAmount('[charge=1.5]a[/charge]', 0));
        $this->assertNull(Charge::maxAmount('no charge', 2));
        $this->assertNull(Charge::maxAmount('[code][charge=10]x[/charge][/code]', 2));
    }

}
