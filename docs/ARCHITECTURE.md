# OpenCredits architecture (LLM / contributor reference)

Source of truth: `addon-src/OpenCredits/Credits/`. It is bind-mounted into the
dev forum container at `src/addons/OpenCredits/Credits/` (see `docker-compose.yml`).
The licensed XenForo runtime unpacks under `src/`, with the XF tree at
`src/src/XF/`; it is gitignored and never committed.

## File map

| Path | Role |
|---|---|
| `addon.json` | Add-on meta. Current: 0.14.0 / 942. Requires XF 2.3.0+, PHP 8.2+ |
| `Setup.php` | Install/upgrade/uninstall steps. Owns schema + seed data |
| `Listener.php` | All code-event listener callbacks (no business logic) |
| `Service/Transact.php` | Façade for every money path (earn, transfer, redeem, charge, prune, rebuild); supplies the `LedgerWrite` module and keeps the public API integrators call |
| `Service/LedgerWrite.php` | **The one author of balance changes and ledger rows.** `commit(movement)` owns user-row lock order, currency precision, the overdraw policy, `xf_oc_balance`, the `oc_credits` mirror, and the `xf_oc_transaction` row shape; callers own the transaction and the post-commit effects |
| `Service/Ledger.php` | Ledger vocabulary: the one trigger-label map (`label()`, award-only `eventTriggers()`), trigger constants, and stored markers (`NOTE_BACKFILL`, `NOTE_REACTION_PREFIX`) shared by history, alerts, and the prune guard |
| `Service/AccountStanding.php` | **The one account-standing rule**: four verdicts (earn, receive, send, unlock) over one state table, pure so every money path reads the same decision |
| `Service/DemurrageRunner.php` | Demurrage pass lifecycle behind `run(batchSize, force, budget) → busy / done / resume`; burns and orphan purges go through the `LedgerWrite` module supplied by `Transact` |
| `Service/ContentCharge.php` | `[charge]` premium-content decision: parser-based post price (highest valid block), idempotent per-(content, member, currency) purchase, paid/unlock-count reads |
| `Entity/Currency.php`, `Entity/CreditEvent.php`, `Entity/Transaction.php` | Entity structures for the 3 tables |
| `BbCode/Charge.php` | `[charge=X]` renderer: view gates, locked box, author stats |
| `Pub/Controller/Credits.php` | `/credits` wallet, `/credits/member` profile tab, redeem/transfer save; legacy transfer/redeem GETs redirect |
| `Widget/TopHolders.php` | "Top credit holders" leaderboard widget (top N by balance, one currency, `ocView`-gated); lists strict `valid` accounts only — a display choice, not account standing |
| `Cli/Command/Rebuild.php` | `oc-credits:rebuild` CLI (calls the service) |
| `Cli/Command/Demurrage.php` | `oc-credits:demurrage` CLI (`--preview` or run now; calls the runner) |
| `Cron/DailyLogin.php` | Stub (daily_login is awarded via `visitor_setup`, not cron) |
| `Cron/Demurrage.php` | Demurrage cron (every 15 min); 10s budget per tick through `Service/DemurrageRunner`, resumable |
| `_output/` | Dev-output JSON/HTML/TXT synced into XF on install/upgrade |

## Database

* `xf_oc_currency(currency_id, title, prefix, suffix, decimals, allow_negative, active, is_primary, visible)`
  Seed: `(1, 'Credits', '$', '', 2, 1, 1, 1, 1)`. Exactly one primary (profile
  main stat + `xf_user.oc_credits` mirror); `visible` currencies list in
  postbit/menu; the profile tab lists all active ones.
* `xf_oc_balance(user_id, currency_id, balance)` — cached per-currency balances
  maintained by `LedgerWrite` on every write; missing row means 0. Keyed
  `(currency_id, balance)` (`currency_balance`) so the leaderboard widget is a
  range scan, not a filesort.
* `xf_oc_event(event_id, currency_id, trigger, amount, forum_ids, usergroup_ids, max_per_day, active)`
  Seeded triggers: `thread 5.00`, `post 1.00`, `reaction_received 2.00`,
  `register 10.00`, `daily_login 5.00`.
