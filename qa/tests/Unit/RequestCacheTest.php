<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Service\ContentCharge;
use OpenCredits\Credits\Service\RequestCache;
use PHPUnit\Framework\TestCase;

/**
 * The one request-scoped memo: every section loads once, and invalidate()
 * drops all of them together so a write cannot leave a stale read behind.
 */
class RequestCacheTest extends TestCase
{
    protected function setUp(): void
    {
        RequestCache::reset();
    }

    protected function tearDown(): void
    {
        RequestCache::reset();
    }

    public function testInvalidateDropsEverySection(): void
    {
        $cache = RequestCache::instance();
        $loads = [
            'balance' => 0,
            'events' => 0,
            'primary' => 0,
            'price' => 0,
            'unlock' => 0,
            'charge' => 0,
        ];

        $balance = function () use (&$loads): array {
            $loads['balance']++;
            return [['currency_id' => 1, 'balance' => '1.00']];
        };
        $events = function () use (&$loads): array {
            $loads['events']++;
            return [['event_id' => 1]];
        };
        $primary = function () use (&$loads): int {
            $loads['primary']++;
            return 1;
        };
        $price = function () use (&$loads): float {
            $loads['price']++;
            return 5.0;
        };
        $unlock = function () use (&$loads): int {
            $loads['unlock']++;
            return 3;
        };
        $charge = function () use (&$loads): ContentCharge {
            $loads['charge']++;
            return new ContentCharge(null, static function (): void {
            }, static function (): void {
            });
        };

        $first = [
            $cache->balances(7, true, $balance),
            $cache->eventRows('post', $events),
            $cache->primaryCurrencyId($primary),
            $cache->price('post:1', $price),
            $cache->unlockCount('post:1', $unlock),
            $cache->contentCharge($charge),
        ];
        // A second read is a cache hit: no loader runs again.
        $cache->balances(7, true, $balance);
        $cache->eventRows('post', $events);
        $cache->primaryCurrencyId($primary);
        $cache->price('post:1', $price);
        $cache->unlockCount('post:1', $unlock);
        $cache->contentCharge($charge);
        $this->assertSame(array_fill_keys(array_keys($loads), 1), $loads);

        $cache->invalidate();

        $this->assertSame([['currency_id' => 1, 'balance' => '1.00']], $cache->balances(7, true, $balance));
        $this->assertSame([['event_id' => 1]], $cache->eventRows('post', $events));
        $this->assertSame(1, $cache->primaryCurrencyId($primary));
        $this->assertSame(5.0, $cache->price('post:1', $price));
        $this->assertSame(3, $cache->unlockCount('post:1', $unlock));
        $this->assertNotSame($first[5], $cache->contentCharge($charge));
        $this->assertSame(array_fill_keys(array_keys($loads), 2), $loads);
    }

    public function testStoredPreloadRowsAreVisibleToReads(): void
    {
        $cache = RequestCache::instance();
        $cache->storeBalances(9, false, [['currency_id' => 2, 'balance' => '2.00']]);

        $this->assertTrue($cache->hasBalances(9, false));
        $this->assertFalse($cache->hasBalances(9, true));
        $this->assertSame(
            [['currency_id' => 2, 'balance' => '2.00']],
            $cache->balances(9, false, function (): array {
                $this->fail('A stored preload row must not reload.');
            })
        );
    }

    public function testResetStartsANewRequest(): void
    {
        $first = RequestCache::instance();
        RequestCache::reset();
        $this->assertNotSame($first, RequestCache::instance());
    }
}
