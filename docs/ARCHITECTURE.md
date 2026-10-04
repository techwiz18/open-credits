# OpenCredits architecture (LLM / contributor reference)

Source of truth: `addon-src/OpenCredits/Credits/`. It is bind-mounted into the
dev forum container at `src/addons/OpenCredits/Credits/` (see `docker-compose.yml`).
The licensed XenForo runtime in `src/` is gitignored and never committed.

## File map

| Path | Role |
|---|---|
| `addon.json` | Add-on meta. Current: 0.7.0 / 700. Requires XF 2.3.0+, PHP 8.2+ |
| `Setup.php` | Install/upgrade/uninstall steps. Owns schema + seed data |
| `Listener.php` | All code-event listener callbacks (no business logic) |
| `Service/Transact.php` | **Only place that writes balances.** Row lock + txn + log |
| `Entity/Currency.php`, `Entity/CreditEvent.php`, `Entity/Transaction.php` | Entity structures for the 3 tables |
| `Pub/Controller/Credits.php` | `/credits/` history, `/credits/transfer`, `/credits/transfer-save` |
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
  maintained by `Transact` on every write; missing row means 0.
* `xf_oc_event(event_id, currency_id, trigger, amount, forum_ids, usergroup_ids, max_per_day, active)`
  Seeded triggers: `thread 5.00`, `post 1.00`, `reaction_received 2.00`,
  `register 10.00`, `daily_login 5.00`.
* `xf_oc_transaction(transaction_id, user_id, currency_id, amount, trigger, content_id, note, log_date)`
  Append-only ledger. **Both `amount` columns are SIGNED** (`->unsigned(false)`);
  XF defaults numerics to unsigned, which rejects debit rows — see GOTCHAS.
* `xf_user.oc_credits DECIMAL(10,2)` — denormalized balance, rebuilt from ledger.

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
Transfers floor at zero and never overdraw; `adjust()` stays unrestricted for
admin tooling. Registration day skips the daily bonus (`register_date` check).
Rebuilds use `Transact::rebuildAllBalances()` (ledger → `xf_oc_balance` rows +
primary mirror in `xf_user.oc_credits`).

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

## Conventions for new work

* Never write balances outside `Service/Transact.php`.
* New triggers: add seed row in `Setup::seedDefaultEvents()`, listener method in
  `Listener.php`, `_output/code_event_listeners/*.json`, version bump + upgrade step.
* New `_output` JSON must follow the per-type filename rules — see `docs/XF-DEV-GOTCHAS.md`.
* Run `./scripts/dev.sh fix-perms` after any `xf-make:*` (generators run as root).
* Import new `_output` with a version bump + `xf-addon:upgrade` (not `sync-json`).
