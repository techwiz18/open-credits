<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\BbCode\Charge;
use PHPUnit\Framework\TestCase;

/**
 * The unlock endpoint validates the submitted price against the charge blocks
 * actually present in the post. This covers the extractor that makes that
 * check possible (no XenForo boot needed).
 */
class ChargeAmountsTest extends TestCase
{
    public function testExtractsSingleAmount(): void
    {
        $this->assertSame(['10.00'], Charge::amountsForMessage('[charge=10]secret[/charge]'));
    }

    public function testCaseInsensitiveAndDecimal(): void
    {
        $this->assertSame(['5.50'], Charge::amountsForMessage('[CHARGE=5.5]x[/CHARGE]'));
    }

    public function testUniqueAcrossBlocks(): void
    {
        $this->assertSame(
            ['10.00', '2.50'],
            Charge::amountsForMessage('[charge=10]a[/charge] [charge=2.50]b[/charge] [charge=10]c[/charge]')
        );
    }

    public function testToleratesSpaces(): void
    {
        $this->assertSame(['3.00'], Charge::amountsForMessage('[charge = 3]x[/charge]'));
    }

    public function testIgnoresInvalidSyntax(): void
    {
        $this->assertSame([], Charge::amountsForMessage('no charge here'));
        $this->assertSame([], Charge::amountsForMessage('[charge]x[/charge]'));
        $this->assertSame([], Charge::amountsForMessage('[charge=abc]x[/charge]'));
        $this->assertSame([], Charge::amountsForMessage('[charge=10.999]x[/charge]'));
    }
}
