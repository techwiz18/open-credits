# OpenCredits tests

Tests live in `qa/` (repo root) — deliberately outside `addon-src/` so
release zips stay clean by construction (the builder ships the addon dir
minus `_output`/`_releases`/dotfiles; no exclusion config needed).

## Unit (`qa/tests/Unit`, plain PHPUnit, no XenForo boot)

Dependency-free static helpers only. Host has no PHP, so tests run from a
throwaway copy inside the `web` container (gitignored `src/`):

```bash
rm -rf src/.qatmp && cp -r qa src/.qatmp
docker compose exec -T web bash -c "cd /var/www/html/.qatmp && composer install && OC_ADDON_SRC=/var/www/html/src/addons/OpenCredits/Credits ./vendor/bin/phpunit"
```

Re-running? Reuse the warm copy — skip the copy/install and run only the
`phpunit` line above. Rebuild `.qatmp` only when `qa/` or the addon changed.
(composer ships in the `web` image; no installer download needed.)

(On any machine with PHP 8.2+ just run `cd qa && composer install && ./vendor/bin/phpunit` — public CI does exactly that.)

## Static analysis (`qa/phpstan.neon`, level 5, no XenForo boot)

`php -l` and the unit suite both pass undefined variables silently (PHP
warns; the expression evaluates as intended), so phpstan covers `Service/`
plus `tests/Unit`. XF parents are analysis-only doubles:
`tests/bootstrap.php` owns them (it must stay the single definition —
a second static copy shadows the runtime one and blinds the analysis) and
`tests/phpstan-bootstrap.php` adds the `XF` facade. Run:

```bash
cd qa && composer install && ./vendor/bin/phpstan analyse --no-progress
```

From the warm container copy the addon path differs, so pass paths
explicitly (the `OC_ADDON_SRC` override is the same as for phpunit):

```bash
docker compose exec -T web bash -c "cd /var/www/html/.qatmp && OC_ADDON_SRC=/var/www/html/src/addons/OpenCredits/Credits ./vendor/bin/phpstan analyse --no-progress /var/www/html/src/addons/OpenCredits/Credits/Service tests/Unit"
```

## Integration (`qa/tests/Integration`, real XenForo boot)

DB-backed service tests run against the licensed dev forum. The bootstrap
(`tests/integration-bootstrap.php`) boots the real app from `OC_XF_ROOT`; when
that is unset the tests mark themselves skipped, so public CI (no licensed
runtime) stays green.

```bash
rm -rf src/.qatmp && cp -r qa src/.qatmp
docker compose exec -T web bash -c "cd /var/www/html/.qatmp && composer install && OC_XF_ROOT=/var/www/html ./vendor/bin/phpunit -c phpunit-integration.xml"
```

Same warm-copy rule as unit: re-run with only the `phpunit` line unless
`qa/` or the addon changed. Content-dependent tests (backfill, charge-render,
negative-balance) skip cleanly on a bare forum — seed with
`qa/seed-dev-content.php` (below) when you need them to execute.

Covered today:
- `PruneHistoryTest` — prune + rebuild keeps balances exact; redeem, backfill,
  and reaction dedupe rows survive; net-zero rows are still pruned; and the
  current earning day is never touched. It writes only under a throwaway
  member (the rebuild only counts live members, so synthetic ids would never
  rebuild).
- `ChargeTest` — the `[charge]` service (#5): funds move reader → author, a
  repeat charge (including a changed price) is a no-op, overdrafts are refused,
  and self-charges are free. It uses two real members (the service locks user
  rows) plus a synthetic content id.
- `ChargeAmountsTest` — the unlock endpoint's parser-based price extractor:
  malformed tags, casing, `[code]`/`[code=rich]`, `[plain]` contents, and
  `maxAmount` picking the highest valid block.
- `ContentChargeTest` (unit) — the pure [charge] price rules: highest valid
  block wins, and non-positive, over-precise (at the currency's decimals), and
  malformed blocks are ignored.
- `ChargeRenderTest` — the renderer gates and fail-closed states: guest,
  non-paid/paid member, author stats, admin bypass, inactive currency,
  invalid price, and two-price posts using the highest price; blocked
  (banned/email-confirm) payers cannot unlock via the controller.
- `ChargeUnlockTest` — a crafted cheap `amount` still charges the post's
  highest valid `[charge]` block.
- `BackfillTest` — the historical backfill preview/run query applies the
  event's forum/group scope and skips first posts; the action writes an
  `event_id` marker, is idempotent, refuses a per-member run after a
  site-wide run, and the history row is labelled/unlinked.
- `ChargeCleanupTest` — hard-deleting a post drops its unlock rows; member
  deletion covers both payer and author charge rows.
- `InflationMathTest` (unit) — pure cap/faucet/demurrage math: partial fills,
  sub-unit headroom flooring on 0-decimal currencies, floor-at-zero, rounding.
- `InflationControlTest` — #8 against the dev forum under a synthetic primary
  currency: cap fill/skip + admin bypass, faucet clamp + redeem counting,
  duplicate-event and event-toggle faucet stability, custom-trigger sharing,
  redeem cap/faucet rejection without consuming the code, secondary-currency
  bypass, disabled no-op, at-cap daily-login claim markers, resumable/forced
  demurrage with mirror + ledger checks, concurrent-pass busy (single step and
  multi-batch runner), token-checked cursor advance/completion, time-budget
  stop then cursor resume, interval skip vs busy, mid-pass disable stop,
  primary-switch `last_run` seed, orphan purge, 0-decimal
  headroom flooring, and stale faucet/claim pruning.
- `AwardEligibilityTest` — banned, rejected, and disabled members earn nothing
  from any trigger, while valid and awaiting-approval members earn normally;
  ineligible members also leave no daily-claim marker behind.
- `CreditEventTest` (unit) — the shared id-list decoder: comma-separated,
  JSON, and array inputs dedupe to the same list, so repeated ids cannot slip
  past the exact-duplicate guard.
- `MemberCreditsTest` — the wallet page (pagination 10 by default with per-page links, currency switcher,
  retired transfer/redeem redirects) and the per-member profile Credits tab
  (balances; send form for other members; no history for self; single-currency self tab uses the wallet).

All clean up in `tearDown()`.

Why not hampel/xenforo-test-framework? For XF 2.3 on PHP 8.2 it requires
framework v3.x, which pins PHPUnit 10 (the harness here is on 11) and is a
mocking unit-test tool, not a DB-integration one. This suite keeps PHPUnit 11,
adds no dependency, and still cannot run in public CI either way (licensed
runtime), so it is wired into the local release checklist instead.

## Composer (dev only, never shipped)

`qa/vendor/` is gitignored. `qa/composer.json` requires only `phpunit`
for now.
