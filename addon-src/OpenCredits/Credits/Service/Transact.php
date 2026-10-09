<?php

namespace OpenCredits\Credits\Service;

use XF\Service\AbstractService;

/**
 * Single entry point for ALL balance changes.
 * Uses row lock + DB transaction, writes xf_oc_transaction log and
 * maintains xf_oc_balance; xf_user.oc_credits mirrors the primary currency.
 */
class Transact extends AbstractService
{
    protected static $primaryCurrencyId = null;
    protected static $balanceCache = [];

    /**
     * Balances for a user across currencies, cached per request.
     * Each row: currency_id, title, prefix, suffix, decimals, is_primary, balance.
     */
    public function balancesFor(int $userId, bool $visibleOnly): array
    {
        if ($userId <= 0) {
            return [];
        }
        $key = $userId . ':' . ($visibleOnly ? 'v' : 'a');
        if (!isset(self::$balanceCache[$key])) {
            self::$balanceCache[$key] = $this->db()->fetchAll(
                'SELECT c.currency_id, c.title, c.prefix, c.suffix, c.decimals, c.is_primary,'
                . ' COALESCE(b.balance, 0) AS balance'
                . ' FROM xf_oc_currency AS c'
                . ' LEFT JOIN xf_oc_balance AS b ON b.currency_id = c.currency_id AND b.user_id = ?'
                . ' WHERE c.active = 1' . ($visibleOnly ? ' AND c.visible = 1' : '')
                . ' ORDER BY c.currency_id',
                $userId
            );
        }
        return self::$balanceCache[$key];
    }

    public static function clearBalanceCache(?int $userId = null): void
    {
        if ($userId === null) {
            self::$balanceCache = [];
        } else {
            unset(self::$balanceCache[$userId . ':v'], self::$balanceCache[$userId . ':a']);
        }
    }

    public function primaryCurrencyId(): int
    {
        if (self::$primaryCurrencyId === null) {
            try {
                $id = (int)$this->db()->fetchOne(
                    'SELECT currency_id FROM xf_oc_currency WHERE is_primary = 1 LIMIT 1'
                );
            } catch (\Throwable $e) {
                $id = 0;
            }
            self::$primaryCurrencyId = $id > 0 ? $id : 1;
        }
        return self::$primaryCurrencyId;
    }

    /**
     * Awards EVERY active event registered for the trigger (e.g. a thread
     * event in each currency). Returns true if at least one applied.
     */
    public function awardByTrigger(string $trigger, int $userId, int $contentId = 0, string $contentType = ''): bool
    {
        $db = $this->db();
        $rows = $db->fetchAll(
            'SELECT e.*, c.decimals, c.title AS currency_title,'
            . ' c.prefix AS currency_prefix, c.suffix AS currency_suffix'
            . ' FROM xf_oc_event AS e'
            . ' INNER JOIN xf_oc_currency AS c ON c.currency_id = e.currency_id AND c.active = 1'
            . ' WHERE e.`trigger` = ? AND e.active = 1 ORDER BY e.event_id',
            $trigger
        );
        if (!$rows) {
            return false;
        }

        $applied = false;
        foreach ($rows as $row) {
            if (!$this->passesDailyLimit((int)$row['event_id'], $userId, (int)$row['max_per_day'])) {
                continue;
            }
            if ($this->adjust($userId, (int)$row['currency_id'], (float)$row['amount'], $trigger, $contentId, '', $contentType)) {
                $applied = true;
                if (!empty($row['send_alert'])) {
                    $this->sendEarnAlert($userId, $row, $trigger);
                }
            }
        }
        return $applied;
    }

    /**
     * Sends a "you earned credits" alert (content_type user, action oc_earn).
     * Never throws: earning must not break because alerting failed.
     * $eventRow needs amount, currency_id, decimals, currency_title,
     * currency_prefix, currency_suffix (awardByTrigger selects them all).
     */
    protected function sendEarnAlert(
        int $userId,
        array $eventRow,
        string $trigger,
        ?\XF\Entity\User $sender = null,
        ?string $reason = null
    ): void {
        try {
            $receiver = $this->app->em()->find('XF:User', $userId);
            if (!$receiver) {
                return;
            }
            $decimals = (int)($eventRow['decimals'] ?? 2);
            if ($reason === null) {
                $reason = \OpenCredits\Credits\Entity\CreditEvent::TRIGGER_LABELS[$trigger]
                    ?? ucwords(str_replace('_', ' ', $trigger));
            }
            $extra = [
                'amount' => round((float)$eventRow['amount'], $decimals),
                'currency_id' => (int)$eventRow['currency_id'],
                'currency_title' => (string)($eventRow['currency_title'] ?? 'Credits'),
                'prefix' => (string)($eventRow['currency_prefix'] ?? ''),
                'suffix' => (string)($eventRow['currency_suffix'] ?? ''),
                'decimals' => $decimals,
                'reason' => $reason,
            ];
            $this->app->repository('XF:UserAlert')->alertFromUser(
                $receiver,
                $sender,
                'user',
                $userId,
                'oc_earn',
                $extra,
                ['dependsOnAddOnId' => 'OpenCredits/Credits']
            );
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits earn alert failed: ');
        }
    }

