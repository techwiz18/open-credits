# OpenCredits tests

Tests live in `qa/` (repo root) — deliberately outside `addon-src/` so
release zips stay clean by construction (the builder ships the addon dir
minus `_output`/`_releases`/dotfiles; no exclusion config needed).

## Unit (`qa/tests/Unit`, plain PHPUnit, no XenForo boot)

Dependency-free static helpers only. Host has no PHP, so tests run from a
synced copy inside the `web` container (gitignored `src/.qatmp`):

```bash
./scripts/dev.sh test unit
./scripts/dev.sh test unit --filter LedgerTest   # one file; extra args reach PHPUnit
```

The wrapper re-syncs `qa/tests/` into `.qatmp` before every run (a stale copy
can no longer report a false green) and installs dev dependencies on the first
run. Delete `src/.qatmp` if `qa/composer.json` changes.
`qa/phpunit.xml` sets `failOnEmptyTestSuite`, so a zero-test run fails.

`./scripts/dev.sh test check` runs lint + unit + PHPStan (no seeded content
needed); it is the target of the tracked pre-commit hook. Install it once per
clone with `./scripts/dev.sh install-hooks`.

(On any machine with PHP 8.2+ just run `cd qa && composer install && ./vendor/bin/phpunit` — public CI does exactly that.)

## Assertions

Every assertion pins a value or behaviour. If it would still pass when the
code under test changed, delete it — a tautology buys a green tick and no
coverage.

## Static analysis (`qa/phpstan.neon`, level 5, no XenForo boot)

`php -l` and the unit suite both pass undefined variables silently (PHP
warns; the expression evaluates as intended), so phpstan covers `Service/`
plus `tests/Unit`. XF parents are analysis-only doubles:
`tests/bootstrap.php` owns them (it must stay the single definition —
a second static copy shadows the runtime one and blinds the analysis) and
`tests/phpstan-bootstrap.php` adds the `XF` facade. Run:

```bash
./scripts/dev.sh test stan
```

`./scripts/dev.sh test lint` lints the synced `qa/` tree and the bind-mounted
`addon-src` tree with `php -l` (the same files public CI lints). Use it after
editing the seeder or tests; a syntax error is otherwise only discovered when
the file is executed or after push.

## Integration (`qa/tests/Integration`, real XenForo boot)

DB-backed service tests run against the licensed dev forum. The bootstrap
(`tests/integration-bootstrap.php`) boots the real app from `OC_XF_ROOT`;
without it the framework is not loaded (public CI runs only the unit suite).

```bash
./scripts/dev.sh test seed    # once per dev forum: content-dependent tests
./scripts/dev.sh test integration
./scripts/dev.sh test integration --filter InflationControl   # one file
./scripts/dev.sh test clean   # remove the seeded content again
```

Content-dependent tests (backfill, charge render/unlock, negative-balance)
skip on a bare forum; `test seed` creates three plain members, a
thread/reply, and an inactive 0-decimal currency, and `test clean` removes
them. The wrapper runs integration with `--display-skipped
--fail-on-skipped`, so an unseeded forum fails with the seed hint instead of
passing silently. The suite also runs in random order
(`executionOrder="random"`) so static-cache leaks between tests surface.
Integration-only pins (for example `DemurrageAdaptersTest`, which needs the XF
boot to build a controller) are not covered by public CI; run
`./scripts/dev.sh test all` before merging.

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
- `DemurrageAdaptersTest` — #17 pins the three thin adapters (needs the XF
  boot; no DB writes): the cron budget
  (10s) and interval flag, the admin 25s budget plus `ran = ran || busy`
  verdict mapping (busy reports busy, an interval skip still shows the form),
  and the CLI full-run/no-budget path with busy vs incomplete wording.
- `AwardEligibilityTest` — banned, rejected, and disabled members earn nothing
  from any trigger, while valid and awaiting-approval members earn normally;
  ineligible members also leave no daily-claim marker behind.
- `CreditEventTest` (unit) — the shared id-list decoder: comma-separated,
  JSON, and array inputs dedupe to the same list, so repeated ids cannot slip
  past the exact-duplicate guard.
- `LedgerTest` (unit) — the one trigger-label map: every label, the humanised
  unknown-trigger fallback, the awardable subset, and the stored marker strings
  the prune guard relies on.
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