* `xf_oc_transaction(transaction_id, user_id, currency_id, amount, trigger, content_type, content_id, event_id, note, log_date)`
  Append-only ledger. `event_id` links historical-backfill rows to their event
  (0 for normal rows); `log_date` and `event_id` are indexed for prune/backfill.
  **Both `amount` columns are SIGNED** (`->unsigned(false)`); XF defaults
  numerics to unsigned, which rejects debit rows — see GOTCHAS.
* `xf_user.oc_credits DECIMAL(10,2)` — denormalized balance, rebuilt from ledger.
* `xf_oc_charge(charge_id, content_type, content_id, user_id, author_user_id,
  currency_id, amount, charge_date)` — premium-content purchases. Unique per
  `(content_type, content_id, user_id, currency_id)`, so a member never pays
  twice for the same content, even if its price changes; `amount` is what that
  member paid. Rows are removed when the post or the payer/author is
  hard-deleted; ledger rows stay as financial history.
* `xf_oc_code(code_id, code, currency_id, amount, max_uses, uses, expiry_date,
  active)` — redeem codes. Codes are normalized on lookup; `uses`/`max_uses`
  enforce caps; `expiry_date` is a Unix timestamp (0 = never).
* `xf_oc_backfill(backfill_id, event_id, user_id, last_user_id, started_date,
  completed_date)` — resumable backfill state, one row per (event, scope).
  `user_id` is 0 for a site-wide run or the member id for a per-member run;
  `last_user_id` is the last committed user-id boundary; `completed_date = 0`
  while running.
* `xf_oc_inflation(currency_id, last_run, cursor_user_id, pass_started,
  pass_token, pass_rate, pass_decimals)` — resumable demurrage state for the
  primary currency. `last_run` is the last completed pass; `cursor_user_id`
  resumes an in-flight pass; `pass_token` (0 = idle) claims a pass for exactly
  one runner and `pass_rate`/`pass_decimals` snapshot the configuration for
  the whole pass. Each burn and cursor advance commits in one transaction
  guarded by the token; a concurrent runner that loses the race gets `busy`
  instead of double-burning, and an hour-stale unprogressed claim is reclaimed
  rather than blocking forever.
* `xf_oc_faucet(user_id, currency_id, day_start, amount)` — daily mint ledger:
  what member mints actually credited today. Updated in the same transaction
  as the award, so duplicating/disabling/deleting an event mid-day cannot move
  the allowance and custom integrator triggers count the same as built-in ones.
* `xf_oc_daily_claim(user_id, day_start)` — daily-visit claim markers written
  when the faucet clamps the daily award to zero, so the per-request
  visitor path short-circuits instead of retrying a write transaction.
  Cap-bound clamps leave no marker: freed cap headroom retries later the
  same day.

## Trigger matrix (listener → service call)

| Code event (hint) | Listener method | Trigger awarded to |
|---|---|---|
| `entity_post_save` (`XF\Entity\Thread`) | `threadEntityPostSave` | author, on visible insert |
| `entity_post_save` (`XF\Entity\Post`) | `postEntityPostSave` | author, on visible insert, skips position 0 |
| `entity_post_save` (`XF\Entity\ReactionContent`) | `reactionContentEntityPostSave` | content author, only if `is_counted` and not self-reaction |
| `entity_post_save` (`XF\Entity\User`) | `userEntityPostSave` | new user, on insert |
| `visitor_setup` (no hint) | `visitorSetup` | logged-in valid user, via `awardDailyLoginIfNeeded` (once/day) |
| `entity_structure` (`XF\Entity\User`) | `userEntityStructure` | exposes `oc_credits` on the User entity |
| `criteria_user` (no hint) | `userCriteria` | rules `oc_credits_more` (>=) / `oc_credits_fewer` (<) |
| `entity_post_delete` (`XF\Entity\Post`) | `postEntityPostDelete` | removes unlock rows for the deleted post |
| `user_delete_clean_init` (no hint) | `userDeleteCleanInit` | removes unlock rows where the member was payer/author |

