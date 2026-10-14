# OpenCredits tests

## Unit (`tests/Unit`, plain PHPUnit, no XenForo boot)

Dependency-free static helpers only. Run from the addon dir inside the
`web` container (host has no PHP):

```bash
docker compose exec -T web bash -c \
  "cd /var/www/html/src/addons/OpenCredits/Credits && ./vendor/bin/phpunit"
```

(composer install first run — see below.)

## Integration (DB-backed service matrix)

`Transact::adjust/transfer/redeem/awardByTrigger`, daily limits, scoping,
dedupe, backfill and rebuild paths are currently verified with scripted CLI
runs against the dev forum (see session notes), cleaned up by exact
transaction id with `rebuildAllBalances()` afterwards. The supported path to
automating these is hampel/xenforo-test-framework v3.x (built for XF 2.3)
with a dedicated test database — not wired yet because it needs its own DB
plus build-time stripping (see `build.json`).

## Composer (dev only, stripped from releases)

```bash
docker compose exec -T web bash -c \
  "cd /var/www/html/src/addons/OpenCredits/Credits && composer install"
```

`vendor/`, `tests/` and `composer.*` never ship: `build.json` excludes
them from `xf-addon:build-release`.
