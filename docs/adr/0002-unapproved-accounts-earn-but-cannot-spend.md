# Unapproved accounts earn and receive but cannot spend

A member awaiting email confirmation or moderator approval may earn credits and
receive transfers, but may not send credits or unlock paid content; banned,
rejected and disabled accounts may do none of the four. `Service/AccountStanding`
is the one table that decides this, and every money path asks it by name.
`email_bounce` counts as an active account (XF's own definition), so it may
spend.

## Considered options

**Block all four for unapproved accounts.** Rejected: the addon deliberately
seeds the register bonus for accounts created under email confirmation or
approval, so blocking earning would break that, and a member would activate with
an empty wallet.

**Allow spending for unapproved accounts.** Rejected: a throwaway, unverified
registration could move credits out of the system, and it contradicts the
documented transfer rule.

## Consequences

Unconfirmed members no longer see the send form, and the transfer the old
controller allowed is refused; they gain the daily bonus and redeem-code access
they previously lacked. Balances already earned stay put — nothing is clawed
back.