All earning paths funnel into `Transact::awardByTrigger()` (awards EVERY active
event for the trigger, so overlapping per-currency events stack) → `adjust()`.
Amounts are quantized to each currency's decimals before any check; dust that
rounds to zero is refused. Reaction awards carry a per-reactor dedupe key, so
un-react/re-react cycles pay once per reactor per content. Threads/replies
created under moderation award on the visible transition. Events may carry
forum/usergroup restrictions (`xf_oc_event.forum_ids`, `usergroup_ids`);
forum scoping applies to thread/post content, group scoping to all earn paths.
Transfers floor at zero and never overdraw; `adjust()` stays unrestricted for
admin tooling. Registration day skips the daily bonus (`register_date` check).
Rebuilds use `Transact::rebuildAllBalances()` (ledger → `xf_oc_balance` rows +
primary mirror in `xf_user.oc_credits`).
`Transact::getBalance()` reads the cached row (missing = 0). Every committed
change fires the `oc_credits_adjust` code event with
`($userId, $currencyId, $amount, $trigger, $contentId)` for cross-addon hooks.

## Enforced rules and known limits

* `ocView` gates history, member pane, profile displays, and postbit; `visible`
  currencies only. Revoking hides the addon completely.
* Account standing (`Service/AccountStanding`, ADR 0002) decides who may act:
  banned, rejected and disabled accounts earn nothing; unconfirmed and
  awaiting-approval accounts earn and receive but cannot send or unlock;
  `email_bounce` counts as an active account, so it may do all four. The
  services (`Transact::transfer()`, `Transact::redeem()`,
  `ContentCharge::purchase()`) enforce the verdict on the row they lock, and
  the controllers refuse early for the message. The top-holders widget keeps
  its strict `valid` filter as a display choice, not standing.
* Transfers always floor at zero; `adjust()` enforces each currency's
  `allow_negative` and refuses unknown users/currencies and inactive
  currencies. Positive deltas are always allowed so a legacy negative balance
  can recover; negative deltas still floor. Transfer with an unresolvable
  `currency_id` errors instead of falling back; the transfer form/save needs
  `ocView` + `ocTransfer` and offers the form only when the sender may send.
* Awards skip inactive currencies; registration and the daily visit follow
  account standing, so an unapproved account's bonus is waiting when it
  activates.
* Daily boundaries default to UTC midnights (`Transact::dayStart()`),
  ignoring forum/user time zones — consistent for all members and ungameable
  by traveling; configurable to board time or a fixed offset via options.
  Registration day skips the daily bonus on the same boundary.
* No clawback on content delete (by design; ledger history stays), but unlock
  rows are removed when a post or member is hard-deleted. Backfill runs
  per-event, scopes the event's `forum_ids`/`usergroup_ids`, skips first posts
  for post events, quantizes to currency decimals, and skips inactive
  currencies. Completion is keyed on `event_id`; each site-wide batch commits
  its rows and resume marker atomically, so a timeout resumes without
  double-paying. Site-wide runs also exclude already per-member-backfilled
  users; `max_per_day` counts per (event, currency) excluding backfill rows and
  is best-effort under concurrency; no per-day guard races the daily award
  thanks to the user-row lock.
* Inflation control is primary-currency only and off by default. A balance cap
  and a daily faucet **amount** clamp member mints (earnings through
  `awardByTrigger`, redemptions) to the headroom / remaining allowance; the
  headroom is floored to the currency's smallest unit so legacy sub-unit
  balances cannot round their way over the cap. The faucet is tracked in
  `xf_oc_faucet`, never derived from event rows. Redeems reject with `capped`
  (code left unused) when they would breach the cap or exhaust the faucet.
  Transfers and charges are supply-neutral and never clamped. The public
  `adjust()` is privileged and bypasses both; member-facing callers pass
  `$respectInflation = true`. A daily award clamped to zero by an exhausted faucet writes a marker
  in `xf_oc_daily_claim`, so the per-request visitor path short-circuits; cap-bound clamps leave no marker since headroom can free later the same day.
  Demurrage burns `rate%` of positive primary balances once per `days`,
  rounds to the currency, floors at zero, skips (and purges the cached rows
  of) deleted members, and runs from a resumable pass owned by
  `Service/DemurrageRunner`: the `ocDemurrage` cron ticks every 15 minutes
  with a 10s budget while a pass is in flight, Tools offers preview
  (excluding already-processed members) + a time-bounded run-now with resume
  state, and the CLI runs full. All three pass a budget and branch on the
  runner's `busy` / `done` / `resume` verdict; none reads or writes pass
  tokens or cursors. Overlapping runners get `busy`, never a second pass.
  Switching primary seeds `last_run` so the new
  currency does not burn immediately. The legacy `xf_user.oc_credits` mirror
  follows the same deltas. These are economy controls, not a security
  boundary.

