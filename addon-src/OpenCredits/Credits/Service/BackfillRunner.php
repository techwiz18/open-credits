<?php

namespace OpenCredits\Credits\Service;

/**
 * Owns the historical-backfill lifecycle: the candidate query, the per-author
 * eligible tally, the resumable batch loop, the completion marker, and the
 * post-run rebuild. Preview and run share one candidate query and one
 * AwardScope tally, so the admin page promises exactly the members a run
 * pays. Callers pass the selected event ids, an optional member scope, a
 * force flag, and a batch budget; they read back a verdict plus progress and
 * never touch the state rows.
 */
class BackfillRunner
{
    /** @var mixed DB adapter */
    protected $db;

    /** @var LedgerWrite Authors of the lump-sum ledger rows. */
    protected $ledgerWrite;

    /** @var callable(): int Rebuilds cached balances from the ledger. */
    protected $rebuild;

    /**
     * @param mixed $db
     * @param callable(): int $rebuild
     */
    public function __construct($db, LedgerWrite $ledgerWrite, callable $rebuild)
    {
        $this->db = $db;
        $this->ledgerWrite = $ledgerWrite;
        $this->rebuild = $rebuild;
    }

    /**
     * Snapshot for the admin Tools page: one row per backfillable event with
     * the member count the run would pay and whether it already completed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function preview(): array
    {
        $preview = [];
        foreach ($this->events([]) as $event) {
            if (!(int)$event['currency_active']) {
                continue;
            }
            $preview[] = [
                'event_id' => (int)$event['event_id'],
                'label' => Ledger::label((string)$event['trigger']),
                'currency' => (string)$event['currency_title'],
                'decimals' => (int)$event['currency_decimals'],
                'amount' => (float)$event['amount'],
                // Same tally as the run: preview promises exactly the users
                // the run will pay, counted in the same user-id ranges.
                'users' => $this->previewUserCount($event),
                'ran' => $this->eventRan((int)$event['event_id'], 0),
            ];
        }
        return $preview;
    }

    /**
     * Distinct eligible authors, tallied in user-id ranges. The ranges are
     * disjoint, so the per-range author counts sum to exactly the users the
     * run pays, without loading a large board's content table into memory.
     *
     * @param array<string, mixed> $event
     */
    protected function previewUserCount(array $event): int
    {
        $batchSize = 5000;
        $maxUserId = (int)$this->db->fetchOne('SELECT MAX(user_id) FROM xf_user');
        $users = 0;
        for ($start = 0; $start < $maxUserId; $start += $batchSize) {
            $users += count($this->eligibleCounts($event, 0, $start, $start + $batchSize));
        }
        return $users;
    }

    /**
     * Runs the selected events, then rebuilds balances from the ledger.
     * Site-wide runs process one user-id range per batch so a huge forum
     * completes in small transactions. Each batch commits its rows and its
     * resume marker atomically, so a timeout resumes exactly where the last
     * committed batch stopped instead of double-paying.
     *
     * Verdicts: `done` (everything completed), `incomplete` (a batch failed;
     * committed batches are kept), `rebuild_failed` (rows are written but
     * cached balances are not), `no_events`, `no_eligible_events`, or
     * `inactive_currencies`.
     *
     * @param int[] $eventIds
     * @return array{verdict: string, awarded: int, skipped: int[]}
     */
    public function run(array $eventIds, int $scopeUserId, bool $force, int $batchSize = 5000): array
    {
        $result = ['verdict' => 'no_events', 'awarded' => 0, 'skipped' => []];
        if (!$eventIds) {
            return $result;
        }
        $events = $this->events($eventIds);
        if (!$events) {
            return array_merge($result, ['verdict' => 'no_eligible_events']);
        }

        // Events on inactive currencies are skipped, never paid.
        $eligible = [];
        foreach ($events as $event) {
            if ((int)$event['currency_active']) {
                $eligible[] = $event;
            } else {
                $result['skipped'][] = (int)$event['event_id'];
            }
        }
        if (!$eligible) {
            return array_merge($result, ['verdict' => 'inactive_currencies']);
        }

        $batchSize = max(1, min(5000, $batchSize));
        $verdict = 'done';
        try {
            foreach ($eligible as $event) {
                $eventId = (int)$event['event_id'];
                if (!$force && $scopeUserId > 0 && $this->eventRan($eventId, 0)) {
                    // A completed site-wide run already paid this member.
                    $result['skipped'][] = $eventId;
                    continue;
                }
                $state = $this->findState($eventId, $scopeUserId);
                if (!$force && !empty($state['completed_date'])) {
                    $result['skipped'][] = $eventId;
                    continue;
                }
                if ($force || !$state) {
                    $state = $this->resetState($eventId, $scopeUserId);
                }

                $backfillId = (int)$state['backfill_id'];
                if ($scopeUserId > 0) {
                    $batch = $this->runBatch($event, $scopeUserId, 0, 0, $backfillId);
                    $result['awarded'] += $batch['rows'];
                } else {
                    // Resume from the last completed batch boundary.
                    $next = (int)($state['last_user_id'] ?? 0);
                    do {
                        $batch = $this->runBatch($event, 0, $next, $batchSize, $backfillId);
                        $result['awarded'] += $batch['rows'];
                        if ($batch['next'] === null) {
                            break;
                        }
                        $next = $batch['next'];
                    } while (true);
                }

                $this->db->update(
                    'xf_oc_backfill',
                    ['completed_date' => \XF::$time, 'last_user_id' => 0],
                    'backfill_id = ?',
                    [$backfillId]
                );
            }
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits backfill failed: ');
            $verdict = 'incomplete';
        }

        // Always rebuild whatever committed so cached balances match the ledger.
        try {
            ($this->rebuild)();
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits backfill rebuild failed: ');
            if ($verdict === 'done') {
                $verdict = 'rebuild_failed';
            }
        }

        $result['verdict'] = $verdict;
        return $result;
    }

