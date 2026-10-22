<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Entity\Code;
use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\TestCase;

/**
 * #8 inflation control against the licensed dev forum.
 *
 * Isolation: a synthetic primary currency holds every seeded balance, so the
 * real forum money supply is never capped, burned, or transferred. The
 * original primary is restored and the real mirrors rebuilt in tearDown().
 */
class InflationControlTest extends TestCase
{
    protected const TRIGGER = 'octest_inflation';
    protected const TITLE = 'OC Inflation Test';

    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    protected ?Transact $transact = null;
    protected int $memberId = 0;
    protected int $otherId = 0;
    protected int $currencyId = 0;
    protected int $originalPrimary = 0;

    /** @var array<int, float> */
    protected array $originalMirrors = [];

    /** @var array<string, mixed> */
    protected array $originalOptions = [];

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

        $ids = $this->db->fetchAllColumn(
            "SELECT user_id FROM xf_user WHERE user_state = 'valid' AND is_banned = 0 ORDER BY user_id LIMIT 2"
        );
        if (count($ids) < 2) {
            $this->markTestSkipped('Need at least two valid members.');
        }
        $this->memberId = (int)$ids[0];
        $this->otherId = (int)$ids[1];

        $this->cleanup();
        $this->originalPrimary = (int)$this->db->fetchOne(
            'SELECT currency_id FROM xf_oc_currency WHERE is_primary = 1 LIMIT 1'
        );
        if (!$this->originalPrimary) {
            $this->markTestSkipped('No primary credits currency is configured.');
        }

        $this->originalMirrors = [];
        foreach ([$this->memberId, $this->otherId] as $userId) {
            $this->originalMirrors[$userId] = (float)$this->db->fetchOne(
                'SELECT oc_credits FROM xf_user WHERE user_id = ?',
                [$userId]
            );
        }

        $this->currencyId = $this->createTestCurrency(self::TITLE);
        $this->db->update('xf_oc_currency', ['is_primary' => 0], 'currency_id = ?', [$this->originalPrimary]);
        $this->db->update('xf_oc_currency', ['is_primary' => 1], 'currency_id = ?', [$this->currencyId]);

        $this->db->insert('xf_oc_event', [
            'currency_id' => $this->currencyId,
            'trigger' => self::TRIGGER,
            'amount' => 10.00,
            'max_per_day' => 0,
            'active' => 1,
            'send_alert' => 0,
        ]);

        $options = \XF::app()->options();
        foreach ([
            'ocInflationEnabled',
            'ocInflationBalanceCap',
            'ocInflationFaucetDaily',
            'ocInflationDemurrageRate',
            'ocInflationDemurrageDays',
            'ocSendEarnAlerts',
        ] as $key) {
            $this->originalOptions[$key] = $options->{$key} ?? null;
        }
        $options->ocInflationEnabled = true;
        $options->ocInflationBalanceCap = 0;
        $options->ocInflationFaucetDaily = 0;
        $options->ocInflationDemurrageRate = 0;
        $options->ocInflationDemurrageDays = 7;
        $options->ocSendEarnAlerts = 0;

