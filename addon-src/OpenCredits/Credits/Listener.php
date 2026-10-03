<?php

namespace OpenCredits\Credits;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Manager as EntityManager;

/**
 * MVP listeners — wired via AdminCP code event listeners (exported to _output/).
 * Triggers: thread, post, reaction_received, register, daily_login.
 * All balance changes go through Service\Transact.
 */
class Listener
{
    public static function threadEntityPostSave(Entity $entity)
    {
        if (!$entity->isInsert() || $entity->get('discussion_state') !== 'visible') {
            return;
        }
        self::applyTrigger('thread', (int)$entity->user_id, (int)$entity->thread_id);
    }

    public static function postEntityPostSave(Entity $entity)
    {
        if (!$entity->isInsert() || $entity->get('message_state') !== 'visible') {
            return;
        }
        // Skip first post of a thread (already awarded as thread event)
        if ((int)$entity->position === 0) {
            return;
        }
        self::applyTrigger('post', (int)$entity->user_id, (int)$entity->post_id);
    }

    public static function userEntityPostSave(Entity $entity)
    {
        if ($entity->isInsert()) {
            self::applyTrigger('register', (int)$entity->user_id, (int)$entity->user_id);
        }
    }

    protected static function applyTrigger(string $trigger, int $userId, int $contentId = 0): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            $app = \XF::app();
            /** @var Service\Transact $svc */
            $svc = $app->service('OpenCredits\Credits:Transact');
            $svc->awardByTrigger($trigger, $userId, $contentId);
        } catch (\Throwable $e) {
            \XF::logException($e, false, "OpenCredits trigger {$trigger} failed: ");
        }
    }
}
