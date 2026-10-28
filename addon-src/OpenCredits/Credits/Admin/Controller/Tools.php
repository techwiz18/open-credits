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
            [
                'label' => \XF::phrase('ocadm_tool_demurrage_title')->render(),
                'hint' => \XF::phrase('ocadm_tool_demurrage_hint')->render(),
                'link' => $this->buildLink('oc-tools/demurrage'),
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
        // forums complete in small transactions instead of one giant one. A
        // batch's rows and its resume marker commit atomically, so a retry
        // resumes exactly where the last committed batch stopped.
        $db = $this->app->db();
        $error = null;
        try {
            foreach ($eligible as $event) {
                if (!$force && $onlyUserId > 0 && $this->backfillEventRan($event, 0)) {
                    // A completed site-wide run already paid this member.
                    $skipped[] = (int)$event->event_id;
                    continue;
                }
                $state = $this->findBackfillState($event, $onlyUserId);
                if (!$force && !empty($state['completed_date'])) {
                    $skipped[] = (int)$event->event_id;
                    continue;
                }

                if ($force || !$state) {
                    $state = $this->resetBackfillState($event, $onlyUserId);
                }

                $backfillId = (int)$state['backfill_id'];
                if ($onlyUserId > 0) {
                    $result = $this->runBackfillEvent($event, $onlyUserId, 0, 0, $backfillId);
                    $awarded += $result['rows'];
                } else {
                    // Resume from the last completed batch boundary.
                    $next = (int)($state['last_user_id'] ?? 0);
                    do {
                        $result = $this->runBackfillEvent($event, 0, $next, 5000, $backfillId);
                        $awarded += $result['rows'];
                        if ($result['next'] === null) {
                            break;
                        }
                        $next = $result['next'];
                    } while (true);
                }

                $db->update(
                    'xf_oc_backfill',
                    ['completed_date' => \XF::$time, 'last_user_id' => 0],
                    'backfill_id = ?',
                    [(int)$state['backfill_id']]
                );
            }
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits backfill failed: ');
            $error = 'Backfill paused part-way. Completed batches are kept — re-run to resume.';
        }

        // Always rebuild whatever committed so cached balances match the ledger.
        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        try {
            $svc->rebuildAllBalancesFully();
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits backfill rebuild failed: ');
            if ($error === null) {
                $error = 'Backfill rows were written, but the balance rebuild failed. '
                    . 'Run AdminCP → Credits → Tools → Rebuild balances to finish.';
            }
        }

        if ($error !== null) {
            return $this->error($error);
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

    public function actionDemurrage(): \XF\Mvc\Reply\AbstractReply
    {
        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');

        return $this->view(
            'OpenCredits\Credits:Tools\Demurrage',
            'oc_tools_demurrage',
            [
                'ran' => false,
                'preview' => $svc->demurragePreview(),
                'result' => null,
            ]
        );
    }

    public function actionDemurrageSave(): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        try {
            // Bounded so a large board cannot exceed the web request limit;
            // an unfinished pass reports its progress and can be resumed.
            $result = $svc->runDemurrage(500, true, 25);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits demurrage failed: ');
            return $this->error(
                'Demurrage failed due to a server error. Completed batches are kept — fix the cause and re-run.'
            );
        }

        // A busy first step means another runner owns the pass: report it
        // as ran so the template shows the busy message instead of the
        // form again. An interval skip (not busy) still shows the form.
        $ran = (bool)$result['ran'] || !empty($result['busy']);
        return $this->view(
            'OpenCredits\Credits:Tools\Demurrage',
            'oc_tools_demurrage',
            [
                'ran' => $ran,
                'busy' => (bool)($result['busy'] ?? false),
                'preview' => $svc->demurragePreview(),
                'result' => $result,
            ]
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

        // Remove unlock rows for content that no longer exists (e.g. a thread
        // hard-deleted in bulk). Ledger rows stay as financial history.
        $this->app->db()->query(
            "DELETE c FROM xf_oc_charge AS c
             LEFT JOIN xf_post AS p ON (c.content_type = 'post' AND p.post_id = c.content_id)
             WHERE c.content_type = 'post' AND p.post_id IS NULL"
        );

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
            . ' WHERE t.log_date <= ? AND t.`trigger` != ? AND t.note != ? AND t.note NOT LIKE ?'
            . ' GROUP BY c.currency_id, c.title ORDER BY c.currency_id',
            [$cutoff, 'redeem', 'Historical backfill', 'reaction:%']
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
        return (bool)$this->app->db()->fetchOne(
            'SELECT backfill_id FROM xf_oc_backfill WHERE event_id = ? AND user_id = ?'
            . ' AND completed_date > 0 LIMIT 1',
            [(int)$event->event_id, $onlyUserId]
        );
    }

    protected function findBackfillState($event, int $scopeUserId): array
    {
        $row = $this->app->db()->fetchRow(
            'SELECT * FROM xf_oc_backfill WHERE event_id = ? AND user_id = ?',
            [(int)$event->event_id, $scopeUserId]
        );
        return $row ?: [];
    }

    protected function resetBackfillState($event, int $scopeUserId): array
    {
        $db = $this->app->db();
        $state = $this->findBackfillState($event, $scopeUserId);
        if (!empty($state['backfill_id'])) {
            $db->update('xf_oc_backfill', [
                'last_user_id' => 0,
                'started_date' => \XF::$time,
                'completed_date' => 0,
            ], 'backfill_id = ?', [(int)$state['backfill_id']]);
        } else {
            $db->insert('xf_oc_backfill', [
                'event_id' => (int)$event->event_id,
                'user_id' => $scopeUserId,
                'last_user_id' => 0,
                'started_date' => \XF::$time,
                'completed_date' => 0,
            ]);
        }
        return $this->findBackfillState($event, $scopeUserId);
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
            [$sql, $params] = $this->backfillContentQuery($event, 0, 0, 0, true);
            $users = (int)$this->app->db()->fetchOne($sql, $params);
            $preview[] = [
                'event_id' => (int)$event->event_id,
                'label' => \OpenCredits\Credits\Entity\CreditEvent::TRIGGER_LABELS[$event->trigger] ?? $event->trigger,
                'currency' => $previewCurrency->title,
                'decimals' => (int)$previewCurrency->decimals,
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
    protected function runBackfillEvent($event, int $onlyUserId = 0, int $startAfterUserId = 0, int $batchSize = 0, int $backfillId = 0): array
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

        $maxUserId = max(
            (int)$db->fetchOne('SELECT MAX(user_id) FROM xf_user'),
            $onlyUserId
        );
        $endUserId = $onlyUserId > 0
            ? $maxUserId
            : ($batchSize > 0 ? $startAfterUserId + $batchSize : $maxUserId);

        [$sql, $params] = $this->backfillContentQuery($event, $onlyUserId, $startAfterUserId, $endUserId, false);
        $counts = $db->fetchPairs($sql, $params);
        $next = ($batchSize > 0 && $onlyUserId <= 0 && $endUserId < $maxUserId) ? $endUserId : null;

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
                    'event_id' => (int)$event->event_id,
                    'note' => 'Historical backfill',
                    'log_date' => $now,
                ]);
                $rows++;
            }
            if ($backfillId > 0) {
                // Commit the resume boundary in the same transaction as the
                // rows; a crash can never leave paid rows behind a stale
                // marker (which would double-pay on resume).
                if ($onlyUserId > 0 || $next === null) {
                    $db->update('xf_oc_backfill', [
                        'last_user_id' => 0,
                        'completed_date' => $now,
                    ], 'backfill_id = ?', [$backfillId]);
                } else {
                    $db->update('xf_oc_backfill', [
                        'last_user_id' => $next,
                    ], 'backfill_id = ?', [$backfillId]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['rows' => $rows, 'next' => $next];
    }

    /**
     * Builds the scoped content query for a backfill. Applies the event's
     * forum/usergroup restrictions, the first-post rule for post events, the
     * user-id range, and (for site-wide runs) excludes members who already
     * received a per-member backfill for the same event.
     *
     * @return array{0: string, 1: array}
     */
    protected function backfillContentQuery(
        $event,
        int $onlyUserId = 0,
        int $startAfterUserId = 0,
        int $endUserId = 0,
        bool $countOnly = false
    ): array {
        $isPost = $event->trigger === 'post';
        if ($isPost) {
            $from = 'xf_post AS p INNER JOIN xf_thread AS t ON t.thread_id = p.thread_id';
            $author = 'p';
            $state = 'p.message_state';
        } else {
            $from = 'xf_thread AS t';
            $author = 't';
            $state = 't.discussion_state';
        }
        $from .= ' INNER JOIN xf_user AS u ON u.user_id = ' . $author . '.user_id';

        $where = "{$state} = 'visible' AND {$author}.user_id > 0";
        // Match the earning rules: banned, rejected, and disabled members
        // earn nothing, so backfilling them would contradict every award path.
        $where .= " AND u.user_state NOT IN ('rejected', 'disabled') AND u.is_banned = 0";
        $params = [];

        if ($isPost) {
            // The thread event already pays the first post.
            $where .= ' AND p.position > 0';
        }

        $forumIds = \OpenCredits\Credits\Entity\CreditEvent::decodeIdList($event->forum_ids);
        if ($forumIds) {
            $placeholders = implode(',', array_fill(0, count($forumIds), '?'));
            $where .= " AND t.node_id IN ({$placeholders})";
            $params = array_merge($params, $forumIds);
        }

        $groupIds = \OpenCredits\Credits\Entity\CreditEvent::decodeIdList($event->usergroup_ids);
        if ($groupIds) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $clauses = ["u.user_group_id IN ({$placeholders})"];
            $params = array_merge($params, $groupIds);
            foreach ($groupIds AS $groupId) {
                $clauses[] = 'FIND_IN_SET(?, u.secondary_group_ids)';
                $params[] = $groupId;
            }
            $where .= ' AND (' . implode(' OR ', $clauses) . ')';
        }

        if ($onlyUserId > 0) {
            $where .= " AND {$author}.user_id = ?";
            $params[] = $onlyUserId;
        } else {
            if ($endUserId > 0) {
                $where .= " AND {$author}.user_id > ? AND {$author}.user_id <= ?";
                $params[] = $startAfterUserId;
                $params[] = $endUserId;
            }
            // Don't re-pay members who already got a per-member backfill for
            // this event (those rows carry a nonzero content_id).
            $where .= " AND {$author}.user_id NOT IN ("
                . 'SELECT user_id FROM xf_oc_transaction WHERE event_id = ? AND content_id <> 0)';
            $params[] = (int)$event->event_id;
        }

        $select = $countOnly
            ? 'COUNT(DISTINCT ' . $author . '.user_id)'
            : $author . '.user_id, COUNT(*)';
        $sql = 'SELECT ' . $select . ' FROM ' . $from . ' WHERE ' . $where;
        if (!$countOnly) {
            $sql .= ' GROUP BY ' . $author . '.user_id';
        }

        return [$sql, $params];
    }
}
