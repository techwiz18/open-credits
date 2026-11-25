<?php

namespace OpenCredits\Credits\Service;

/**
 * The one author of balance changes and ledger rows.
 *
 * A movement describes what moves: a currency, a trigger, a note, and one or
 * more legs (member + signed amount). commit() owns the how: user-row lock
 * order, currency precision and negative-balance policy, the cached balance
 * row, the legacy xf_user.oc_credits mirror, and the xf_oc_transaction row
 * shape.
 *
 * Callers own the transaction, because their side tables (charge rows, code
 * counters, faucet tallies, resume markers) must commit with the money, and
 * because the cache clear and post-commit event must not fire for a
 * transaction that later rolls back.
 */
class LedgerWrite
{
    /** @var mixed DB adapter */
    protected $db;

    /** @var callable(): int Primary currency id (the mirror's currency). */
    protected $primaryCurrencyId;

    /**
     * @param mixed $db
     * @param callable(): int $primaryCurrencyId
     */
    public function __construct($db, callable $primaryCurrencyId)
    {
        $this->db = $db;
        $this->primaryCurrencyId = $primaryCurrencyId;
    }

    /**
     * Applies one balance change. Must run inside the caller's transaction.
     *
     * Returns 'ok' when written, or 'zero' (nothing to move), 'no_user' (a
     * leg's member is missing), 'currency_inactive' (unknown or inactive
     * currency) or 'insufficient' (the movement would breach the floor).
     * Nothing is written on any verdict but 'ok'.
     */
    public function commit(array $movement): string
    {
        $legs = isset($movement['legs']) && is_array($movement['legs']) ? $movement['legs'] : [];
        if (!$legs) {
            return 'zero';
        }
        $currencyId = (int)($movement['currency_id'] ?? 0);
        if ($currencyId <= 0) {
            return 'currency_inactive';
        }

        $db = $this->db;
        // Lock every member row this movement touches, ascending. Two
        // movements can then never hold the same locks in opposite orders;
        // a caller that already locked theirs makes this a no-op.
        $userIds = [];
        foreach ($legs as $leg) {
            $userId = (int)($leg['user_id'] ?? 0);
            if ($userId <= 0) {
                return 'no_user';
            }
            $userIds[$userId] = $userId;
        }
        sort($userIds);
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $locked = $db->fetchAll(
            "SELECT user_id FROM xf_user WHERE user_id IN ({$placeholders})"
            . ' ORDER BY user_id FOR UPDATE',
            $userIds
        );
        if (count($locked) !== count($userIds)) {
            return 'no_user';
        }

        // Inactive currencies fail closed: awards skip them upstream and
        // direct writes are refused here.
        $currency = $db->fetchRow(
            'SELECT decimals, active, allow_negative FROM xf_oc_currency WHERE currency_id = ?',
            $currencyId
        );
        if (!$currency || !(int)$currency['active']) {
            return 'currency_inactive';
        }
        $decimals = (int)$currency['decimals'];

        $rows = [];
        $negative = [];
        foreach ($legs as $leg) {
            // Quantize to the currency's smallest unit so the ledger never
            // holds sub-decimal values that display misleadingly (e.g. 0.50
            // on a 0-decimal currency renders as "1").
            $amount = round((float)($leg['amount'] ?? 0.0), $decimals);
            if ($amount == 0.0) {
                return 'zero';
            }
            $userId = (int)$leg['user_id'];
            $rows[] = [
                'user_id' => $userId,
                'amount' => $amount,
                'content_id' => $leg['content_id'] ?? null,
            ];
            if ($amount < 0) {
                $negative[$userId] = ($negative[$userId] ?? 0.0) + $amount;
            }
        }

        // 'never' is the transfer/charge/burn rule; 'currency' follows
        // allow_negative (positive deltas are always legal, so a member with
        // a legacy negative balance can still earn their way out).
        $policy = ($movement['overdraft'] ?? 'never') === 'currency' ? 'currency' : 'never';
        if ($negative && ($policy === 'never' || !(int)$currency['allow_negative'])) {
            $negativeIds = array_keys($negative);
            $placeholders = implode(',', array_fill(0, count($negativeIds), '?'));
            $balances = [];
            foreach ($db->fetchAll(
                'SELECT user_id, balance FROM xf_oc_balance'
                . " WHERE user_id IN ({$placeholders}) AND currency_id = ? FOR UPDATE",
                array_merge($negativeIds, [$currencyId])
            ) as $row) {
                $balances[(int)$row['user_id']] = (float)$row['balance'];
            }
            foreach ($negative as $userId => $delta) {
                if (($balances[$userId] ?? 0.0) + $delta < 0) {
                    return 'insufficient';
                }
            }
        }

        $now = (int)($movement['log_date'] ?? \XF::$time);
        $primaryId = (int)call_user_func($this->primaryCurrencyId);
        foreach ($rows as $row) {
            $this->applyBalance($row['user_id'], $currencyId, $row['amount'], $primaryId);
            $db->insert('xf_oc_transaction', self::buildRow($movement, $row, $now));
        }

        return 'ok';
    }

    /**
     * Drops a deleted member's cached balance row. Must run inside the
     * caller's transaction; the ledger stays as financial history.
     */
    public function purgeBalance(int $userId, int $currencyId): void
    {
        $this->db->delete(
            'xf_oc_balance',
            'user_id = ? AND currency_id = ?',
            [$userId, $currencyId]
        );
    }

    /**
     * One xf_oc_transaction row. Pure so the column set, the per-leg
     * content_id override and the column-width truncation are unit-testable
     * without a database.
     */
    public static function buildRow(array $movement, array $leg, int $now): array
    {
        return [
            'user_id' => (int)$leg['user_id'],
            'currency_id' => (int)$movement['currency_id'],
            'amount' => (float)$leg['amount'],
            'trigger' => substr((string)($movement['trigger'] ?? ''), 0, 50),
            'content_type' => substr((string)($movement['content_type'] ?? ''), 0, 25),
            'content_id' => (int)($leg['content_id'] ?? $movement['content_id'] ?? 0),
            'event_id' => (int)($movement['event_id'] ?? 0),
            'note' => substr((string)($movement['note'] ?? ''), 0, 255),
            'log_date' => $now,
        ];
    }

    /**
     * Updates the cached balance row and, for the primary currency, the
     * legacy xf_user.oc_credits column. Must run inside a transaction.
     */
    protected function applyBalance(int $userId, int $currencyId, float $amount, int $primaryCurrencyId): void
    {
        $this->db->query(
            'INSERT INTO xf_oc_balance (user_id, currency_id, balance) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)',
            [$userId, $currencyId, $amount]
        );
        if ($currencyId === $primaryCurrencyId) {
            $this->db->query(
                'UPDATE xf_user SET oc_credits = oc_credits + ? WHERE user_id = ?',
                [$amount, $userId]
            );
        }
    }
}
