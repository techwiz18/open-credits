---
name: opencredits-ux
description: OpenCredits UI/UX and information architecture. Use when changing credits templates, the /credits wallet page, the profile Credits tab, or any member-facing wallet, history, or action layout.
---

# OpenCredits UX & Information Architecture

Read [shared policy](../xf-shared-policy/SKILL.md) for universal rules (runner detection, artifact boundaries, import/export, phrase conventions).

## Surface roles — do not blur them

- **`/credits` is the wallet.** It owns the balance, the redeem and transfer actions, and the full paged history. It is the public "Credits" nav tab.
- **The profile "Credits" tab is per-member.** It shows that member's balances. For another member it adds a "Send credits" form locked to them; for self it shows an "Open wallet" button. Never put the full wallet history or the redeem form here.
- **Single-currency forums:** when only one visible active currency exists, your own profile tab loads the wallet (`credits_index`) directly; other members still get the per-member card.
- **Retired pages:** `/credits/redeem` and `/credits/transfer` GET actions redirect to `/credits`. Redeem and transfer POST from the wallet (or the per-member send form).

## Layout rules

1. Balance is the hero and appears once per surface.
2. History is the primary content; actions are secondary and compact.
3. Wallet order: balance + currency switcher → compact Redeem → compact Transfer → history (10/page by default; 10/20/50 selector).
4. Per-member order: balances → (other member: Send credits) / (self: Open wallet).
5. Actions are one-line `inputGroup`s, always visible; do not hide them behind toggles.
6. Confirm actions by landing back on `/credits`; the new ledger row and updated balance are the confirmation.
7. Currency is wallet-level context: the switcher changes the balance, the transfer currency, and the history together.
8. Use standard XF components: `block`, `block-container`, `block-header`, `block-body`, `block-row`, `inputGroup`, `inputGroup-splitter`, `inputGroup-text`, `u-pullRight`, `pairs pairs--justified`, `pageNav`, `button`, `button--primary`.

## Display options (modularity)

One option per surface/component, fixed order, defaults preserve current behaviour:

- Existing surfaces: `ocShowPostbit`, `ocShowMenu`, `ocShowProfileStats`, `ocShowProfileTab`.
- Wallet blocks: `ocShowWalletRedeem`, `ocShowTransfer` (transfer also hides the per-member send form).
- Profile mode: `ocProfileTabMode` — `auto` (wallet when one visible active currency), `wallet` (your own tab always wallet), `member` (always per-member card).
- Fallbacks: profile tab off → profile stat links to the wallet; transfer off → both transfer UIs gone; redeem off → wallet redeem gone.
- Group visibility stays in permissions (`ocView`/`ocTransfer`), not per-group options.
- No block reordering or page-builder; keep a fixed order with on/off.

## Implementation map

| Surface | Controller | Template |
|---|---|---|
| Wallet | `Pub\Controller\Credits::actionIndex` | `credits_index.html` |
| Per-member tab | `actionMember` | `credits_member.html` |
| Retired GETs | `actionRedeem`, `actionTransfer` (redirect) | — |

- `actionMember` passes `canSend`, `lockedTo`, `targetReceivable`, `senderBalance`, `currency`, `amountStep`; balances come from `$user.oc_balances`.
- The per-member send form posts `oc_in_tab=1`; `actionTransferSave` redirects back to the **recipient's** profile tab (`#credits`).
- When the wallet is embedded in the profile tab, its redeem/transfer forms post `oc_in_tab=1` + `oc_return_user_id` (the profile owner) so the action returns to `members/<owner>/#credits`, not `/credits`.
- Wallet paging defaults to 10/page; the 10/20/50 per-page options are **links** in the history footer (below the pager). On the standalone wallet they use `credits`; inside the profile tab they use `credits/member?user_id=...` and `oc_credits_tab.js` reloads the pane. The choice persists in the session (`ocWalletPerPage`). Use `xf:pagenav` and keep `per_page` in its `params`; XF's pager class is `pageNav` (capital N).
- The pane JS intercepts `pageNav`/`pageNavSimple` links, `.oc-credits-per-page`, and `.oc-credits-switch`; keep those classes on new controls.
- Never submit a GET form to an XF route URL (`index.php?credits/`): the form replaces the route query and lands on the forum index. Use `link()` URLs/links or a POST.
- The `credits` route's `context` is `ocCredits`; that is what marks the Credits nav tab active. Keep it in sync with the nav entry id.
- The profile stat link goes to the profile page's Credits tab (`link('members', $user)` + `#credits`), not the standalone tab page.
- Prefer a normal form/redirect for core navigation. Add add-on JS only when an interaction genuinely must stay in place.

## Charge pricing

One purchase unlocks the whole post, so the price is the **highest valid `[charge]` block** (`Charge::maxAmount()`). Never charge the cheapest block: a crafted POST or a cheap locked box would otherwise unlock the expensive blocks too. The unlock form's amount is informational; the server derives the price from the post.

## Checks before finishing

- `php -l` changed PHP.
- `xf-dev:import --addon=OpenCredits/Credits` after `_output` edits, then `./scripts/dev.sh fix-perms`.
- Integration tests: wallet pagination (20), per-member params, retired-route redirects.
- Update `docs/ARCHITECTURE.md` when the surface roles change.
