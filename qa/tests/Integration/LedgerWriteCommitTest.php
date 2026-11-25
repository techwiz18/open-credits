<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\TestCase;

/**
 * LedgerWrite::commit() contract: the floor applies to a member's net delta
 * (a movement may debit and credit the same member), and a caller-supplied
 * decimals snapshot pins quantization so a resumable demurrage pass cannot
 * re-round a burn under rules it did not use.
 */
class LedgerWriteCommitTest extends TestCase
{
    protected const TRIGGER = 'octest_ledgerwrite';

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

    public function testMixedLegsForOneMemberUseTheNetDelta(): void
    {
        $verdict = $this->commit([
            ['user_id' => $this->userId, 'amount' => 5.0],
            ['user_id' => $this->userId, 'amount' => -3.0],
        ]);

        $this->assertSame('ok', $verdict);
        $this->assertEqualsWithDelta(12.0, $this->balance(), 0.0001);
    }

    public function testNetDebitWithinBalanceIsAllowed(): void
    {
        $verdict = $this->commit([
            ['user_id' => $this->userId, 'amount' => 5.0],
            ['user_id' => $this->userId, 'amount' => -8.0],
        ]);

        $this->assertSame('ok', $verdict);
        $this->assertEqualsWithDelta(7.0, $this->balance(), 0.0001);
    }

    public function testNetDebitBeyondBalanceIsRefused(): void
    {
        $verdict = $this->commit([
            ['user_id' => $this->userId, 'amount' => 5.0],
            ['user_id' => $this->userId, 'amount' => -16.0],
        ]);

        $this->assertSame('insufficient', $verdict);
        $this->assertEqualsWithDelta(10.0, $this->balance(), 0.0001);
        $this->assertSame(0, $this->ledgerRows());
    }

    public function testDecimalsOverridePinsQuantization(): void
    {
        $verdict = $this->commit(
            [['user_id' => $this->userId, 'amount' => 1.75]],
            ['decimals' => 0]
        );

        $this->assertSame('ok', $verdict);
        $this->assertEqualsWithDelta(12.0, $this->balance(), 0.0001);
    }

    /**
     * @param array<int, array<string, mixed>> $legs
     */
    protected function commit(array $legs, array $overrides = []): string
    {
        $movement = array_merge([
            'currency_id' => $this->currencyId,
            'trigger' => self::TRIGGER,
            'overdraft' => 'never',
            'note' => '',
            'legs' => $legs,
        ], $overrides);

        $db = $this->db;
        $db->beginTransaction();
        try {
            $verdict = $this->transact->ledgerWrite()->commit($movement);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $verdict;
    }

    protected function balance(): float
    {
        return $this->transact->getBalance($this->userId, $this->currencyId);
    }

    protected function ledgerRows(): int
    {
        return (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_transaction WHERE user_id = ? AND `trigger` = ?',
            [$this->userId, self::TRIGGER]
        );
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
    }
}
