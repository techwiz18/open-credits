---
name: opencredits-ux
description: OpenCredits UI/UX and information architecture. Use when changing credits templates, the /credits wallet page, the profile Credits tab, or any member-facing wallet, history, or action layout.
---

# OpenCredits UX & Information Architecture

Read [shared policy](../xf-shared-policy/SKILL.md) for universal rules (runner detection, artifact boundaries, import/export, phrase conventions).

## Surface roles — do not blur them

- **`/credits` is the wallet.** It owns the balance, the redeem and transfer actions, and the full paged history. It is the public "Credits" nav tab.
- **The profile "Credits" tab is per-member.** It shows that member's balances. For another member it adds a "Send credits" form locked to them; for self it shows an "Open wallet" button. Never put the full wallet history or the redeem form here.
- **Retired pages:** `/credits/redeem` and `/credits/transfer` GET actions redirect to `/credits`. Redeem and transfer POST from the wallet (or the per-member send form).

## Layout rules

1. Balance is the hero and appears once per surface.
2. History is the primary content; actions are secondary and compact.
3. Wallet order: balance + currency switcher → compact Redeem → compact Transfer → history (20/page).
4. Per-member order: balances → (other member: Send credits) / (self: Open wallet).
5. Actions are one-line `inputGroup`s, always visible; do not hide them behind toggles.
6. Confirm actions by landing back on `/credits`; the new ledger row and updated balance are the confirmation.
7. Currency is wallet-level context: the switcher changes the balance, the transfer currency, and the history together.
8. Use standard XF components: `block`, `block-container`, `block-header`, `block-body`, `block-row`, `inputGroup`, `inputGroup-splitter`, `inputGroup-text`, `u-pullRight`, `pairs pairs--justified`, `pageNav`, `button`, `button--primary`.

## Implementation map

| Surface | Controller | Template |
|---|---|---|
| Wallet | `Pub\Controller\Credits::actionIndex` | `credits_index.html` |
| Per-member tab | `actionMember` | `credits_member.html` |
| Retired GETs | `actionRedeem`, `actionTransfer` (redirect) | — |

- `actionMember` passes `canSend`, `lockedTo`, `targetReceivable`, `senderBalance`, `currency`, `amountStep`; balances come from `$user.oc_balances`.
- The per-member send form posts `oc_in_tab=1`; `actionTransferSave` redirects back to the **recipient's** profile tab (`#credits`).
- Wallet paging is 20/page; XF's pager class is `pageNav` (capital N). Do not hand-roll selectors or custom paging JS for the wallet.
- Prefer a normal form/redirect for core navigation. Add add-on JS only when an interaction genuinely must stay in place.

## Checks before finishing

- `php -l` changed PHP.
- `xf-dev:import --addon=OpenCredits/Credits` after `_output` edits, then `./scripts/dev.sh fix-perms`.
- Integration tests: wallet pagination (20), per-member params, retired-route redirects.
- Update `docs/ARCHITECTURE.md` when the surface roles change.
