<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Pub\Controller\Credits;
use PHPUnit\Framework\TestCase;

/**
 * The two credits surfaces: the wallet page (/credits) owns the balance,
 * actions and full paged history; the profile Credits tab is per-member
 * (balances + a send form for other members, "Open wallet" for self).
 *
 * Runs against the licensed dev forum and cleans its own seeded rows.
 */
class MemberCreditsTest extends TestCase
{
    protected const SEED_TRIGGER = 'octest_wallet';

    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    protected int $memberId = 0;
    protected int $otherId = 0;
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
        $this->currencyId = \XF::app()->service('OpenCredits\Credits:Transact')->primaryCurrencyId();

        $ids = $this->db->fetchAllColumn(
            "SELECT user_id FROM xf_user WHERE user_state = 'valid' AND is_banned = 0 ORDER BY user_id LIMIT 2"
        );
        if (count($ids) < 2) {
            $this->markTestSkipped('Need at least two valid members.');
        }
        $this->memberId = (int)$ids[0];
        $this->otherId = (int)$ids[1];

        $this->cleanup();
        \XF::app()->session()->remove('ocWalletPerPage');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        \XF::setVisitor(null);
    }

    public function testWalletPaginatesAtTenByDefault(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));
        $this->seedWalletRows(25);

        $page1 = $this->callIndex(1)->getParams();
        $this->assertCount(10, $page1['entries']);
        $this->assertGreaterThanOrEqual(25, (int)$page1['total']);

        $page2 = $this->callIndex(2)->getParams();
        $this->assertCount(10, $page2['entries']);

        $larger = $this->callIndex(1, 0, 50)->getParams();
        $this->assertGreaterThanOrEqual(25, count($larger['entries']));
    }

    public function testWalletPerPageSelectionPersists(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));
        $this->seedWalletRows(25);

        $first = $this->callIndex(1, 0, 20)->getParams();
        $this->assertCount(20, $first['entries']);

        // No per_page param: the session value (20) is reused.
        $second = $this->callIndex(1)->getParams();
        $this->assertCount(20, $second['entries']);
    }

    public function testWalletCurrencySwitcher(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $params = $this->callIndex(1, $this->currencyId)->getParams();
        $this->assertSame($this->currencyId, (int)$params['currency']->currency_id);
    }

    public function testRetiredTransferPageRedirectsToWallet(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            ['currency_id' => $this->currencyId],
            [],
            [],
            ['REQUEST_METHOD' => 'GET']
        );
        $controller = \XF::app()->controller(Credits::class, $request);
        $reply = $controller->actionTransfer(new \XF\Mvc\ParameterBag([]));

        $this->assertInstanceOf(\XF\Mvc\Reply\Redirect::class, $reply);
        $this->assertStringContainsString('credits', $reply->getUrl());
        $this->assertStringNotContainsString('transfer', $reply->getUrl());
    }

    public function testRetiredRedeemPageRedirectsToWallet(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'GET']
        );
        $controller = \XF::app()->controller(Credits::class, $request);
        $reply = $controller->actionRedeem(new \XF\Mvc\ParameterBag([]));

        $this->assertInstanceOf(\XF\Mvc\Reply\Redirect::class, $reply);
        $this->assertStringContainsString('credits', $reply->getUrl());
    }

    public function testOwnTabIsPerMember(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $params = $this->callMember($this->memberId)->getParams();
        $this->assertTrue($params['isSelf']);
        $this->assertSame('', $params['lockedTo']);
        $this->assertArrayNotHasKey('entries', $params);
        $this->assertArrayNotHasKey('page', $params);
    }

    public function testOtherMemberTabLocksSendForm(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $other = \XF::em()->find('XF:User', $this->otherId);
        $params = $this->callMember($this->otherId)->getParams();
        $this->assertFalse($params['isSelf']);
        $this->assertSame($other->username, $params['lockedTo']);
        $this->assertSame($this->otherId, (int)$params['user']->user_id);
    }

    public function testTabSendFormRedirectsToRecipientTab(): void
    {
        $sender = \XF::em()->find('XF:User', $this->otherId);
        $recipient = \XF::em()->find('XF:User', $this->memberId);
        $this->assertNotNull($sender);
        $this->assertNotNull($recipient);
        \XF::setVisitor($sender);

        $before = (int)$this->db->fetchOne(
            'SELECT COALESCE(MAX(transaction_id), 0) FROM xf_oc_transaction'
        );
        $svc = \XF::app()->service('OpenCredits\Credits:Transact');
        $svc->adjust($this->otherId, $this->currencyId, 10.00, 'octest_wallet_seed', 0, 'test');

        try {
            $request = new \XF\Http\Request(
                \XF::app()->inputFilterer(),
                [
                    'to' => $recipient->username,
                    'amount' => 1,
                    'currency_id' => $this->currencyId,
                    'oc_in_tab' => 1,
                ],
                [],
                [],
                ['REQUEST_METHOD' => 'POST']
            );
            $controller = \XF::app()->controller(Credits::class, $request);
            $reply = $controller->actionTransferSave(new \XF\Mvc\ParameterBag([]));

            $this->assertInstanceOf(\XF\Mvc\Reply\Redirect::class, $reply);
            $this->assertStringContainsString($recipient->username, $reply->getUrl());
            $this->assertStringContainsString('#credits', $reply->getUrl());
        } finally {
            $this->db->delete('xf_oc_transaction', 'transaction_id > ?', [$before]);
            $svc->rebuildAllBalancesFully();
        }
    }

    protected function callIndex(int $page, int $currencyId = 0, ?int $perPage = null): \XF\Mvc\Reply\AbstractReply
    {
        $input = ['page' => $page, 'currency_id' => $currencyId];
        if ($perPage !== null) {
            $input['per_page'] = $perPage;
        }

        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            $input,
            [],
            [],
            ['REQUEST_METHOD' => 'GET']
        );
        $controller = \XF::app()->controller(Credits::class, $request);
        return $controller->actionIndex(new \XF\Mvc\ParameterBag([]));
    }

    protected function callMember(int $userId): \XF\Mvc\Reply\AbstractReply
    {
        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            ['user_id' => $userId],
            [],
            [],
            ['REQUEST_METHOD' => 'GET']
        );
        $controller = \XF::app()->controller(Credits::class, $request);
        return $controller->actionMember(new \XF\Mvc\ParameterBag([]));
    }

    protected function seedWalletRows(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->db->insert('xf_oc_transaction', [
                'user_id' => $this->memberId,
                'currency_id' => $this->currencyId,
                'amount' => 1,
                'trigger' => self::SEED_TRIGGER,
                'content_type' => '',
                'content_id' => 0,
                'event_id' => 0,
                'note' => 'test',
                'log_date' => \XF::$time + $i,
            ]);
        }
    }

    protected function cleanup(): void
    {
        if ($this->memberId) {
            $this->db->delete(
                'xf_oc_transaction',
                'user_id = ? AND `trigger` = ?',
                [$this->memberId, self::SEED_TRIGGER]
            );
            \XF::app()->service('OpenCredits\Credits:Transact')->rebuildAllBalancesFully();
        }
    }
}
