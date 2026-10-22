<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Listener;
use PHPUnit\Framework\TestCase;

/**
 * Charge rows must not outlive their content or member. These exercise the
 * cleanup listeners directly; the event wiring itself is imported from
 * _output/code_event_listeners.
 */
class ChargeCleanupTest extends TestCase
{
    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    protected int $postId = 0;

    protected function setUp(): void
    {
        if (!class_exists('XF', false) || !\XF::app()) {
            $this->markTestSkipped('Set OC_XF_ROOT to a licensed XenForo install to run integration tests.');
        }
        if (!\XF::$time) {
            \XF::$time = time();
        }
        $this->db = \XF::db();
        $this->postId = (int)$this->db->fetchOne('SELECT post_id FROM xf_post ORDER BY post_id LIMIT 1');
        if (!$this->postId) {
            $this->markTestSkipped('No posts available for the cleanup test.');
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testPostDeleteRemovesChargeRows(): void
    {
        $post = \XF::em()->find('XF:Post', $this->postId);
        $this->assertNotNull($post);

        $this->db->insert('xf_oc_charge', [
            'content_type' => 'post',
            'content_id' => $this->postId,
            'user_id' => (int)$post->user_id,
            'author_user_id' => (int)$post->user_id,
            'currency_id' => 1,
            'amount' => 1.00,
            'charge_date' => \XF::$time,
        ]);
        $this->assertSame(1, $this->chargeCount());

        Listener::postEntityPostDelete($post);
        $this->assertSame(0, $this->chargeCount());
    }

    public function testMemberDeleteCleanupCoversPayerAndAuthor(): void
    {
        $deletes = [];
        Listener::userDeleteCleanInit(null, $deletes);
        $this->assertSame(
            ['user_id = ?', 'author_user_id = ?'],
            $deletes['xf_oc_charge'] ?? null
        );
    }

    protected function chargeCount(): int
    {
        return (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM xf_oc_charge WHERE content_type = 'post' AND content_id = ?",
            [$this->postId]
        );
    }

    protected function cleanup(): void
    {
        if ($this->postId) {
            $this->db->delete(
                'xf_oc_charge',
                "content_type = 'post' AND content_id = ?",
                [$this->postId]
            );
        }
    }
}
