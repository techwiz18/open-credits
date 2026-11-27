<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Entity\Code;
use OpenCredits\Credits\Service\DemurrageRunner;
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

    protected function createTestCurrency(string $title, bool $primary = false, int $decimals = 2): int
    {
        $this->db->insert('xf_oc_currency', [
            'title' => $title,
            'prefix' => 'T',
            'suffix' => '',
            'decimals' => $decimals,
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
                'xf_oc_faucet',
                'xf_oc_event',
                'xf_oc_code',
            ] as $table) {
                $this->db->query("DELETE FROM {$table} WHERE currency_id IN ({$in})");
            }
            $this->db->query("DELETE FROM xf_oc_currency WHERE currency_id IN ({$in})");
        }
        // Daily-claim markers are keyed by member, not currency.
        $this->db->query(
            'DELETE FROM xf_oc_daily_claim WHERE user_id IN (?, ?)',
            [$this->memberId, $this->otherId]
        );

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

    public function testPreviewMatchesBurnForRoundingEdges(): void
    {
        \XF::app()->options()->ocInflationDemurrageRate = 10;

        // A sub-unit burn rounds to zero and must be skipped, not counted.
        $this->transact->adjust($this->memberId, $this->currencyId, 0.04, 'admin_adjust');
        $this->transact->adjust($this->otherId, $this->currencyId, 4.99, 'admin_adjust');

        $preview = $this->transact->demurragePreview();
        $this->assertSame(1, $preview['accounts']);
        $this->assertEqualsWithDelta(0.5, $preview['total'], 0.0001);
        $this->assertEqualsWithDelta(
            DemurrageRunner::burn(0.04, 10.0, 2) + DemurrageRunner::burn(4.99, 10.0, 2),
            $preview['total'],
            0.0001,
            'Preview sums the same burn() the pass uses.'
        );
    }

    public function testPreviewUsesTheInProgressPassSnapshot(): void
    {
        // The rest of an in-flight pass burns at the rate and precision it
        // claimed, so the estimate must not follow later settings changes.
        \XF::app()->options()->ocInflationDemurrageRate = 10;
        $this->transact->adjust($this->otherId, $this->currencyId, 10.0, 'admin_adjust');
        $this->db->insert('xf_oc_inflation', [
            'currency_id' => $this->currencyId,
            'last_run' => 0,
            'cursor_user_id' => $this->memberId,
            'pass_started' => \XF::$time,
            'pass_token' => 424242,
            'pass_rate' => 50,
            'pass_decimals' => 2,
        ]);

        $preview = $this->transact->demurragePreview();
        $this->assertTrue($preview['inProgress']);
        $this->assertSame(50.0, (float)$preview['rate'], 'The estimate reports the pass rate');
        $this->assertEqualsWithDelta(5.0, $preview['total'], 0.0001, 'Burn at the pass rate, not the new 10%');
        $this->assertSame(1, $preview['accounts']);
    }

    public function testRedeemEnforcesDailyFaucet(): void
    {
        \XF::app()->options()->ocInflationFaucetDaily = 8;
        $code = Code::generate();
        $this->db->insert('xf_oc_code', [
            'code' => $code,
            'currency_id' => $this->currencyId,
            'amount' => 50.00,
            'max_uses' => 1,
            'uses' => 0,
            'expiry_date' => 0,
            'active' => 1,
        ]);

        // A 50 code against an 8 faucet is rejected without consuming it.
        $granted = null;
        $this->assertSame('capped', $this->transact->redeem($this->memberId, $code, $granted));
        $this->assertSame(
            0,
            (int)$this->db->fetchOne('SELECT uses FROM xf_oc_code WHERE code = ?', [$code])
        );

        // A 5 code fits and is counted, so the faucet has 3 left.
        $small = Code::generate();
        $this->db->insert('xf_oc_code', [
            'code' => $small,
            'currency_id' => $this->currencyId,
            'amount' => 5.00,
            'max_uses' => 1,
            'uses' => 0,
            'expiry_date' => 0,
            'active' => 1,
        ]);
        $this->assertSame('ok', $this->transact->redeem($this->memberId, $small, $granted));

        // The event award (10) is trimmed to the remaining 3.
        $this->assertTrue($this->transact->awardByTrigger(self::TRIGGER, $this->memberId));
        $this->assertEqualsWithDelta(8.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
    }

    public function testDuplicateEventsDoNotMultiplyFaucet(): void
    {
        \XF::app()->options()->ocInflationFaucetDaily = 15;
        $this->db->insert('xf_oc_event', [
            'currency_id' => $this->currencyId,
            'trigger' => self::TRIGGER,
            'amount' => 10.00,
            'max_per_day' => 0,
            'active' => 1,
            'send_alert' => 0,
        ]);
        Transact::clearEventCache();

        // Two identical active events: the faucet counts credited coins once.
        $this->assertTrue($this->transact->awardByTrigger(self::TRIGGER, $this->memberId));
        $this->assertEqualsWithDelta(
            15.0,
            $this->transact->getBalance($this->memberId, $this->currencyId),
            0.0001,
            'Duplicate events fill the 15 faucet once, not twice.'
        );
        $this->assertEqualsWithDelta(
            15.0,
            (float)$this->db->fetchOne(
                'SELECT COALESCE(amount, 0) FROM xf_oc_faucet WHERE user_id = ? AND currency_id = ?',
                [$this->memberId, $this->currencyId]
            ),
            0.0001
        );
    }

    public function testEventToggleDoesNotEraseFaucet(): void
    {
        \XF::app()->options()->ocInflationFaucetDaily = 20;
        $this->assertTrue($this->transact->awardByTrigger(self::TRIGGER, $this->memberId));

        // Disabling the event mid-day must not erase the morning's earnings.
        $this->db->update(
            'xf_oc_event',
            ['active' => 0],
            '`trigger` = ? AND currency_id = ?',
            [self::TRIGGER, $this->currencyId]
        );
        Transact::clearEventCache();
        $this->assertFalse($this->transact->awardByTrigger(self::TRIGGER, $this->memberId));

        $this->db->update(
            'xf_oc_event',
            ['active' => 1],
            '`trigger` = ? AND currency_id = ?',
            [self::TRIGGER, $this->currencyId]
        );
        Transact::clearEventCache();
        $this->assertTrue($this->transact->awardByTrigger(self::TRIGGER, $this->memberId));
        $this->assertEqualsWithDelta(
            20.0,
            $this->transact->getBalance($this->memberId, $this->currencyId),
            0.0001,
            'The re-enabled event fills only the remaining 10.'
        );
    }

    public function testCustomAdjustRespectInflationIsCumulative(): void
    {
        \XF::app()->options()->ocInflationFaucetDaily = 10;

        $first = null;
        $this->assertTrue($this->transact->adjust(
            $this->memberId, $this->currencyId, 6.0, 'custom_bonus', 0, '', '', true, $first
        ));
        $this->assertEqualsWithDelta(6.0, $first, 0.0001);

        $second = null;
        $this->assertTrue($this->transact->adjust(
            $this->memberId, $this->currencyId, 6.0, 'custom_other', 0, '', '', true, $second
        ));
        $this->assertEqualsWithDelta(4.0, $second, 0.0001, 'Custom triggers share one faucet.');
        $this->assertEqualsWithDelta(10.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
    }

    /**
     * Seeds a 10-credit daily_login event on the test currency, suppresses
     * other currencies' daily events, and stashes the member's real daily
     * rows for today (the fast path counts every daily_login row, so a
     * member who already visited today would short-circuit before the
     * claim logic). Returns [suppressed event ids, stashed rows] for
     * restoreDailyLoginScenario() in a finally block.
     *
     * @return array{0: int[], 1: array[]}
     */
    protected function seedDailyLoginScenario(): array
    {
        $this->db->insert('xf_oc_event', [
            'currency_id' => $this->currencyId,
            'trigger' => 'daily_login',
            'amount' => 10.00,
            'max_per_day' => 0,
            'active' => 1,
            'send_alert' => 0,
        ]);
        // The shared dev forum has its own daily_login events on other
        // currencies; awardByTrigger fires every active one, so suppress
        // them for a deterministic clamp (restored in finally).
        $others = $this->db->fetchAllColumn(
            "SELECT event_id FROM xf_oc_event WHERE `trigger` = 'daily_login'"
            . ' AND active = 1 AND currency_id != ?',
            [$this->currencyId]
        );
        if ($others) {
            $in = implode(',', array_map('intval', $others));
            $this->db->query("UPDATE xf_oc_event SET active = 0 WHERE event_id IN ({$in})");
        }
        $stashed = $this->db->fetchAll(
            'SELECT user_id, currency_id, amount, `trigger`, content_type, content_id,'
            . ' event_id, note, log_date FROM xf_oc_transaction'
            . ' WHERE user_id = ? AND `trigger` = ? AND log_date >= ?',
            [$this->memberId, 'daily_login', Transact::dayStart()]
        );
        if ($stashed) {
            $this->db->query(
                'DELETE FROM xf_oc_transaction WHERE user_id = ? AND `trigger` = ? AND log_date >= ?',
                [$this->memberId, 'daily_login', Transact::dayStart()]
            );
        }
        return [$others, $stashed];
    }

    protected function restoreDailyLoginScenario(array $others, array $stashed): void
    {
        if (!empty($others)) {
            $in = implode(',', array_map('intval', $others));
            $this->db->query("UPDATE xf_oc_event SET active = 1 WHERE event_id IN ({$in})");
            Transact::clearEventCache();
        }
        foreach ($stashed as $row) {
            $this->db->insert('xf_oc_transaction', $row);
        }
    }

    public function testAtCapDailyLoginLeavesNoMarker(): void
    {
        [$others, $stashed] = $this->seedDailyLoginScenario();
        try {
            Transact::clearEventCache();
            \XF::app()->options()->ocInflationBalanceCap = 5;
            $this->transact->adjust($this->memberId, $this->currencyId, 5.0, 'admin_adjust');

            // At the cap the daily award clamps to zero but leaves no
            // marker: cap headroom can free later the same day.
            $this->assertFalse($this->transact->awardDailyLoginIfNeeded($this->memberId));
            $this->assertSame(
                0,
                (int)$this->db->fetchOne(
                    'SELECT COUNT(*) FROM xf_oc_daily_claim WHERE user_id = ?',
                    [$this->memberId]
                ),
                'A cap-bound daily award leaves no claim marker.'
            );
            $this->assertSame(
                0,
                (int)$this->db->fetchOne(
                    "SELECT COUNT(*) FROM xf_oc_transaction WHERE user_id = ? AND `trigger` = 'daily_login'"
                    . ' AND log_date >= ?',
                    [$this->memberId, Transact::dayStart()]
                ),
                'A clamped daily award writes no ledger row.'
            );

            // Freeing cap headroom lets the retry award: the morning clamp
            // must not become a day-long block.
            $this->assertTrue(
                $this->transact->transfer($this->memberId, $this->otherId, 5.0, $this->currencyId)
            );
            $this->assertTrue($this->transact->awardDailyLoginIfNeeded($this->memberId));
            $this->assertEqualsWithDelta(
                5.0,
                $this->transact->getBalance($this->memberId, $this->currencyId),
                0.0001,
                'The freed headroom takes the daily award on retry.'
            );
        } finally {
            $this->restoreDailyLoginScenario($others, $stashed);
        }
    }

    public function testFaucetExhaustedDailyLoginMarksClaim(): void
    {
        [$others, $stashed] = $this->seedDailyLoginScenario();
        try {
            Transact::clearEventCache();
            \XF::app()->options()->ocInflationFaucetDaily = 8;
            // Exhaust the faucet through another trigger first.
            $this->assertTrue($this->transact->awardByTrigger(self::TRIGGER, $this->memberId));
            $this->assertEqualsWithDelta(
                8.0,
                $this->transact->getBalance($this->memberId, $this->currencyId),
                0.0001
            );

            // The daily award clamps on the exhausted faucet and marks the
            // day: faucet exhaustion never frees the same day.
            $this->assertFalse($this->transact->awardDailyLoginIfNeeded($this->memberId));
            $this->assertSame(
                1,
                (int)$this->db->fetchOne(
                    'SELECT COUNT(*) FROM xf_oc_daily_claim WHERE user_id = ?',
                    [$this->memberId]
                ),
                'A faucet-bound daily award leaves a claim marker.'
            );

            // The retry short-circuits on the marker: no second marker, no row.
            $this->assertFalse($this->transact->awardDailyLoginIfNeeded($this->memberId));
            $this->assertSame(
                1,
                (int)$this->db->fetchOne(
                    'SELECT COUNT(*) FROM xf_oc_daily_claim WHERE user_id = ?',
                    [$this->memberId]
                )
            );
            $this->assertSame(
                0,
                (int)$this->db->fetchOne(
                    "SELECT COUNT(*) FROM xf_oc_transaction WHERE user_id = ? AND `trigger` = 'daily_login'"
                    . ' AND log_date >= ?',
                    [$this->memberId, Transact::dayStart()]
                ),
                'A clamped daily award writes no ledger row.'
            );
        } finally {
            $this->restoreDailyLoginScenario($others, $stashed);
        }
    }

    public function testDemurrageBusyWhenPassInProgress(): void
    {
        \XF::app()->options()->ocInflationDemurrageRate = 10;
        $this->transact->adjust($this->memberId, $this->currencyId, 10.0, 'admin_adjust');

        // Simulate a runner that claimed a pass but has not processed anyone.
        $this->seedClaimedPass(424242);

        $step = $this->transact->demurrageStep(500, true);
        $this->assertTrue($step['busy'], 'A second runner must report busy, not double-burn.');
        $this->assertEqualsWithDelta(
            10.0,
            $this->transact->getBalance($this->memberId, $this->currencyId),
            0.0001,
            'The losing runner burns nothing.'
        );

        // An hour-old unprogressed claim is abandoned: the next runner
        // reclaims it instead of blocking forever.
        $this->db->update(
            'xf_oc_inflation',
            ['pass_started' => \XF::$time - 7200],
            'currency_id = ?',
            [$this->currencyId]
        );
        $recovered = $this->transact->demurrageStep(500, true);
        $this->assertTrue($recovered['ran']);
        $this->assertFalse($recovered['busy'] ?? false);
        $this->assertEqualsWithDelta(
            9.0,
            $this->transact->getBalance($this->memberId, $this->currencyId),
            0.0001
        );
    }

    /**
     * A second runner owns a freshly claimed pass (token set, no progress).
     * The runner reports busy through runDemurrage(), exits before burning,
     * and leaves the owner's claim untouched.
     */
    public function testRunnerReportsBusyWithoutBurningWhenPassIsClaimed(): void
    {
        $this->seedDemurrageBalances();
        $this->seedClaimedPass(424242);

        $result = $this->transact->runDemurrage(1, true);

        $this->assertFalse($result['ran'], 'The losing runner did not run.');
        $this->assertTrue($result['busy'], 'The runner surfaces the lost race as busy.');
        $this->assertFalse($result['done']);
        $this->assertSame(0, $result['processed']);
        $this->assertEqualsWithDelta(0.0, $result['burned'], 0.0001);
        $this->assertSame(0, $this->demurrageRowCount(), 'The losing runner burned nothing.');
        $this->assertEqualsWithDelta(10.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
        $this->assertEqualsWithDelta(5.0, $this->transact->getBalance($this->otherId, $this->currencyId), 0.0001);
        $state = $this->demurrageState();
        $this->assertSame(424242, (int)$state['pass_token'], "The owner's claim is untouched.");
        $this->assertSame(0, (int)$state['last_run'], 'The losing runner does not advance last_run.');
    }

    /**
     * A cursor advance is conditional on the owning token: after our first
     * batch another runner swaps the pass token (cursor untouched), so the
     * advance is rejected and the untouched member is not burned.
     */
    public function testCursorAdvanceIsRejectedWhenPassTokenChanges(): void
    {
        $this->seedDemurrageBalances();

        $racer = $this->racer($this->stealPassAfterCommits(1, 777777));

        $result = $racer->runDemurrage(10, true);

        $this->assertTrue($result['busy'], 'A busy batch aborts the runner.');
        $this->assertFalse($result['done']);
        $this->assertEqualsWithDelta(9.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
        $this->assertEqualsWithDelta(
            5.0,
            $this->transact->getBalance($this->otherId, $this->currencyId),
            0.0001,
            'The rejected advance burns nothing.'
        );
        $this->assertSame(1, $this->demurrageRowCount());
        $state = $this->demurrageState();
        $this->assertSame($this->memberId, (int)$state['cursor_user_id'], 'The cursor matches; only the token changed.');
        $this->assertSame(777777, (int)$state['pass_token']);
        $this->assertSame(0, (int)$state['last_run']);
    }

    /**
     * Busy propagation through the multi-batch runner: batch one commits, the
     * token is stolen before the runner re-reads the pass for batch two, and
     * the runner reports busy instead of starting another batch.
     */
    public function testRunnerAbortsWhenTokenIsStolenBetweenBatches(): void
    {
        $this->seedDemurrageBalances();

        $racer = $this->racer();
        $racer->racingDb->afterFetchRow = $this->stealTokenOnStateRead(2, 777777);

        $result = $racer->runDemurrage(1, true);

        $this->assertTrue($result['busy']);
        $this->assertFalse($result['done']);
        $this->assertEqualsWithDelta(9.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
        $this->assertEqualsWithDelta(5.0, $this->transact->getBalance($this->otherId, $this->currencyId), 0.0001);
        $this->assertSame(1, $this->demurrageRowCount());
        $state = $this->demurrageState();
        $this->assertSame($this->memberId, (int)$state['cursor_user_id']);
        $this->assertSame(777777, (int)$state['pass_token']);
        $this->assertSame(0, (int)$state['last_run']);
    }

    /**
     * The completion write is conditional on the owning token: when another
     * runner swaps the token between our last batch and our completion, our
     * completion is rejected (busy) and last_run is not advanced.
     */
    public function testCompletionIsRejectedWhenPassTokenNoLongerMatches(): void
    {
        $this->seedDemurrageBalances();

        $handledAt = \XF::$time - 1000;
        $racer = $this->racer($this->stealPassAfterCommits(2, 777777, $handledAt));

        $step = $racer->demurrageStep(10, true);

        $this->assertTrue($step['ran']);
        $this->assertTrue($step['busy'], 'Completion is rejected when the pass token no longer matches.');
        $this->assertFalse($step['done']);
        $this->assertSame(2, $step['processed']);
        $this->assertEqualsWithDelta(1.5, $step['burned'], 0.0001);
        $this->assertEqualsWithDelta(9.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
        $this->assertEqualsWithDelta(4.5, $this->transact->getBalance($this->otherId, $this->currencyId), 0.0001);
        $this->assertSame(2, $this->demurrageRowCount());
        $state = $this->demurrageState();
        $this->assertSame($handledAt, (int)$state['last_run'], 'The losing runner must not advance last_run.');
        $this->assertSame($this->otherId, (int)$state['cursor_user_id'], 'The cursor matches; only the token changed.');
        $this->assertSame(777777, (int)$state['pass_token']);
    }

    /**
     * The cron/admin time budget stops the runner mid-pass; the next run
     * resumes at the saved cursor and only burns the remaining members.
     */
    public function testTimeBudgetStopResumesFromCursor(): void
    {
        $this->seedDemurrageBalances();

        // A zero budget always breaks after the first successful batch.
        $first = $this->transact->runDemurrage(1, true, 0);
        $this->assertTrue($first['ran']);
        $this->assertFalse($first['done'], 'The budget stops an unfinished pass.');
        $this->assertFalse($first['busy']);
        $this->assertSame(1, $first['processed']);
        $this->assertEqualsWithDelta(1.0, $first['burned'], 0.0001);
        $this->assertSame(1, $first['accounts']);
        $state = $this->demurrageState();
        $this->assertSame($this->memberId, (int)$state['cursor_user_id'], 'The cursor is saved for the resume.');
        $this->assertNotSame(0, (int)$state['pass_token']);
        $this->assertSame(0, (int)$state['last_run']);

        $second = $this->transact->runDemurrage(500, true);
        $this->assertTrue($second['ran']);
        $this->assertTrue($second['done']);
        $this->assertFalse($second['busy']);
        $this->assertSame(1, $second['processed'], 'The resume continues from the cursor, not the start.');
        $this->assertEqualsWithDelta(0.5, $second['burned'], 0.0001);
        $this->assertSame(2, $this->demurrageRowCount(), 'No member is burned twice across the resume.');
        $amounts = [];
        foreach ($this->db->fetchAll(
            'SELECT user_id, amount FROM xf_oc_transaction WHERE currency_id = ? AND `trigger` = ?',
            [$this->currencyId, 'demurrage']
        ) as $row) {
            $amounts[(int)$row['user_id']] = (float)$row['amount'];
        }
        $this->assertEqualsWithDelta(-1.0, $amounts[$this->memberId], 0.0001);
        $this->assertEqualsWithDelta(-0.5, $amounts[$this->otherId], 0.0001);
        $state = $this->demurrageState();
        $this->assertGreaterThan(0, (int)$state['last_run']);
        $this->assertSame(0, (int)$state['cursor_user_id']);
        $this->assertSame(0, (int)$state['pass_token']);
    }

    /**
     * An interval skip is a distinct verdict from a lost pass: both report
     * ran=false, but only the lost race is busy.
     */
    public function testIntervalSkipIsNotBusy(): void
    {
        $this->seedDemurrageBalances();
        $this->assertTrue($this->transact->runDemurrage(500, true)['done']);
        $lastRun = (int)$this->demurrageState()['last_run'];

        $skip = $this->transact->runDemurrage(500, false);
        $this->assertFalse($skip['ran']);
        $this->assertFalse($skip['busy'], 'An interval skip is not a busy pass.');
        $this->assertTrue($skip['done']);
        $this->assertSame(0, $skip['processed']);
        $this->assertEqualsWithDelta(9.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
        $this->assertEqualsWithDelta(4.5, $this->transact->getBalance($this->otherId, $this->currencyId), 0.0001);
        $this->assertSame(2, $this->demurrageRowCount());
        $this->assertSame($lastRun, (int)$this->demurrageState()['last_run']);

        // The same ran=false verdict from a live claim is busy instead.
        $this->db->update(
            'xf_oc_inflation',
            ['pass_started' => \XF::$time, 'pass_token' => 999, 'cursor_user_id' => 0],
            'currency_id = ?',
            [$this->currencyId]
        );
        $busy = $this->transact->runDemurrage(500, true);
        $this->assertFalse($busy['ran']);
        $this->assertTrue($busy['busy']);
        $this->assertFalse($busy['done']);
        $this->assertSame(2, $this->demurrageRowCount());
    }

    /**
     * Disabling inflation while a pass is in flight stops the runner at the
     * next batch (the settings are re-read per step, not snapshotted). The
     * pass stays resumable; a later run picks it up from the cursor.
     */
    public function testDisablingInflationMidPassStopsTheRunner(): void
    {
        $this->seedDemurrageBalances();

        $first = $this->transact->runDemurrage(1, true, 0);
        $this->assertSame('resume', $first['verdict']);
        $this->assertSame($this->memberId, (int)$this->demurrageState()['cursor_user_id']);

        \XF::app()->options()->ocInflationEnabled = false;
        $second = $this->transact->runDemurrage(500, true);

        $this->assertSame('done', $second['verdict']);
        $this->assertFalse($second['ran'], 'A disabled control stops the pass.');
        $this->assertSame(0, $second['processed']);
        $this->assertEqualsWithDelta(9.0, $this->transact->getBalance($this->memberId, $this->currencyId), 0.0001);
        $this->assertEqualsWithDelta(
            5.0,
            $this->transact->getBalance($this->otherId, $this->currencyId),
            0.0001,
            'The remaining member is not burned after the disable.'
        );
        $this->assertSame(1, $this->demurrageRowCount());
    }

    /**
     * The primary-switch seed owns the inflation row shape: a new primary is
     * marked as just burned, with no cursor or pass claim left behind.
     */
    public function testSeedLastRunMarksNewPrimaryAsBurned(): void
    {
        $now = \XF::$time - 10;
        $this->transact->demurrageRunner()->seedLastRun($now, 2);

        $state = $this->demurrageState();
        $this->assertSame($now, (int)$state['last_run']);
        $this->assertSame(0, (int)$state['cursor_user_id']);
        $this->assertSame(0, (int)$state['pass_token']);
    }

    /**
     * A few real members hold balances the whole pass walks in user_id order.
     */
    protected function seedDemurrageBalances(): void
    {
        \XF::app()->options()->ocInflationDemurrageRate = 10;
        $this->transact->adjust($this->memberId, $this->currencyId, 10.0, 'admin_adjust');
        $this->transact->adjust($this->otherId, $this->currencyId, 5.0, 'admin_adjust');
    }

    /** A pass claimed a moment ago that has not processed anyone yet. */
    protected function seedClaimedPass(int $token): void
    {
        $this->db->insert('xf_oc_inflation', [
            'currency_id' => $this->currencyId,
            'last_run' => 0,
            'cursor_user_id' => 0,
            'pass_started' => \XF::$time,
            'pass_token' => $token,
            'pass_rate' => 10,
            'pass_decimals' => 2,
        ]);
    }

    protected function demurrageRowCount(): int
    {
        return (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_transaction WHERE currency_id = ? AND `trigger` = ?',
            [$this->currencyId, 'demurrage']
        );
    }

    /** @return array<string, mixed> */
    protected function demurrageState(): array
    {
        return $this->db->fetchRow(
            'SELECT last_run, cursor_user_id, pass_token FROM xf_oc_inflation WHERE currency_id = ?',
            [$this->currencyId]
        ) ?: [];
    }

    /**
     * A Transact wired to a wrapper that acts like another runner at a commit
     * boundary, where its state write survives the batch transaction.
     */
    protected function racer(?callable $afterCommit = null): RacingTransact
    {
        $racer = new RacingTransact(\XF::app());
        $racer->racingDb = new RacingDb($this->db);
        $racer->racingDb->afterCommit = $afterCommit;
        return $racer;
    }

    /**
     * Hook that swaps only the pass token (and optionally last_run) after the
     * given commit count, so a lone cursor mismatch cannot explain a rejection.
     */
    protected function stealPassAfterCommits(int $commitCount, int $stolenToken, int $lastRun = 0): callable
    {
        $db = $this->db;
        $currencyId = $this->currencyId;
        return function () use ($db, $currencyId, $commitCount, $stolenToken, $lastRun): void {
            static $commits = 0;
            if (++$commits !== $commitCount) {
                return;
            }
            $db->update(
                'xf_oc_inflation',
                ['pass_token' => $stolenToken, 'last_run' => $lastRun],
                'currency_id = ?',
                [$currencyId]
            );
        };
    }

    /**
     * Hook that steals the token on the Nth full pass-state read, as a runner
     * landing between our read and our next write would, leaving the cursor.
     */
    protected function stealTokenOnStateRead(int $readCount, int $stolenToken): callable
    {
        $db = $this->db;
        $currencyId = $this->currencyId;
        return function (string $sql) use ($db, $currencyId, $readCount, $stolenToken): void {
            if (!str_contains($sql, 'pass_rate, pass_decimals')) {
                return;
            }
            static $reads = 0;
            if (++$reads !== $readCount) {
                return;
            }
            $db->update(
                'xf_oc_inflation',
                ['pass_token' => $stolenToken],
                'currency_id = ?',
                [$currencyId]
            );
        };
    }

    public function testStaleFaucetAndClaimRowsArePruned(): void
    {
        $old = Transact::dayStart() - 8 * 86400;
        $this->db->insert('xf_oc_faucet', [
            'user_id' => $this->memberId,
            'currency_id' => $this->currencyId,
            'day_start' => $old,
            'amount' => 5.00,
        ]);
        $this->db->insert('xf_oc_daily_claim', [
            'user_id' => $this->memberId,
            'day_start' => $old,
        ]);

        // Any demurrage tick prunes, even with the rate at 0. Faucet-only
        // setups must not accumulate rows forever. Other tests in this run
        // leave same-day rows behind, so only the stale rows must be gone.
        \XF::app()->options()->ocInflationDemurrageRate = 0;
        $step = $this->transact->demurrageStep(500, true);
        $this->assertFalse($step['ran']);

        $this->assertSame(
            0,
            (int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM xf_oc_faucet WHERE user_id = ? AND day_start = ?',
                [$this->memberId, $old]
            )
        );
        $this->assertSame(
            0,
            (int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM xf_oc_daily_claim WHERE user_id = ? AND day_start = ?',
                [$this->memberId, $old]
            )
        );
    }

    public function testDemurragePurgesOrphanBalance(): void
    {
        \XF::app()->options()->ocInflationDemurrageRate = 10;
        $ghost = 1000 + max(
            (int)$this->db->fetchOne('SELECT COALESCE(MAX(user_id), 0) FROM xf_user'),
            (int)$this->db->fetchOne('SELECT COALESCE(MAX(user_id), 0) FROM xf_oc_transaction')
        );
        $this->assertFalse((bool)$this->db->fetchOne('SELECT user_id FROM xf_user WHERE user_id = ?', [$ghost]));
        $this->db->query(
            'INSERT INTO xf_oc_balance (user_id, currency_id, balance) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE balance = VALUES(balance)',
            [$ghost, $this->currencyId, 50.0]
        );

        $step = $this->transact->demurrageStep(500, true);
        $this->assertTrue($step['ran']);
        $this->assertSame(
            0,
            (int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM xf_oc_balance WHERE user_id = ? AND currency_id = ?',
                [$ghost, $this->currencyId]
            ),
            'Orphan balance rows are purged, not burned.'
        );
        $this->assertSame(
            0,
            (int)$this->db->fetchOne(
                "SELECT COUNT(*) FROM xf_oc_transaction WHERE user_id = ? AND `trigger` = 'demurrage'",
                [$ghost]
            )
        );
    }

    public function testZeroDecimalCapFloorsSubUnitHeadroom(): void
    {
        $zero = $this->createTestCurrency(self::TITLE . ' Zero', false, 0);
        $this->db->update('xf_oc_currency', ['is_primary' => 0], 'currency_id = ?', [$this->currencyId]);
        $this->db->update('xf_oc_currency', ['is_primary' => 1], 'currency_id = ?', [$zero]);
        Transact::clearPrimaryCache();
        \XF::app()->options()->ocInflationBalanceCap = 5;

        // A legacy sub-unit balance the 0-decimal currency can no longer mint.
        $this->db->query(
            'INSERT INTO xf_oc_balance (user_id, currency_id, balance) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE balance = VALUES(balance)',
            [$this->memberId, $zero, 4.50]
        );

        $credited = null;
        $this->assertFalse(
            $this->transact->adjust($this->memberId, $zero, 10.0, self::TRIGGER, 0, '', '', true, $credited),
            '0.50 headroom floors to 0 on a 0-decimal currency.'
        );
    }
}

/**
 * Test-only DB proxy: forwards every call to the real adapter and fires hooks
 * at the moments a competing runner's write can land: after a batch commit
 * (outside its transaction) and after a pass-state read.
 */
class RacingDb
{
    /** @var \XF\Db\AbstractAdapter */
    public $adapter;

    /** @var (callable(): void)|null */
    public $afterCommit;

    /** @var (callable(string): void)|null */
    public $afterFetchRow;

    public function __construct($adapter)
    {
        $this->adapter = $adapter;
    }

    public function commit(): void
    {
        $this->adapter->commit();
        if ($this->afterCommit) {
            ($this->afterCommit)();
        }
    }

    public function fetchRow($query, $params = [])
    {
        $row = $this->adapter->fetchRow($query, $params);
        if ($this->afterFetchRow) {
            ($this->afterFetchRow)((string)$query);
        }
        return $row;
    }

    public function __call(string $name, array $args)
    {
        return $this->adapter->{$name}(...$args);
    }
}

/** Transact bound to a RacingDb instead of the app's real adapter. */
class RacingTransact extends Transact
{
    /** @var RacingDb|null */
    public $racingDb;

    protected function db()
    {
        return $this->racingDb ?: parent::db();
    }
}
