<?php

namespace OpenCredits\Credits\Service;

/**
 * One decision for [charge] premium content: what a post costs and what a
 * purchase does. Render, unlock, and the ledger read the price through
 * priceForMessage(), so a locked box, an unlock POST, and the charge row can
 * never disagree.
 *
 * Price rules: one purchase unlocks the whole post, so the post costs its
 * highest valid block at the currency's precision — otherwise a reader could
 * pay the cheapest block and read the rest. Non-positive, over-precise, and
 * malformed blocks are ignored. A purchase is idempotent per (content,
 * member, currency) and never overdraws, even when the currency allows a
 * negative balance.
 */
class ContentCharge
{
    /** @var mixed DB adapter */
    protected $db;

    /** @var callable(int, int, float): void Applies one balance delta. */
    protected $applyDelta;

    /** @var callable(int, int, float, string, int): void Post-commit cache clear and event. */
    protected $postCommit;

    /**
     * @param mixed $db
     * @param callable(int, int, float): void $applyDelta
     * @param callable(int, int, float, string, int): void $postCommit
     */
    public function __construct($db, callable $applyDelta, callable $postCommit)
    {
        $this->db = $db;
        $this->applyDelta = $applyDelta;
        $this->postCommit = $postCommit;
    }

    /**
     * Charge prices found in a message, using XenForo's own parser/AST so the
     * result matches what actually renders: `[code]`/`[plain]` contents are
     * skipped and malformed tags (e.g. spaces around `=`) are ignored.
     *
     * @return string[]
     */
    public static function amountsInMessage(string $message): array
    {
        try {
            $app = \XF::app();
            $parser = $app->bbCode()->parser();
            $rules = $app->bbCode()->rules('post:post');
            $ast = $parser->parse($message, $rules);
        } catch (\Throwable $e) {
            return [];
        }

        $amounts = [];
        self::collectAmounts($ast, $amounts);
        return array_values(array_unique($amounts));
    }

    /**
     * Highest valid price among raw block options, at the currency's
     * precision. Pure so the rules are unit-testable without XenForo.
     *
     * @param string[] $rawAmounts
     */
    public static function priceOf(array $rawAmounts, int $decimals): ?float
    {
        $decimals = max(0, $decimals);
        $max = null;
        foreach ($rawAmounts AS $raw) {
            $raw = trim((string)$raw);
            if ($raw === '' || !is_numeric($raw)) {
                continue;
            }
            $candidate = (float)$raw;
            if (!is_finite($candidate)
                || $candidate <= 0
                || abs($candidate - round($candidate, $decimals)) > 0.00001) {
                continue;
            }
            $candidate = round($candidate, $decimals);
            if ($max === null || $candidate > $max) {
                $max = $candidate;
            }
        }
        return $max;
    }

    /**
     * The stored post's price: the highest valid [charge] block in its
     * message. Previews and quotes render a message different from the stored
     * post, so they pass that block's own option back through priceOf().
     */
    public static function priceForMessage(string $message, int $decimals): ?float
    {
        return self::priceOf(self::amountsInMessage($message), $decimals);
    }

