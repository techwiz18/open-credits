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
                'preview' => $this->backfillPreview(),
                'alreadyRan' => $this->backfillAlreadyRan(),
            ]
        );
    }

    public function actionBackfillSave(): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        if ($this->backfillAlreadyRan()) {
            return $this->error('Historical backfill has already run. It is a one-time operation.');
        }

        $awarded = $this->runBackfill();

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        $svc->rebuildAllBalances();

        return $this->view(
            'OpenCredits\Credits:Tools\Backfill',
            'oc_tools_backfill',
            [
                'ran' => true,
                'awarded' => $awarded,
                'preview' => [],
                'alreadyRan' => true,
            ]
        );
    }

    protected function backfillAlreadyRan(): bool
    {
        return (bool)$this->app->db()->fetchOne(
            "SELECT transaction_id FROM xf_oc_transaction WHERE note = 'Historical backfill' LIMIT 1"
        );
    }

    protected function backfillPreview(): array
    {
        $preview = [];
        $events = $this->finder('OpenCredits\Credits:CreditEvent')
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
            $preview[] = [
                'trigger' => $event->trigger,
                'currency_id' => $event->currency_id,
                'amount' => (float)$event->amount,
                'users' => $users,
            ];
        }
        return $preview;
    }

    protected function runBackfill(): int
    {
        $db = $this->app->db();
        $now = \XF::$time;
        $rows = 0;

        $counts = [
            'post' => $db->fetchPairs(
                "SELECT user_id, COUNT(*) FROM xf_post WHERE message_state = 'visible' AND user_id > 0 GROUP BY user_id"
            ),
            'thread' => $db->fetchPairs(
                "SELECT user_id, COUNT(*) FROM xf_thread WHERE discussion_state = 'visible' AND user_id > 0 GROUP BY user_id"
            ),
        ];

        $events = $this->finder('OpenCredits\Credits:CreditEvent')
            ->where('active', 1)
            ->where('trigger', ['post', 'thread'])
            ->fetch();

        foreach ($events as $event) {
            $perItem = (float)$event->amount;
            if ($perItem == 0.0) {
                continue;
            }
            foreach ($counts[$event->trigger] as $userId => $count) {
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
        }
        return $rows;
    }
}
