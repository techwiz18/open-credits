# OpenCredits architecture (LLM / contributor reference)

Source of truth: `addon-src/OpenCredits/Credits/`. It is bind-mounted into the
dev forum container at `src/addons/OpenCredits/Credits/` (see `docker-compose.yml`).
The licensed XenForo runtime in `src/` is gitignored and never committed.

## File map

| Path | Role |
|---|---|
| `addon.json` | Add-on meta. Current: 0.11.0-dev / 931. Requires XF 2.3.0+, PHP 8.2+ |
| `Setup.php` | Install/upgrade/uninstall steps. Owns schema + seed data |
| `Listener.php` | All code-event listener callbacks (no business logic) |
| `Service/Transact.php` | **Only place that writes balances.** Row lock + txn + log |
| `Entity/Currency.php`, `Entity/CreditEvent.php`, `Entity/Transaction.php` | Entity structures for the 3 tables |
| `Entity/Charge.php` | Entity structure for paid `[charge]` unlocks |
| `BbCode/Charge.php` | `[charge=X]` callback: paywall gate + locked box |
| `Pub/Controller/Credits.php` | `/credits/` history, `/credits/transfer`, `/credits/transfer-save` |
| `Widget/TopHolders.php` | "Top credit holders" leaderboard widget (top N by balance, one currency, `ocView`-gated) |
| `Cli/Command/Rebuild.php` | `oc-credits:rebuild` CLI (calls the service) |
| `Job/RebuildBalances.php` | Job wrapper around the same service method |
| `Cron/DailyLogin.php` | Stub (daily_login is awarded via `visitor_setup`, not cron) |
| `_output/` | Dev-output JSON/HTML/TXT synced into XF on install/upgrade |

## Database

* `xf_oc_currency(currency_id, title, prefix, suffix, decimals, allow_negative, active, is_primary, visible)`
  Seed: `(1, 'Credits', '$', '', 2, 1, 1, 1, 1)`. Exactly one primary (profile
  main stat + `xf_user.oc_credits` mirror); `visible` currencies list in
  postbit/menu; the profile tab lists all active ones.
* `xf_oc_balance(user_id, currency_id, balance)` — cached per-currency balances
  maintained by `Transact` on every write; missing row means 0. Keyed
  `(currency_id, balance)` (`currency_balance`) so the leaderboard widget is a
  range scan, not a filesort.
* `xf_oc_event(event_id, currency_id, trigger, amount, forum_ids, usergroup_ids, max_per_day, active)`
  Seeded triggers: `thread 5.00`, `post 1.00`, `reaction_received 2.00`,
  `register 10.00`, `daily_login 5.00`.
* `xf_oc_transaction(transaction_id, user_id, currency_id, amount, trigger, content_id, note, log_date)`
  Append-only ledger. **Both `amount` columns are SIGNED** (`->unsigned(false)`);
  XF defaults numerics to unsigned, which rejects debit rows — see GOTCHAS.
* `xf_user.oc_credits DECIMAL(10,2)` — denormalized balance, rebuilt from ledger.
* `xf_oc_charge(charge_id, content_type, content_id, user_id, author_user_id,
  currency_id, amount, charge_date)` — paid unlocks for `[charge]`. Unique per
  `(content_type, content_id, user_id, currency_id, amount)`, so a member never
  pays twice for the same content at the same price.

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
* Transfers always floor at zero; `adjust()` enforces each currency's
  `allow_negative` and refuses unknown users/currencies and inactive
  currencies. Transfer with an unresolvable `currency_id` errors instead of
  falling back.
* Awards skip inactive currencies; registration skips banned/rejected/disabled.
* Daily boundaries default to UTC midnights (`Transact::dayStart()`),
  ignoring forum/user time zones — consistent for all members and ungameable
  by traveling; configurable to board time or a fixed offset via options.
  Registration day skips the daily bonus on the same boundary.
* No clawback on content delete (by design); backfill runs per-event inside a
  transaction, quantizes to currency decimals, skips inactive currencies, and
  site-wide runs exclude already per-member-backfilled users;
  `max_per_day` counts per (event, currency) excluding backfill rows and is
  best-effort under concurrency; no per-day guard races the
  daily award thanks to the user-row lock.

## Frontend

* Route prefix `credits` → `Pub\Controller\Credits`: `actionIndex` (history, paged 20),
  `actionTransfer` (form), `actionTransferSave` (POST, CSRF via `xf:form`).
* Templates: `credits_index`, `credits_transfer` (public).
* Template mods: `oc_credits_postbit` (`message_macros`, after reaction score),
  `oc_credits_account_menu` (`account_visitor_menu`, visitor stats),
  `oc_credits_criteria` (`helper_criteria`, admin trophy/promotion form).
* Permissions (namespace `general`, own interface tab `ocCredits`):
  `ocView` (history), `ocTransfer` (transfer form + save). Default deny;
  install/upgrade auto-allows both for the group titled `Registered`.
* Display options (`ocCredits` option group): `ocShowPostbit`, `ocShowMenu`,
  `ocShowProfileStats`, `ocShowProfileTab` — each template mod gates on its
  option; all default on.
* Tools (`oc-tools` admin route): rebuild (sync service call + result view),
  one-shot historical backfill (lump sums per member per post/thread event,
  guarded by existing `Historical backfill` rows, then rebuild).
* Widget `oc_top_holders` (`Widget`/`TopHolders.php`): top N members by balance
  in one currency (primary by default, or any visible active currency),
  `ocView`-gated, excludes banned/disabled and zero balances. One aggregate
  `xf_oc_balance ⋈ xf_user` query + one user fetch. Templates:
  `oc_widget_top_holders` (public) and `widget_def_options_oc_top_holders`
  (admin options: count + currency).
* `[charge=X]…[/charge]` (`BbCode/Charge.php`) hides post content until the
  reader pays X of the primary currency; authors, staff, and `ocChargeFree`
  holders bypass. Locked content is never rendered into the page. The unlock
  form posts to `/credits/unlock`, which loads the post, checks `canView()`,
  calls `Transact::chargeForContent()`, and redirects back. One payment per
  (post, member, amount) unlocks every block at that price.

## Conventions for new work

* Never write balances outside `Service/Transact.php`.
* New triggers: add seed row in `Setup::seedDefaultEvents()`, listener method in
  `Listener.php`, `_output/code_event_listeners/*.json`, version bump + upgrade step.
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
