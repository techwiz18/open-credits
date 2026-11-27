<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Pub\Controller\Credits;
use OpenCredits\Credits\Service\Transact;

/**
 * One purchase unlocks the whole post, so the unlock endpoint must charge the
 * highest valid [charge] block even when the form submits the cheapest one.
 */
class ChargeUnlockTest extends IntegrationTestCase
{
    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    /** @var Transact */
    protected $transact;

    protected int $postId = 0;
    protected int $authorId = 0;
    protected int $memberId = 0;
    protected int $currencyId = 0;
    protected string $originalMessage = '';

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
        $this->currencyId = $this->transact->primaryCurrencyId();

        $this->postId = (int)$this->db->fetchOne(
            "SELECT p.post_id FROM xf_post AS p"
            . " LEFT JOIN xf_oc_charge AS c ON c.content_type = 'post' AND c.content_id = p.post_id"
            . " WHERE p.message_state = 'visible'"
            . " GROUP BY p.post_id HAVING COUNT(c.charge_id) = 0"
            . " ORDER BY p.post_id LIMIT 1"
        );
        if (!$this->postId) {
            $this->markTestSkipped('No charge-free visible post available. Run ./scripts/dev.sh test seed.');
        }
        $this->authorId = (int)$this->db->fetchOne(
            'SELECT user_id FROM xf_post WHERE post_id = ?',
            [$this->postId]
        );
        $this->memberId = (int)$this->db->fetchOne(
            "SELECT user_id FROM xf_user WHERE user_state = 'valid' AND is_banned = 0"
            . ' AND is_admin = 0 AND is_staff = 0 AND is_moderator = 0 AND user_id <> ?'
            . ' ORDER BY user_id LIMIT 1',
            [$this->authorId]
        );
        if (!$this->memberId) {
            $this->markTestSkipped('No non-author regular member available. Run ./scripts/dev.sh test seed.');
        }
        $this->originalMessage = (string)$this->db->fetchOne(
            'SELECT message FROM xf_post WHERE post_id = ?',
            [$this->postId]
        );
    }

    protected function tearDown(): void
    {
        $this->db->delete(
            'xf_oc_charge',
            "content_type = 'post' AND content_id = ? AND user_id = ?",
            [$this->postId, $this->memberId]
        );
        $this->db->delete(
            'xf_flood_check',
            'user_id = ? AND flood_action = ?',
            [$this->memberId, 'oc_unlock']
        );
        $this->db->update(
            'xf_post',
            ['message' => $this->originalMessage],
            'post_id = ?',
            [$this->postId]
        );
        \XF::em()->clearEntityCache();
        $this->transact->rebuildAllBalancesFully();
    }

    public function testUnlockChargesHighestBlockPrice(): void
    {
        $this->db->update(
            'xf_post',
            ['message' => '[charge=5]CHEAP_SECRET[/charge][charge=50]PRICEY_SECRET[/charge]'],
            'post_id = ?',
            [$this->postId]
        );
        \XF::em()->clearEntityCache();
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $balanceBefore = $this->transact->getBalance($this->memberId, $this->currencyId);
        $txnBefore = (int)$this->db->fetchOne(
            'SELECT COALESCE(MAX(transaction_id), 0) FROM xf_oc_transaction'
        );
        $this->transact->adjust($this->memberId, $this->currencyId, 100.00, 'octest_unlock_seed', 0, 'test');
        $txnAfterSeed = (int)$this->db->fetchOne(
            'SELECT COALESCE(MAX(transaction_id), 0) FROM xf_oc_transaction'
        );
        $chargeCount = function (): int {
            return (int)$this->db->fetchOne(
                "SELECT COUNT(*) FROM xf_oc_charge WHERE content_type = 'post' AND content_id = ? AND user_id = ?",
                [$this->postId, $this->memberId]
            );
        };

        try {
            $reply = $this->unlock($this->memberId);

            $this->assertInstanceOf(\XF\Mvc\Reply\Redirect::class, $reply);

            $charged = (float)$this->db->fetchOne(
                "SELECT amount FROM xf_oc_charge WHERE content_type = 'post' AND content_id = ? AND user_id = ?",
                [$this->postId, $this->memberId]
            );
            $this->assertEqualsWithDelta(50.0, $charged, 0.0001);
            $this->assertSame(1, $chargeCount(), 'One unlock writes one charge row');
            $balanceAfterFirst = $this->transact->getBalance($this->memberId, $this->currencyId);
            $this->assertEqualsWithDelta($balanceBefore + 50.0, $balanceAfterFirst, 0.0001);

            $repeat = $this->unlock($this->memberId);
            $this->assertInstanceOf(\XF\Mvc\Reply\Redirect::class, $repeat);
            $this->assertSame(1, $chargeCount(), 'A repeat unlock writes no second charge row');
            $this->assertSame(
                2,
                (int)$this->db->fetchOne(
                    'SELECT COUNT(*) FROM xf_oc_transaction WHERE transaction_id > ?',
                    [$txnAfterSeed]
                ),
                'One unlock writes exactly the payer and payee ledger rows'
            );
            $this->assertEqualsWithDelta(
                $balanceAfterFirst,
                $this->transact->getBalance($this->memberId, $this->currencyId),
                0.0001,
                'A repeat unlock moves no credits'
            );
        } finally {
            $this->db->delete('xf_oc_transaction', 'transaction_id > ?', [$txnBefore]);
            $this->db->delete(
                'xf_oc_charge',
                "content_type = 'post' AND content_id = ? AND user_id = ?",
                [$this->postId, $this->memberId]
            );
            $this->transact->rebuildAllBalancesFully();
        }
    }

    public function testGuestUnlockPostIsForbidden(): void
    {
        $reply = $this->unlock(null);

        $this->assertInstanceOf(\XF\Mvc\Reply\Error::class, $reply);
        $this->assertSame(403, $reply->getResponseCode());
    }

    /**
     * Runs the unlock POST as $userId (or a guest when null).
     */
    protected function unlock(?int $userId): \XF\Mvc\Reply\AbstractReply
    {
        \XF::setVisitor($userId ? \XF::em()->find('XF:User', $userId) : null);

        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            [
                'content_type' => 'post',
                'content_id' => $this->postId,
                'amount' => 5,
            ],
            [],
            [],
            ['REQUEST_METHOD' => 'POST']
        );
        $controller = \XF::app()->controller(Credits::class, $request);
        return $controller->actionUnlock(new \XF\Mvc\ParameterBag([]));
    }
}
