# OpenCredits tests

Tests live in `qa/` (repo root) — deliberately outside `addon-src/` so
release zips stay clean by construction (the builder ships the addon dir
minus `_output`/`_releases`/dotfiles; no exclusion config needed).

## Unit (`qa/tests/Unit`, plain PHPUnit, no XenForo boot)

Dependency-free static helpers only. Host has no PHP, so tests run from a
throwaway copy inside the `web` container (gitignored `src/`):

```bash
rm -rf src/.qatmp && cp -r qa src/.qatmp
docker compose exec -T web bash -c "cd /var/www/html/.qatmp && php -r 'copy(\"https://getcomposer.org/installer\", \"/tmp/cs.php\");' && php /tmp/cs.php --install-dir=/usr/local/bin --filename=composer && composer install && OC_ADDON_SRC=/var/www/html/src/addons/OpenCredits/Credits ./vendor/bin/phpunit"
```

(On any machine with PHP 8.2+ just run `cd qa && composer install && ./vendor/bin/phpunit` — public CI does exactly that.)

## Integration (`qa/tests/Integration`, real XenForo boot)

DB-backed service tests run against the licensed dev forum. The bootstrap
(`tests/integration-bootstrap.php`) boots the real app from `OC_XF_ROOT`; when
that is unset the tests mark themselves skipped, so public CI (no licensed
runtime) stays green.

```bash
rm -rf src/.qatmp && cp -r qa src/.qatmp
docker compose exec -T web bash -c "cd /var/www/html/.qatmp && composer install && OC_XF_ROOT=/var/www/html ./vendor/bin/phpunit -c phpunit-integration.xml"
```

Covered today:
- `PruneHistoryTest` — prune + rebuild keeps balances exact; redeem, backfill,
  and reaction dedupe rows survive; net-zero rows are still pruned; and the
  current earning day is never touched. It writes only under a synthetic user
  id above every real one.
- `ChargeTest` — the `[charge]` service (#5): funds move reader → author, a
  repeat charge (including a changed price) is a no-op, overdrafts are refused,
  and self-charges are free. It uses two real members (the service locks user
  rows) plus a synthetic content id.
- `ChargeAmountsTest` — the unlock endpoint's parser-based price extractor:
  malformed tags, casing, `[code]`/`[code=rich]`, and `[plain]` contents.
- `ChargeRenderTest` — the renderer gates and fail-closed states: guest,
  non-paid/paid member, author stats, admin bypass, inactive currency, and
  invalid price; blocked (banned/email-confirm) payers cannot unlock via the
  controller.
- `BackfillTest` — the historical backfill preview/run query applies the
  event's forum/group scope and skips first posts; the action writes an
  `event_id` marker, is idempotent, refuses a per-member run after a
  site-wide run, and the history row is labelled/unlinked.
- `ChargeCleanupTest` — hard-deleting a post drops its unlock rows; member
  deletion covers both payer and author charge rows.
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
