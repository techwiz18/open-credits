<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Service\Transact;

/**
 * The request cache stays fresh after a write: no caller clears it, and the
 * next read in the same request sees both write paths (the ledger module and
 * the legacy adjust) and the event rows the write invalidated.
 */
class RequestCacheTest extends IntegrationTestCase
{
    protected const TRIGGER = 'octest_request_cache';

    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    /** @var Transact */
    protected $transact;

    protected int $userId = 0;
    protected int $currencyId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('XF', false) || !\XF::app()) {
            $this->markTestSkipped('Set OC_XF_ROOT to a licensed XenForo install to run integration tests.');
        }
        if (!\XF::$time) {
            \XF::$time = time();
        }
        $this->db = \XF::db();
        $this->transact = \XF::app()->service('OpenCredits\Credits:Transact');

        $currency = $this->db->fetchRow(
            'SELECT currency_id FROM xf_oc_currency WHERE active = 1 ORDER BY currency_id LIMIT 1'
        );
        if (!$currency) {
            $this->markTestSkipped('No active currency to test.');
        }
        $this->currencyId = (int)$currency['currency_id'];

        $this->userId = (int)$this->db->fetchOne(
            "SELECT user_id FROM xf_user WHERE user_state = 'valid' ORDER BY user_id LIMIT 1"
        );
        if (!$this->userId) {
            $this->markTestSkipped('No valid member to test.');
        }

        $this->cleanup();
        $this->setBalance(10.0);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->transact->rebuildAllBalancesFully();
    }

    public function testLedgerWriteCommitRefreshesCachedBalances(): void
    {
        $this->assertSame(10.0, $this->cachedBalance(), 'Reading first warms the cache at the seeded balance.');

        $this->assertSame('ok', $this->commit([['user_id' => $this->userId, 'amount' => 5.0]]));

        $this->assertSame(15.0, $this->cachedBalance());
    }

    public function testLegacyAdjustRefreshesCachedBalances(): void
    {
        $this->assertSame(10.0, $this->cachedBalance());

        $this->assertTrue($this->transact->adjust(
            $this->userId,
            $this->currencyId,
            2.5,
            self::TRIGGER
        ));

        $this->assertSame(12.5, $this->cachedBalance());
    }

    public function testLedgerWriteCommitDropsEventRows(): void
    {
        $this->assertSame([], $this->transact->eventRowsForTrigger(self::TRIGGER));

        $this->db->insert('xf_oc_event', [
            'currency_id' => $this->currencyId,
            'trigger' => self::TRIGGER,
            'amount' => 1.00,
            'max_per_day' => 0,
            'active' => 1,
            'send_alert' => 0,
        ]);

        $this->assertSame('ok', $this->commit([['user_id' => $this->userId, 'amount' => 1.0]]));

        $this->assertCount(
            1,
            $this->transact->eventRowsForTrigger(self::TRIGGER),
            'The write path invalidates event rows, not only balances.'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $legs
     */
    protected function commit(array $legs): string
    {
        $movement = [
            'currency_id' => $this->currencyId,
            'trigger' => self::TRIGGER,
            'overdraft' => 'never',
            'note' => '',
            'legs' => $legs,
        ];

        $this->db->beginTransaction();
        try {
            $verdict = $this->transact->ledgerWrite()->commit($movement);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $verdict;
    }

    protected function cachedBalance(): float
    {
        foreach ($this->transact->balancesFor($this->userId, false) as $row) {
            if ((int)$row['currency_id'] === $this->currencyId) {
                return (float)$row['balance'];
            }
        }
        return -1.0;
    }

    protected function setBalance(float $balance): void
    {
        $this->db->query(
            'INSERT INTO xf_oc_balance (user_id, currency_id, balance) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE balance = ?',
            [$this->userId, $this->currencyId, $balance, $balance]
        );
    }

    protected function cleanup(): void
    {
        if ($this->userId) {
            $this->db->delete(
                'xf_oc_transaction',
                'user_id = ? AND `trigger` = ?',
                [$this->userId, self::TRIGGER]
            );
        }
        $this->db->delete('xf_oc_event', '`trigger` = ?', [self::TRIGGER]);
    }
}
