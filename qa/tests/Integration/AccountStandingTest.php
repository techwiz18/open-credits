<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Entity\Code;
use OpenCredits\Credits\Service\RequestCache;
use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\TestCase;

/**
 * Account standing at the money seams. The unit table pins the verdicts; this
 * pins that transfer, redeem and the charge purchase consult them, so an
 * integrator calling the service directly cannot move credits for an account
 * the controller would have refused.
 *
 * Runs against the licensed dev forum with two throwaway members.
 */
class AccountStandingTest extends TestCase
{
    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    protected ?Transact $transact = null;
    protected int $payerId = 0;
    protected int $otherId = 0;
    protected int $currencyId = 0;
    protected int $postId = 0;

    /** @var string[] Codes seeded by a test, removed in tearDown. */
    protected array $codes = [];

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
        $this->currencyId = $this->transact->primaryCurrencyId();

        // Standing is orthogonal to inflation: pin the levers off so balances
        // and redeem verdicts are deterministic whatever the dev forum has.
        $options = \XF::app()->options();
        foreach ([
            'ocInflationEnabled',
            'ocInflationBalanceCap',
            'ocInflationFaucetDaily',
            'ocInflationDemurrageRate',
            'ocSendEarnAlerts',
        ] as $key) {
            $this->originalOptions[$key] = $options->{$key} ?? null;
        }
        $options->ocInflationEnabled = true;
        $options->ocInflationBalanceCap = 0;
        $options->ocInflationFaucetDaily = 0;
        $options->ocInflationDemurrageRate = 0;
        $options->ocSendEarnAlerts = 0;

        $this->removeThrowawayUsers();
        $this->payerId = $this->createUser('octest_stand_payer');
        $this->otherId = $this->createUser('octest_stand_other');
        $this->seedBalance($this->payerId, 100.0);

