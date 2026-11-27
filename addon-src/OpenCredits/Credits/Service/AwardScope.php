<?php

namespace OpenCredits\Credits\Service;

use OpenCredits\Credits\Entity\CreditEvent;

/**
 * Single award-eligibility verdict shared by the live award path
 * (Transact::awardByTrigger) and the historical backfill (Tools).
 * Pure: facts in, bool out — no entities, no DB.
 * Award limits (daily caps, dedupe keys, faucet) live elsewhere.
 */
class AwardScope
{
    public static function eligible(
        array $eventRow,
        int $nodeId,
        array $memberGroupIds,
        bool $isVisible,
        bool $isFirstPost,
        bool $canParticipate
    ): bool {
        if (!$canParticipate) {
            return false;
        }
        if (!$isVisible) {
            return false;
        }
        if ($isFirstPost) {
            return false;
        }
        $scope = self::scopeFor($eventRow);
        if ($scope['forumIds'] && ($nodeId <= 0 || !in_array($nodeId, $scope['forumIds'], true))) {
            return false;
        }
        if ($scope['groupIds'] && !array_intersect($scope['groupIds'], array_map('intval', $memberGroupIds))) {
            return false;
        }
        return true;
    }

    /**
     * Tallies eligible content rows per author. Pure: the backfill's verdict
     * path — every counted row passed eligible(), so the live path and the
     * backfill cannot drift. $rows are raw candidate-query rows.
     *
     * @return array<int,int> author user_id => eligible row count
     */
    public static function countEligible(array $eventRow, bool $isPost, array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $groups = array_merge(
                [(int)($row['user_group_id'] ?? 0)],
                CreditEvent::decodeIdList($row['secondary_group_ids'] ?? null)
            );
            if (!self::eligible(
                $eventRow,
                (int)($row['node_id'] ?? 0),
                $groups,
                (string)($row['content_state'] ?? '') === 'visible',
                $isPost && (int)($row['position'] ?? 0) === 0,
                AccountStanding::canEarn(
                    (string)($row['user_state'] ?? ''),
                    !empty($row['is_banned'])
                )
            )) {
                continue;
            }
            $author = (int)($row['author_id'] ?? 0);
            if ($author > 0) {
                $counts[$author] = ($counts[$author] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * Decoded scope sets; empty means unscoped. Shared by the predicate and
     * the backfill SQL so both read the same forum/group restrictions.
     *
     * @return array{forumIds: int[], groupIds: int[]}
     */
    public static function scopeFor(array $eventRow): array
    {
        return [
            'forumIds' => CreditEvent::decodeIdList($eventRow['forum_ids'] ?? null),
            'groupIds' => CreditEvent::decodeIdList($eventRow['usergroup_ids'] ?? null),
        ];
    }
}
