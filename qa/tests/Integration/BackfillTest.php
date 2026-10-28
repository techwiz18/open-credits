<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Admin\Controller\Tools;
use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\TestCase;

/**
 * DB-backed checks for the historical backfill: event scoping, the first-post
 * rule, event-id completion markers, re-run idempotency, and the per-member
 * double-pay guard.
 *
 * Runs against the licensed dev forum and cleans its own backfill rows and
 * markers in tearDown(); skips without OC_XF_ROOT.
 */
class BackfillTest extends TestCase
{
    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    /** @var \XF\App */
    protected $app;

    protected int $eventId = 0;

    protected function setUp(): void
    {
        if (!class_exists('XF', false) || !\XF::app()) {
            $this->markTestSkipped('Set OC_XF_ROOT to a licensed XenForo install to run integration tests.');
        }
        if (!\XF::$time) {
            \XF::$time = time();
        }
        $this->app = \XF::app();
        $this->db = \XF::db();

        $event = \XF::finder('OpenCredits\Credits:CreditEvent')
            ->with('Currency')
            ->where('trigger', 'post')
            ->where('active', 1)
            ->fetchOne();
        if (!$event || !$event->Currency || !$event->Currency->active) {
            $this->markTestSkipped('No active post event with an active currency.');
        }
        $this->eventId = (int)$event->event_id;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if ($this->eventId) {
            $this->cleanup();
        }
    }

    public function testContentQueryAppliesScopeAndFirstPost(): void
    {
        $event = \XF::em()->create('OpenCredits\Credits:CreditEvent');
        $event->trigger = 'post';
        $event->forum_ids = [1];
        $event->usergroup_ids = [2];
        $event->amount = 1.0;
        $event->currency_id = 1;

        [$sql, $params] = $this->invoke('backfillContentQuery', $event, 0, 0, 100, true);

        $this->assertStringContainsString('p.position > 0', $sql);
        $this->assertStringContainsString('t.node_id IN', $sql);
        $this->assertStringContainsString('FIND_IN_SET', $sql);
        $this->assertStringContainsString('event_id = ?', $sql);
        $this->assertStringContainsString("u.is_banned = 0", $sql);
        $this->assertStringContainsString('COUNT(DISTINCT p.user_id)', $sql);
        $this->assertContains(1, $params);
        $this->assertContains(2, $params);
    }

    public function testActionBackfillsOnceAndSkipsReruns(): void
    {
        if ($this->backfillUserCount() === 0) {
            $this->markTestSkipped('No backfillable content for the post event.');
        }

        $this->runAction([$this->eventId]);

        $rows = (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM xf_oc_transaction WHERE event_id = ? AND note = 'Historical backfill'",
            [$this->eventId]
        );
        $this->assertGreaterThan(0, $rows);

        $marker = $this->db->fetchRow(
            'SELECT * FROM xf_oc_backfill WHERE event_id = ? AND user_id = 0',
            [$this->eventId]
        );
        $this->assertNotEmpty($marker);
        $this->assertGreaterThan(0, (int)$marker['completed_date']);

        // Re-run: the completed marker means no new rows.
        $this->runAction([$this->eventId]);
        $again = (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM xf_oc_transaction WHERE event_id = ? AND note = 'Historical backfill'",
            [$this->eventId]
        );
        $this->assertSame($rows, $again);
    }

    public function testMemberRunAfterSiteWideIsSkipped(): void
    {
        if ($this->backfillUserCount() === 0) {
            $this->markTestSkipped('No backfillable content for the post event.');
        }

        $this->runAction([$this->eventId]);
        $rows = (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM xf_oc_transaction WHERE event_id = ? AND note = 'Historical backfill'",
            [$this->eventId]
        );

        // A completed site-wide run already paid this member.
        $username = (string)$this->db->fetchOne('SELECT username FROM xf_user ORDER BY user_id LIMIT 1');
        $this->runAction([$this->eventId], $username);
        $again = (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM xf_oc_transaction WHERE event_id = ? AND note = 'Historical backfill'",
            [$this->eventId]
        );
        $this->assertSame($rows, $again);
    }

    public function testBackfillHistoryIsLabelledAndUnlinked(): void
    {
        if ($this->backfillUserCount() === 0) {
            $this->markTestSkipped('No backfillable content for the post event.');
        }

        $this->runAction([$this->eventId]);

        $txns = \XF::finder('OpenCredits\Credits:Transaction')
            ->where('event_id', $this->eventId)
            ->where('note', 'Historical backfill')
            ->fetch();
        $this->assertGreaterThan(0, $txns->count());

        $request = new \XF\Http\Request(
            $this->app->inputFilterer(),
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'GET']
        );
        $controller = $this->app->controller(\OpenCredits\Credits\Pub\Controller\Credits::class, $request);
        $ref = new \ReflectionMethod($controller, 'buildHistoryEntries');
        $ref->setAccessible(true);
        $entries = $ref->invoke($controller, $txns);

        $this->assertSame('Historical backfill', $entries[0]['label']);
        $this->assertNull($entries[0]['detailThread']);
        $this->assertNull($entries[0]['detailUser']);
    }

    protected function backfillUserCount(): int
    {
        $event = \XF::finder('OpenCredits\Credits:CreditEvent')
            ->where('event_id', $this->eventId)
            ->fetchOne();
        if (!$event) {
            return 0;
        }
        [$sql, $params] = $this->invoke('backfillContentQuery', $event, 0, 0, 0, true);
        return (int)$this->db->fetchOne($sql, $params);
    }

    protected function runAction(array $eventIds, string $username = ''): void
    {
        $request = new \XF\Http\Request(
            $this->app->inputFilterer(),
            ['event_ids' => $eventIds, 'username' => $username, 'force' => 0],
            [],
            [],
            ['REQUEST_METHOD' => 'POST']
        );
        $controller = $this->app->controller(Tools::class, $request);
        $this->invoke('actionBackfillSave', $controller);
    }

    /**
     * @return mixed
     */
    protected function invoke(string $method, ...$args)
    {
        if (($args[0] ?? null) instanceof Tools) {
            $controller = array_shift($args);
        } else {
            $request = new \XF\Http\Request($this->app->inputFilterer(), [], [], [], ['REQUEST_METHOD' => 'POST']);
            $controller = $this->app->controller(Tools::class, $request);
        }

        $ref = new \ReflectionMethod($controller, $method);
        $ref->setAccessible(true);
        return $ref->invoke($controller, ...$args);
    }

    protected function cleanup(): void
    {
        $this->db->delete(
            'xf_oc_transaction',
            "note = 'Historical backfill' AND event_id = ?",
            [$this->eventId]
        );
        $this->db->delete('xf_oc_backfill', 'event_id = ?', [$this->eventId]);
        \XF::app()->service('OpenCredits\Credits:Transact')->rebuildAllBalancesFully();
    }
}
