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
            [
                'label' => 'Adjust member credits',
                'hint' => 'Grant or remove credits for a member. Fully logged with a note.',
                'link' => $this->buildLink('oc-tools/adjust'),
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
            if (!$force && $this->backfillEventRan($event, $onlyUserId)) {
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

    public function actionAdjust(): \XF\Mvc\Reply\AbstractReply
    {
        $currencies = $this->finder('OpenCredits\Credits:Currency')
            ->where('active', 1)
            ->order('currency_id')
            ->fetch();

        return $this->view(
            'OpenCredits\Credits:Tools\Adjust',
            'oc_tools_adjust',
            [
                'ran' => false,
                'currencies' => $currencies,
                'username' => '',
                'currencyId' => $currencies->first()?->currency_id ?? 1,
                'amount' => '',
                'note' => '',
            ]
        );
    }

    public function actionAdjustSave(): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        $username = trim($this->filter('username', 'str'));
        $currencyId = $this->filter('currency_id', 'uint');
        $amount = (float)$this->filter('amount', 'str');
        $note = trim($this->filter('note', 'str'));

        $currencies = $this->finder('OpenCredits\Credits:Currency')
            ->where('active', 1)
            ->order('currency_id')
            ->fetch();

        $viewParams = [
            'ran' => false,
            'currencies' => $currencies,
            'username' => $username,
            'currencyId' => $currencyId,
            'amount' => $this->filter('amount', 'str'),
            'note' => $note,
        ];

        if (!strlen($username)) {
            return $this->error('Enter a username.');
        }
        $target = $this->finder('XF:User')->where('username', $username)->fetchOne();
        if (!$target) {
            return $this->error('User not found. Check the spelling and try again.');
        }
        if ($currencyId <= 0 || !isset($currencies[$currencyId])) {
            return $this->error('Select an active currency.');
        }
        if ($amount == 0.0) {
            return $this->error('Enter a non-zero amount. Positive grants, negative removes.');
        }

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        $ok = $svc->adjust(
            (int)$target->user_id,
            $currencyId,
            $amount,
            'admin_adjust',
            0,
            $note !== '' ? $note : 'Admin adjustment'
        );

        if (!$ok) {
            return $this->error('Adjustment refused — check the currency allows the resulting balance (no overdraft when negatives are disabled).');
        }

        return $this->view(
            'OpenCredits\Credits:Tools\Adjust',
            'oc_tools_adjust',
            array_merge($viewParams, [
                'ran' => true,
                'newBalance' => $svc->getBalance((int)$target->user_id, $currencyId),
                'targetUsername' => $target->username,
            ])
        );
    }

    protected function backfillEventRan($event, int $onlyUserId = 0): bool
    {
        $sql = 'SELECT transaction_id FROM xf_oc_transaction WHERE note = ? AND `trigger` = ? AND currency_id = ?';
        $params = ['Historical backfill', $event->trigger, $event->currency_id];
        if ($onlyUserId > 0) {
            // Per-member runs are tracked per member, so one member's backfill
            // never blocks the site-wide run (or vice versa).
            $sql .= ' AND (user_id = ? OR content_id = ?)';
            $params[] = $onlyUserId;
            $params[] = $onlyUserId;
        } else {
            $sql .= ' AND content_id = 0';
        }
        return (bool)$this->app->db()->fetchOne($sql . ' LIMIT 1', $params);
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
        $params = [];
        if ($onlyUserId > 0) {
            $sql .= ' AND user_id = ?';
            $params[] = $onlyUserId;
        }
        $counts = $db->fetchPairs($sql . ' GROUP BY user_id', $params);

        foreach ($counts as $userId => $count) {
            $db->insert('xf_oc_transaction', [
                'user_id' => (int)$userId,
                'currency_id' => (int)$event->currency_id,
                'amount' => round($count * $perItem, 2),
                'trigger' => $event->trigger,
                'content_type' => $event->trigger,
                'content_id' => $onlyUserId > 0 ? $onlyUserId : 0,
                'note' => 'Historical backfill',
                'log_date' => $now,
            ]);
            $rows++;
        }
        return $rows;
    }
}