    /**
     * Backfills one event. Per-member mode writes a single row. Site-wide
     * mode processes one user-id range per call (pass $batchSize > 0 and loop
     * on 'next') so huge forums complete in small transactions. Each call
     * commits atomically and returns ['rows', 'next' (or null when done)].
     *
     * @param array<string, mixed> $event
     * @return array{rows: int, next: int|null}
     */
    protected function runBatch(
        array $event,
        int $onlyUserId = 0,
        int $startAfterUserId = 0,
        int $batchSize = 0,
        int $backfillId = 0
    ): array {
        $db = $this->db;
        $now = \XF::$time;

        $decimals = (int)$event['currency_decimals'];
        // Quantize like every other write path: 0-decimal currencies must
        // never receive sub-unit lump sums.
        $perItem = round((float)$event['amount'], $decimals);
        if ($perItem == 0.0) {
            return ['rows' => 0, 'next' => null];
        }

        $next = null;
        $rows = 0;
        $db->beginTransaction();
        try {
            if ($backfillId > 0) {
                // Re-read the resume marker under its row lock: two concurrent
                // resumes can start from the same stale boundary, and the one
                // that arrives second must not pay the first one's range again.
                // Locked before any ledger row, like the other batch writes.
                $state = $db->fetchRow(
                    'SELECT last_user_id, completed_date FROM xf_oc_backfill'
                    . ' WHERE backfill_id = ? FOR UPDATE',
                    [$backfillId]
                );
                if ($state) {
                    if ((int)$state['completed_date'] > 0) {
                        // The other resume finished this event while we waited.
                        $db->commit();
                        return ['rows' => 0, 'next' => null];
                    }
                    if ($onlyUserId <= 0) {
                        $startAfterUserId = max($startAfterUserId, (int)$state['last_user_id']);
                    }
                }
            }

            $maxUserId = max(
                (int)$db->fetchOne('SELECT MAX(user_id) FROM xf_user'),
                $onlyUserId
            );
            $endUserId = $onlyUserId > 0
                ? $maxUserId
                : ($batchSize > 0 ? $startAfterUserId + $batchSize : $maxUserId);

            // Row-level verdict, tallied per author: every paid row passed
            // AwardScope::eligible(), so the backfill pays exactly what the live
            // path would have paid. Ledger shape is unchanged (one lump sum per
            // author), as are batch boundaries and resume markers.
            $counts = $this->eligibleCounts($event, $onlyUserId, $startAfterUserId, $endUserId);
            $next = ($batchSize > 0 && $onlyUserId <= 0 && $endUserId < $maxUserId) ? $endUserId : null;

            foreach ($counts as $userId => $count) {
                $verdict = $this->ledgerWrite->commit([
                    'currency_id' => (int)$event['currency_id'],
                    'trigger' => (string)$event['trigger'],
                    'content_type' => (string)$event['trigger'],
                    'content_id' => $onlyUserId,
                    'event_id' => (int)$event['event_id'],
                    'note' => Ledger::NOTE_BACKFILL,
                    'log_date' => $now,
                    'decimals' => $decimals,
                    'legs' => [
                        [
                            'user_id' => (int)$userId,
                            'amount' => round($count * $perItem, $decimals),
                        ],
                    ],
                ]);
                // A failed leg (currency deactivated mid-run, missing member)
                // aborts the batch: the transaction rolls back whole, so the
                // marker stays resumable instead of completing an event that
                // still owes members their rows.
                if ($verdict !== 'ok') {
                    throw new \RuntimeException(
                        "Backfill commit for user {$userId} returned '{$verdict}'."
                    );
                }
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
     * Eligible content counts per author for one backfill scope. Single seam
     * for preview and run: fetch candidate rows, verdict each one through
     * AwardScope, tally per author.
     *
     * @param array<string, mixed> $event
     * @return array<int, int> author user_id => eligible row count
     */
    protected function eligibleCounts(
        array $event,
        int $onlyUserId = 0,
        int $startAfterUserId = 0,
        int $endUserId = 0
    ): array {
        [$sql, $params] = $this->candidateQuery($event, $onlyUserId, $startAfterUserId, $endUserId);
        return AwardScope::countEligible(
            ['forum_ids' => $event['forum_ids'], 'usergroup_ids' => $event['usergroup_ids']],
            $event['trigger'] === Ledger::TRIGGER_POST,
            $this->db->fetchAll($sql, $params)
        );
    }

    /**
     * Builds the candidate content query for a backfill. Selects per-row
     * facts (author, node, state, position, member standing and groups) over
     * the user-id range; every eligibility rule is decided per row by
     * AwardScope, never here. Keeps the user-id range and (for site-wide
     * runs) the exclusion of members who already received a per-member
     * backfill for the same event — that is bookkeeping, not eligibility.
     *
     * @param array<string, mixed> $event
     * @return array{0: string, 1: array<int, int>}
     */
    protected function candidateQuery(
        array $event,
        int $onlyUserId = 0,
        int $startAfterUserId = 0,
        int $endUserId = 0
    ): array {
        $isPost = $event['trigger'] === Ledger::TRIGGER_POST;
        if ($isPost) {
            $from = 'xf_post AS p INNER JOIN xf_thread AS t ON t.thread_id = p.thread_id';
            $author = 'p';
            $select = 'p.user_id AS author_id, t.node_id AS node_id,'
                . ' p.position AS position, p.message_state AS content_state,';
        } else {
            $from = 'xf_thread AS t';
            $author = 't';
            $select = 't.user_id AS author_id, t.node_id AS node_id,'
                . ' t.discussion_state AS content_state,';
        }
        $from .= ' INNER JOIN xf_user AS u ON u.user_id = ' . $author . '.user_id';

        $where = "{$author}.user_id > 0";
        $params = [];

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
            $params[] = (int)$event['event_id'];
        }

        $select .= ' u.user_state AS user_state, u.is_banned AS is_banned,'
            . ' u.user_group_id AS user_group_id, u.secondary_group_ids AS secondary_group_ids';
        return ['SELECT ' . $select . ' FROM ' . $from . ' WHERE ' . $where, $params];
    }

    /**
     * Active post/thread events joined to their currency. An empty id list
     * means every backfillable event (the preview).
     *
     * @param int[] $eventIds
     * @return array<int, array<string, mixed>>
     */
    protected function events(array $eventIds): array
    {
        $params = [Ledger::TRIGGER_POST, Ledger::TRIGGER_THREAD];
        $where = 'e.active = 1 AND e.trigger IN (?, ?)';
        if ($eventIds) {
            $where .= ' AND e.event_id IN (' . implode(',', array_fill(0, count($eventIds), '?')) . ')';
            $params = array_merge($params, array_map('intval', $eventIds));
        }
        return $this->db->fetchAll(
            'SELECT e.event_id, e.currency_id, e.trigger, e.amount, e.forum_ids, e.usergroup_ids,'
            . ' c.title AS currency_title, c.decimals AS currency_decimals, c.active AS currency_active'
            . ' FROM xf_oc_event AS e'
            . ' INNER JOIN xf_oc_currency AS c ON c.currency_id = e.currency_id'
            . ' WHERE ' . $where
            . ' ORDER BY e.event_id',
            $params
        );
    }

    protected function eventRan(int $eventId, int $scopeUserId): bool
    {
        return (bool)$this->db->fetchOne(
            'SELECT backfill_id FROM xf_oc_backfill WHERE event_id = ? AND user_id = ?'
            . ' AND completed_date > 0 LIMIT 1',
            [$eventId, $scopeUserId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function findState(int $eventId, int $scopeUserId): array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM xf_oc_backfill WHERE event_id = ? AND user_id = ?',
            [$eventId, $scopeUserId]
        );
        return $row ?: [];
    }

    /**
     * Resets (or inserts) the resume state for one (event, scope) pair.
     *
     * @return array<string, mixed>
     */
    protected function resetState(int $eventId, int $scopeUserId): array
    {
        $db = $this->db;
        $state = $this->findState($eventId, $scopeUserId);
        if (!empty($state['backfill_id'])) {
            $db->update('xf_oc_backfill', [
                'last_user_id' => 0,
                'started_date' => \XF::$time,
                'completed_date' => 0,
            ], 'backfill_id = ?', [(int)$state['backfill_id']]);
        } else {
            $db->insert('xf_oc_backfill', [
                'event_id' => $eventId,
                'user_id' => $scopeUserId,
                'last_user_id' => 0,
                'started_date' => \XF::$time,
                'completed_date' => 0,
            ]);
        }
        return $this->findState($eventId, $scopeUserId);
    }
}
