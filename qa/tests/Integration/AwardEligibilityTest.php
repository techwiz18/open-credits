<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\TestCase;

/**
 * Earning eligibility: banned, rejected, and disabled members earn nothing
 * from content activity, matching the register path (which never seeds
 * those states). Unapproved states still earn so the register bonus is
 * waiting when the account activates.
 *
 * Runs against the licensed dev forum with a throwaway member.
 */
class AwardEligibilityTest extends TestCase
{
    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    protected ?Transact $transact = null;
    protected int $userId = 0;
    protected int $currencyId = 0;

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

        // Eligibility is orthogonal to inflation: pin the levers off so the
        // test is deterministic whatever the dev forum has configured.
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

        $this->removeThrowawayUser();
        $user = \XF::repository('XF:User')->setupBaseUser();
        $user->username = 'octest_elig';
        $user->email = 'octest-elig-' . time() . '@example.invalid';
        $user->user_group_id = 2;
        $user->Auth->setPassword(bin2hex(random_bytes(16)));
        $user->save();
        $this->userId = (int)$user->user_id;

        // Creating the member fires its own register award; clear it so each
        // test starts from a zero balance with an empty faucet.
        $this->db->query('DELETE FROM xf_oc_transaction WHERE user_id = ?', [$this->userId]);
        $this->db->query('DELETE FROM xf_oc_faucet WHERE user_id = ?', [$this->userId]);
        $this->db->query('DELETE FROM xf_oc_balance WHERE user_id = ?', [$this->userId]);
        Transact::clearBalanceCache();

        $this->db->insert('xf_oc_event', [
            'currency_id' => $this->currencyId,
            'trigger' => 'octest_elig',
            'amount' => 10.00,
            'max_per_day' => 0,
            'active' => 1,
            'send_alert' => 0,
        ]);
        Transact::clearEventCache();
    }

    protected function tearDown(): void
    {
        if ($this->db && $this->userId) {
            $this->db->query('DELETE FROM xf_oc_transaction WHERE user_id = ?', [$this->userId]);
            $this->db->query('DELETE FROM xf_oc_faucet WHERE user_id = ?', [$this->userId]);
            $this->db->query('DELETE FROM xf_oc_balance WHERE user_id = ?', [$this->userId]);
            $this->db->query('DELETE FROM xf_oc_daily_claim WHERE user_id = ?', [$this->userId]);
            $this->db->query(
                "DELETE FROM xf_oc_event WHERE `trigger` = 'octest_elig' AND currency_id = ?",
                [$this->currencyId]
            );
        }
        $options = \XF::app()->options();
        foreach ($this->originalOptions as $key => $value) {
            $options->{$key} = $value;
        }
        $this->removeThrowawayUser();
        Transact::clearEventCache();
        Transact::clearBalanceCache();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ineligibleStates')]
    public function testIneligibleStatesEarnNothing(string $state, bool $banned): void
    {
        // Change state through the entity so the request's identity map
        // sees it too, exactly as a real ban/disable would.
        $user = \XF::em()->find('XF:User', $this->userId);
        $user->set('user_state', $state);
        $user->set('is_banned', $banned ? 1 : 0);
        $user->save();

        $this->assertFalse(
            $this->transact->awardByTrigger('octest_elig', $this->userId),
            "user_state={$state} banned=" . ($banned ? '1' : '0') . ' must not earn'
        );
        $this->assertEqualsWithDelta(
            0.0,
            $this->transact->getBalance($this->userId, $this->currencyId),
            0.0001
        );
    }

    public static function ineligibleStates(): array
    {
        return [
            'banned' => ['valid', true],
            'rejected' => ['rejected', false],
            'disabled' => ['disabled', false],
        ];
    }

    public function testValidUserEarns(): void
    {
        $this->assertTrue($this->transact->awardByTrigger('octest_elig', $this->userId));
        $this->assertEqualsWithDelta(
            10.0,
            $this->transact->getBalance($this->userId, $this->currencyId),
            0.0001
        );
    }

    public function testAwaitingApprovalStillEarns(): void
    {
        $user = \XF::em()->find('XF:User', $this->userId);
        $user->set('user_state', 'email_confirm');
        $user->save();

        $this->assertTrue($this->transact->awardByTrigger('octest_elig', $this->userId));
        $this->assertEqualsWithDelta(
            10.0,
            $this->transact->getBalance($this->userId, $this->currencyId),
            0.0001
        );
    }

    private function removeThrowawayUser(): void
    {
        $existing = \XF::em()->findOne('XF:User', ['username' => 'octest_elig']);
        if ($existing) {
            $existing->delete();
        }
        Transact::clearBalanceCache();
    }
}
