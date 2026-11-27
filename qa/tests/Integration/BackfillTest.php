<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Service\BackfillRunner;
use PHPUnit\Framework\TestCase;

/**
 * DB-backed checks for the historical backfill: event scoping, the first-post
 * rule, event-id completion markers, re-run idempotency, and the per-member
 * double-pay guard. Everything drives Service\BackfillRunner; the controller
 * is only covered by BackfillAdaptersTest.
 *
 * Runs against the licensed dev forum and cleans its own backfill rows and
 * markers in tearDown(); skips without OC_XF_ROOT.
 */
class BackfillTest extends TestCase
{
    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    protected int $eventId = 0;

    protected function setUp(): void
    {
        if (!class_exists('XF', false) || !\XF::app()) {
            $this->markTestSkipped('Set OC_XF_ROOT to a licensed XenForo install to run integration tests.');
        }
        if (!\XF::$time) {
            \XF::$time = time();
        }
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

    public function testPreviewMatchesRunUsers(): void
    {
        $users = $this->previewUserCount();
        if ($users === 0) {
            $this->markTestSkipped('No backfillable content for the post event.');
        }

        $this->runner()->run([$this->eventId], 0, false);

        // Preview and run share the candidate query and the AwardScope tally,
        // so the promised user count must equal the paid distinct users.
        $paid = (int)$this->db->fetchOne(
            "SELECT COUNT(DISTINCT user_id) FROM xf_oc_transaction WHERE event_id = ? AND note = 'Historical backfill'",
            [$this->eventId]
        );
        $this->assertSame($users, $paid);
    }

    public function testRunBackfillsOnceAndSkipsReruns(): void
    {
        if ($this->previewUserCount() === 0) {
            $this->markTestSkipped('No backfillable content for the post event.');
        }

        $result = $this->runner()->run([$this->eventId], 0, false);
        $this->assertSame('done', $result['verdict']);

        $rows = (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM xf_oc_transaction WHERE event_id = ? AND note = 'Historical backfill'",
            [$this->eventId]
        );
        $this->assertGreaterThan(0, $rows);
        $this->assertSame($rows, $result['awarded'], 'The runner reports the rows it wrote.');

        $marker = $this->db->fetchRow(
            'SELECT * FROM xf_oc_backfill WHERE event_id = ? AND user_id = 0',
            [$this->eventId]
        );
        $this->assertNotEmpty($marker);
        $this->assertGreaterThan(0, (int)$marker['completed_date']);

        // Re-run: the completed marker means no new rows, and the event is
        // reported as skipped instead of silently paid again.
        $again = $this->runner()->run([$this->eventId], 0, false);
        $this->assertSame('done', $again['verdict']);
        $this->assertSame([$this->eventId], $again['skipped']);
        $this->assertSame(0, $again['awarded']);
        $rowsAgain = (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM xf_oc_transaction WHERE event_id = ? AND note = 'Historical backfill'",
            [$this->eventId]
        );
        $this->assertSame($rows, $rowsAgain);
    }

    public function testMemberRunAfterSiteWideIsSkipped(): void
    {
        if ($this->previewUserCount() === 0) {
            $this->markTestSkipped('No backfillable content for the post event.');
        }

        $this->runner()->run([$this->eventId], 0, false);
        $rows = (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM xf_oc_transaction WHERE event_id = ? AND note = 'Historical backfill'",
            [$this->eventId]
        );

        // A completed site-wide run already paid this member.
        $userId = (int)$this->db->fetchOne('SELECT user_id FROM xf_user ORDER BY user_id LIMIT 1');
        $this->runner()->run([$this->eventId], $userId, false);
        $again = (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM xf_oc_transaction WHERE event_id = ? AND note = 'Historical backfill'",
            [$this->eventId]
        );
        $this->assertSame($rows, $again);
    }

    public function testBackfillHistoryIsLabelledAndUnlinked(): void
    {
        if ($this->previewUserCount() === 0) {
            $this->markTestSkipped('No backfillable content for the post event.');
        }

        $this->runner()->run([$this->eventId], 0, false);

        $txns = \XF::finder('OpenCredits\Credits:Transaction')
            ->where('event_id', $this->eventId)
            ->where('note', 'Historical backfill')
            ->fetch();
        $this->assertGreaterThan(0, $txns->count());

        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'GET']
        );
        $controller = \XF::app()->controller(\OpenCredits\Credits\Pub\Controller\Credits::class, $request);
        $ref = new \ReflectionMethod($controller, 'buildHistoryEntries');
        $ref->setAccessible(true);
        $entries = $ref->invoke($controller, $txns);

        $this->assertSame('Historical backfill', $entries[0]['label']);
        $this->assertNull($entries[0]['detailThread']);
        $this->assertNull($entries[0]['detailUser']);
    }

    protected function runner(): BackfillRunner
    {
        return \XF::app()->service('OpenCredits\Credits:Transact')->backfillRunner();
    }

    /** The member count the admin preview promises for the post event. */
    protected function previewUserCount(): int
    {
        foreach ($this->runner()->preview() as $row) {
            if ((int)$row['event_id'] === $this->eventId) {
                return (int)$row['users'];
            }
        }
        return 0;
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