    /**
     * Charges a member to unlock premium content and credits the author.
     * Idempotent per (content, payer, currency): a member never pays twice
     * for the same content, even if its price changes. Never overdraws.
     *
     * Returns 'ok', 'already_paid', 'self', 'no_funds', 'invalid' or
     * 'invalid_author' (the payer may not unlock, or the author may not
     * receive credits).
     */
    public function purchase(
        int $fromUserId,
        int $toUserId,
        int $currencyId,
        float $amount,
        string $contentType,
        int $contentId
    ): string {
        if ($fromUserId <= 0 || $toUserId <= 0 || $contentId <= 0 || $amount <= 0 || $contentType === '') {
            return 'invalid';
        }
        if ($fromUserId === $toUserId) {
            return 'self';
        }
        $type = substr($contentType, 0, 25);
        $db = $this->db;
        $db->beginTransaction();
        try {
            $first = min($fromUserId, $toUserId);
            $second = max($fromUserId, $toUserId);
            $locked = $db->fetchAll(
                'SELECT user_id, user_state, is_banned FROM xf_user'
                . ' WHERE user_id IN (?, ?) ORDER BY user_id FOR UPDATE',
                [$first, $second]
            );
            if (count($locked) !== 2) {
                $db->rollBack();
                return 'invalid_author';
            }
            foreach ($locked AS $row) {
                // The payer must be allowed to spend, the author to receive;
                // both verdicts read the locked row so a concurrent ban or
                // state change cannot race the write.
                $allowed = (int)$row['user_id'] === $fromUserId
                    ? AccountStanding::canUnlock((string)$row['user_state'], !empty($row['is_banned']))
                    : AccountStanding::canReceive((string)$row['user_state'], !empty($row['is_banned']));
                if (!$allowed) {
                    $db->rollBack();
                    return 'invalid_author';
                }
            }
            $currency = $db->fetchRow(
                'SELECT decimals, active FROM xf_oc_currency WHERE currency_id = ?',
                $currencyId
            );
            if (!$currency || !(int)$currency['active']) {
                $db->rollBack();
                return 'invalid';
            }
            $amount = round($amount, (int)$currency['decimals']);
            if ($amount <= 0) {
                $db->rollBack();
                return 'invalid';
            }
            // One purchase per content item, regardless of the price paid.
            $existing = $db->fetchOne(
                'SELECT charge_id FROM xf_oc_charge WHERE content_type = ? AND content_id = ?'
                . ' AND user_id = ? AND currency_id = ?',
                [$type, $contentId, $fromUserId, $currencyId]
            );
            if ($existing) {
                $db->commit();
                return 'already_paid';
            }
            // Charges never overdraw, regardless of the currency's allow_negative.
            $senderBalance = (float)$db->fetchOne(
                'SELECT COALESCE(balance, 0) FROM xf_oc_balance WHERE user_id = ? AND currency_id = ? FOR UPDATE',
                [$fromUserId, $currencyId]
            );
            if ($senderBalance < $amount) {
                $db->rollBack();
                return 'no_funds';
            }
            ($this->applyDelta)($fromUserId, $currencyId, -$amount);
            ($this->applyDelta)($toUserId, $currencyId, $amount);
            $now = \XF::$time;
            $note = 'Charge: ' . $type . ' #' . $contentId;
            $db->insert('xf_oc_transaction', [
                'user_id' => $fromUserId, 'currency_id' => $currencyId,
                'amount' => -$amount, 'trigger' => Ledger::TRIGGER_CHARGE,
                'content_type' => $type, 'content_id' => $contentId,
                'note' => $note, 'log_date' => $now,
            ]);
            $db->insert('xf_oc_transaction', [
                'user_id' => $toUserId, 'currency_id' => $currencyId,
                'amount' => $amount, 'trigger' => Ledger::TRIGGER_CHARGE,
                'content_type' => $type, 'content_id' => $contentId,
                'note' => $note, 'log_date' => $now,
            ]);
            $db->insert('xf_oc_charge', [
                'content_type' => $type,
                'content_id' => $contentId,
                'user_id' => $fromUserId,
                'author_user_id' => $toUserId,
                'currency_id' => $currencyId,
                'amount' => $amount,
                'charge_date' => $now,
            ]);
            $db->commit();
            ($this->postCommit)($fromUserId, $currencyId, -$amount, $type, $contentId);
            ($this->postCommit)($toUserId, $currencyId, $amount, $type, $contentId);
            return 'ok';
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * The one access verdict for this content and visitor, gathered from the
     * entity, the visitor and the charge row. Render and the unlock POST both
     * branch on it; ChargeAccess owns the priority.
     */
    public function access($content, $visitor): string
    {
        $userId = (int)($visitor->user_id ?? 0);
        $authorId = (int)($content->user_id ?? 0);
        $canView = $userId <= 0 || $visitor->hasPermission('general', 'ocView');
        $isAuthor = $userId > 0 && $userId === $authorId;
        $isStaff = $userId > 0
            && (!empty($visitor->is_staff) || !empty($visitor->is_admin) || !empty($visitor->is_moderator));
        $isBypass = $userId > 0 && $visitor->hasPermission('general', 'ocChargeFree');
        // A denied visitor never sees the block and resolve() ignores paid
        // state for them, so skip the lookup that cannot change the verdict
        // (and would otherwise throw before the denial).
        $canSee = $canView || $isAuthor || $isStaff || $isBypass;
        $hasPaid = $canSee && $userId > 0 && $this->hasPaid(
            $userId,
            (string)$content->getEntityContentType(),
            (int)$content->getEntityId(),
            0
        );

        return ChargeAccess::resolve($canView, $authorId > 0, $isAuthor, $isStaff, $isBypass, $hasPaid);
    }

    /**
     * Whether the member has already unlocked this content item (any price).
     * Pass $currencyId = 0 to ignore the currency (used before resolving the
     * primary currency so existing buyers keep access). Always reads the DB.
     */
    public function hasPaid(int $userId, string $contentType, int $contentId, int $currencyId = 0): bool
    {
        if ($userId <= 0 || $contentId <= 0 || $contentType === '') {
            return false;
        }
        $sql = 'SELECT charge_id FROM xf_oc_charge WHERE user_id = ? AND content_type = ? AND content_id = ?';
        $params = [$userId, substr($contentType, 0, 25), $contentId];
        if ($currencyId > 0) {
            $sql .= ' AND currency_id = ?';
            $params[] = $currencyId;
        }
        $sql .= ' LIMIT 1';
        return (bool)$this->db->fetchOne($sql, $params);
    }

    /**
     * Number of members who have unlocked a content item at a given price.
     * Intended for the author-facing stats line.
     */
    public function unlockCount(string $contentType, int $contentId, int $currencyId, float $amount): int
    {
        if ($contentId <= 0 || $contentType === '') {
            return 0;
        }
        return (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_charge WHERE content_type = ? AND content_id = ?'
            . ' AND currency_id = ? AND amount = ?',
            [$contentType, $contentId, $currencyId, $amount]
        );
    }

    /**
     * Removes unlock rows whose post no longer exists (e.g. a thread
     * hard-deleted in bulk, which skips the per-post delete listener).
     * Ledger rows stay as financial history.
     */
    public function purgeOrphans(): int
    {
        $stmt = $this->db->query(
            "DELETE c FROM xf_oc_charge AS c
             LEFT JOIN xf_post AS p ON (c.content_type = 'post' AND p.post_id = c.content_id)
             WHERE c.content_type = 'post' AND p.post_id IS NULL"
        );
        return (int)$stmt->rowsAffected();
    }

    protected static function collectAmounts(array $tree, array &$out): void
    {
        foreach ($tree AS $node) {
            if (!is_array($node)) {
                continue;
            }
            $tag = (string)($node['tag'] ?? '');
            if ($tag === 'charge') {
                $out[] = (string)($node['option'] ?? '');
                continue;
            }
            if ($tag === 'plain') {
                continue;
            }
            if ($tag === 'code' && strtolower((string)($node['option'] ?? '')) !== 'rich') {
                continue;
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                self::collectAmounts($node['children'], $out);
            }
        }
    }
}