        Transact::clearPrimaryCache();
        Transact::clearBalanceCache();
        Transact::clearEventCache();
    }

    protected function tearDown(): void
    {
        if (!$this->db || !$this->transact) {
            return;
        }
        $options = \XF::app()->options();
        foreach ($this->originalOptions as $key => $value) {
            $options->{$key} = $value;
        }
        if ($this->originalPrimary) {
            $this->db->update('xf_oc_currency', ['is_primary' => 0], 'is_primary = 1');
            $this->db->update('xf_oc_currency', ['is_primary' => 1], 'currency_id = ?', [$this->originalPrimary]);
        }
        $this->cleanup();
        Transact::clearPrimaryCache();
        Transact::clearBalanceCache();
        Transact::clearEventCache();
        \XF::setVisitor(null);
    }

    protected function createTestCurrency(string $title, bool $primary = false): int
    {
        $this->db->insert('xf_oc_currency', [
            'title' => $title,
            'prefix' => 'T',
            'suffix' => '',
            'decimals' => 2,
            'allow_negative' => 0,
            'active' => 1,
            'is_primary' => $primary ? 1 : 0,
            'visible' => 1,
        ]);

        return (int)$this->db->lastInsertId();
    }

    protected function cleanup(): void
    {
        if (!$this->db || !$this->transact) {
            return;
        }
        $ids = $this->db->fetchAllColumn(
            'SELECT currency_id FROM xf_oc_currency WHERE title LIKE ?',
            ['OC Inflation Test%']
        );
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            foreach ([
                'xf_oc_transaction',
                'xf_oc_balance',
                'xf_oc_inflation',
                'xf_oc_event',
                'xf_oc_code',
            ] as $table) {
                $this->db->query("DELETE FROM {$table} WHERE currency_id IN ({$in})");
            }
            $this->db->query("DELETE FROM xf_oc_currency WHERE currency_id IN ({$in})");
        }

        $primary = (int)$this->db->fetchOne(
            'SELECT currency_id FROM xf_oc_currency WHERE is_primary = 1 LIMIT 1'
        );
        if (!$primary) {
            $fallback = (int)$this->db->fetchOne(
                'SELECT currency_id FROM xf_oc_currency WHERE active = 1 ORDER BY currency_id LIMIT 1'
            );
            if ($fallback) {
                $this->db->update('xf_oc_currency', ['is_primary' => 1], 'currency_id = ?', [$fallback]);
            }
        }

        Transact::clearPrimaryCache();
        if ($ids) {
            // Restore the real members' mirrors from their real ledger.
            $this->transact->rebuildAllBalancesFully();
        }
        Transact::clearBalanceCache();
    }

    public function testEarningsFillToBalanceCap(): void
    {
        \XF::app()->options()->ocInflationBalanceCap = 5;

        $this->assertTrue($this->transact->awardByTrigger(self::TRIGGER, $this->memberId));
        $this->assertEqualsWithDelta(
            5.0,
            $this->transact->getBalance($this->memberId, $this->currencyId),
            0.0001,
            'A 10 credit award fills the 5 cap only.'
        );

        $this->assertFalse(
            $this->transact->awardByTrigger(self::TRIGGER, $this->memberId),
            'At the cap, earnings are skipped.'
        );
        $this->assertEqualsWithDelta(5.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);

        $this->assertTrue(
            $this->transact->adjust($this->memberId, $this->currencyId, 5.0, 'admin_adjust'),
            'Admin/integrator adjust() bypasses the cap.'
        );
        $this->assertEqualsWithDelta(10.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
    }

    public function testFaucetLimitsDailyEarnings(): void
    {
        \XF::app()->options()->ocInflationFaucetDaily = 8;

        $this->assertTrue($this->transact->awardByTrigger(self::TRIGGER, $this->memberId));
        $this->assertEqualsWithDelta(
            8.0,
            $this->transact->getBalance($this->memberId, $this->currencyId),
            0.0001,
            'A 10 credit award is trimmed to the 8 daily faucet.'
        );

        $this->assertFalse(
            $this->transact->awardByTrigger(self::TRIGGER, $this->memberId),
            'The faucet is exhausted for the day.'
        );
        $this->assertEqualsWithDelta(8.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
    }

    public function testRedeemRejectsOverCapWithoutConsumingCode(): void
    {
        \XF::app()->options()->ocInflationBalanceCap = 10;
        $code = Code::generate();
        $this->db->insert('xf_oc_code', [
            'code' => $code,
            'currency_id' => $this->currencyId,
            'amount' => 15.00,
            'max_uses' => 1,
            'uses' => 0,
            'expiry_date' => 0,
            'active' => 1,
        ]);

        $granted = null;
        $this->assertSame('capped', $this->transact->redeem($this->memberId, $code, $granted));
        $this->assertSame(
            0,
            (int)$this->db->fetchOne('SELECT uses FROM xf_oc_code WHERE code = ?', [$code]),
            'A capped redeem must not consume the code.'
        );
        $this->assertEqualsWithDelta(0.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
    }

    public function testTransfersDoNotHitTheCap(): void
    {
        \XF::app()->options()->ocInflationBalanceCap = 10;
        $this->transact->adjust($this->memberId, $this->currencyId, 10.0, 'admin_adjust');
        $this->transact->adjust($this->otherId, $this->currencyId, 5.0, 'admin_adjust');

        $this->assertTrue($this->transact->transfer($this->otherId, $this->memberId, 5.0, $this->currencyId));
        $this->assertEqualsWithDelta(
            15.0,
            $this->transact->getBalance($this->memberId, $this->currencyId),
            0.0001,
            'Transfers are supply-neutral and may cross the cap.'
        );
        $this->assertEqualsWithDelta(0.0, $this->transact->getBalance($this->otherId, $this->currencyId), 0.0001);
    }

    public function testSecondaryCurrencyBypassesInflation(): void
    {
        $secondary = $this->createTestCurrency(self::TITLE . ' Secondary');
        \XF::app()->options()->ocInflationBalanceCap = 1;

        $credited = null;
        $this->assertTrue($this->transact->adjust(
            $this->memberId,
            $secondary,
            5.0,
            'admin_adjust',
            0,
            '',
            '',
            true,
            $credited
        ));
        $this->assertEqualsWithDelta(5.0, $credited, 0.0001, 'Only the primary currency is controlled.');
        $this->assertEqualsWithDelta(5.0, $this->transact->getBalance($this->memberId, $secondary), 0.0001);
    }

    public function testDisabledInflationIsNoOp(): void
    {
        $options = \XF::app()->options();
        $options->ocInflationEnabled = false;
        $options->ocInflationBalanceCap = 1;
        $options->ocInflationFaucetDaily = 1;
        $options->ocInflationDemurrageRate = 10;

        $this->assertTrue($this->transact->awardByTrigger(self::TRIGGER, $this->memberId));
        $this->assertEqualsWithDelta(
            10.0,
            $this->transact->getBalance($this->memberId, $this->currencyId),
            0.0001,
            'Disabled controls leave the full award in place.'
        );

        $step = $this->transact->demurrageStep(100, true);
        $this->assertFalse($step['ran'], 'Disabled demurrage does nothing.');
        $this->assertEqualsWithDelta(10.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
    }

    public function testDemurrageBurnsResumesAndHonorsInterval(): void
    {
        $options = \XF::app()->options();
        $options->ocInflationDemurrageRate = 10;
        $options->ocInflationDemurrageDays = 7;

        $this->transact->adjust($this->memberId, $this->currencyId, 10.0, 'admin_adjust');
        $this->transact->adjust($this->otherId, $this->currencyId, 5.0, 'admin_adjust');

        $preview = $this->transact->demurragePreview();
        $this->assertSame(2, $preview['accounts']);
        $this->assertEqualsWithDelta(1.5, $preview['total'], 0.0001);

        // First batch: exactly one member (the lower user id).
        $step = $this->transact->demurrageStep(1, true);
        $this->assertTrue($step['ran']);
        $this->assertFalse($step['done']);
        $this->assertSame(1, $step['processed']);
        $this->assertEqualsWithDelta(1.0, $step['burned'], 0.0001);
        $this->assertEqualsWithDelta(9.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
        $this->assertEqualsWithDelta(5.0, $this->transact->getBalance($this->otherId, $this->currencyId), 0.0001);

        // Second batch finishes the pass.
        $step = $this->transact->demurrageStep(10, true);
        $this->assertTrue($step['done']);
        $this->assertEqualsWithDelta(0.5, $step['burned'], 0.0001);
        $this->assertEqualsWithDelta(4.5, $this->transact->getBalance($this->otherId, $this->currencyId), 0.0001);

        // The interval blocks a normal second pass.
        $again = $this->transact->runDemurrage(500, false);
        $this->assertFalse($again['ran']);
        $this->assertEqualsWithDelta(9.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);

        // The explicit admin/CLI force runs again.
        $forced = $this->transact->runDemurrage(500, true);
        $this->assertTrue($forced['ran']);
        $this->assertEqualsWithDelta(8.1, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
        $this->assertEqualsWithDelta(4.05, $this->transact->getBalance($this->otherId, $this->currencyId), 0.0001);

        $rows = $this->db->fetchAll(
            'SELECT amount, `trigger` FROM xf_oc_transaction'
            . ' WHERE currency_id = ? AND `trigger` = ? ORDER BY transaction_id',
            [$this->currencyId, 'demurrage']
        );
        $this->assertCount(4, $rows);
        $this->assertEqualsWithDelta(-1.0, (float)$rows[0]['amount'], 0.0001);
        $this->assertEqualsWithDelta(-0.5, (float)$rows[1]['amount'], 0.0001);
        $this->assertEqualsWithDelta(-0.9, (float)$rows[2]['amount'], 0.0001);
        $this->assertEqualsWithDelta(-0.45, (float)$rows[3]['amount'], 0.0001);
        $this->assertSame('demurrage', $rows[0]['trigger']);

        $state = $this->db->fetchRow(
            'SELECT last_run, cursor_user_id FROM xf_oc_inflation WHERE currency_id = ?',
            [$this->currencyId]
        );
        $this->assertGreaterThan(0, (int)$state['last_run']);
        $this->assertSame(0, (int)$state['cursor_user_id']);

        $this->assertEqualsWithDelta(
            $this->originalMirrors[$this->memberId] + 8.1,
            (float)$this->db->fetchOne('SELECT oc_credits FROM xf_user WHERE user_id = ?', [$this->memberId]),
            0.0001,
            'The legacy mirror follows the primary-currency deltas through demurrage.'
        );
    }
}
