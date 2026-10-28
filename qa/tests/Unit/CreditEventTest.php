<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Entity\CreditEvent;
use PHPUnit\Framework\TestCase;

/**
 * The shared id-list decoder: comma-separated admin input and JSON-stored
 * rows must compare equal, including repeated ids ("4,4" is "4"), or the
 * exact-duplicate guard can be slipped past.
 */
class CreditEventTest extends TestCase
{
    public function testDecodeIdListDedupesRepeatedIds(): void
    {
        $this->assertSame([4], CreditEvent::decodeIdList('4,4'));
        $this->assertSame([4], CreditEvent::decodeIdList('4, 4'));
        $this->assertSame([1, 2], CreditEvent::decodeIdList('1,2,1'));
    }

    public function testDecodeIdListAcceptsJsonAndArrays(): void
    {
        $this->assertSame([1, 2], CreditEvent::decodeIdList('[1,2]'));
        $this->assertSame([1, 2], CreditEvent::decodeIdList([1, 2]));
        // Order is preserved; callers sort when order must not matter.
        $this->assertSame([2, 1], CreditEvent::decodeIdList('2,1'));
    }

    public function testDecodeIdListRejectsJunk(): void
    {
        $this->assertSame([], CreditEvent::decodeIdList(''));
        $this->assertSame([], CreditEvent::decodeIdList('abc'));
        $this->assertSame([], CreditEvent::decodeIdList('0,-1'));
        $this->assertSame([], CreditEvent::decodeIdList(null));
        $this->assertSame([], CreditEvent::decodeIdList([]));
    }
}
