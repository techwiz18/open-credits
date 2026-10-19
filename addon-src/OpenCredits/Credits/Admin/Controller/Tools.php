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
                'label' => \XF::phrase('ocadm_tool_rebuild_title')->render(),
                'hint' => \XF::phrase('ocadm_tool_rebuild_hint')->render(),
                'link' => $this->buildLink('oc-tools/rebuild-form'),
            ],
            [
                'label' => \XF::phrase('ocadm_tool_backfill_title')->render(),
                'hint' => \XF::phrase('ocadm_tool_backfill_hint')->render(),
                'link' => $this->buildLink('oc-tools/backfill'),
            ],
            [
                'label' => \XF::phrase('ocadm_tool_adjust_title')->render(),
                'hint' => \XF::phrase('ocadm_tool_adjust_hint')->render(),
                'link' => $this->buildLink('oc-tools/adjust'),
            ],
            [
                'label' => \XF::phrase('ocadm_tool_prune_title')->render(),
                'hint' => \XF::phrase('ocadm_tool_prune_hint')->render(),
                'link' => $this->buildLink('oc-tools/prune'),
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
        try {
            $rows = $svc->rebuildAllBalancesFully();
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits rebuild failed: ');
            return $this->error('Balance rebuild failed due to a server error. Please try again.');
        }

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
            ->with('Currency')
            ->where('event_id', $eventIds)
            ->where('active', 1)
            ->where('trigger', ['post', 'thread'])
            ->fetch();

        if (!count($events)) {
            return $this->error('No eligible events selected. Only active post/thread events can be backfilled.');
        }

        // Events on inactive currencies are skipped, never paid.
        $eligible = [];
        $awarded = 0;
        $skipped = [];
        foreach ($events as $event) {
            $currency = $event->Currency;
            if ($currency && $currency->active) {
                $eligible[] = $event;
            } else {
                $skipped[] = (int)$event->event_id;
            }
        }
        if (!$eligible) {
            return $this->error('No eligible events selected. The chosen events use inactive currencies.');
        }

        // Each event (and each site-wide batch) commits separately so huge
        // forums complete in small transactions instead of one giant one.
        // A failed event rolls back to its pre-run state (tracked by ledger
        // id), so retrying it neither double-pays nor under-pays.
        $db = $this->app->db();
        try {
            foreach ($eligible as $event) {
                if (!$force && $this->backfillEventRan($event, $onlyUserId)) {
                    $skipped[] = (int)$event->event_id;
                    continue;
                }
                $txnStart = (int)$db->fetchOne('SELECT COALESCE(MAX(transaction_id), 0) FROM xf_oc_transaction');
                try {
                if ($onlyUserId > 0) {
                    $result = $this->runBackfillEvent($event, $onlyUserId);
                    $awarded += $result['rows'];
                    continue;
                }
                    // Site-wide runs page through user-id ranges so huge forums
                    // complete in small transactions instead of one giant one.
                    $next = 0;
                    do {
                        $result = $this->runBackfillEvent($event, 0, $next, 5000);
                        $awarded += $result['rows'];
                        $next = $result['next'];
                    } while ($next !== null);
                } catch (\Throwable $e) {
                    $db->query(
                        'DELETE FROM xf_oc_transaction WHERE transaction_id > ?'
                        . ' AND `trigger` = ? AND note = ?',
                        [$txnStart, $event->trigger, 'Historical backfill']
                    );
                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits backfill failed: ');
            return $this->error(
                'Backfill failed part-way. Finished events are kept and the '
                . 'failed event was rolled back — fix the cause and re-run.'
            );
        }

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        try {
            $svc->rebuildAllBalancesFully();
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits backfill rebuild failed: ');
            return $this->error(
                'Backfill rows were written, but the balance rebuild failed. '
                . 'Run AdminCP → Credits → Tools → Rebuild balances to finish.'
            );
        }

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
        $amount = $this->filter('amount', 'float');
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
        $currency = $currencies[$currencyId];
        if (round($amount, (int)$currency->decimals) == 0.0) {
            return $this->error(
                'That amount is smaller than ' . $currency->title . '\'s smallest unit'
                . ' — enter a larger amount.'
            );
        }

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        try {
            $ok = $svc->adjust(
                (int)$target->user_id,
                $currencyId,
                $amount,
                'admin_adjust',
                0,
                $note !== '' ? $note : 'Admin adjustment'
            );
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits admin adjust failed: ');
            return $this->error('Adjustment failed due to a server error. Please try again.');
        }

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

    public function actionPrune(): \XF\Mvc\Reply\AbstractReply
    {
        $retention = (int)$this->app()->options()->ocHistoryRetentionDays;
        return $this->view(
            'OpenCredits\Credits:Tools\Prune',
            'oc_tools_prune',
            [
                'ran' => false,
                'days' => $retention > 0 ? $retention : 365,
                'snapshots' => 0,
                'deleted' => 0,
                'preview' => $this->prunePreview($retention > 0 ? $retention : 365),
            ]
        );
    }

    public function actionPruneSave(): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        $days = $this->filter('days', 'uint');
        if ($days < 1) {
            return $this->error('Enter at least 1 day.');
        }
        $cutoff = \XF::$time - $days * 86400;

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        $snapshots = 0;
        $deleted = 0;
        try {
            $next = 0;
            do {
                $result = $svc->pruneHistory($cutoff, $next, 5000);
                $snapshots += $result['snapshots'];
                $deleted += $result['deleted'];
                $next = $result['next'];
            } while ($next !== null);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits prune failed: ');
            return $this->error('Pruning failed due to a server error. Completed batches are kept — fix the cause and re-run.');
        }

        return $this->view(
            'OpenCredits\Credits:Tools\Prune',
            'oc_tools_prune',
            [
                'ran' => true,
                'days' => $days,
                'snapshots' => $snapshots,
                'deleted' => $deleted,
                'preview' => [],
            ]
        );
    }

    protected function prunePreview(int $days): array
    {
        // Match pruneHistory()'s current-day guard so the preview never
        // promises to delete rows the service will refuse to touch.
        $cutoff = min(
            \XF::$time - $days * 86400,
            \OpenCredits\Credits\Service\Transact::dayStart() - 1
        );
        $db = $this->app->db();
        $rows = $db->fetchAll(
            'SELECT c.currency_id, c.title, COUNT(*) AS entry_count FROM xf_oc_transaction AS t'
            . ' INNER JOIN xf_oc_currency AS c ON c.currency_id = t.currency_id'
            . ' WHERE t.log_date <= ? AND t.`trigger` != ? AND t.note != ?'
            . ' GROUP BY c.currency_id, c.title ORDER BY c.currency_id',
            [$cutoff, 'redeem', 'Historical backfill']
        );
        $preview = [];
        foreach ($rows as $row) {
            $preview[] = [
                'currency' => $row['title'],
                'rows' => (int)$row['entry_count'],
            ];
        }
        return $preview;
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
            $previewCurrency = $event->Currency;
            if (!$previewCurrency || !$previewCurrency->active) {
                continue;
            }
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

    /**
     * Backfills one event. Per-member mode writes a single row. Site-wide
     * mode processes one user-id range per call (pass $batchSize > 0 and loop
     * on 'next') so huge forums complete in small transactions. Each call
     * commits atomically and returns ['rows', 'next' (or null when done)].
     */
    protected function runBackfillEvent($event, int $onlyUserId = 0, int $startAfterUserId = 0, int $batchSize = 0): array
    {
        $db = $this->app->db();
        $now = \XF::$time;

        $currency = $event->Currency;
        $decimals = $currency ? (int)$currency->decimals : 2;
        // Quantize like every other write path: 0-decimal currencies must
        // never receive sub-unit lump sums.
        $perItem = round((float)$event->amount, $decimals);
        if ($perItem == 0.0) {
            return ['rows' => 0, 'next' => null];
        }

        if ($event->trigger === 'post') {
            $contentTable = 'xf_post';
            $stateColumn = 'message_state';
            $stateValue = 'visible';
        } else {
            $contentTable = 'xf_thread';
            $stateColumn = 'discussion_state';
            $stateValue = 'visible';
        }
        $maxUserId = max(
            (int)$db->fetchOne('SELECT MAX(user_id) FROM xf_user'),
            $onlyUserId
        );

        $where = "{$stateColumn} = '{$stateValue}' AND user_id > 0";
        $params = [];
        if ($onlyUserId > 0) {
            $where .= ' AND user_id = ?';
            $params[] = $onlyUserId;
            $endUserId = $maxUserId;
        } elseif ($batchSize > 0) {
            $endUserId = $startAfterUserId + $batchSize;
            $where .= ' AND user_id > ? AND user_id <= ?';
            $params[] = $startAfterUserId;
            $params[] = $endUserId;
            // A site-wide run must not re-pay members who already received a
            // per-member backfill for this event (their rows carry a nonzero
            // content_id, invisible to the site-wide completion guard).
            $where .= ' AND user_id NOT IN (SELECT user_id FROM xf_oc_transaction'
                . " WHERE note = 'Historical backfill' AND `trigger` = ? AND currency_id = ? AND content_id <> 0)";
            $params[] = $event->trigger;
            $params[] = (int)$event->currency_id;
        } else {
            $endUserId = $maxUserId;
            // A site-wide run must not re-pay members who already received a
            // per-member backfill for this event (their rows carry a nonzero
            // content_id, invisible to the site-wide completion guard).
            $where .= ' AND user_id NOT IN (SELECT user_id FROM xf_oc_transaction'
                . " WHERE note = 'Historical backfill' AND `trigger` = ? AND currency_id = ? AND content_id <> 0)";
            $params[] = $event->trigger;
            $params[] = (int)$event->currency_id;
        }
        $counts = $db->fetchPairs(
            "SELECT user_id, COUNT(*) FROM {$contentTable} WHERE {$where} GROUP BY user_id",
            $params
        );

        $rows = 0;
        $db->beginTransaction();
        try {
            foreach ($counts as $userId => $count) {
                $db->insert('xf_oc_transaction', [
                    'user_id' => (int)$userId,
                    'currency_id' => (int)$event->currency_id,
                    'amount' => round($count * $perItem, $decimals),
                    'trigger' => $event->trigger,
                    'content_type' => $event->trigger,
                    'content_id' => $onlyUserId > 0 ? $onlyUserId : 0,
                    'note' => 'Historical backfill',
                    'log_date' => $now,
                ]);
                $rows++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $next = ($batchSize > 0 && $onlyUserId <= 0 && $endUserId < $maxUserId) ? $endUserId : null;
        return ['rows' => $rows, 'next' => $next];
    }
}
