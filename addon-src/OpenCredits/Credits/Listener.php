<?php

namespace OpenCredits\Credits;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Manager as EntityManager;
use XF\Mvc\Entity\Structure;

/**
 * MVP listeners — wired via AdminCP code event listeners (exported to _output/).
 * Triggers: thread, post, reaction_received, register, daily_login.
 * All balance changes go through Service\Transact.
 */
class Listener
{
    /**
     * Per-request cache: does any active event for this trigger carry a
     * forum restriction? Avoids parent-content lookups when unscoped.
     */
    protected static $needsForumContext = [];

    protected static function needsForumContext(string $trigger): bool
    {
        if (!array_key_exists($trigger, self::$needsForumContext)) {
            self::$needsForumContext[$trigger] = (bool)\XF::app()->db()->fetchOne(
                'SELECT event_id FROM xf_oc_event WHERE `trigger` = ? AND active = 1'
                . " AND forum_ids IS NOT NULL AND forum_ids != '' AND forum_ids != '[]' LIMIT 1",
                $trigger
            );
        }
        return self::$needsForumContext[$trigger];
    }

    public static function threadEntityPostSave(Entity $entity)
    {
        $visibleNow = $entity->get('discussion_state') === 'visible';
        $isNewVisible = $entity->isInsert() && $visibleNow;
        // Approve-after-moderation: award on the transition, once (inserts
        // already paid, so only fire when the previous state hid it).
        $becameVisible = !$entity->isInsert() && $visibleNow
            && $entity->getPreviousValue('discussion_state') !== 'visible';
        if ((!$isNewVisible && !$becameVisible)) {
            return;
        }
        self::applyTrigger('thread', (int)$entity->user_id, (int)$entity->thread_id, 'thread', (int)$entity->get('node_id'));
    }

    public static function postEntityPostSave(Entity $entity)
    {
        $visibleNow = $entity->get('message_state') === 'visible';
        $isNewVisible = $entity->isInsert() && $visibleNow;
        $becameVisible = !$entity->isInsert() && $visibleNow
            && $entity->getPreviousValue('message_state') !== 'visible';
        if (!$isNewVisible && !$becameVisible) {
            return;
        }
        // Skip first post of a thread (already awarded as thread event)
        if ((int)$entity->position === 0) {
            return;
        }
        $nodeId = 0;
        if (self::needsForumContext('post')) {
            $thread = $entity->Thread;
            $nodeId = $thread ? (int)$thread->node_id : 0;
        }
        self::applyTrigger('post', (int)$entity->user_id, (int)$entity->post_id, 'post', $nodeId);
    }

    public static function userEntityPostSave(Entity $entity)
    {
        if (!$entity->isInsert()) {
            return;
        }
        // Award only real accounts: never seed banned/rejected/disabled users.
        // Unapproved states (moderated, email_confirm) still earn so the bonus
        // is waiting when the account activates.
        if (in_array($entity->get('user_state'), ['banned', 'rejected', 'disabled'], true)) {
            return;
        }
        self::applyTrigger('register', (int)$entity->user_id, (int)$entity->user_id, 'user');
    }

    public static function userEntityStructure(EntityManager $em, Structure &$structure)
    {
        $structure->columns['oc_credits'] = ['type' => Entity::FLOAT, 'default' => 0.0];
        $structure->getters['oc_balances'] = true;
        $structure->getters['oc_all_balances'] = true;
        $structure->getters['oc_primary'] = true;
    }

    public static function reactionContentEntityPostSave(Entity $entity)
    {
        if (!$entity->isInsert() || !$entity->get('is_counted')) {
            return;
        }
        $authorId = (int)$entity->get('content_user_id');
        $reactorId = (int)$entity->get('reaction_user_id');
        if ($authorId <= 0 || $authorId === $reactorId) {
            return;
        }
        $contentType = (string)$entity->get('content_type');
        $contentId = (int)$entity->get('content_id');
        // One award per reactor per content: un-react is a row delete, so a
        // re-react would otherwise pay again without limit.
        $dedupeKey = 'reaction:' . $reactorId;
        self::applyTrigger(
            'reaction_received',
            $authorId,
            $contentId,
            $contentType,
            self::resolveReactionNodeId($contentType, $contentId),
            $dedupeKey
        );
    }

    protected static function resolveReactionNodeId(string $contentType, int $contentId): int
    {
        if ($contentId <= 0 || !self::needsForumContext('reaction_received')) {
            return 0;
        }
        try {
            $app = \XF::app();
            if ($contentType === 'thread') {
                $thread = $app->em()->find('XF:Thread', $contentId);
                return $thread ? (int)$thread->node_id : 0;
            }
            if ($contentType === 'post') {
                $post = $app->em()->find('XF:Post', $contentId);
                return ($post && $post->Thread) ? (int)$post->Thread->node_id : 0;
            }
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits reaction forum lookup failed: ');
        }
        return 0;
    }

    public static function visitorSetup(\XF\Entity\User &$user = null)
    {
        if (!$user || $user->user_id <= 0 || $user->get('user_state') !== 'valid') {
            return;
        }
        // Registration day is covered by the register bonus, not the daily one.
        if ((int)$user->get('register_date') >= strtotime('today midnight')) {
            return;
        }
        try {
            $app = \XF::app();
            /** @var Service\Transact $svc */
            $svc = $app->service('OpenCredits\Credits:Transact');
            $svc->awardDailyLoginIfNeeded((int)$user->user_id);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits daily_login failed: ');
        }
    }

    public static function userCriteria($rule, array $data, \XF\Entity\User $user, &$return): void
    {
        if ($rule === 'oc_credits_more') {
            $return = ((float)$user->get('oc_credits') >= (float)($data['credits'] ?? 0));
        } elseif ($rule === 'oc_credits_fewer') {
            $return = ((float)$user->get('oc_credits') < (float)($data['credits'] ?? 0));
        }
    }

    protected static function applyTrigger(
        string $trigger,
        int $userId,
        int $contentId = 0,
        string $contentType = '',
        int $nodeId = 0,
        ?string $dedupeKey = null
    ): void {
        if ($userId <= 0) {
            return;
        }
        try {
            $app = \XF::app();
            /** @var Service\Transact $svc */
            $svc = $app->service('OpenCredits\Credits:Transact');
            $svc->awardByTrigger($trigger, $userId, $contentId, $contentType, $nodeId, [], $dedupeKey);
        } catch (\Throwable $e) {
            \XF::logException($e, false, "OpenCredits trigger {$trigger} failed: ");
        }
    }
}
