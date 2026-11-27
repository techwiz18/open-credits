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
    protected static $eventRowsCache = [];

    public static function clearEventCache(): void
    {
        self::$eventRowsCache = [];
    }

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

    /**
     * Bulk-fill the balance cache for many users in two small queries.
     * Thread views call this once per page so the postbit's per-author
     * getter hits cache instead of issuing one query per author (N+1).
     * Users/currencies already cached are skipped; unknown ids simply get
     * zero-balance rows like balancesFor() returns.
     */
    public function preloadBalances(array $userIds, bool $visibleOnly = true): void
    {
        $key = $visibleOnly ? 'v' : 'a';
        $missing = [];
        foreach ($userIds as $id) {
            $id = (int)$id;
            if ($id > 0 && !isset(self::$balanceCache[$id . ':' . $key])) {
                $missing[$id] = $id;
            }
        }
        if (!$missing) {
            return;
        }
        $currencies = $this->db()->fetchAll(
            'SELECT currency_id, title, prefix, suffix, decimals, is_primary FROM xf_oc_currency WHERE active = 1'
            . ($visibleOnly ? ' AND visible = 1' : '')
            . ' ORDER BY currency_id'
        );
        $balances = [];
        if ($currencies) {
            $placeholders = implode(',', array_fill(0, count($missing), '?'));
            foreach ($this->db()->fetchAll(
                'SELECT user_id, currency_id, balance FROM xf_oc_balance'
                . " WHERE user_id IN ({$placeholders})",
                array_values($missing)
            ) as $row) {
                $balances[(int)$row['user_id']][(int)$row['currency_id']] = $row['balance'];
            }
        }
        foreach ($missing as $id) {
            $rows = [];
            foreach ($currencies as $currency) {
                $cid = (int)$currency['currency_id'];
                $rows[] = [
                    'currency_id' => $cid,
                    'title' => (string)$currency['title'],
                    'prefix' => (string)$currency['prefix'],
                    'suffix' => (string)$currency['suffix'],
                    'decimals' => (int)$currency['decimals'],
                    'is_primary' => (int)$currency['is_primary'],
                    // Same shape the lazy query's COALESCE(..., 0) returns:
                    // the balance column is decimal(10,2), so a missing row
                    // is the string '0.00', not a float.
                    'balance' => $balances[$id][$cid] ?? '0.00',
                ];
            }
            self::$balanceCache[$id . ':' . $key] = $rows;
        }
    }

    public static function clearBalanceCache(?int $userId = null): void
    {
        if ($userId === null) {
            self::$balanceCache = [];
        } else {
            unset(self::$balanceCache[$userId . ':v'], self::$balanceCache[$userId . ':a']);
        }
    }

    public static function clearPrimaryCache(): void
    {
        self::$primaryCurrencyId = null;
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
     * Active event rows for a trigger, with their currency, from the
     * request-static cache. The award loop and AwardScope's node-context
     * question read the same rows, so a content save pays for one query.
     *
     * @return array<int,array<string,mixed>>
     */
    public function eventRowsForTrigger(string $trigger): array
    {
        if (!array_key_exists($trigger, self::$eventRowsCache)) {
            // Event configs are request-static: bulk content saves must not
            // refetch identical rows per save. Admin event saves run in their
            // own requests, so no invalidation is needed here.
            self::$eventRowsCache[$trigger] = $this->db()->fetchAll(
                'SELECT e.*, c.decimals, c.title AS currency_title,'
                . ' c.prefix AS currency_prefix, c.suffix AS currency_suffix'
                . ' FROM xf_oc_event AS e'
                . ' INNER JOIN xf_oc_currency AS c ON c.currency_id = e.currency_id AND c.active = 1'
                . ' WHERE e.`trigger` = ? AND e.active = 1 ORDER BY e.event_id',
                $trigger
            );
        }
        return self::$eventRowsCache[$trigger];
    }

    /**
     * Awards EVERY active event registered for the trigger (e.g. a thread
     * event in each currency). Returns true if at least one applied.
     *
     * $nodeId/$userGroupIds scope events carrying forum_ids/usergroup_ids
     * restrictions; unrestricted events always apply. Forum scoping only
     * constrains content triggers — triggers without a forum context
     * (register, daily_login) pass the forum check. $dedupeKey (e.g. a
     * per-reactor marker) is stored in the ledger note and skips the award
     * when an identical row already exists.
     */
    public function awardByTrigger(
        string $trigger,
        int $userId,
        int $contentId = 0,
        string $contentType = '',
        int $nodeId = 0,
        array $userGroupIds = [],
        ?string $dedupeKey = null
    ): bool {
        // No earnings for accounts that cannot participate, mirroring the
        // register path (banned/rejected/disabled never earn). Unapproved
        // states (moderated, email_confirm) still earn so the register bonus
        // is waiting when the account activates. Checked once here so every
        // entry point (listeners, daily login, integrators) is covered.
        if ($userId > 0 && !$this->isAwardEligible($userId)) {
            return false;
        }
        $rows = $this->eventRowsForTrigger($trigger);
        if (!$rows) {
            return false;
        }

        $applied = false;
        $needsGroups = false;
        foreach ($rows as $row) {
            $rawGroups = $row['usergroup_ids'] ?? null;
            if (is_string($rawGroups)
                ? ($rawGroups !== '' && $rawGroups !== '[]' && $rawGroups !== 'null')
                : !empty($rawGroups)
            ) {
                $needsGroups = true;
                break;
            }
        }
        $groupIds = $userGroupIds !== []
            ? $userGroupIds
            : ($needsGroups ? $this->loadEarnerGroupIds($userId) : []);
        foreach ($rows as $row) {
            // Eligibility lives in AwardScope. Participation was enforced
            // above (isAwardEligible); visibility and first-post are vetted
            // by callers (Listener) before this point, so they arrive true —
            // no new refusals for direct awardByTrigger callers.
            if (!AwardScope::eligible($row, $nodeId, $groupIds, true, false, true)) {
                continue;
            }
            if (!$this->passesDailyLimit($userId, $trigger, (int)$row['max_per_day'], (int)$row['currency_id'])) {
                continue;
            }
            if ($dedupeKey !== null && $dedupeKey !== '' && $this->dedupeKeyUsed($userId, $trigger, $contentType, $contentId, $dedupeKey)) {
                continue;
            }
            $credited = null;
            if ($this->adjust($userId, (int)$row['currency_id'], (float)$row['amount'], $trigger, $contentId, (string)$dedupeKey, $contentType, true, $credited)) {
                $applied = true;
                if (!empty($row['send_alert'])) {
                    $this->sendEarnAlert($userId, $row, $trigger, null, null, $credited);
                }
            }
        }
        return $applied;
    }

    /**
     * Whether the member may earn at all. Unknown users fail here too, but
     * that changes nothing: adjust() already refuses them.
     */
    protected function isAwardEligible(int $userId): bool
    {
        try {
            $user = $this->app->em()->find('XF:User', $userId);
            if (!$user) {
                return false;
            }
            return AccountStanding::canEarn(
                (string)$user->get('user_state'),
                !empty($user->is_banned)
            );
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits eligibility check failed: ');
            return false;
        }
    }

    protected function dedupeKeyUsed(int $userId, string $trigger, string $contentType, int $contentId, string $dedupeKey): bool
    {
        return (bool)$this->db()->fetchOne(
            'SELECT transaction_id FROM xf_oc_transaction'
            . ' WHERE user_id = ? AND `trigger` = ? AND content_type = ? AND content_id = ? AND note = ? LIMIT 1',
            [$userId, $trigger, substr($contentType, 0, 25), $contentId, substr($dedupeKey, 0, 255)]
        );
    }

    /**
     * Primary + secondary group ids of the earner, for usergroup-scoped
     * events. Only called when at least one event carries a restriction.
     */
    protected function loadEarnerGroupIds(int $userId): array
    {
        try {
            $user = $this->app->em()->find('XF:User', $userId);
            if (!$user) {
                return [];
            }
            return array_values(array_unique(array_merge(
                [(int)$user->user_group_id],
                array_map('intval', (array)$user->secondary_group_ids)
            )));
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits earner groups lookup failed: ');
            return [];
        }
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
        ?string $reason = null,
        ?float $amount = null
    ): void {
        if (empty($this->app->options()->ocSendEarnAlerts)) {
            return;
        }
        try {
            $receiver = $this->app->em()->find('XF:User', $userId);
            if (!$receiver) {
                return;
            }
            $decimals = (int)($eventRow['decimals'] ?? 2);
            if ($reason === null) {
                $reason = Ledger::label($trigger);
            }
            $extra = [
                'amount' => round($amount ?? (float)$eventRow['amount'], $decimals),
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
     * Current balance for a user/currency, read directly (missing row = 0).
     * Integrators: check this before charging (adjust() itself never
     * overdraw-blocks — privileged code only). Note the check holds no lock,
     * so re-verify after any concurrent writes if exactness matters.
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

    /**
     * Apply a balance change. $respectInflation is used by member-facing
     * earning paths: it clamps the credit to the primary currency's balance
     * cap and daily faucet. Admin/integrator calls leave it false (trusted).
     * $credited receives the amount actually credited (after clamping).
     */
    public function adjust(int $userId, int $currencyId, float $amount, string $trigger, int $contentId = 0, string $note = '', string $contentType = '', bool $respectInflation = false, &$credited = null): bool
    {
        if ($userId <= 0 || $amount == 0.0) {
            return false;
        }

        $db = $this->db();
        $db->beginTransaction();
        try {
            $userExists = $db->fetchOne('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', $userId);
            if (!$userExists) {
                $db->rollBack();
                return false;
            }
            // Quantize to the currency's smallest unit so the ledger never
            // holds sub-decimal values that would display misleadingly
            // (e.g. 0.50 stored on a 0-decimal currency renders as "1").
            // Inactive currencies fail closed: awards skip them upstream and
            // direct writes are refused here.
            $currency = $db->fetchRow(
                'SELECT decimals, active, allow_negative FROM xf_oc_currency WHERE currency_id = ?',
                $currencyId
            );
            if (!$currency || !(int)$currency['active']) {
                $db->rollBack();
                return false;
            }
            $amount = round($amount, (int)$currency['decimals']);
            if ($amount == 0.0) {
                $db->rollBack();
                return false;
            }
            $faucetDayStart = 0;
            if ($respectInflation && $amount > 0 && $this->inflationApplies($currencyId)) {
                $settings = $this->inflationSettings();
                if ($settings['enabled']) {
                    $balance = (float)$db->fetchOne(
                        'SELECT COALESCE(balance, 0) FROM xf_oc_balance WHERE user_id = ? AND currency_id = ? FOR UPDATE',
                        [$userId, $currencyId]
                    );
                    // The faucet ledger counts exactly what member mints
                    // credited today — never event rows, so duplicating,
                    // disabling, or deleting an event mid-day cannot move
                    // the allowance, and custom integrator triggers count
                    // the same as built-in ones. Tracked whenever inflation
                    // is enabled so switching the faucet on mid-day still
                    // sees this morning's earnings.
                    $faucetDayStart = self::dayStart();
                    $earnedToday = 0.0;
                    if ($settings['faucet'] > 0) {
                        $earnedToday = (float)$db->fetchOne(
                            'SELECT COALESCE(amount, 0) FROM xf_oc_faucet'
                            . ' WHERE user_id = ? AND currency_id = ? AND day_start = ? FOR UPDATE',
                            [$userId, $currencyId, $faucetDayStart]
                        );
                    }
                    $amount = InflationClamp::credited(
                        $amount,
                        $balance,
                        $earnedToday,
                        $settings['cap'],
                        $settings['faucet'],
                        (int)$currency['decimals']
                    );
                    if ($amount <= 0.0) {
                        $db->rollBack();
                        return false;
                    }
                }
            }
            if (!$this->allowsResultingBalance($userId, $currencyId, $amount, (int)$currency['allow_negative'])) {
                $db->rollBack();
                return false;
            }
            $this->applyBalanceDelta($userId, $currencyId, $amount);
            $db->insert('xf_oc_transaction', [
                'user_id' => $userId,
                'currency_id' => $currencyId,
                'amount' => $amount,
                'trigger' => substr($trigger, 0, 50),
                'content_type' => substr($contentType, 0, 25),
                'content_id' => $contentId,
                'note' => substr($note, 0, 255),
                'log_date' => \XF::$time,
            ]);
            if ($faucetDayStart > 0) {
                $this->trackFaucetMint($userId, $currencyId, $faucetDayStart, $amount);
            }
            $db->commit();
            $credited = $amount;
            self::clearBalanceCache($userId);
            $this->fireAdjustEvent([$userId, $currencyId, $amount, $trigger, $contentId]);
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Whether applying $delta keeps the balance legal for the currency.
     * Currencies with allow_negative off floor at zero. Must run inside a
     * transaction holding the user's locks. Pass $allowNegative when the
     * caller already fetched the currency row to save a query.
     */
    protected function allowsResultingBalance(int $userId, int $currencyId, float $delta, ?int $allowNegative = null): bool
    {
        $db = $this->db();
        if ($allowNegative === null) {
            $currency = $db->fetchRow(
                'SELECT allow_negative FROM xf_oc_currency WHERE currency_id = ?',
                $currencyId
            );
            if (!$currency) {
                return false;
            }
            $allowNegative = (int)$currency['allow_negative'];
        }
        if ($allowNegative || $delta >= 0) {
            // Positive deltas are always allowed: if a currency was switched
            // from allow_negative to the floor-at-zero rule, members with a
            // legacy negative balance can still earn their way back out.
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

    /**
     * Records a member mint in the daily faucet ledger. Must run inside the
     * caller's transaction so the row commits with the award. Shared by
     * adjust() and redeem() so every mint counts toward the one allowance.
     */
    protected function trackFaucetMint(int $userId, int $currencyId, int $dayStart, float $amount): void
    {
        $this->db()->query(
            'INSERT INTO xf_oc_faucet (user_id, currency_id, day_start, amount) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)',
            [$userId, $currencyId, $dayStart, $amount]
        );
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
            $locked = $db->fetchAll(
                'SELECT user_id, user_state, is_banned FROM xf_user'
                . ' WHERE user_id IN (?, ?) ORDER BY user_id FOR UPDATE',
                [$first, $second]
            );
            if (count($locked) !== 2) {
                $db->rollBack();
                return false;
            }
            // Account standing is enforced here, not only in the controller,
            // so integrators calling the service cannot bypass it. The state
            // comes from the locked row, so a concurrent ban cannot slip in
            // between the check and the write.
            foreach ($locked AS $row) {
                $allowed = (int)$row['user_id'] === $fromUserId
                    ? AccountStanding::canSend((string)$row['user_state'], !empty($row['is_banned']))
                    : AccountStanding::canReceive((string)$row['user_state'], !empty($row['is_banned']));
                if (!$allowed) {
                    $db->rollBack();
                    return false;
                }
            }
            // Quantize to the currency's smallest unit (see adjust()).
            $currency = $db->fetchRow(
                'SELECT decimals, active, allow_negative FROM xf_oc_currency WHERE currency_id = ?',
                $currencyId
            );
            if (!$currency || !(int)$currency['active']) {
                $db->rollBack();
                return false;
            }
            $amount = round($amount, (int)$currency['decimals']);
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
                'amount' => -$amount, 'trigger' => Ledger::TRIGGER_TRANSFER,
                'content_id' => $toUserId, 'note' => '', 'log_date' => $now,
            ]);
            $db->insert('xf_oc_transaction', [
                'user_id' => $toUserId, 'currency_id' => $currencyId,
                'amount' => $amount, 'trigger' => Ledger::TRIGGER_TRANSFER,
                'content_id' => $fromUserId, 'note' => '', 'log_date' => $now,
            ]);
            $db->commit();
            self::clearBalanceCache($fromUserId);
            self::clearBalanceCache($toUserId);
            $this->fireAdjustEvent([$fromUserId, $currencyId, -$amount, Ledger::TRIGGER_TRANSFER, $toUserId]);
            $this->fireAdjustEvent([$toUserId, $currencyId, $amount, Ledger::TRIGGER_TRANSFER, $fromUserId]);
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
                Ledger::TRIGGER_TRANSFER,
                $sender ?: null,
                'Transfer from ' . ($sender ? $sender->username : 'a member')
            );
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Content-charge module for [charge] premium content. Owns the post price
     * and the purchase; the legacy wrappers below keep existing callers and
     * tests on their entry points.
     */
    public function contentCharge(): ContentCharge
    {
        $db = $this->db();

        return new ContentCharge(
            $db,
            function (int $userId, int $currencyId, float $amount): void {
                $this->applyBalanceDelta($userId, $currencyId, $amount);
            },
            function (int $userId, int $currencyId, float $amount, string $contentType, int $contentId): void {
                self::clearBalanceCache($userId);
                $this->fireAdjustEvent([$userId, $currencyId, $amount, Ledger::TRIGGER_CHARGE, $contentId]);
            }
        );
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
    public function chargeForContent(
        int $fromUserId,
        int $toUserId,
        int $currencyId,
        float $amount,
        string $contentType,
        int $contentId
    ): string {
        return $this->contentCharge()->purchase(
            $fromUserId,
            $toUserId,
            $currencyId,
            $amount,
            $contentType,
            $contentId
        );
    }

    /**
     * Fires a post-commit event without letting a listener turn a completed
     * charge into a reported failure.
     */
    protected function fireAdjustEvent(array $args): void
    {
        try {
            $this->app->fire('oc_credits_adjust', $args);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits adjust event failed: ');
        }
    }

    /**
     * Whether the member has already unlocked this content item (any price).
     * Pass $currencyId = 0 to ignore the currency (used before resolving the
     * primary currency so existing buyers keep access).
     */
    public function hasPaidForContent(int $userId, string $contentType, int $contentId, int $currencyId = 0): bool
    {
        return $this->contentCharge()->hasPaid($userId, $contentType, $contentId, $currencyId);
    }

    /**
     * Number of members who have unlocked a content item at a given price.
     * Intended for the author-facing stats line.
     */
    public function unlockCountFor(string $contentType, int $contentId, int $currencyId, float $amount): int
    {
        return $this->contentCharge()->unlockCount($contentType, $contentId, $currencyId, $amount);
    }

    /**
     * Redeems a coupon code for a member. Owns its transaction and locks the
     * code row, so limited-use caps stay exact under concurrency.
     *
     * Returns 'ok' or a failure code: 'not_found', 'inactive', 'expired',
     * 'exhausted', 'already_redeemed', 'ineligible', 'invalid_amount',
     * 'capped'. On 'ok', $granted receives ['amount', 'currency_id'] for the
     * result view. 'capped' leaves the code unused so it can be redeemed
     * after spending.
     */
    public function redeem(int $userId, string $code, ?array &$granted = null): string
    {
        $granted = null;
        if ($userId <= 0) {
            return 'not_found';
        }
        $code = \OpenCredits\Credits\Entity\Code::normalize($code);
        if ($code === '') {
            return 'not_found';
        }

        $db = $this->db();
        $db->beginTransaction();
        try {
            $row = $db->fetchRow(
                'SELECT * FROM xf_oc_code WHERE code = ? FOR UPDATE',
                $code
            );
            if (!$row) {
                $db->rollBack();
                return 'not_found';
            }
            if (!(int)$row['active']) {
                $db->rollBack();
                return 'inactive';
            }
            if ((int)$row['expiry_date'] > 0 && (int)$row['expiry_date'] <= \XF::$time) {
                $db->rollBack();
                return 'expired';
            }
            $already = (int)$db->fetchOne(
                'SELECT COUNT(*) FROM xf_oc_transaction'
                . ' WHERE user_id = ? AND `trigger` = ? AND content_id = ?',
                [$userId, Ledger::TRIGGER_REDEEM, (int)$row['code_id']]
            );
            if ($already > 0) {
                $db->rollBack();
                return 'already_redeemed';
            }
            if ((int)$row['max_uses'] > 0 && (int)$row['uses'] >= (int)$row['max_uses']) {
                $db->rollBack();
                return 'exhausted';
            }

            $userRow = $db->fetchRow(
                'SELECT user_id, user_state, is_banned FROM xf_user WHERE user_id = ? FOR UPDATE',
                $userId
            );
            if (!$userRow) {
                $db->rollBack();
                return 'invalid_amount';
            }
            // Redeeming is earning: the same verdict as awards, enforced at
            // the locked row so an integrator cannot skip the controller.
            if (!AccountStanding::canEarn((string)$userRow['user_state'], !empty($userRow['is_banned']))) {
                $db->rollBack();
                return 'ineligible';
            }
            $currencyId = (int)$row['currency_id'];
            $currency = $db->fetchRow(
                'SELECT decimals, active, allow_negative FROM xf_oc_currency WHERE currency_id = ?',
                $currencyId
            );
            if (!$currency || !(int)$currency['active']) {
                $db->rollBack();
                return 'invalid_amount';
            }
            $amount = round((float)$row['amount'], (int)$currency['decimals']);
            if ($amount <= 0) {
                $db->rollBack();
                return 'invalid_amount';
            }
            $faucetDayStart = 0;
            if ($this->inflationApplies($currencyId)) {
                $settings = $this->inflationSettings();
                if ($settings['enabled']) {
                    $balance = (float)$db->fetchOne(
                        'SELECT COALESCE(balance, 0) FROM xf_oc_balance WHERE user_id = ? AND currency_id = ? FOR UPDATE',
                        [$userId, $currencyId]
                    );
                    // Redeem is all-or-nothing — a capped code stays
                    // unused instead of half-consumed — while adjust() takes
                    // partial credit; both verdicts come out of credited().
                    // Faucet rows only when the faucet is on (historic redeem
                    // behavior); adjust() alone keeps the always-track ledger
                    // for a mid-day faucet switch-on.
                    $earnedToday = 0.0;
                    if ($settings['faucet'] > 0) {
                        $faucetDayStart = self::dayStart();
                        $earnedToday = (float)$db->fetchOne(
                            'SELECT COALESCE(amount, 0) FROM xf_oc_faucet'
                            . ' WHERE user_id = ? AND currency_id = ? AND day_start = ? FOR UPDATE',
                            [$userId, $currencyId, $faucetDayStart]
                        );
                    }
                    if (!InflationClamp::fitsFull(
                        $amount,
                        $balance,
                        $earnedToday,
                        $settings['cap'],
                        $settings['faucet'],
                        (int)$currency['decimals']
                    )) {
                        $db->rollBack();
                        return 'capped';
                    }
                }
            }
            if (!$this->allowsResultingBalance($userId, $currencyId, $amount, (int)$currency['allow_negative'])) {
                $db->rollBack();
                return 'invalid_amount';
            }

            $this->applyBalanceDelta($userId, $currencyId, $amount);
            $db->insert('xf_oc_transaction', [
                'user_id' => $userId,
                'currency_id' => $currencyId,
                'amount' => $amount,
                'trigger' => Ledger::TRIGGER_REDEEM,
                'content_type' => 'code',
                'content_id' => (int)$row['code_id'],
                'note' => 'code #' . (int)$row['code_id'],
                'log_date' => \XF::$time,
            ]);
            if ($faucetDayStart > 0) {
                $this->trackFaucetMint($userId, $currencyId, $faucetDayStart, $amount);
            }
            $db->query(
                'UPDATE xf_oc_code SET uses = uses + 1 WHERE code_id = ?',
                (int)$row['code_id']
            );
            $db->commit();
            self::clearBalanceCache($userId);
            $this->fireAdjustEvent([$userId, $currencyId, $amount, Ledger::TRIGGER_REDEEM, (int)$row['code_id']]);
            $granted = ['amount' => $amount, 'currency_id' => $currencyId];
            return 'ok';
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * The ledger write module. Callers keep their own transaction, side
     * tables, cache clearing and post-commit event.
     */
    public function ledgerWrite(): LedgerWrite
    {
        return new LedgerWrite($this->db(), function (): int {
            return $this->primaryCurrencyId();
        });
    }

    /**
     * Demurrage runner for the whole pass. The cron job, admin Tools action,
     * and CLI command all go through this module; the legacy wrappers below
     * keep existing callers and the characterization tests on their entry
     * points without exposing the token/cursor protocol.
     */
    public function demurrageRunner(): DemurrageRunner
    {
        $currencyId = $this->primaryCurrencyId();
        $db = $this->db();

        return new DemurrageRunner(
            $db,
            function (): array {
                return $this->inflationSettings();
            },
            $currencyId,
            $this->ledgerWrite(),
            function (int $userId, float $amount) use ($currencyId): void {
                self::clearBalanceCache($userId);
                $this->fireAdjustEvent([$userId, $currencyId, -$amount, Ledger::TRIGGER_DEMURRAGE, 0]);
            }
        );
    }

    /**
     * Snapshot for the admin Tools page. Delegates to the runner so the
     * in-flight cursor exclusion has a single owner.
     */
    public function demurragePreview(): array
    {
        return $this->demurrageRunner()->preview();
    }

    /**
     * One batch, kept for the characterization tests and existing callers.
     * A zero budget makes the module stop after its first batch.
     */
    public function demurrageStep(int $batchSize = 500, bool $ignoreInterval = false): array
    {
        return $this->demurrageRunner()->run($batchSize, $ignoreInterval, 0);
    }

    /**
     * Run the whole pass within an optional time budget.
     */
    public function runDemurrage(int $batchSize = 500, bool $ignoreInterval = false, ?int $timeBudget = null): array
    {
        return $this->demurrageRunner()->run($batchSize, $ignoreInterval, $timeBudget);
    }

    /**
     * Start of the current earning day as a UTC timestamp. Follows the
     * ocDayBoundary option: shared UTC midnight (default, ungameable),
     * board time-zone midnight, or a fixed UTC offset in hours.
     */
    public static function dayStart(): int
    {
        $now = \XF::$time;
        $mode = 'utc';
        $offsetHours = 0;
        $tzName = '';
        try {
            $options = \XF::app()->options();
            $mode = (string)($options->ocDayBoundary ?? 'utc');
            $offsetHours = (int)($options->ocDayOffsetHours ?? 0);
            $tzName = (string)($options->guestTimeZone ?? '');
        } catch (\Throwable $e) {
            $mode = 'utc';
        }
        return self::dayStartAt($now, $mode, $offsetHours, $tzName);
    }

    /**
     * Pure boundary math, split out of dayStart() so it can be unit-tested
     * without booting XenForo. Unknown or invalid input falls back to UTC
     * midnight, the safe, ungameable default.
     *
     * @param int $now Unix timestamp to resolve the day start for
     * @param string $mode 'utc' | 'board' | 'fixed'
     * @param int $offsetHours Fixed offset hours (clamped to -12..+14)
     * @param string $tzName Board time zone name (empty = UTC)
     */
    public static function dayStartAt(int $now, string $mode, int $offsetHours = 0, string $tzName = ''): int
    {
        if ($mode === 'board') {
            try {
                $tz = new \DateTimeZone($tzName !== '' ? $tzName : 'UTC');
                $midnight = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
                return (int)$midnight->format('U');
            } catch (\Throwable $e) {
                // Invalid time zone name: fall through to the UTC default.
            }
        } elseif ($mode === 'fixed') {
            $offsetHours = max(-12, min(14, $offsetHours));
            $shifted = $now + $offsetHours * 3600;
            return $shifted - ($shifted % 86400) - $offsetHours * 3600;
        }
        return $now - ($now % 86400);
    }

    /**
     * Inflation settings, primary currency only. Amounts are in currency
     * units; 0 disables the individual lever.
     */
    protected function inflationSettings(): array
    {
        $options = $this->app->options();
        $rate = (float)($options->ocInflationDemurrageRate ?? 0);
        return [
            'enabled' => (bool)($options->ocInflationEnabled ?? false),
            'cap' => max(0.0, (float)($options->ocInflationBalanceCap ?? 0)),
            'faucet' => max(0.0, (float)($options->ocInflationFaucetDaily ?? 0)),
            'rate' => max(0.0, min(100.0, $rate)),
            'days' => max(1, (int)($options->ocInflationDemurrageDays ?? 7)),
        ];
    }

    protected function inflationApplies(int $currencyId): bool
    {
        return $currencyId > 0 && $currencyId === $this->primaryCurrencyId();
    }

    /**
     * Actual member credit after the balance cap and daily faucet.
     * Historic signature kept for integrators: $faucetRemaining is today's
     * remaining allowance (PHP_FLOAT_MAX disables the faucet). New code
     * should call InflationClamp::credited() directly.
     */
    public static function calculateCredit(
        float $requested,
        float $balance,
        float $cap,
        float $faucetRemaining,
        int $decimals
    ): float {
        if ($faucetRemaining == 0.0) {
            return 0.0;
        }
        // Negative or PHP_FLOAT_MAX sentinel: faucet disabled, ignore it
        // (historic behavior); only a finite positive remainder clamps.
        $faucet = ($faucetRemaining < 0.0 || $faucetRemaining >= (PHP_FLOAT_MAX / 2))
            ? 0.0
            : $faucetRemaining;
        return InflationClamp::credited($requested, $balance, 0.0, $cap, $faucet, $decimals);
    }

    /**
     * Round down to the currency's smallest unit. Kept here for integrators;
     * new code should call InflationClamp::floorToDecimals() directly.
     */
    public static function floorToDecimals(float $value, int $decimals): float
    {
        return InflationClamp::floorToDecimals($value, $decimals);
    }

    /**
     * Demurrage burn for a balance. Kept for integrators; the runner owns
     * the math so previews and passes cannot drift.
     */
    public static function calculateDemurrage(float $balance, float $rate, int $decimals): float
    {
        return DemurrageRunner::burn($balance, $rate, $decimals);
    }

    protected function passesDailyLimit(int $userId, string $trigger, int $maxPerDay, int $currencyId): bool
    {
        if ($maxPerDay <= 0) {
            return true;
        }
        $count = (int)$this->db()->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_transaction WHERE user_id = ? AND currency_id = ? AND log_date >= ?'
            . ' AND note != ? AND `trigger` = ?',
            [$userId, $currencyId, self::dayStart(), Ledger::NOTE_BACKFILL, $trigger]
        );
        return $count < $maxPerDay;
    }

    /**
     * Daily login: exactly once per calendar day, regardless of event config.
     * Called from the visitor_setup listener (every request for logged-in users).
     * Fast path avoids the transaction when already awarded; the user-row lock
     * serializes concurrent first-requests of the day.
     *
     * When the faucet clamps the award to zero, a claim marker is recorded
     * so later requests today short-circuit on the fast path instead of
     * retrying a write transaction on every page view. Cap-bound clamps
     * leave no marker: cap headroom can free later the same day.
     */
    public function awardDailyLoginIfNeeded(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $db = $this->db();
        $start = self::dayStart();
        $already = (int)$db->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_transaction WHERE user_id = ? AND `trigger` = ? AND log_date >= ?',
            [$userId, Ledger::TRIGGER_DAILY_LOGIN, $start]
        );
        if ($already > 0) {
            return false;
        }
        $claimed = (int)$db->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_daily_claim WHERE user_id = ? AND day_start = ?',
            [$userId, $start]
        );
        if ($claimed > 0) {
            return false;
        }
        $db->beginTransaction();
        try {
            $db->query('SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE', $userId);
            $recheck = (int)$db->fetchOne(
                'SELECT COUNT(*) FROM xf_oc_transaction WHERE user_id = ? AND `trigger` = ? AND log_date >= ?',
                [$userId, Ledger::TRIGGER_DAILY_LOGIN, $start]
            );
            if ($recheck > 0) {
                $db->commit();
                return false;
            }
            $recheckClaim = (int)$db->fetchOne(
                'SELECT COUNT(*) FROM xf_oc_daily_claim WHERE user_id = ? AND day_start = ?',
                [$userId, $start]
            );
            if ($recheckClaim > 0) {
                $db->commit();
                return false;
            }
            $awarded = $this->awardByTrigger(Ledger::TRIGGER_DAILY_LOGIN, $userId, $userId);
            $mark = InflationClamp::shouldMarkClaimed(
                $awarded,
                $this->hasActiveEvents(Ledger::TRIGGER_DAILY_LOGIN),
                $this->isAwardEligible($userId)
            );
            if ($mark) {
                // Only faucet-bound clamps mark the day: faucet exhaustion
                // never frees the same day, but cap headroom can (a later
                // transfer, charge, or demurrage burn frees it), so a
                // cap-bound member retries instead of finding a stale
                // marker. Every amount must clamp on the faucet alone.
                $mark = $this->dailyAwardsFaucetBound($userId, $start);
            }
            if ($mark) {
                // Faucet-bound: the day is claimed so later requests skip the
                // write transaction. The write itself stays here (needs $db);
                // only the verdict lives in the clamp module. Ineligible
                // members get no marker — unbanning later the same day must
                // not find a stale claim blocking their award.
                $db->query(
                    'INSERT IGNORE INTO xf_oc_daily_claim (user_id, day_start) VALUES (?, ?)',
                    [$userId, $start]
                );
            }
            $db->commit();
            return $awarded;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Whether every active primary-currency daily_login event clamps to zero
     * on the faucet alone (cap ignored). Reads under the caller's locks.
     * Secondary-currency events need no check: with no clamp they either
     * awarded (contradicting the marker path) or are dust that never credits.
     */
    protected function dailyAwardsFaucetBound(int $userId, int $dayStart): bool
    {
        $primaryId = $this->primaryCurrencyId();
        $amounts = [];
        $decimals = 2;
        foreach (self::$eventRowsCache[Ledger::TRIGGER_DAILY_LOGIN] ?? [] as $row) {
            if ((int)($row['currency_id'] ?? 0) === $primaryId) {
                $amounts[] = (float)($row['amount'] ?? 0);
                $decimals = (int)($row['decimals'] ?? $decimals);
            }
        }
        if ($amounts === []) {
            return true;
        }
        $settings = $this->inflationSettings();
        $db = $this->db();
        $balance = (float)$db->fetchOne(
            'SELECT COALESCE(balance, 0) FROM xf_oc_balance WHERE user_id = ? AND currency_id = ? FOR UPDATE',
            [$userId, $primaryId]
        );
        $earnedToday = 0.0;
        if ($settings['faucet'] > 0) {
            $earnedToday = (float)$db->fetchOne(
                'SELECT COALESCE(amount, 0) FROM xf_oc_faucet'
                . ' WHERE user_id = ? AND currency_id = ? AND day_start = ? FOR UPDATE',
                [$userId, $primaryId, $dayStart]
            );
        }
        return InflationClamp::allFaucetBound($amounts, $balance, $earnedToday, $settings['faucet'], $decimals);
    }

    /**
     * Whether any active event exists for a trigger (used to tell "nothing
     * configured" apart from "configured but clamped to zero").
     */
    protected function hasActiveEvents(string $trigger): bool
    {
        if (array_key_exists($trigger, self::$eventRowsCache)) {
            return (bool)self::$eventRowsCache[$trigger];
        }
        return (bool)$this->db()->fetchOne(
            'SELECT event_id FROM xf_oc_event WHERE `trigger` = ? AND active = 1 LIMIT 1',
            [$trigger]
        );
    }

    /**
     * Summarizes ledger rows at or before $cutoff into per-(member,currency)
     * snapshot rows and deletes them. Backfill markers, redeem rows, and
     * reaction dedupe markers are never deleted (completion guards,
     * one-per-member checks, and re-reaction dedupe depend on them). Batched
     * by user-id range like rebuilds; totals stay exact because each snapshot
     * equals the sum of its deleted rows.
     * Returns ['snapshots' => upserted, 'deleted' => rows, 'next' => ?].
     */
    public function pruneHistory(int $cutoff, int $startAfterUserId = 0, int $batchSize = 0): array
    {
        $empty = ['snapshots' => 0, 'deleted' => 0, 'next' => null];
        if ($cutoff <= 0 || $cutoff >= \XF::$time) {
            return $empty;
        }
        // Never prune rows from the current earning day. On a 25-hour DST
        // day a days=1 cutoff can otherwise land after dayStart() and delete
        // the daily-login / daily-limit guards, allowing a second award.
        $cutoff = min($cutoff, self::dayStart() - 1);
        if ($cutoff <= 0) {
            return $empty;
        }
        $db = $this->db();
        $maxUserId = max(
            (int)$db->fetchOne('SELECT MAX(user_id) FROM xf_user'),
            (int)$db->fetchOne('SELECT MAX(user_id) FROM xf_oc_transaction')
        );
        $endUserId = $batchSize > 0 ? $startAfterUserId + $batchSize : $maxUserId;
        $pairs = $db->fetchAll(
            'SELECT user_id, currency_id FROM xf_oc_transaction'
            . ' WHERE user_id > ? AND user_id <= ? AND log_date <= ?'
            . ' GROUP BY user_id, currency_id',
            [$startAfterUserId, $endUserId, $cutoff]
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
                    'SELECT COALESCE(SUM(amount), 0) FROM xf_oc_transaction'
                    . ' WHERE user_id = ? AND currency_id = ? AND log_date <= ?'
                    . " AND `trigger` != ? AND note != ? AND note NOT LIKE ?",
                    array_merge([$uid, $cid, $cutoff], Ledger::pruneGuardParams())
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
                    'DELETE FROM xf_oc_transaction'
                    . ' WHERE user_id = ? AND currency_id = ? AND log_date <= ?'
                    . " AND `trigger` != ? AND note != ? AND note NOT LIKE ?",
                    array_merge([$uid, $cid, $cutoff], Ledger::pruneGuardParams())
                );
                $deleted += (int)$stmt->rowsAffected();
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        self::clearBalanceCache();
        $next = ($batchSize > 0 && $endUserId < $maxUserId) ? $endUserId : null;
        return ['snapshots' => $snapshots, 'deleted' => $deleted, 'next' => $next];
    }

    /**
     * Recomputes cached balances from snapshots plus the transaction log, including the
     * legacy primary-currency column.
     *
     * Single-pass when $batchSize <= 0 (small boards, CLI, tests). With a
     * batch size, rebuilds one user-id range per call so huge ledgers
     * complete across requests instead of one giant transaction: returns
     * ['rows' => rebuilt rows, 'next' => next startAfterUserId or null].
     */
    public function rebuildAllBalances(int $startAfterUserId = 0, int $batchSize = 0): array
    {
        $db = $this->db();
        if ($batchSize <= 0) {
            $db->beginTransaction();
            try {
                // Lock users first (ascending) so concurrent awards, which lock
                // the user row then the balance row, cannot deadlock with this
                // rebuild (which used to lock balances first).
                $db->query('SELECT user_id FROM xf_user ORDER BY user_id FOR UPDATE');
                $db->query('DELETE FROM xf_oc_balance');
                // Live members only: ledger rows for deleted users stay as
                // financial history but must not resurrect balance rows (the
                // demurrage burn skips orphans the same way).
                $stmt = $db->query(
                    'INSERT INTO xf_oc_balance (user_id, currency_id, balance)'
                    . ' SELECT combined.user_id, combined.currency_id, SUM(combined.amount) FROM ('
                    . 'SELECT user_id, currency_id, balance AS amount FROM xf_oc_snapshot'
                    . ' UNION ALL SELECT user_id, currency_id, amount FROM xf_oc_transaction'
                    . ') AS combined INNER JOIN xf_user AS u ON u.user_id = combined.user_id'
                    . ' GROUP BY combined.user_id, combined.currency_id'
                );
                $rows = (int)$stmt->rowsAffected();
                $this->resyncPrimaryMirror(null, null);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            self::clearBalanceCache();
            return ['rows' => $rows, 'next' => null];
        }

        $maxUserId = max(
            (int)$db->fetchOne('SELECT MAX(user_id) FROM xf_user'),
            (int)$db->fetchOne('SELECT MAX(user_id) FROM xf_oc_transaction'),
            (int)$db->fetchOne('SELECT MAX(user_id) FROM xf_oc_snapshot')
        );
        $endUserId = $startAfterUserId + $batchSize;
        $db->beginTransaction();
        try {
            $db->query(
                'SELECT user_id FROM xf_user WHERE user_id > ? AND user_id <= ? ORDER BY user_id FOR UPDATE',
                [$startAfterUserId, $endUserId]
            );
            $db->query(
                'DELETE FROM xf_oc_balance WHERE user_id > ? AND user_id <= ?',
                [$startAfterUserId, $endUserId]
            );
            $stmt = $db->query(
                'INSERT INTO xf_oc_balance (user_id, currency_id, balance)'
                . ' SELECT combined.user_id, combined.currency_id, SUM(combined.amount) FROM ('
                . 'SELECT user_id, currency_id, balance AS amount FROM xf_oc_snapshot WHERE user_id > ? AND user_id <= ?'
                . ' UNION ALL SELECT user_id, currency_id, amount FROM xf_oc_transaction WHERE user_id > ? AND user_id <= ?'
                . ') AS combined INNER JOIN xf_user AS u ON u.user_id = combined.user_id'
                . ' GROUP BY combined.user_id, combined.currency_id',
                [$startAfterUserId, $endUserId, $startAfterUserId, $endUserId]
            );
            $rows = (int)$stmt->rowsAffected();
            $this->resyncPrimaryMirror($startAfterUserId, $endUserId);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        self::clearBalanceCache();
        return ['rows' => $rows, 'next' => $endUserId >= $maxUserId ? null : $endUserId];
    }

    /**
     * Full rebuild in batches (CLI, admin). Loops single-range rebuilds so
     * each transaction stays small.
     */
    public function rebuildAllBalancesFully(int $batchSize = 5000): int
    {
        $rows = 0;
        $next = 0;
        do {
            $result = $this->rebuildAllBalances($next, $batchSize);
            $rows += $result['rows'];
            $next = $result['next'];
        } while ($next !== null);
        return $rows;
    }

    protected function resyncPrimaryMirror(?int $startAfterUserId, ?int $endUserId): void
    {
        $db = $this->db();
        $primaryId = $this->primaryCurrencyId();
        $sql = 'UPDATE xf_user u LEFT JOIN xf_oc_balance b ON b.user_id = u.user_id AND b.currency_id = ?'
            . ' SET u.oc_credits = COALESCE(b.balance, 0)';
        $params = [$primaryId];
        if ($startAfterUserId !== null && $endUserId !== null) {
            $sql .= ' WHERE u.user_id > ? AND u.user_id <= ?';
            $params[] = $startAfterUserId;
            $params[] = $endUserId;
        }
        $db->query($sql, $params);
    }
}
