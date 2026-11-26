<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\TestCase;

/**
 * Lowering a currency's decimals must be refused while any stored balance
 * carries more places, or the next write and a ledger rebuild would silently
 * round money an admin already granted.
 */
class PrecisionGuardTest extends TestCase
{
    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    /** @var Transact */
    protected $transact;

    protected int $userId = 0;
    protected int $currencyId = 0;

    /** @var array<string, mixed>|null */
    protected ?array $balanceRestore = null;

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

        $this->currencyId = (int)$this->db->fetchOne(
            'SELECT currency_id FROM xf_oc_currency ORDER BY currency_id LIMIT 1'
        );
        $this->userId = (int)$this->db->fetchOne(
            "SELECT user_id FROM xf_user WHERE user_state = 'valid' ORDER BY user_id LIMIT 1"
        );
        if (!$this->currencyId || !$this->userId) {
            $this->markTestSkipped('No currency and valid member to test.');
        }

        $this->balanceRestore = $this->db->fetchRow(
            'SELECT * FROM xf_oc_balance WHERE user_id = ? AND currency_id = ?',
            [$this->userId, $this->currencyId]
        );
    }

    protected function tearDown(): void
    {
        if (!$this->userId) {
            return;
        }
        if ($this->balanceRestore) {
            $this->db->update(
                'xf_oc_balance',
                ['balance' => $this->balanceRestore['balance']],
                'user_id = ? AND currency_id = ?',
                [$this->userId, $this->currencyId]
            );
        } else {
            $this->db->delete(
                'xf_oc_balance',
                'user_id = ? AND currency_id = ?',
                [$this->userId, $this->currencyId]
            );
        }
    }

    public function testWholeBalancesSurviveDroppingToZeroDecimals(): void
    {
        $this->setBalance(10.00);

        $this->assertSame(0, $this->countUnrepresentable(0));
    }

    public function testTrailingZeroIsRepresentableAtOneDecimal(): void
    {
        $this->setBalance(10.50);

        $this->assertSame(0, $this->countUnrepresentable(1));
    }

    public function testFractionalBalanceBlocksLowerPrecision(): void
    {
        $this->setBalance(10.55);

        $this->assertSame(0, $this->countUnrepresentable(2));
        $this->assertSame(1, $this->countUnrepresentable(1));
        $this->assertSame(1, $this->countUnrepresentable(0));
    }

    protected function setBalance(float $balance): void
    {
        $this->db->query(
            'INSERT INTO xf_oc_balance (user_id, currency_id, balance) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE balance = ?',
            [$this->userId, $this->currencyId, $balance, $balance]
        );
    }

    protected function countUnrepresentable(int $decimals): int
    {
        return $this->transact->ledgerWrite()
            ->countUnrepresentableBalances($this->currencyId, $decimals);
    }
}
