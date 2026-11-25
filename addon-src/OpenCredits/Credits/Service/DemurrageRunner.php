<?php

namespace OpenCredits\Credits\Service;

/**
 * Owns the demurrage pass lifecycle: claim, stale-claim recovery, rate and
 * decimals snapshot, cursor advance, orphan balance purge, interval gate,
 * and completion. Callers pass a time budget and read back a verdict
 * (`busy`, `done`, or `resume`) plus progress; none of them touch pass
 * tokens or cursors. A pass is claimed atomically with a random pass token
 * (pass_token = 0 means idle); every cursor advance and the completion write
 * must match the token, so a concurrent runner that loses the race gets
 * `busy` instead of double-burning. The rate and decimals are snapshotted at
 * pass start so a resumable pass burns every member at the same rate even if
 * the admin changes settings mid-pass.
 */
class DemurrageRunner
{
    /** @var mixed DB adapter */
    protected $db;

    /** @var callable(): array{enabled: bool, rate: float, days: int} */
    protected $settings;

    protected int $currencyId;

    /** @var LedgerWrite Burns and orphan purges. */
    protected $ledgerWrite;

    /** @var callable(int, float): void Post-commit cache clear and event. */
    protected $afterBurn;

    /**
     * @param mixed $db
     * @param callable(): array{enabled: bool, rate: float, days: int} $settings
     * @param callable(int, float): void $afterBurn
     */
    public function __construct(
        $db,
        callable $settings,
        int $currencyId,
        LedgerWrite $ledgerWrite,
        callable $afterBurn
    ) {
        $this->db = $db;
        $this->settings = $settings;
        $this->currencyId = $currencyId;
        $this->ledgerWrite = $ledgerWrite;
        $this->afterBurn = $afterBurn;
    }

    /**
     * A newly-primary currency has never been burned: mark the last run now
     * so the next cron does not fire an immediate surprise pass. Owns the
     * row shape so callers never write pass fields directly.
     */
    public function seedLastRun(int $now, int $decimals): void
    {
        $this->db->query(
            'INSERT INTO xf_oc_inflation (currency_id, last_run, cursor_user_id, pass_started, pass_token, pass_rate, pass_decimals)'
            . ' VALUES (?, ?, 0, 0, 0, 0, ?)'
            . ' ON DUPLICATE KEY UPDATE last_run = VALUES(last_run)',
            [$this->currencyId, $now, $decimals]
        );
    }

    /**
     * Run demurrage batches until the pass completes, another runner owns
     * it, or the optional time budget is hit. Never retries a lost race: a
     * `busy` step aborts immediately so overlapping admin/CLI/cron runs
     * cannot start a second pass over the same balances.
     *
     * @return array<string, mixed> verdict/progress for the caller
     */
    public function run(int $batchSize = 500, bool $ignoreInterval = true, ?int $timeBudget = null): array
    {
        $start = microtime(true);
        $processed = 0;
        $burned = 0.0;
        $accounts = 0;
        $ran = false;
        do {
            $step = $this->step($batchSize, $ignoreInterval);
            if ($step['busy']) {
                return $this->result(
                    'busy',
                    $ran || $step['ran'],
                    $processed + $step['processed'],
                    $burned + $step['burned'],
                    $accounts + $step['accounts'],
                    $step['lastRun'],
                    $step['nextRun']
                );
            }
            if (!$step['ran']) {
                return $this->result(
                    $step['done'] ? 'done' : 'resume',
                    $ran,
                    $processed,
                    $burned,
                    $accounts,
                    $step['lastRun'],
                    $step['nextRun']
                );
            }
            $ran = true;
            $processed += $step['processed'];
            $burned += $step['burned'];
            $accounts += $step['accounts'];
            if ($step['done']) {
                return $this->result('done', true, $processed, $burned, $accounts, $step['lastRun'], $step['nextRun']);
            }
            if ($timeBudget !== null && (microtime(true) - $start) >= $timeBudget) {
                return $this->result('resume', true, $processed, $burned, $accounts, $step['lastRun'], $step['nextRun']);
            }
        } while (true);
    }