    /**
     * Current cached balance for a user/currency. Missing row means 0.
     * Integrators: check this before charging (adjust() itself is
     * unrestricted by design — privileged code only).
     */
    public function getBalance(int $userId, int $currencyId): float
    {
        if ($userId <= 0) {
            return 0.0;
        }
        return (float)$this->db()->fetchOne(
            'SELECT COALESCE(balance, 0) FROM xf_oc_balance WHERE user_id = ? AND currency_id = ?',
            [$userId, $currencyId]
        );
    }

    public function adjust(int $userId, int $currencyId, float $amount, string $trigger, int $contentId = 0, string $note = '', string $contentType = ''): bool
    {
        if ($userId <= 0 || $amount == 0.0) {
            return false;
        }

        $db = $this->db();
        $db->beginTransaction();
        try {
            $db->query('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', $userId);
            // Quantize to the currency's smallest unit so the ledger never
            // holds sub-decimal values that would display misleadingly
            // (e.g. 0.50 stored on a 0-decimal currency renders as "1").
            $decimals = $db->fetchOne(
                'SELECT decimals FROM xf_oc_currency WHERE currency_id = ?',
                $currencyId
            );
            if ($decimals === false || $decimals === null) {
                $db->rollBack();
                return false;
            }
            $amount = round($amount, (int)$decimals);
            if ($amount == 0.0) {
                $db->rollBack();
                return false;
            }
            if (!$this->allowsResultingBalance($userId, $currencyId, $amount)) {
                $db->rollBack();
                return false;
            }
            $this->applyBalanceDelta($userId, $currencyId, $amount);
            $db->insert('xf_oc_transaction', [
                'user_id' => $userId,
                'currency_id' => $currencyId,
                'amount' => $amount,
                'trigger' => $trigger,
                'content_type' => substr($contentType, 0, 25),
                'content_id' => $contentId,
                'note' => substr($note, 0, 255),
                'log_date' => \XF::$time,
            ]);
            $db->commit();
            self::clearBalanceCache($userId);
            $this->app->fire('oc_credits_adjust', [$userId, $currencyId, $amount, $trigger, $contentId]);
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Whether applying $delta keeps the balance legal for the currency.
     * Currencies with allow_negative off floor at zero. Must run inside a
     * transaction holding the user's locks.
     */
    protected function allowsResultingBalance(int $userId, int $currencyId, float $delta): bool
    {
        $db = $this->db();
        $currency = $db->fetchRow(
            'SELECT allow_negative FROM xf_oc_currency WHERE currency_id = ?',
            $currencyId
        );
        if (!$currency) {
            return false;
        }
        if ((int)$currency['allow_negative']) {
            return true;
        }
        $balance = (float)$db->fetchOne(
            'SELECT COALESCE(balance, 0) FROM xf_oc_balance WHERE user_id = ? AND currency_id = ? FOR UPDATE',
            [$userId, $currencyId]
        );
        return ($balance + $delta) >= 0;
    }

    /**
     * Updates the cached balance row and, for the primary currency,
     * the legacy xf_user.oc_credits column. Must run inside a transaction.
     */
    protected function applyBalanceDelta(int $userId, int $currencyId, float $amount): void
    {
        $db = $this->db();
        $db->query(
            'INSERT INTO xf_oc_balance (user_id, currency_id, balance) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)',
            [$userId, $currencyId, $amount]
        );
        if ($currencyId === $this->primaryCurrencyId()) {
            $db->query(
                'UPDATE xf_user SET oc_credits = oc_credits + ? WHERE user_id = ?',
                [$amount, $userId]
            );
        }
    }

    public function transfer(int $fromUserId, int $toUserId, float $amount, int $currencyId = 1): bool
    {
        if ($fromUserId === $toUserId || $amount <= 0) {
            return false;
        }
        $db = $this->db();
        $db->beginTransaction();
        try {
            $first = min($fromUserId, $toUserId);
            $second = max($fromUserId, $toUserId);
            $db->query('SELECT user_id FROM xf_user WHERE user_id IN (?, ?) FOR UPDATE', [$first, $second]);
            // Quantize to the currency's smallest unit (see adjust()).
            $decimals = $db->fetchOne(
                'SELECT decimals FROM xf_oc_currency WHERE currency_id = ?',
                $currencyId
            );
            if ($decimals === false || $decimals === null) {
                $db->rollBack();
                return false;
            }
            $amount = round($amount, (int)$decimals);
            if ($amount <= 0) {
                $db->rollBack();
                return false;
            }
            // Transfers never overdraw: the sender must cover the amount.
            // (Admin adjustments via adjust() stay unrestricted by design.)
            $senderBalance = (float)$db->fetchOne(
                'SELECT COALESCE(balance, 0) FROM xf_oc_balance WHERE user_id = ? AND currency_id = ? FOR UPDATE',
                [$fromUserId, $currencyId]
            );
            if ($senderBalance < $amount) {
                $db->rollBack();
                return false;
            }
            $this->applyBalanceDelta($fromUserId, $currencyId, -$amount);
            $this->applyBalanceDelta($toUserId, $currencyId, $amount);
            $now = \XF::$time;
            $db->insert('xf_oc_transaction', [
                'user_id' => $fromUserId, 'currency_id' => $currencyId,
                'amount' => -$amount, 'trigger' => 'transfer',
                'content_id' => $toUserId, 'note' => '', 'log_date' => $now,
            ]);
            $db->insert('xf_oc_transaction', [
                'user_id' => $toUserId, 'currency_id' => $currencyId,
                'amount' => $amount, 'trigger' => 'transfer',
                'content_id' => $fromUserId, 'note' => '', 'log_date' => $now,
            ]);
            $db->commit();
            self::clearBalanceCache($fromUserId);
            self::clearBalanceCache($toUserId);
            $this->app->fire('oc_credits_adjust', [$fromUserId, $currencyId, -$amount, 'transfer', $toUserId]);
            $this->app->fire('oc_credits_adjust', [$toUserId, $currencyId, $amount, 'transfer', $fromUserId]);
            $sender = $this->app->em()->find('XF:User', $fromUserId);
            $currency = $this->app->em()->find('OpenCredits\Credits:Currency', $currencyId);
            $this->sendEarnAlert(
                $toUserId,
                [
                    'amount' => $amount,
                    'currency_id' => $currencyId,
                    'decimals' => $currency ? (int)$currency->decimals : 2,
                    'currency_title' => $currency ? (string)$currency->title : 'Credits',
                    'currency_prefix' => $currency ? (string)$currency->prefix : '',
                    'currency_suffix' => $currency ? (string)$currency->suffix : '',
                ],
                'transfer',
                $sender ?: null,
                'Transfer from ' . ($sender ? $sender->username : 'a member')
            );
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    protected function passesDailyLimit(int $eventId, int $userId, int $maxPerDay): bool
    {
        if ($maxPerDay <= 0) {
            return true;
        }
        $start = strtotime('today midnight');
        $count = (int)$this->db()->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_transaction WHERE user_id = ? AND log_date >= ? AND `trigger` IN (SELECT `trigger` FROM xf_oc_event WHERE event_id = ?)',
            [$userId, $start, $eventId]
        );
        return $count < $maxPerDay;
    }

    /**
     * Daily login: exactly once per calendar day, regardless of event config.
     * Called from the visitor_setup listener (every request for logged-in users).
     * The user-row lock serializes concurrent first-requests of the day.
     */
    public function awardDailyLoginIfNeeded(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $db = $this->db();
        $db->beginTransaction();
        try {
            $db->query('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', $userId);
            $start = strtotime('today midnight');
            $already = (int)$db->fetchOne(
                'SELECT COUNT(*) FROM xf_oc_transaction WHERE user_id = ? AND `trigger` = ? AND log_date >= ?',
                [$userId, 'daily_login', $start]
            );
            if ($already > 0) {
                $db->commit();
                return false;
            }
            $awarded = $this->awardByTrigger('daily_login', $userId, $userId);
            $db->commit();
            return $awarded;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Recomputes every cached balance from the transaction log, including the
     * legacy primary-currency column. Returns rebuilt balance rows.
     */
    public function rebuildAllBalances(): int
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $db->query('DELETE FROM xf_oc_balance');
            $stmt = $db->query(
                'INSERT INTO xf_oc_balance (user_id, currency_id, balance)'
                . ' SELECT user_id, currency_id, SUM(amount) FROM xf_oc_transaction GROUP BY user_id, currency_id'
            );
            $rows = (int)$stmt->rowsAffected();
            $primaryId = $this->primaryCurrencyId();
            $db->query(
                'UPDATE xf_user u LEFT JOIN xf_oc_balance b ON b.user_id = u.user_id AND b.currency_id = ?'
                . ' SET u.oc_credits = COALESCE(b.balance, 0)',
                $primaryId
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        self::clearBalanceCache();
        return $rows;
    }
}
