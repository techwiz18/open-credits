<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\TestCase;

/**
 * DB-backed proof for the history-pruning invariant (#10B): pruning old
 * ledger rows into per-member snapshots must not change any balance once the
 * cache is rebuilt from snapshots + surviving rows.
 *
 * Runs against the licensed dev forum (see tests/README.md). Configure with
 *   OC_XF_ROOT=/var/www/html ./vendor/bin/phpunit -c phpunit-integration.xml
 * and it skips cleanly everywhere else (including public CI).
 *
 * Isolation: every row is written under a synthetic user id above every real
 * id, so no member's ledger, snapshot, or balance is touched. tearDown()
 * removes the synthetic rows and rebuilds.
 */
class PruneHistoryTest extends TestCase
{
    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    /** @var Transact */
    protected $transact;

    protected int $userId = 0;
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

        $this->userId = 1000 + max(
            (int)$this->db->fetchOne('SELECT COALESCE(MAX(user_id), 0) FROM xf_user'),
            (int)$this->db->fetchOne('SELECT COALESCE(MAX(user_id), 0) FROM xf_oc_transaction'),
            (int)$this->db->fetchOne('SELECT COALESCE(MAX(user_id), 0) FROM xf_oc_snapshot')
        );

        $this->deleteSyntheticRows();
    }

    protected function tearDown(): void
    {
        if (!$this->userId) {
            return;
        }
        $this->deleteSyntheticRows();
        $this->transact->rebuildAllBalancesFully();
    }

    public function testPrunePreservesBalanceThroughRebuild(): void
    {
        $old = 1; // before any real ledger row; only synthetic rows live here
        $this->seed('octest_seed', 50.00, $old);
        $this->seed('octest_seed', -20.00, $old);
        $this->seed('octest_seed', 5.00, $old);
        // Newer than the cutoff: must survive the prune untouched.
        $this->seed('octest_seed', 7.00, \XF::$time);

        $this->transact->rebuildAllBalancesFully();
        $before = $this->transact->getBalance($this->userId, $this->currencyId);
        $this->assertEqualsWithDelta(42.00, $before, 0.0001, 'Seeded ledger should total 42.00');

        $result = $this->transact->pruneHistory(2);
        $this->assertSame(3, $result['deleted'], 'Only the three pre-cutoff rows are pruned');
        $this->assertSame(1, $result['snapshots'], 'One snapshot for the synthetic member');

        $this->transact->rebuildAllBalancesFully();
        $after = $this->transact->getBalance($this->userId, $this->currencyId);

        // The invariant: snapshots + surviving rows rebuild to the same balance.
        $this->assertEqualsWithDelta($before, $after, 0.0001, 'Rebuild after prune must preserve the balance');
        $this->assertEqualsWithDelta(42.00, $after, 0.0001);

        $snapshot = (float)$this->db->fetchOne(
            'SELECT balance FROM xf_oc_snapshot WHERE user_id = ? AND currency_id = ?',
            [$this->userId, $this->currencyId]
        );
        $this->assertEqualsWithDelta(35.00, $snapshot, 0.0001, 'Snapshot holds the pruned subtotal');

        $survivors = (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_transaction WHERE user_id = ?',
            $this->userId
        );
        $this->assertSame(1, $survivors, 'The post-cutoff row survives');
    }

    public function testPruneKeepsRedeemAndBackfillRows(): void
    {
        $old = 1;
        $this->seed('octest_seed', 10.00, $old);                          // pruned
        $this->seed('redeem', 30.00, $old);                               // never pruned
        $this->seed('octest_seed', 15.00, $old, 'Historical backfill');   // never pruned

        $this->transact->rebuildAllBalancesFully();
        $before = $this->transact->getBalance($this->userId, $this->currencyId);
        $this->assertEqualsWithDelta(55.00, $before, 0.0001);

        $result = $this->transact->pruneHistory(2);
        $this->assertSame(1, $result['deleted'], 'Redeem and backfill rows are excluded');

        $kept = (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_transaction WHERE user_id = ?',
            $this->userId
        );
        $this->assertSame(2, $kept, 'Redeem and backfill rows remain in the ledger');

        $this->transact->rebuildAllBalancesFully();
        $after = $this->transact->getBalance($this->userId, $this->currencyId);
        $this->assertEqualsWithDelta($before, $after, 0.0001, 'Balance is unchanged after prune + rebuild');
    }

    private function seed(string $trigger, float $amount, int $logDate, string $note = ''): void
    {
        $this->db->insert('xf_oc_transaction', [
            'user_id' => $this->userId,
            'currency_id' => $this->currencyId,
            'amount' => number_format($amount, 2, '.', ''),
            'trigger' => $trigger,
            'content_id' => 0,
            'note' => $note,
            'log_date' => $logDate,
            'content_type' => '',
        ]);
    }

    private function deleteSyntheticRows(): void
    {
        $this->db->delete('xf_oc_transaction', 'user_id = ?', [$this->userId]);
        $this->db->delete('xf_oc_snapshot', 'user_id = ?', [$this->userId]);
        Transact::clearBalanceCache();
    }
}