    /**
     * Snapshot for the admin Tools page: current rate, last/next run, and
     * what a pass would burn right now.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $settings = ($this->settings)();
        $db = $this->db;
        $currency = $db->fetchRow(
            'SELECT currency_id, title, prefix, suffix, decimals, active FROM xf_oc_currency WHERE currency_id = ?',
            [$this->currencyId]
        );
        $state = $db->fetchRow(
            'SELECT last_run, cursor_user_id, pass_started FROM xf_oc_inflation WHERE currency_id = ?',
            [$this->currencyId]
        );
        $lastRun = $state ? (int)$state['last_run'] : 0;
        $inProgress = $state && (int)$state['cursor_user_id'] > 0;
        $result = [
            'enabled' => $settings['enabled'],
            'rate' => $settings['rate'],
            'days' => $settings['days'],
            'currency' => $currency ?: null,
            'lastRun' => $lastRun,
            'nextRun' => $lastRun > 0 ? $lastRun + $settings['days'] * 86400 : 0,
            'inProgress' => $inProgress,
            'accounts' => 0,
            'total' => 0.0,
        ];
        if (!$settings['enabled'] || $settings['rate'] <= 0 || !$currency || !(int)$currency['active']) {
            return $result;
        }
        $decimals = (int)$currency['decimals'];
        // Only real members count: orphan balance rows (member deleted) are
        // skipped by the burn and must not inflate the preview. An in-flight
        // pass already burned up to the cursor, so those are excluded too.
        $params = [$settings['rate'], $decimals, $settings['rate'], $decimals, $this->currencyId];
        $cursorClause = '';
        if ($inProgress) {
            $cursorClause = ' AND b.user_id > ?';
            $params[] = (int)$state['cursor_user_id'];
        }
        $row = $db->fetchRow(
            'SELECT SUM(CASE WHEN ROUND(b.balance * ? / 100, ?) > 0 THEN 1 ELSE 0 END) AS accounts,'
            . ' COALESCE(SUM(LEAST(b.balance, ROUND(b.balance * ? / 100, ?))), 0) AS total'
            . ' FROM xf_oc_balance AS b INNER JOIN xf_user AS u ON u.user_id = b.user_id'
            . ' WHERE b.currency_id = ? AND b.balance > 0' . $cursorClause,
            $params
        );
        $result['accounts'] = (int)($row['accounts'] ?? 0);
        $result['total'] = (float)($row['total'] ?? 0);
        return $result;
    }

    /**
     * One resumable demurrage batch.
     *
     * @return array<string, mixed>
     */
    protected function step(int $batchSize, bool $ignoreInterval): array
    {
        $batchSize = max(1, min(5000, $batchSize));
        $settings = ($this->settings)();
        $currencyId = $this->currencyId;
        $result = [
            'ran' => false, 'done' => true, 'busy' => false, 'processed' => 0,
            'burned' => 0.0, 'accounts' => 0, 'lastRun' => 0, 'nextRun' => 0,
        ];
        if (!$settings['enabled']) {
            return $result;
        }
        $db = $this->db;
        $now = \XF::$time;
        // Stale faucet/claim rows are dead weight: drop anything older than
        // a week. Runs on every tick — not just pass start — so faucet-only
        // setups (demurrage rate 0) do not accumulate rows forever.
        $db->query('DELETE FROM xf_oc_faucet WHERE day_start < ?', [$now - 7 * 86400]);
        $db->query('DELETE FROM xf_oc_daily_claim WHERE day_start < ?', [$now - 7 * 86400]);
        if ($settings['rate'] <= 0) {
            return $result;
        }
        $currency = $db->fetchRow(
            'SELECT decimals, active FROM xf_oc_currency WHERE currency_id = ?',
            [$currencyId]
        );
        if (!$currency || !(int)$currency['active']) {
            return $result;
        }
        $state = $db->fetchRow(
            'SELECT last_run, cursor_user_id, pass_started, pass_token, pass_rate, pass_decimals'
            . ' FROM xf_oc_inflation WHERE currency_id = ?',
            [$currencyId]
        );
        if (!$state) {
            $db->insert('xf_oc_inflation', [
                'currency_id' => $currencyId,
                'last_run' => 0,
                'cursor_user_id' => 0,
                'pass_started' => 0,
                'pass_token' => 0,
                'pass_rate' => 0,
                'pass_decimals' => (int)$currency['decimals'],
            ]);
            $state = [
                'last_run' => 0, 'cursor_user_id' => 0, 'pass_started' => 0,
                'pass_token' => 0, 'pass_rate' => 0, 'pass_decimals' => (int)$currency['decimals'],
            ];
        }
        $lastRun = (int)$state['last_run'];
        $cursor = (int)$state['cursor_user_id'];
        $token = (int)$state['pass_token'];
        $passStarted = (int)$state['pass_started'];
        // A stale row (cursor without a token) from an older version is
        // resumed under a freshly claimed token below.
        $resuming = $cursor > 0 && $token !== 0;
        $interval = $settings['days'] * 86400;
        // Tolerance for cron jitter: XF preserves the request second when
        // scheduling, so the tick can land a few seconds early and must not
        // push the whole period out by a day.
        if (!$resuming && !$ignoreInterval && $lastRun > 0 && ($now - $lastRun) < $interval - 60) {
            return array_merge($result, ['lastRun' => $lastRun, 'nextRun' => $lastRun + $interval]);
        }
        if ($resuming) {
            // Heartbeat: prove the pass is still ours and mark it alive, so
            // a pass abandoned mid-flight (owner died over an hour ago) can
            // be reclaimed below instead of blocking every future run.
            // MySQL reports 0 affected rows when the value is unchanged
            // (same-second heartbeat), so a miss is confirmed with a fresh
            // read before crying busy.
            $alive = $db->update(
                'xf_oc_inflation',
                ['pass_started' => $now],
                'currency_id = ? AND pass_token = ? AND cursor_user_id = ?',
                [$currencyId, $token, $cursor]
            );
            if (!$alive) {
                $fresh = $db->fetchRow(
                    'SELECT cursor_user_id, pass_token FROM xf_oc_inflation WHERE currency_id = ?',
                    [$currencyId]
                );
                if (!$fresh || (int)$fresh['pass_token'] !== $token || (int)$fresh['cursor_user_id'] !== $cursor) {
                    return array_merge($result, ['busy' => true, 'done' => false, 'lastRun' => $lastRun]);
                }
            }
            $rate = (float)$state['pass_rate'];
            $decimals = (int)$state['pass_decimals'];
            if ($rate <= 0) {
                $rate = (float)$settings['rate'];
            }
        } elseif ($cursor > 0 && $token === 0) {
            // Legacy in-flight row: claim it instead of restarting.
            $token = random_int(1, 2147483647);
            $claimed = $db->update(
                'xf_oc_inflation',
                [
                    'pass_token' => $token,
                    'pass_started' => $now,
                    'pass_rate' => $settings['rate'],
                    'pass_decimals' => (int)$currency['decimals'],
                ],
                'currency_id = ? AND pass_token = 0 AND cursor_user_id = ?',
                [$currencyId, $cursor]
            );
            if (!$claimed) {
                return array_merge($result, ['busy' => true, 'done' => false, 'lastRun' => $lastRun]);
            }
            $rate = (float)$settings['rate'];
            $decimals = (int)$currency['decimals'];
        } else {
            if ($token !== 0) {
                // Claimed but no progress yet: owned by another runner,
                // unless the owner died between claim and first batch.
                if ($passStarted < $now - 3600) {
                    $released = $db->update(
                        'xf_oc_inflation',
                        [
                            'pass_token' => 0,
                            'pass_started' => 0,
                            'pass_rate' => 0,
                        ],
                        'currency_id = ? AND pass_token = ? AND cursor_user_id = 0 AND pass_started < ?',
                        [$currencyId, $token, $now - 3600]
                    );
                    if (!$released) {
                        return array_merge($result, ['busy' => true, 'done' => false, 'lastRun' => $lastRun]);
                    }
                    $token = 0;
                } else {
                    return array_merge($result, ['busy' => true, 'done' => false, 'lastRun' => $lastRun]);
                }
            }
            $token = random_int(1, 2147483647);
            $claimed = $db->update(
                'xf_oc_inflation',
                [
                    'pass_token' => $token,
                    'pass_started' => $now,
                    'pass_rate' => $settings['rate'],
                    'pass_decimals' => (int)$currency['decimals'],
                    'cursor_user_id' => 0,
                ],
                'currency_id = ? AND pass_token = 0 AND cursor_user_id = 0 AND last_run = ?',
                [$currencyId, $lastRun]
            );
            if (!$claimed) {
                // Another runner (cron, admin, or CLI) owns the pass, or a
                // pass completed between our read and our claim.
                return array_merge($result, ['busy' => true, 'done' => false, 'lastRun' => $lastRun]);
            }
            $cursor = 0;
            $rate = (float)$settings['rate'];
            $decimals = (int)$currency['decimals'];
        }
        $rows = $db->fetchAll(
            'SELECT user_id FROM xf_oc_balance WHERE currency_id = ? AND user_id > ? AND balance > 0'
            . ' ORDER BY user_id LIMIT ' . (int)$batchSize,
            [$currencyId, $cursor]
        );
        if (!$rows) {
            $finished = $db->update(
                'xf_oc_inflation',
                [
                    'last_run' => $now,
                    'cursor_user_id' => 0,
                    'pass_started' => 0,
                    'pass_token' => 0,
                    'pass_rate' => 0,
                ],
                'currency_id = ? AND pass_token = ? AND cursor_user_id = ?',
                [$currencyId, $token, $cursor]
            );
            if (!$finished) {
                return array_merge($result, ['busy' => true, 'done' => false, 'lastRun' => $lastRun]);
            }
            return array_merge($result, ['ran' => true, 'done' => true, 'lastRun' => $now]);
        }
        $processed = 0;
        $burned = 0.0;
        $accounts = 0;
        $lastUserId = $cursor;
        foreach ($rows as $balanceRow) {
            $userId = (int)$balanceRow['user_id'];
            $burn = 0.0;
            $db->beginTransaction();
            try {
                $userExists = $db->fetchOne('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', $userId);
                $advanced = $db->update(
                    'xf_oc_inflation',
                    ['cursor_user_id' => $userId],
                    'currency_id = ? AND cursor_user_id = ? AND pass_token = ?',
                    [$currencyId, $lastUserId, $token]
                );
                if (!$advanced) {
                    $db->rollBack();
                    return array_merge($result, [
                        'ran' => true, 'busy' => true, 'done' => false, 'processed' => $processed,
                        'burned' => $burned, 'accounts' => $accounts, 'lastRun' => $lastRun,
                    ]);
                }
                if ($userExists) {
                    $balance = (float)$db->fetchOne(
                        'SELECT COALESCE(balance, 0) FROM xf_oc_balance WHERE user_id = ? AND currency_id = ? FOR UPDATE',
                        [$userId, $currencyId]
                    );
                    $burn = Transact::calculateDemurrage($balance, $rate, $decimals);
                    if ($burn > 0) {
                        $verdict = $this->ledgerWrite->commit([
                            'currency_id' => $this->currencyId,
                            'trigger' => Ledger::TRIGGER_DEMURRAGE,
                            'overdraft' => 'never',
                            'note' => 'Demurrage',
                            'log_date' => $now,
                            'legs' => [
                                ['user_id' => $userId, 'amount' => -$burn],
                            ],
                        ]);
                        // A currency deactivated mid-pass fails closed here;
                        // report nothing burned instead of counting it.
                        if ($verdict !== 'ok') {
                            $burn = 0.0;
                        }
                    }
                } else {
                    // Orphan balance (member deleted): drop the cached row.
                    // The ledger stays as financial history; the rebuild no
                    // longer resurrects it (it only rebuilds live members).
                    $this->ledgerWrite->purgeBalance($userId, $currencyId);
                }
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            $lastUserId = $userId;
            $processed++;
            if ($burn > 0) {
                $burned += $burn;
                $accounts++;
                ($this->afterBurn)($userId, $burn);
            }
        }
        $done = count($rows) < $batchSize;
        if ($done) {
            $finished = $db->update(
                'xf_oc_inflation',
                [
                    'last_run' => $now,
                    'cursor_user_id' => 0,
                    'pass_started' => 0,
                    'pass_token' => 0,
                    'pass_rate' => 0,
                ],
                'currency_id = ? AND pass_token = ? AND cursor_user_id = ?',
                [$currencyId, $token, $lastUserId]
            );
            if (!$finished) {
                return array_merge($result, [
                    'ran' => true, 'busy' => true, 'done' => false, 'processed' => $processed,
                    'burned' => $burned, 'accounts' => $accounts, 'lastRun' => $lastRun,
                ]);
            }
        } elseif ($processed > 0) {
            // Heartbeat for long passes: a resumable run spanning many cron
            // ticks must not look abandoned to the stale-claim recovery.
            $db->update(
                'xf_oc_inflation',
                ['pass_started' => $now],
                'currency_id = ? AND pass_token = ? AND cursor_user_id = ?',
                [$currencyId, $token, $lastUserId]
            );
        }
        return array_merge($result, [
            'ran' => true,
            'done' => $done,
            'processed' => $processed,
            'burned' => $burned,
            'accounts' => $accounts,
            'lastRun' => $done ? $now : $lastRun,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function result(
        string $verdict,
        bool $ran,
        int $processed,
        float $burned,
        int $accounts,
        int $lastRun,
        int $nextRun
    ): array {
        return [
            'verdict' => $verdict,
            'ran' => $ran,
            'busy' => $verdict === 'busy',
            'done' => $verdict === 'done',
            'processed' => $processed,
            'burned' => $burned,
            'accounts' => $accounts,
            'lastRun' => $lastRun,
            'nextRun' => $nextRun,
        ];
    }
}
