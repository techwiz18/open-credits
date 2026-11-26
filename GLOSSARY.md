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

**Movement**:
One intent to change balances: a currency, a trigger, and one or more legs, each a member and a signed amount. Applied whole or not at all.
_Avoid_: transaction, entry, delta
