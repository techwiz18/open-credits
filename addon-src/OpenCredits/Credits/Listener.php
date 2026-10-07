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
    public static function threadEntityPostSave(Entity $entity)
    {
        if (!$entity->isInsert() || $entity->get('discussion_state') !== 'visible') {
            return;
        }
        self::applyTrigger('thread', (int)$entity->user_id, (int)$entity->thread_id, 'thread');
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
        self::applyTrigger('post', (int)$entity->user_id, (int)$entity->post_id, 'post');
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
        self::applyTrigger('reaction_received', $authorId, (int)$entity->get('content_id'), (string)$entity->get('content_type'));
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

    protected static function applyTrigger(string $trigger, int $userId, int $contentId = 0, string $contentType = ''): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            $app = \XF::app();
            /** @var Service\Transact $svc */
            $svc = $app->service('OpenCredits\Credits:Transact');
            $svc->awardByTrigger($trigger, $userId, $contentId, $contentType);
        } catch (\Throwable $e) {
            \XF::logException($e, false, "OpenCredits trigger {$trigger} failed: ");
        }
    }
}