## Frontend

* Route prefix `credits` → `Pub\Controller\Credits`: `actionIndex` (the wallet:
  balance, compact redeem/transfer, history paged 10 with 10/20/50 footer links), `actionMember` (the
  per-member profile tab), `actionTransferSave` / `actionRedeemSave` (POST,
  CSRF via `xf:form`). The legacy `actionTransfer` / `actionRedeem` GETs
  redirect to the wallet. The route's context is `ocCredits`, which marks the
  Credits nav tab active.
* Templates: `credits_index` (wallet) and `credits_member` (per-member tab).
  The wallet owns the redeem/transfer actions and the full history; the
  per-member tab shows the member's balances, a "Send credits" form for other
  members, and an "Open wallet" link for self. On single-currency forums the
  self tab renders the wallet template directly; other members keep the
  per-member card. When the wallet is embedded, its pager/per-page/currency links use the
  member route and are AJAX-reloaded by `oc_credits_tab.js` so they stay in
  the profile tab.
* Template mods: `oc_credits_postbit` (`message_macros`, after reaction score),
  `oc_credits_account_menu` (`account_visitor_menu`, visitor stats),
  `oc_credits_criteria` (`helper_criteria`, admin trophy/promotion form).
* Permissions (namespace `general`, own interface tab `ocCredits`):
  `ocView` (history, member pane, transfer), `ocTransfer` (transfer form +
  save; both permissions are required). Default deny;
  install/upgrade auto-allows both for the group titled `Registered`.
* Display options (`ocCredits` option group): `ocShowPostbit`, `ocShowMenu`,
  `ocShowProfileStats`, `ocShowProfileTab`, `ocShowWalletRedeem`,
  `ocShowTransfer` (all default on), plus `ocProfileTabMode`
  (`auto`/`wallet`/`member`; `auto` loads the wallet on single-currency
  forums). When the profile tab is off, the profile stat links to the wallet;
  the transfer/redeem toggles hide their wallet blocks (transfer also hides the
  per-member send form).
* Inflation options (`ocCredits` group): `ocInflationEnabled` (off),
  `ocInflationBalanceCap`, `ocInflationFaucetDaily`,
  `ocInflationDemurrageRate` (0 disables each lever), and
  `ocInflationDemurrageDays` (default 7). Primary currency only. The faucet
  counts every member mint in `xf_oc_faucet` (all sources share the one
  allowance); stale faucet/claim rows are pruned on every inflation tick.
* Earnings require a participable account: banned, rejected, and disabled
  members earn nothing from any trigger (checked once in
  `awardByTrigger()`, mirroring the register path).
* Tools (`oc-tools` admin route): rebuild (sync service call + result view),
  one-shot/resumable historical backfill (lump sums per member per post/thread
  event, scoped by the event's forum/group restrictions, completion keyed on
  `event_id`, then rebuild), prune (batched snapshots, current day protected),
  adjust (grant/remove a member's credits), and demurrage (preview + run-now
  with a 25s budget and resume/busy states; the `ocDemurrage` cron ticks every
  15 minutes with a 10s budget and applies the configured rate once per
  period).
