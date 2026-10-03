<?php

namespace OpenCredits\Credits\Service;

use XF\Service\AbstractService;

/**
 * Single entry point for ALL balance changes.
 * Uses row lock + DB transaction, writes xf_oc_transaction log.
 * V1: single default currency (id=1), negatives allowed.
 */
class Transact extends AbstractService
{
    public function awardByTrigger(string $trigger, int $userId, int $contentId = 0): bool
    {
        $db = $this->db();
        $row = $db->fetchRow('SELECT * FROM xf_oc_event WHERE `trigger` = ? AND active = 1 LIMIT 1', $trigger);
        if (!$row) {
            return false;
        }

        if (!$this->passesDailyLimit((int)$row['event_id'], $userId, (int)$row['max_per_day'])) {
            return false;
        }

        return $this->adjust($userId, (int)$row['currency_id'], (float)$row['amount'], $trigger, $contentId, '');
    }

    public function adjust(int $userId, int $currencyId, float $amount, string $trigger, int $contentId = 0, string $note = ''): bool
    {
        if ($userId <= 0 || $amount == 0.0) {
            return false;
        }

        $db = $this->db();
        $db->beginTransaction();
        try {
            $db->query('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', $userId);
            $db->query(
                'UPDATE xf_user SET oc_credits = oc_credits + ? WHERE user_id = ?',
                [$amount, $userId]
            );
            $db->insert('xf_oc_transaction', [
                'user_id' => $userId,
                'currency_id' => $currencyId,
                'amount' => $amount,
                'trigger' => $trigger,
                'content_id' => $contentId,
                'note' => substr($note, 0, 255),
                'log_date' => \XF::$time,
            ]);
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
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
            $db->query('UPDATE xf_user SET oc_credits = oc_credits - ? WHERE user_id = ?', [$amount, $fromUserId]);
            $db->query('UPDATE xf_user SET oc_credits = oc_credits + ? WHERE user_id = ?', [$amount, $toUserId]);
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
     * Recomputes every user's balance from the transaction log.
     * Returns number of affected user rows.
     */
    public function rebuildAllBalances(): int
    {
        $db = $this->db();
        $stmt = $db->query(
            'UPDATE xf_user u LEFT JOIN (SELECT user_id, SUM(amount) AS total FROM xf_oc_transaction GROUP BY user_id) t'
            . ' ON t.user_id = u.user_id SET u.oc_credits = COALESCE(t.total, 0)'
        );
        return (int)$stmt->rowsAffected();
    }
}
