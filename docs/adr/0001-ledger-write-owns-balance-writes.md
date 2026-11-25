# LedgerWrite owns balance writes; callers own the transaction

Every money path wrote balances the same way: open a transaction, lock the
member row, quantize to the currency's precision, check the negative-balance
floor, upsert `xf_oc_balance`, mirror `xf_user.oc_credits`, insert the
`xf_oc_transaction` row. `Service/LedgerWrite.php` now owns that protocol behind
`commit(movement)`. Callers keep the transaction, because their side tables
(charge rows, code counters, faucet tallies, resume markers) must commit with
the money, and because the cache clear and the `oc_credits_adjust` event must
not fire for a transaction that later rolls back.

## Considered options

**Lock the currency row through `commit()`**, so a currency deactivated mid-move
cannot be written. Rejected: every award, transfer and charge would contend on
one row, a global mutex on the hot path, to close a window where a single write
lands just after an admin deactivates the currency. Deactivation is not
deletion: the balance stays consistent and nothing is exposed, and the existing
`adjust()` reads `active` the same way, so the window is pre-existing rather
than introduced. Revisit only if deactivation is ever made destructive or
audited per movement.

## Consequences

Cutover is one writer per PR, suite green at each: demurrage burn (done), then
transfer, charge, redeem, adjust, backfill. Until the last one lands,
`Transact::applyBalanceDelta()` is still a legal writer and
`scripts/check-balance-writes.sh` allowlists it. "Never write balances outside
`LedgerWrite`" is the target of that series, not a fact about the current tree.
