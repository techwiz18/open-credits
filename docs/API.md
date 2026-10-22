# OpenCredits integrator API (for other add-on developers)

All cross-addon integration goes through one service. Get it anywhere
(controllers, services, cron, CLI) with:

```php
/** @var \OpenCredits\Credits\Service\Transact $credits */
$credits = \XF::app()->service('OpenCredits\Credits:Transact');
```

## Reading balances

```php
$balance = $credits->getBalance(int $userId, int $currencyId): float
```

Cached per-currency balance. Users or currencies with no rows read as `0.0`.

```php
$rows = $credits->balancesFor(int $userId, bool $visibleOnly): array
```

Every active currency (or only visible ones) with the user's balance.
Each row: `currency_id, title, prefix, suffix, decimals, is_primary, balance`.

```php
$id = $credits->primaryCurrencyId(): int
```

The currency flagged primary in AdminCP (falls back to id 1).

## Writing balances

```php
$ok = $credits->adjust(
    int $userId, int $currencyId, float $amount,
    string $trigger, int $contentId = 0,
    string $note = '', string $contentType = '',
    bool $respectInflation = false, &$credited = null
): bool
```

The general-purpose entry point. Positive amounts award, negative amounts
charge; every call writes one ledger row. **Trust model: `adjust()` never
checks funds and never overdraw-blocks — it is privileged code. Always check
`getBalance()` first when charging a member.** Amounts are rounded to the
currency's decimals (dust rounds to zero and is refused). Returns false for
empty input (`$userId <= 0` or `$amount == 0.0`), unknown users/currencies,
inactive currencies, and overdrafts when the currency disallows negatives.

Pass `$respectInflation = true` for member-facing minting (earnings or
redemptions): when inflation control is enabled, the credit is clamped to the
primary currency's balance cap and daily faucet. `$credited` receives the
amount **actually** credited after clamping (earn alerts use it). Admin and
integrator calls leave it false and remain trusted; transfers and charges are
supply-neutral and are never clamped. The cap is an economy control, not a
security boundary.

```php
$ok = $credits->transfer(int $fromUserId, int $toUserId, float $amount, int $currencyId = 1): bool
```

Double-entry member transfer (`-X` sender / `+X` receiver). Unlike `adjust()`,
**transfers refuse to overdraw** and roll back returning false when the sender
is short. Same user or non-positive amounts also return false.

```php
$status = $credits->chargeForContent(
    int $fromUserId, int $toUserId, int $currencyId,
    float $amount, string $contentType, int $contentId
): string
```

Double-entry premium-content charge: debits the reader and credits the author,
recording one purchase per `(content, reader, currency)`. Like `transfer()`, it
**never overdraws**. Returns `ok`, `already_paid`, `self` (reader is the author),
`no_funds`, `invalid`, or `invalid_author` (the reader or author is not a valid,
non-banned user). Repeated calls for the same content (any price) return
`already_paid` without moving money. The `[charge]` BBCode uses this; other
add-ons can call it for their own premium content.

```php
$paid = $credits->hasPaidForContent(int $userId, string $contentType, int $contentId, int $currencyId = 0): bool
```

Whether the member has already unlocked this content item (regardless of the
price they paid). Pass `0` for the currency to ignore it.

```php
$count = $credits->unlockCountFor(string $contentType, int $contentId, int $currencyId, float $amount): int
```

Number of members who have unlocked the content at that price. Used by the
`[charge]` BBCode's author-only stats line.

```php
$result = $credits->rebuildAllBalances(int $startAfterUserId = 0, int $batchSize = 0): array
$rows = $credits->rebuildAllBalancesFully(int $batchSize = 5000): int
```

`rebuildAllBalances()` processes one user-id batch and returns
`['rows' => int, 'next' => int|null]` (loop while `next !== null`);
`rebuildAllBalancesFully()` runs the whole rebuild and returns the row count.
Also available as `oc-credits:rebuild` and AdminCP → Credits → Rebuild balances.

## Worked example: spend-to-play entry fee

```php
$price = 10.00;
$currencyId = $credits->primaryCurrencyId();

if ($credits->getBalance($userId, $currencyId) < $price) {
    return $this->error('You need ' . $price . ' credits to enter.');
}

$credits->adjust($userId, $currencyId, -$price, 'my_addon_entry', $contentId, 'Tournament entry');
```

Charge with your own trigger key (namespaced, e.g. `my_addon_entry`) so the
member's history stays readable.

## Premium content (`[charge]`)

Forum posts can show a locked box in place of content until the reader pays:

```
[charge=10]This text is shown after the reader pays 10 credits.[/charge]
```

The amount is in the primary currency. Authors, staff/admins/moderators, and
members with the **Bypass charge BBCode** permission see it for free. One
purchase unlocks every `[charge]` block in the post; the post's price is the
**highest** valid block, the reader is charged and the author credited (both
ledger rows use the `charge` trigger). The BBCode renders a form that posts to
`/credits/unlock`; the endpoint refuses banned and non-valid accounts (email
confirmation, rejected, disabled).

This is a **credits-gated display, not content protection**: the text remains
in the post message, so it can still appear in raw post output, snippets,
quotes, and the API. Treat it as a premium teaser, not DRM.

```php
$status = $credits->redeem(int $userId, string $code, ?array &$granted = null): string
```

Redeems a member-facing coupon code (AdminCP → Credits → Redeem codes).
Owns its transaction and locks the code row, so limited-use caps stay exact.
Returns `'ok'` (with `$granted = ['amount', 'currency_id']`) or a failure
code: `not_found`, `inactive`, `expired`, `exhausted`, `already_redeemed`
(one per member per code), `invalid_amount`. No earn alert is sent.

## Reacting to balance changes

Listen to the `oc_credits_adjust` code event (AdminCP → Development → Code
event listeners → Add; it is registered by OpenCredits so it appears in the
event picker). It fires after every committed change — awards, charges, each
leg of a transfer, and demurrage burns — with:

| Arg | Meaning |
|---|---|
| `$userId` | User whose balance changed |
| `$currencyId` | Currency id |
| `$amount` | Signed delta (negative for debits) |
| `$trigger` | Trigger key (`post`, `transfer`, your custom key…) |
| `$contentId` | Related content id, or the counterparty user id for transfers |

Returning `false` from your listener halts later listeners (standard XF).

## Trophy and promotion criteria

The `criteria_user` rules `oc_credits_more` (>= X, primary currency) and
`oc_credits_fewer` (< X, primary currency) evaluate automatically wherever
user criteria are used — trophies, notices, user-group promotions. The input
fields appear in the criteria form with no setup.

## What not to do

* Never write `xf_oc_balance`, `xf_oc_transaction`, or `xf_user.oc_credits`
  directly — always go through `Transact`, which holds the row locks and keeps
  the three stores consistent.
* Never call `adjust()` with member-supplied amounts without a funds check.
