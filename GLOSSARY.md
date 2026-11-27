# OpenCredits

Earning and spending community credits inside a XenForo forum.

## Language

**Account standing**:
Whether a member's account stage permits earning, receiving, sending or unlocking credits. Unconfirmed and awaiting-approval accounts earn and receive but cannot send or unlock; banned, rejected and disabled accounts may do none of these.
_Avoid_: eligibility, user state check, account status

**Award eligibility**:
Whether a member and a piece of content qualify for an award at all (account standing, forum and usergroup scope, visible state, first-post rule).
_Avoid_: award limits, eligibility check

**Award limits**:
Per-award gates applied after eligibility passes (daily caps, reaction dedupe keys, faucet and balance-cap clamps).
_Avoid_: award eligibility, throttling

**Charge access**:
The one verdict for a `[charge]` block: `denied`, `author`, `staff`, `bypass`, `paid` or `locked`, resolved in that priority order (author beats staff so the stats line renders once). Render and the unlock POST branch on the same verdict.
_Avoid_: paywall check, exemption list, paid cache

**Historical backfill**:
A one-off, resumable payout of an event's existing content, so posts and threads made before the event changed earn what the live path would have paid. Preview and run share one tally, so the promised member count is the paid member count.
_Avoid_: retrofit, re-award, batch payout

**History prune**:
Age-based deletion of ledger rows. Preview and run share one deletable predicate, so the count an admin sees is the count the run deletes; orphan charge rows are purged separately by the charge module.
_Avoid_: cleanup, purge

**Movement**:
One intent to change balances: a currency, a trigger, and one or more legs, each a member and a signed amount. Applied whole or not at all.
_Avoid_: transaction, entry, delta

**Request cache**:
The one memo that survives for a request: balance rows, active event rows, the primary currency id, and the charge price, unlock-count and prior-purchase state. A committed write invalidates the whole memo, so no caller clears a section by hand.
_Avoid_: static cache, balance cache, render cache

**Wallet view**:
The currency selection, visibility and amount step one member sees on the wallet page and the profile tab, resolved once so every surface agrees.
_Avoid_: wallet page state, view model, viewable currencies

**Release-ready**:
A tree state that has passed every gate a release tag requires, so tagging it needs no further code changes.
_Avoid_: stable, green, shippable

**1.0**:
The first release that carries the stability promise: the stable surface may be relied on for the whole 1.x line, and removing any part of it requires a major release.
_Avoid_: stable release, production-ready, v1

**Stable surface**:
The documented integrator API, the `oc_credits_adjust` contract, criteria rule ids, and the option, permission, phrase-key, route, `[charge]`, widget and template-name identities, plus the schema's guarantee that upgrades preserve data.
_Avoid_: public API, contract

**Breaking change**:
A change that stops a documented call working, changes the meaning of persisted data, removes or renames a stable-surface identity, or changes money outcomes with no admin opt-in. A fix that restores documented behaviour is not breaking.
_Avoid_: backwards-incompatible change
