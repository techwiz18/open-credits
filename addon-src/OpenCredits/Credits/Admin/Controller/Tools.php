<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class Tools extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('ocCredits');
        $this->setSectionContext('ocTools');
    }

    public function actionIndex(): \XF\Mvc\Reply\AbstractReply
    {
        $tools = [
            [
                'label' => 'Rebuild balances',
                'hint' => 'Recompute every balance from the transaction log. Safe to run any time.',
                'link' => $this->buildLink('oc-tools/rebuild-form'),
            ],
            [
                'label' => 'Backfill historical content',
                'hint' => 'Award credits for pre-install posts and threads, per event and per member, with preview.',
                'link' => $this->buildLink('oc-tools/backfill'),
            ],
        ];

        return $this->view(
            'OpenCredits\Credits:Tools\Index',
            'oc_tools_index',
            ['tools' => $tools]
        );
    }

    public function actionRebuildForm(): \XF\Mvc\Reply\AbstractReply
    {
        return $this->view(
            'OpenCredits\Credits:Tools\Rebuild',
            'oc_tools_rebuild',
            ['ran' => false, 'rows' => 0]
        );
    }

    public function actionRebuild(): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        $rows = $svc->rebuildAllBalances();

        return $this->view(
            'OpenCredits\Credits:Tools\Rebuild',
            'oc_tools_rebuild',
            ['ran' => true, 'rows' => $rows]
        );
    }

    public function actionBackfill(): \XF\Mvc\Reply\AbstractReply
    {
        return $this->view(
            'OpenCredits\Credits:Tools\Backfill',
            'oc_tools_backfill',
            [
                'ran' => false,
                'awarded' => 0,
                'skipped' => [],
                'preview' => $this->backfillPreview(),
            ]
        );
    }

    public function actionBackfillSave(): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        $eventIds = $this->filter('event_ids', 'array-uint');
        $username = trim($this->filter('username', 'str'));
        $force = $this->filter('force', 'bool');

        if (!$eventIds) {
            return $this->error('Select at least one event to backfill.');
        }

        $onlyUserId = 0;
        if (strlen($username)) {
            $target = $this->finder('XF:User')->where('username', $username)->fetchOne();
            if (!$target) {
                return $this->error('User not found. Check the spelling and try again.');
            }
            $onlyUserId = (int)$target->user_id;
        }

        $events = $this->finder('OpenCredits\Credits:CreditEvent')
            ->where('event_id', $eventIds)
            ->where('active', 1)
            ->where('trigger', ['post', 'thread'])
            ->fetch();

        if (!count($events)) {
            return $this->error('No eligible events selected. Only active post/thread events can be backfilled.');
        }

        $awarded = 0;
        $skipped = [];
        foreach ($events as $event) {
            if (!$force && $this->backfillEventRan($event)) {
                $skipped[] = (int)$event->event_id;
                continue;
            }
            $awarded += $this->runBackfillEvent($event, $onlyUserId);
        }

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        $svc->rebuildAllBalances();

        return $this->view(
            'OpenCredits\Credits:Tools\Backfill',
            'oc_tools_backfill',
            [
                'ran' => true,
                'awarded' => $awarded,
                'skipped' => $skipped,
                'preview' => [],
            ]
        );
    }

    protected function backfillEventRan($event): bool
    {
        return (bool)$this->app->db()->fetchOne(
            'SELECT transaction_id FROM xf_oc_transaction WHERE note = ? AND `trigger` = ? AND currency_id = ? LIMIT 1',
            ['Historical backfill', $event->trigger, $event->currency_id]
        );
    }

    protected function backfillPreview(): array
    {
        $preview = [];
        $events = $this->finder('OpenCredits\Credits:CreditEvent')
            ->with('Currency')
            ->where('active', 1)
            ->where('trigger', ['post', 'thread'])
            ->fetch();
        foreach ($events as $event) {
            if ($event->trigger === 'post') {
                $users = (int)$this->app->db()->fetchOne(
                    "SELECT COUNT(DISTINCT user_id) FROM xf_post WHERE message_state = 'visible' AND user_id > 0"
                );
            } else {
                $users = (int)$this->app->db()->fetchOne(
                    "SELECT COUNT(DISTINCT user_id) FROM xf_thread WHERE discussion_state = 'visible' AND user_id > 0"
                );
            }
            $currency = $event->Currency;
            $preview[] = [
                'event_id' => (int)$event->event_id,
                'label' => \OpenCredits\Credits\Entity\CreditEvent::TRIGGER_LABELS[$event->trigger] ?? $event->trigger,
                'currency' => $currency ? $currency->title : 'Credits',
                'decimals' => $currency ? (int)$currency->decimals : 2,
                'amount' => (float)$event->amount,
                'users' => $users,
                'ran' => $this->backfillEventRan($event),
            ];
        }
        return $preview;
    }

    protected function runBackfillEvent($event, int $onlyUserId = 0): int
    {
        $db = $this->app->db();
        $now = \XF::$time;
        $rows = 0;

        $perItem = (float)$event->amount;
        if ($perItem == 0.0) {
            return 0;
        }

        if ($event->trigger === 'post') {
            $sql = "SELECT user_id, COUNT(*) FROM xf_post WHERE message_state = 'visible' AND user_id > 0";
        } else {
            $sql = "SELECT user_id, COUNT(*) FROM xf_thread WHERE discussion_state = 'visible' AND user_id > 0";
        }
        if ($onlyUserId > 0) {
            $sql .= ' AND user_id = ' . $onlyUserId;
        }
        $counts = $db->fetchPairs($sql . ' GROUP BY user_id');

        foreach ($counts as $userId => $count) {
            $db->insert('xf_oc_transaction', [
                'user_id' => (int)$userId,
                'currency_id' => (int)$event->currency_id,
                'amount' => round($count * $perItem, 2),
                'trigger' => $event->trigger,
                'content_type' => $event->trigger,
                'content_id' => 0,
                'note' => 'Historical backfill',
                'log_date' => $now,
            ]);
            $rows++;
        }
        return $rows;
    }
}
