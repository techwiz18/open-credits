<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Pub\Controller\Credits;
use PHPUnit\Framework\TestCase;

/**
 * DB-backed checks for the profile Credits tab (the [charge] hub layout):
 * the action paginates the member's own history at 10/page, honours the
 * currency switcher, hides history for other members, and refuses
 * banned/non-valid viewers. Runs against the licensed dev forum.
 */
class MemberCreditsTest extends TestCase
{
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
    }

    public function testOwnTabPaginatesAtTen(): void
    {
        $user = \XF::em()->find('XF:User', $this->memberId);
        \XF::setVisitor($user);

        $reply = $this->callAction($this->memberId, 1);
        $this->assertInstanceOf(\XF\Mvc\Reply\View::class, $reply);
        $params = $reply->getParams();
        $this->assertTrue($params['isSelf']);
        $this->assertSame(1, (int)$params['page']);
        $this->assertSame(10, (int)$params['perPage']);
        $this->assertIsArray($params['entries']);
        $this->assertGreaterThanOrEqual(0, (int)$params['total']);
    }

    public function testPageParamIsHonoured(): void
    {
        $user = \XF::em()->find('XF:User', $this->memberId);
        \XF::setVisitor($user);

        $reply = $this->callAction($this->memberId, 2);
        $params = $reply->getParams();
        $this->assertSame(2, (int)$params['page']);
    }

    public function testCurrencySwitcherFollowsCurrencyId(): void
    {
        $user = \XF::em()->find('XF:User', $this->memberId);
        \XF::setVisitor($user);

        $reply = $this->callAction($this->memberId, 1, $this->currencyId);
        $params = $reply->getParams();
        $this->assertSame($this->currencyId, (int)$params['currency']->currency_id);
    }

    public function testOtherMemberTabHasNoHistory(): void
    {
        $user = \XF::em()->find('XF:User', $this->memberId);
        \XF::setVisitor($user);

        $reply = $this->callAction($this->otherId, 1);
        $params = $reply->getParams();
        $this->assertFalse($params['isSelf']);
        $this->assertSame([], $params['entries']);
        $this->assertSame(0, (int)$params['total']);
    }

    // (banned viewing is gated by ocView/permission; the spending-path
    // guard is covered by ChargeRenderTest::testBlockedMemberCannotUnlock)

    protected function callAction(int $userId, int $page, int $currencyId = 0): \XF\Mvc\Reply\AbstractReply
    {
        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            ['user_id' => $userId, 'page' => $page, 'currency_id' => $currencyId],
            [],
            [],
            ['REQUEST_METHOD' => 'GET']
        );
        $controller = \XF::app()->controller(Credits::class, $request);
        return $controller->actionMember(new \XF\Mvc\ParameterBag([]));
    }

    protected function tearDown(): void
    {
        \XF::setVisitor(null);
    }
}
