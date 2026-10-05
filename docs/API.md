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
    string $note = '', string $contentType = ''
): bool
```

The general-purpose entry point. Positive amounts award, negative amounts
charge; every call writes one ledger row. **Trust model: `adjust()` never
checks funds and never overdraw-blocks — it is privileged code. Always check
`getBalance()` first when charging a member.** Returns false only for empty
input (`$userId <= 0` or `$amount == 0.0`).

```php
$ok = $credits->transfer(int $fromUserId, int $toUserId, float $amount, int $currencyId = 1): bool
```

Double-entry member transfer (`-X` sender / `+X` receiver). Unlike `adjust()`,
**transfers refuse to overdraw** and roll back returning false when the sender
is short. Same user or non-positive amounts also return false.

```php
$rows = $credits->rebuildAllBalances(): int
```

Recomputes every cached balance from the ledger (also available as
`oc-credits:rebuild` and AdminCP → Credits → Rebuild balances).

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

## Reacting to balance changes

Listen to the `oc_credits_adjust` code event (AdminCP → Development → Code
event listeners → Add; it is registered by OpenCredits so it appears in the
event picker). It fires after every committed change — awards, charges, and
each leg of a transfer — with:

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