        $this->postId = (int)$this->db->fetchOne(
            "SELECT p.post_id FROM xf_post AS p"
            . " LEFT JOIN xf_oc_charge AS c ON c.content_type = 'post' AND c.content_id = p.post_id"
            . " WHERE p.message_state = 'visible'"
            . " GROUP BY p.post_id HAVING COUNT(c.charge_id) = 0"
            . " ORDER BY p.post_id LIMIT 1"
        );
    }

    protected function tearDown(): void
    {
        foreach ([$this->payerId, $this->otherId] as $userId) {
            if ($userId > 0) {
                // The user delete listener drops balances, charges and
                // faucet rows; the ledger stays as financial history.
                $this->db->delete('xf_oc_transaction', 'user_id = ?', [$userId]);
            }
        }
        foreach ($this->codes as $code) {
            $this->db->delete('xf_oc_code', 'code = ?', [$code]);
        }
        $this->removeThrowawayUsers();
        $options = \XF::app()->options();
        foreach ($this->originalOptions as $key => $value) {
            $options->{$key} = $value;
        }
        RequestCache::reset();
    }

    public function testUnconfirmedAndAwaitingApprovalCannotSend(): void
    {
        foreach (['email_confirm', 'moderated'] as $state) {
            $this->setState($this->payerId, $state);

            $this->assertFalse(
                $this->transact->transfer($this->payerId, $this->otherId, 5.0, $this->currencyId),
                "user_state={$state} must not send credits"
            );
            $this->assertEqualsWithDelta(100.0, $this->transact->getBalance($this->payerId, $this->currencyId), 0.0001);
            $this->assertEqualsWithDelta(0.0, $this->transact->getBalance($this->otherId, $this->currencyId), 0.0001);
        }
    }

    public function testUnconfirmedTargetReceivesAndBouncedEmailSends(): void
    {
        $this->setState($this->payerId, 'email_bounce');
        $this->setState($this->otherId, 'email_confirm');

        $this->assertTrue(
            $this->transact->transfer($this->payerId, $this->otherId, 5.0, $this->currencyId),
            'A bounced-email sender may send and an unconfirmed target may receive'
        );
        $this->assertEqualsWithDelta(95.0, $this->transact->getBalance($this->payerId, $this->currencyId), 0.0001);
        $this->assertEqualsWithDelta(5.0, $this->transact->getBalance($this->otherId, $this->currencyId), 0.0001);
    }

    public function testRedeemFollowsEarningStanding(): void
    {
        // Unconfirmed members earn, so a code works for them.
        $this->setState($this->payerId, 'email_confirm');
        $granted = null;
        $this->assertSame(
            'ok',
            $this->transact->redeem($this->payerId, $this->seedCode(5.0), $granted)
        );

        // Banned members earn nothing, and an ineligible redeem leaves the
        // code unused.
        $this->setState($this->payerId, 'valid', true);
        $bannedCode = $this->seedCode(50.0);
        $this->assertSame('ineligible', $this->transact->redeem($this->payerId, $bannedCode));
        $this->assertSame(
            0,
            (int)$this->db->fetchOne('SELECT uses FROM xf_oc_code WHERE code = ?', [$bannedCode])
        );
    }

    public function testChargePurchaseRefusesUnapprovedPayer(): void
    {
        if ($this->postId <= 0) {
            $this->markTestSkipped('No charge-free visible post available. Run ./scripts/dev.sh test seed.');
        }

        foreach (['moderated', 'email_confirm'] as $state) {
            $this->setState($this->payerId, $state);
            $this->assertSame(
                'invalid_author',
                $this->transact->chargeForContent(
                    $this->payerId,
                    $this->otherId,
                    $this->currencyId,
                    5.0,
                    'post',
                    $this->postId
                ),
                "user_state={$state} must not unlock paid content"
            );
        }

        $this->setState($this->payerId, 'valid');
        $this->assertSame(
            'ok',
            $this->transact->chargeForContent(
                $this->payerId,
                $this->otherId,
                $this->currencyId,
                5.0,
                'post',
                $this->postId
            )
        );
        $this->assertEqualsWithDelta(95.0, $this->transact->getBalance($this->payerId, $this->currencyId), 0.0001);
        $this->assertEqualsWithDelta(5.0, $this->transact->getBalance($this->otherId, $this->currencyId), 0.0001);
    }

    private function createUser(string $username): int
    {
        $user = \XF::repository('XF:User')->setupBaseUser();
        $user->username = $username;
        $user->email = $username . '-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $user->user_group_id = 2;
        $user->Auth->setPassword(bin2hex(random_bytes(16)));
        $user->save();

        $userId = (int)$user->user_id;
        // The register award fires on insert; clear it so the test starts
        // from a zero balance.
        $this->db->delete('xf_oc_transaction', 'user_id = ?', [$userId]);
        $this->db->delete('xf_oc_balance', 'user_id = ?', [$userId]);
        $this->db->delete('xf_oc_faucet', 'user_id = ?', [$userId]);
        RequestCache::reset();

        return $userId;
    }

    private function seedBalance(int $userId, float $amount): void
    {
        // adjust() is the privileged path: it is deliberately not gated by
        // account standing, which is exactly what a test fixture needs.
        $this->assertTrue($this->transact->adjust($userId, $this->currencyId, $amount, 'adjust'));
    }

    private function seedCode(float $amount): string
    {
        $code = Code::generate();
        $this->db->insert('xf_oc_code', [
            'code' => $code,
            'currency_id' => $this->currencyId,
            'amount' => $amount,
            'max_uses' => 1,
            'uses' => 0,
            'expiry_date' => 0,
            'active' => 1,
        ]);
        $this->codes[] = $code;

        return $code;
    }

    private function setState(int $userId, string $state, bool $banned = false): void
    {
        // Change state through the entity so the request's identity map sees
        // it too, exactly as a real ban or approval would.
        $user = \XF::em()->find('XF:User', $userId);
        $user->set('user_state', $state);
        $user->set('is_banned', $banned ? 1 : 0);
        $user->save();
    }

    private function removeThrowawayUsers(): void
    {
        foreach (['octest_stand_payer', 'octest_stand_other'] as $username) {
            $existing = \XF::em()->findOne('XF:User', ['username' => $username]);
            if ($existing) {
                $existing->delete();
            }
        }
        RequestCache::reset();
    }
}
