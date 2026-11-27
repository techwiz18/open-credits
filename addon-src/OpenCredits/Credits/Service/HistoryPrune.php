<?php

namespace OpenCredits\Credits\Service;

/**
 * Owns history pruning: the deletable-row predicate, the current-day cutoff,
 * the per-member batched snapshot loop, and the preview. Preview and run read
 * the one predicate and the one cutoff, so the admin page cannot promise to
 * delete rows the run refuses to touch.
 *
 * Backfill markers, redeem rows, and reaction dedupe markers are never
 * deleted (completion guards, one-per-member checks, and re-reaction dedupe
 * depend on them). Batched by user-id range like rebuilds; totals stay exact
 * because each snapshot equals the sum of its deleted rows.
 */
class HistoryPrune
{
    /** @var mixed DB adapter */
    protected $db;

    /** @var callable(): int Start of the current earning day. */
    protected $dayStart;

    /**
     * @param mixed $db
     * @param callable(): int $dayStart
     */
    public function __construct($db, callable $dayStart)
    {
        $this->db = $db;
        $this->dayStart = $dayStart;
    }

    /**
     * The cutoff for a retention window, clamped so the current earning day
     * is never pruned. On a 25-hour DST day a days=1 cutoff can otherwise
     * land after dayStart() and delete the daily-login / daily-limit guards,
     * allowing a second award.
     */
    public function cutoff(int $days): int
    {
        if ($days < 1) {
            return 0;
        }
        return $this->clampCutoff(\XF::$time - $days * 86400);
    }

    /**
     * What run() would delete for a retention window: rows per currency.
     *
     * @return array<int, array{currency: string, rows: int}>
     */
    public function preview(int $days): array
    {
        $cutoff = $this->cutoff($days);
        if ($cutoff <= 0) {
            return [];
        }
        [$deletable, $guardParams] = $this->deletable();
        $rows = $this->db->fetchAll(
            'SELECT c.currency_id, c.title, COUNT(*) AS entry_count FROM xf_oc_transaction AS t'
            . ' INNER JOIN xf_oc_currency AS c ON c.currency_id = t.currency_id'
            . ' WHERE ' . $deletable
            . ' GROUP BY c.currency_id, c.title ORDER BY c.currency_id',
            array_merge([$cutoff], $guardParams)
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

    /**
     * Prune every batch for a retention window.
     *
     * @return array{snapshots: int, deleted: int}
     */
    public function run(int $days, int $batchSize = 5000): array
    {
        $cutoff = $this->cutoff($days);
        $snapshots = 0;
        $deleted = 0;
        if ($cutoff <= 0) {
            return ['snapshots' => 0, 'deleted' => 0];
        }
        $next = 0;
        do {
            $result = $this->prune($cutoff, $next, $batchSize);
            $snapshots += $result['snapshots'];
            $deleted += $result['deleted'];
            $next = $result['next'];
        } while ($next !== null);

        return ['snapshots' => $snapshots, 'deleted' => $deleted];
    }

    /**
     * One batched prune step over a user-id range.
     * Returns ['snapshots' => upserted, 'deleted' => rows, 'next' => ?].
     *
     * @return array{snapshots: int, deleted: int, next: ?int}
     */
    public function prune(int $cutoff, int $startAfterUserId = 0, int $batchSize = 0): array
    {
        $cutoff = $this->clampCutoff($cutoff);
        if ($cutoff <= 0) {
            return ['snapshots' => 0, 'deleted' => 0, 'next' => null];
        }
        $db = $this->db;
        $maxUserId = max(
            (int)$db->fetchOne('SELECT MAX(user_id) FROM xf_user'),
            (int)$db->fetchOne('SELECT MAX(user_id) FROM xf_oc_transaction')
        );
        $endUserId = $batchSize > 0 ? $startAfterUserId + $batchSize : $maxUserId;
        [$deletable, $guardParams] = $this->deletable();
        $pairs = $db->fetchAll(
            'SELECT t.user_id, t.currency_id FROM xf_oc_transaction AS t'
            . ' WHERE t.user_id > ? AND t.user_id <= ? AND ' . $deletable
            . ' GROUP BY t.user_id, t.currency_id',
            array_merge([$startAfterUserId, $endUserId, $cutoff], $guardParams)
        );
        $snapshots = 0;
        $deleted = 0;
        // Snapshot precision follows each currency so 0-decimal currencies
        // never store sub-unit values a display would round misleadingly.
        $decimalsByCurrency = $db->fetchPairs('SELECT currency_id, decimals FROM xf_oc_currency');
        $db->beginTransaction();
        try {
            foreach ($pairs as $pair) {
                $uid = (int)$pair['user_id'];
                $cid = (int)$pair['currency_id'];
                $decimals = max(0, (int)($decimalsByCurrency[$cid] ?? 2));
                $existing = (float)$db->fetchOne(
                    'SELECT COALESCE(balance, 0) FROM xf_oc_snapshot WHERE user_id = ? AND currency_id = ?',
                    [$uid, $cid]
                );
                $sum = (float)$db->fetchOne(
                    'SELECT COALESCE(SUM(t.amount), 0) FROM xf_oc_transaction AS t'
                    . ' WHERE t.user_id = ? AND t.currency_id = ? AND ' . $deletable,
                    array_merge([$uid, $cid, $cutoff], $guardParams)
                );
                if ($sum != 0.0) {
                    $new = round($existing + $sum, $decimals);
                    $db->query(
                        'INSERT INTO xf_oc_snapshot (user_id, currency_id, balance, log_date) VALUES (?, ?, ?, ?)'
                        . ' ON DUPLICATE KEY UPDATE balance = ?, log_date = ?',
                        [$uid, $cid, $new, $cutoff, $new, $cutoff]
                    );
                    $snapshots++;
                }
                // Delete even when the old rows net to zero; otherwise those
                // rows are never pruned and the retention window never applies.
                $stmt = $db->query(
                    'DELETE t FROM xf_oc_transaction AS t'
                    . ' WHERE t.user_id = ? AND t.currency_id = ? AND ' . $deletable,
                    array_merge([$uid, $cid, $cutoff], $guardParams)
                );
                $deleted += (int)$stmt->rowsAffected();
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Transact::clearBalanceCache();
        $next = ($batchSize > 0 && $endUserId < $maxUserId) ? $endUserId : null;
        return ['snapshots' => $snapshots, 'deleted' => $deleted, 'next' => $next];
    }

    /**
     * The one predicate for rows the prune may remove, including the
     * current-day cutoff parameter.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    protected function deletable(): array
    {
        return [
            't.log_date <= ? AND t.`trigger` != ? AND t.note != ? AND t.note NOT LIKE ?',
            Ledger::pruneGuardParams(),
        ];
    }

    protected function clampCutoff(int $cutoff): int
    {
        if ($cutoff <= 0 || $cutoff >= \XF::$time) {
            return 0;
        }
        return min($cutoff, ($this->dayStart)() - 1);
    }
}
