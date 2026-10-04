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
            'SELECT * FROM xf_oc_event WHERE `trigger` = ? AND active = 1 ORDER BY event_id',
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
            }
        }
        return $applied;
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
            $this->clearBalanceCache($userId);
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
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
            $this->clearBalanceCache($fromUserId);
            $this->clearBalanceCache($toUserId);
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    protected function clearBalanceCache(int $userId): void
    {
        $class = 'OpenCredits\Credits\XF\Entity\User';
        if (class_exists($class)) {
            $class::clearOcBalanceCache($userId);
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
     */
    public function awardDailyLoginIfNeeded(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $start = strtotime('today midnight');
        $already = (int)$this->db()->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_transaction WHERE user_id = ? AND `trigger` = ? AND log_date >= ?',
            [$userId, 'daily_login', $start]
        );
        if ($already > 0) {
            return false;
        }
        return $this->awardByTrigger('daily_login', $userId, $userId);
    }

    /**
     * Recomputes every cached balance from the transaction log, including the
     * legacy primary-currency column. Returns rebuilt balance rows.
     */
    public function rebuildAllBalances(): int
    {
        $db = $this->db();
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
        $class = 'OpenCredits\Credits\XF\Entity\User';
        if (class_exists($class)) {
            $class::clearOcBalanceCache();
        }
        return $rows;
    }
}
