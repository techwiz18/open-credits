<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Service\AwardScope;
use PHPUnit\Framework\TestCase;

/**
 * The shared eligibility verdict: live awards and backfill must agree on
 * who and what qualifies, so the scope matrix lives here, not in callers.
 */
class AwardScopeTest extends TestCase
{
    public function testUnscopedEventAdmitsParticipatingMember(): void
    {
        $this->assertTrue(AwardScope::eligible([], 0, [], true, false, true));
    }

    public function testForumScope(): void
    {
        $row = ['forum_ids' => '1,2', 'usergroup_ids' => null];
        $this->assertTrue(AwardScope::eligible($row, 2, [], true, false, true));
        $this->assertFalse(AwardScope::eligible($row, 9, [], true, false, true));
        $this->assertFalse(AwardScope::eligible($row, 0, [], true, false, true));
    }

    public function testGroupScope(): void
    {
        $row = ['forum_ids' => null, 'usergroup_ids' => '[3]'];
        $this->assertTrue(AwardScope::eligible($row, 0, [3, 7], true, false, true));
        $this->assertTrue(AwardScope::eligible($row, 0, ['3'], true, false, true));
        $this->assertFalse(AwardScope::eligible($row, 0, [7], true, false, true));
        $this->assertFalse(AwardScope::eligible($row, 0, [], true, false, true));
    }

    public function testContentAndParticipationFacts(): void
    {
        $row = [];
        $this->assertFalse(AwardScope::eligible($row, 0, [], true, false, false));
        $this->assertFalse(AwardScope::eligible($row, 0, [], false, false, true));
        $this->assertFalse(AwardScope::eligible($row, 0, [], true, true, true));
    }

    public function testScopeForDecodesBothSets(): void
    {
        $this->assertSame(
            ['forumIds' => [1, 2], 'groupIds' => [3]],
            AwardScope::scopeFor(['forum_ids' => '1,2', 'usergroup_ids' => '[3]'])
        );
        $this->assertSame(
            ['forumIds' => [], 'groupIds' => []],
            AwardScope::scopeFor([])
        );
    }

    public function testNeedsNodeContextEmptyScope(): void
    {
        $this->assertFalse(AwardScope::needsNodeContext([]));
        $this->assertFalse(AwardScope::needsNodeContext([
            ['forum_ids' => null],
            ['forum_ids' => ''],
            ['forum_ids' => '[]'],
            ['forum_ids' => []],
            [],
        ]));
    }

    public function testNeedsNodeContextReadsPopulatedScope(): void
    {
        $this->assertTrue(AwardScope::needsNodeContext([['forum_ids' => '[3,4]']]));
        $this->assertTrue(AwardScope::needsNodeContext([['forum_ids' => '3,4']]));
        $this->assertTrue(AwardScope::needsNodeContext([
            ['forum_ids' => null],
            ['forum_ids' => '[5]'],
        ]));
    }

    public function testNeedsNodeContextTreatsUnparseableScopeAsUnscoped(): void
    {
        // Values the lenient decoder cannot turn into positive ids read as
        // unscoped, so the listener must not resolve a node for them either.
        foreach (['garbage', '"3"', '0', '-3', 'null'] as $malformed) {
            $row = ['forum_ids' => $malformed];
            $this->assertFalse(
                AwardScope::needsNodeContext([$row]),
                'forum_ids=' . var_export($malformed, true) . ' must read as unscoped'
            );
            $this->assertTrue(
                AwardScope::eligible($row, 0, [], true, false, true),
                'forum_ids=' . var_export($malformed, true) . ' must admit any node'
            );
        }
    }

    public function testNeedsNodeContextAgreesWithEligibilityOnMalformedJson(): void
    {
        // Non-JSON falls back to a comma list, so a truncated array or an
        // object still yields the ids it can read. Asking for a node and
        // requiring a matching node are then the same question.
        foreach (['[1,2' => 2, '{"a":1}' => 1] as $malformed => $nodeId) {
            $row = ['forum_ids' => $malformed];
            $this->assertTrue(AwardScope::needsNodeContext([$row]), $malformed);
            $this->assertTrue(AwardScope::eligible($row, $nodeId, [], true, false, true), $malformed);
            $this->assertFalse(AwardScope::eligible($row, 0, [], true, false, true), $malformed);
        }
    }

    public function testCountEligibleTalliesOnlyQualifyingRows(): void
    {
        $event = ['forum_ids' => '1', 'usergroup_ids' => null];
        $rows = [
            // Eligible: visible reply in scoped forum, standing member.
            $this->row(7, 1, 'visible', 3, 'valid', 0, 2, ''),
            // Second eligible row from the same author tallies twice.
            $this->row(7, 1, 'visible', 5, 'valid', 0, 2, ''),
            // Wrong forum, invisible, first post, banned, rejected: out.
            $this->row(8, 9, 'visible', 3, 'valid', 0, 2, ''),
            $this->row(9, 1, 'moderated', 3, 'valid', 0, 2, ''),
            $this->row(10, 1, 'visible', 0, 'valid', 0, 2, ''),
            $this->row(11, 1, 'visible', 3, 'valid', 1, 2, ''),
            $this->row(12, 1, 'visible', 3, 'rejected', 0, 2, ''),
        ];
        $this->assertSame([7 => 2], AwardScope::countEligible($event, true, $rows));
    }

    public function testCountEligibleThreadsIgnorePosition(): void
    {
        $event = ['forum_ids' => null, 'usergroup_ids' => '[3]'];
        $rows = [
            $this->row(7, 1, 'visible', 0, 'valid', 0, 2, '3'),
            $this->row(8, 1, 'visible', 0, 'valid', 0, 2, ''),
        ];
        $this->assertSame([7 => 1], AwardScope::countEligible($event, false, $rows));
    }

    /** @return array<string,mixed> */
    private function row(
        int $author,
        int $node,
        string $state,
        int $position,
        string $userState,
        int $banned,
        int $group,
        string $secondary
    ): array {
        return [
            'author_id' => $author,
            'node_id' => $node,
            'content_state' => $state,
            'position' => $position,
            'user_state' => $userState,
            'is_banned' => $banned,
            'user_group_id' => $group,
            'secondary_group_ids' => $secondary,
        ];
    }
}
