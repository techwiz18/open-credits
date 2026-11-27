<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Service\RequestCache;
use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\TestCase;

/**
 * Balance preload for thread views: one bulk fill must produce exactly what
 * per-user lookups return, including zero-balance rows for users and
 * currencies with no ledger activity.
 */
class BalancePreloadTest extends TestCase
{
    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    protected ?Transact $transact = null;
    protected int $memberId = 0;
    protected int $otherId = 0;
    protected int $currencyId = 0;

    protected function setUp(): void
    {
        if (!class_exists('XF', false) || !\XF::app()) {
            $this->markTestSkipped('Set OC_XF_ROOT to a licensed XenForo install to run integration tests.');
        }
        if (!\XF::$time) {
            \XF::$time = time();
        }
        $this->db = \XF::db();
        $this->transact = \XF::app()->service('OpenCredits\Credits:Transact');
        $this->currencyId = $this->transact->primaryCurrencyId();

        $ids = $this->db->fetchAllColumn(
            "SELECT user_id FROM xf_user WHERE user_state = 'valid' AND is_banned = 0 ORDER BY user_id LIMIT 2"
        );
        if (count($ids) < 2) {
            $this->markTestSkipped('Need at least two valid members.');
        }
        $this->memberId = (int)$ids[0];
        $this->otherId = (int)$ids[1];
        RequestCache::reset();
    }

    public function testPreloadMatchesLazyForMemberWithNoLedgerRows(): void
    {
        // A missing member cannot have balance rows, so this pins the
        // no-activity shape without depending on the dev forum's data.
        $ghost = 999999999;
        RequestCache::reset();
        $expected = $this->transact->balancesFor($ghost, true);
        RequestCache::reset();

        $this->transact->preloadBalances([$ghost], true);

        $this->assertSame($expected, $this->transact->balancesFor($ghost, true));
    }

    protected function tearDown(): void
    {
        RequestCache::reset();
        \XF::setVisitor(null);
    }

    public function testPreloadMatchesLazyLookups(): void
    {
        $this->transact->adjust($this->memberId, $this->currencyId, 7.5, 'admin_adjust');

        // Lazy path first: the per-user shape, including zero rows.
        $expectedMember = $this->transact->balancesFor($this->memberId, true);
        $expectedOther = $this->transact->balancesFor($this->otherId, true);
        $this->assertNotEmpty($expectedMember);
        RequestCache::reset();

        // Includes junk and duplicates without extra work or errors.
        $this->transact->preloadBalances([$this->memberId, 0, -3, $this->memberId, $this->otherId], true);

        $this->assertSame($expectedMember, $this->transact->balancesFor($this->memberId, true));
        $this->assertSame($expectedOther, $this->transact->balancesFor($this->otherId, true));
    }
}