* Widget `oc_top_holders` (`Widget`/`TopHolders.php`): top N members by balance
  in one currency (primary by default, or any visible active currency),
  `ocView`-gated, excludes banned/disabled and zero balances. Guests are hidden
  unless the widget's `show_guests` option is enabled (or `ocView` is granted
  to the guest group). One aggregate `xf_oc_balance ⋈ xf_user` query + one user
  fetch. Templates: `oc_widget_top_holders` (public) and
  `widget_def_options_oc_top_holders` (admin options: count, currency, guests).
* `[charge=X]…[/charge]` (`BbCode/Charge.php`) shows a locked box in place of
  the post content until the reader pays X of the primary currency; authors,
  staff/admins/moderators, and `ocChargeFree` holders bypass. This is a
  **credits-gated display, not content protection**: the body remains in the
  post message and can appear in raw post output, snippets, quotes, and the
  API. The unlock form posts to `/credits/unlock`, which loads the post,
  checks `canView()` and the visitor's account state (banned/email-confirm are
  refused), derives the price from the post's parsed `[charge]` blocks (the
  **highest** valid block, since one purchase unlocks the post; the client
  cannot set it), calls `ContentCharge::purchase()`, and redirects back.
  Render, unlock, and the ledger read the same `ContentCharge::priceForMessage()`
  decision. One purchase per (post, member) unlocks every block in that post.
  The author also sees a per-block "Members unlocked: N · total earned"
  line.
* The `charge` button is added once to the editor toolbar (`moreText` group,
  all viewports) by `Setup::syncEditorButton()` on install/upgrade; existing
  placements are left alone, and admins can move or remove it via the BB code
  button manager.

## Conventions for new work

* Never write balances or insert ledger rows outside `Service/LedgerWrite.php`
  (callers reach it through `Transact::ledgerWrite()`; `Transact` still holds
  its legacy `applyBalanceDelta` until the last caller moves). `DemurrageRunner`
  mutates balances only through the module.
* Domain logic that is not an XF service is a plain class under `Service/`
  (`AwardScope`, `AccountStanding`, `InflationClamp`, `LedgerWrite`, `DemurrageRunner`, `ContentCharge`), constructed directly or
  through a `Transact` factory. You do not need `\XF::app()->service()` for it.
* Per-request caches belong to the presentation layer (renderer/controller).
  Service reads stay DB-fresh so moderation and cleanup see deletions
  immediately; `BbCode\Charge` caches its renders, `ContentCharge` queries each
  time.
* Id-list parsing (JSON columns, comma-separated form input) lives in exactly
  one place: `CreditEvent::decodeIdList()`. Do not add local copies.
* New triggers must be unique per (trigger, currency) while active — the admin
  save refuses duplicates because `awardByTrigger()` pays every active row.
* Trigger names, display labels, and the ledger marker strings live in
  `Service/Ledger.php` only. History, admin lists, alerts, and prune read it;
  unknown triggers humanise through `Ledger::label()`.
* New triggers: add a `Ledger::TRIGGER_*` const + `TRIGGER_LABELS` entry (and an
  `eventTriggers()` entry if awardable), seed row in `Setup::seedDefaultEvents()`,
  listener method in `Listener.php`, `_output/code_event_listeners/*.json`,
  version bump + upgrade step.
* New widgets: add the class under `Widget/`, `_output/widget_definitions/{id}.json`
  (only `definition_class`; id comes from the filename), public + admin option
  templates, and the `widget_def.{id}` / `widget_def_desc.{id}` phrases. Version
  bump + upgrade imports it; `_metadata.json` and `_data` are build artifacts.
* New BBCodes: add `_output/bb_codes/{tag}.json` with `bb_code_mode: callback`
  and `callback_class`/`callback_method`, a callback class whose static method
  is `render(array $children, $option, array $tag, array $options, Html $renderer)`
  (use `$renderer->renderSubTree()` for the body and `$options['entity']` for
  the content), plus the `custom_bb_code_title|desc|example|output.{tag}`
  phrases. Version bump + upgrade imports it.
* New `_output` JSON must follow the per-type filename rules — see `docs/XF-DEV-GOTCHAS.md`.
* Run `./scripts/dev.sh fix-perms` after any `xf-make:*` (generators run as root).
* Import new `_output` with a version bump + `xf-addon:upgrade` (not `sync-json`).
