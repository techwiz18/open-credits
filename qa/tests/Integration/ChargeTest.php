<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\TestCase;

/**
 * DB-backed proof for the [charge] BBCode service (#5): a charge moves the
 * amount from the reader to the author, records a single payment, refuses
 * overdrafts, and is idempotent per content/price.
 *
 * Runs against the licensed dev forum (see tests/README.md); skips without
 * OC_XF_ROOT. Uses two real members (the service locks xf_user rows) and a
 * synthetic content id, then removes every row it wrote and rebuilds.
 */
class ChargeTest extends TestCase
{
    protected const CONTENT_ID = 900000001;
    protected const CONTENT_TYPE = 'post';
    protected const SEED_TRIGGER = 'octest_charge_seed';

    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    /** @var Transact */
    protected $transact;

    protected int $currencyId = 0;
    protected int $payerId = 0;
    protected int $authorId = 0;

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

        $userIds = $this->db->fetchAllColumn(
            "SELECT user_id FROM xf_user WHERE user_state = 'valid' ORDER BY user_id LIMIT 2"
        );
        if (count($userIds) < 2) {
            $this->markTestSkipped('Need at least two valid members to run the charge test.');
        }
        $this->payerId = (int)$userIds[0];
        $this->authorId = (int)$userIds[1];

        $this->cleanup();
        $this->transact->rebuildAllBalancesFully();
        // Fund the payer with a seeded ledger row so the test is independent
        // of existing balances; cleanup removes it again.
        $this->transact->adjust(
            $this->payerId,
            $this->currencyId,
            100.00,
            self::SEED_TRIGGER,
            self::CONTENT_ID,
            'octest charge seed'
        );
    }

    protected function tearDown(): void
    {
        if (!$this->payerId) {
            return;
        }
        $this->cleanup();
        $this->transact->rebuildAllBalancesFully();
    }

    public function testChargeMovesFundsAndRecordsPayment(): void
    {
        $payerBefore = $this->transact->getBalance($this->payerId, $this->currencyId);
        $authorBefore = $this->transact->getBalance($this->authorId, $this->currencyId);

        $result = $this->transact->chargeForContent(
            $this->payerId,
            $this->authorId,
            $this->currencyId,
            10.00,
            self::CONTENT_TYPE,
            self::CONTENT_ID
        );

        $this->assertSame('ok', $result);
        $this->assertEqualsWithDelta($payerBefore - 10.00, $this->transact->getBalance($this->payerId, $this->currencyId), 0.0001);
        $this->assertEqualsWithDelta($authorBefore + 10.00, $this->transact->getBalance($this->authorId, $this->currencyId), 0.0001);

        $paid = $this->transact->paidAmountsFor($this->payerId, self::CONTENT_TYPE, self::CONTENT_ID, $this->currencyId);
        $this->assertArrayHasKey('10.00', $paid);
    }

    public function testChargeIsIdempotent(): void
    {
        $first = $this->transact->chargeForContent(
            $this->payerId,
            $this->authorId,
            $this->currencyId,
            10.00,
            self::CONTENT_TYPE,
            self::CONTENT_ID
        );
        $this->assertSame('ok', $first);

        $payerAfterFirst = $this->transact->getBalance($this->payerId, $this->currencyId);
        $second = $this->transact->chargeForContent(
            $this->payerId,
            $this->authorId,
            $this->currencyId,
            10.00,
            self::CONTENT_TYPE,
            self::CONTENT_ID
        );

        $this->assertSame('already_paid', $second);
        $this->assertEqualsWithDelta($payerAfterFirst, $this->transact->getBalance($this->payerId, $this->currencyId), 0.0001);
    }

    public function testChargeRefusesOverdraft(): void
    {
        $result = $this->transact->chargeForContent(
            $this->payerId,
            $this->authorId,
            $this->currencyId,
            100000.00,
            self::CONTENT_TYPE,
            self::CONTENT_ID
        );

        $this->assertSame('no_funds', $result);
        $this->assertSame(
            [],
            $this->transact->paidAmountsFor($this->payerId, self::CONTENT_TYPE, self::CONTENT_ID, $this->currencyId)
        );
    }

    public function testSelfChargeIsFree(): void
    {
        $result = $this->transact->chargeForContent(
            $this->payerId,
            $this->payerId,
            $this->currencyId,
            10.00,
            self::CONTENT_TYPE,
            self::CONTENT_ID
        );

        $this->assertSame('self', $result);
    }

    private function cleanup(): void
    {
        $this->db->delete('xf_oc_charge', 'content_type = ? AND content_id = ?', [
            self::CONTENT_TYPE,
            self::CONTENT_ID,
        ]);
        $this->db->delete(
            'xf_oc_transaction',
            'content_id = ? AND `trigger` IN (?, ?)',
            [self::CONTENT_ID, 'charge', self::SEED_TRIGGER]
        );
        Transact::clearBalanceCache();
    }
}
