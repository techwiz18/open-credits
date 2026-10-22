<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\TestCase;

/**
 * A currency switched from allow_negative to floor-at-zero can leave members
 * with a legacy negative balance. Positive awards must still be allowed so
 * they can earn their way back out; negative awards must stay refused.
 */
class NegativeBalanceTest extends TestCase
{
    protected const TRIGGER = 'octest_recovery';

    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    /** @var Transact */
    protected $transact;

    protected int $userId = 0;
    protected int $currencyId = 0;

    /** @var array{currency_id: int, active: int, visible: int}|null */
    protected ?array $currencyRestore = null;

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
            'SELECT currency_id, active, visible FROM xf_oc_currency WHERE allow_negative = 0 ORDER BY currency_id LIMIT 1'
        );
        if (!$currency) {
            $this->markTestSkipped('No floor-at-zero currency to test.');
        }
        $this->currencyId = (int)$currency['currency_id'];
        if (!(int)$currency['active'] || !(int)$currency['visible']) {
            $this->currencyRestore = $currency;
            $this->db->update(
                'xf_oc_currency',
                ['active' => 1, 'visible' => 1],
                'currency_id = ?',
                [$this->currencyId]
            );
            \XF::em()->clearEntityCache();
        }

        $this->userId = (int)$this->db->fetchOne(
            "SELECT user_id FROM xf_user WHERE user_state = 'valid' ORDER BY user_id LIMIT 1"
        );
        if (!$this->userId) {
            $this->markTestSkipped('No valid member to test.');
        }

        $this->cleanup();
        $this->db->query(
            'INSERT INTO xf_oc_balance (user_id, currency_id, balance) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE balance = ?',
            [$this->userId, $this->currencyId, -10, -10]
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->transact->rebuildAllBalancesFully();
        if ($this->currencyRestore) {
            $this->db->update(
                'xf_oc_currency',
                [
                    'active' => (int)$this->currencyRestore['active'],
                    'visible' => (int)$this->currencyRestore['visible'],
                ],
                'currency_id = ?',
                [(int)$this->currencyRestore['currency_id']]
            );
            \XF::em()->clearEntityCache();
        }
    }

    public function testPositiveAwardRecoversLegacyNegativeBalance(): void
    {
        $ok = $this->transact->adjust($this->userId, $this->currencyId, 3.00, self::TRIGGER, 0, 'test');

        $this->assertTrue($ok);
        $this->assertEqualsWithDelta(
            -7.0,
            $this->transact->getBalance($this->userId, $this->currencyId),
            0.0001
        );
    }

    public function testNegativeAwardStillRefused(): void
    {
        $ok = $this->transact->adjust($this->userId, $this->currencyId, -1.00, self::TRIGGER, 0, 'test');

        $this->assertFalse($ok);
        $this->assertEqualsWithDelta(
            -10.0,
            $this->transact->getBalance($this->userId, $this->currencyId),
            0.0001
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
