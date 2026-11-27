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
 * because the post-commit event must not fire for a transaction that later
 * rolls back. The module drops the request cache itself: a clear is safe
 * before commit (a later read sees the connection's own rows, and a rollback
 * only costs a reload), so no caller has to remember it.
 */
class LedgerWrite
{
    /** @var mixed DB adapter */
    protected $db;

    /** @var callable(): int Primary currency id (the mirror's currency). */
    protected $primaryCurrencyId;

    /** @var RequestCache Request memo a committed movement drops. */
    protected $cache;

    /**
     * @param mixed $db
     * @param callable(): int $primaryCurrencyId
     */
    public function __construct($db, callable $primaryCurrencyId, RequestCache $cache)
    {
        $this->db = $db;
        $this->primaryCurrencyId = $primaryCurrencyId;
        $this->cache = $cache;
    }

    /**
     * Applies one balance change. Must run inside the caller's transaction.
     *
     * Movement fields: currency_id, trigger, legs (user_id, amount and an
     * optional per-leg content_id), content_type, content_id, note, event_id,
     * log_date, overdraft ('currency' | 'never'), and decimals (pins
     * quantization to a caller's snapshot; omitted means the currency's
     * current precision).
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
        // Callers that snapshot precision (a resumable demurrage pass) pin it
        // here so the row and the caller's own total cannot disagree. The
        // column holds 2 decimals, so a wider override is not honoured.
        $decimals = isset($movement['decimals'])
            ? max(0, min(2, (int)$movement['decimals']))
            : (int)$currency['decimals'];

        $rows = [];
        $net = [];
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
            // The floor applies to the member's net delta, not to each leg:
            // a movement may debit and credit the same member.
            $net[$userId] = ($net[$userId] ?? 0.0) + $amount;
        }

        $belowFloor = [];
        foreach ($net as $userId => $delta) {
            if ($delta < 0) {
                $belowFloor[$userId] = $delta;
            }
        }

        // 'never' is the transfer/charge/burn rule; 'currency' follows
        // allow_negative (positive deltas are always legal, so a member with
        // a legacy negative balance can still earn their way out).
        $policy = ($movement['overdraft'] ?? 'never') === 'currency' ? 'currency' : 'never';
        if ($belowFloor && ($policy === 'never' || !(int)$currency['allow_negative'])) {
            $negativeIds = array_keys($belowFloor);
            $placeholders = implode(',', array_fill(0, count($negativeIds), '?'));
            $balances = [];
            foreach ($db->fetchAll(
                'SELECT user_id, balance FROM xf_oc_balance'
                . " WHERE user_id IN ({$placeholders}) AND currency_id = ? FOR UPDATE",
                array_merge($negativeIds, [$currencyId])
            ) as $row) {
                $balances[(int)$row['user_id']] = (float)$row['balance'];
            }
            foreach ($belowFloor as $userId => $delta) {
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

        // One invalidation point for every movement, so a caller cannot
        // forget the cache when it adds a write path.
        $this->cache->invalidate();
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
        $this->cache->invalidate();
    }

    /**
     * How many stored balances a lower precision would round. The balance
     * column keeps two decimals whatever the currency says, so the test is
     * the value's own round-trip at the new precision: a non-zero count
     * means the next write and a ledger rebuild would disagree with what is
     * stored today.
     */
    public function countUnrepresentableBalances(int $currencyId, int $decimals): int
    {
        return (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_balance WHERE currency_id = ? AND ROUND(balance, ?) <> balance',
            [$currencyId, max(0, $decimals)]
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
