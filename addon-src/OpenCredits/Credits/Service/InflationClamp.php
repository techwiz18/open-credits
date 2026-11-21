<?php

namespace OpenCredits\Credits\Service;

/**
 * Single inflation-clamp verdict for every member mint: earn awards through
 * adjust() and redeem codes. Pure: facts in, amount out — no entities, no DB.
 * Earn takes partial credit (cap headroom / faucet remainder); redeem is
 * all-or-nothing (a capped code stays unused, never half-consumed), and both
 * verdicts come out of credited() so the two paths cannot drift. The
 * daily-claim verdict lives here too; only its DB write stays in Transact.
 */
class InflationClamp
{
    /**
     * Actual member credit after the balance cap and daily faucet.
     * $cap = 0 disables the cap; $faucet = 0 disables the faucet.
     * $earnedToday is what the member already minted today (xf_oc_faucet).
     * Headroom is floored to the currency's smallest unit before clamping,
     * so a legacy sub-unit balance can never round its way over the cap on
     * a 0-decimal currency. Pure so it can be unit-tested without XenForo.
     */
    public static function credited(
        float $requested,
        float $balance,
        float $earnedToday,
        float $cap,
        float $faucet,
        int $decimals
    ): float {
        if ($requested <= 0) {
            return 0.0;
        }
        $decimals = max(0, $decimals);
        $amount = $requested;
        if ($cap > 0) {
            $amount = min($amount, self::floorToDecimals($cap - $balance, $decimals));
        }
        if ($faucet > 0) {
            $amount = min($amount, self::floorToDecimals($faucet - $earnedToday, $decimals));
        }
        $amount = round($amount, $decimals);
        return $amount > 0 ? $amount : 0.0;
    }

    /**
     * All-or-nothing verdict for redeem codes: the full amount must fit, so
     * a capped code stays unused instead of half-consumed. Defined as
     * credited() covering the request — one seam for both paths.
     */
    public static function fitsFull(
        float $amount,
        float $balance,
        float $earnedToday,
        float $cap,
        float $faucet,
        int $decimals
    ): bool {
        if ($amount <= 0) {
            return false;
        }
        return self::credited($amount, $balance, $earnedToday, $cap, $faucet, $decimals) >= $amount;
    }

    /**
     * Whether a missed daily award leaves a claim marker. Pure: the visitor
     * path calls this with what it already knows — whether anything was
     * awarded, whether any event is configured, and whether the member may
     * earn at all. A marker means "configured but clamped to zero", so later
     * requests today skip the write transaction; anything else (nothing
     * configured, ineligible member) leaves no marker behind.
     */
    public static function shouldMarkClaimed(bool $awarded, bool $hasActiveEvents, bool $isEligible): bool
    {
        return !$awarded && $hasActiveEvents && $isEligible;
    }

    /**
     * Whether every amount in $amounts clamps to zero on the faucet alone
     * (cap ignored). Pure. The daily-login path marks the day claimed only
     * then: faucet exhaustion never frees the same day, but cap headroom
     * can (a later transfer, charge, or demurrage burn frees it), so a
     * cap-bound member retries instead of finding a stale marker.
     *
     * @param float[] $amounts
     */
    public static function allFaucetBound(array $amounts, float $balance, float $earnedToday, float $faucet, int $decimals): bool
    {
        foreach ($amounts as $amount) {
            if (self::credited((float)$amount, $balance, $earnedToday, 0.0, $faucet, $decimals) > 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Round down to the currency's smallest unit. Pure for unit tests.
     */
    public static function floorToDecimals(float $value, int $decimals): float
    {
        $decimals = max(0, $decimals);
        $mult = 10 ** $decimals;
        return floor(max(0.0, $value) * $mult + 1e-9) / $mult;
    }
}
