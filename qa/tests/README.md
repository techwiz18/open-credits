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

## Integration (DB-backed service matrix)

`Transact::adjust/transfer/redeem/awardByTrigger`, daily limits, scoping,
dedupe, backfill and rebuild paths are currently verified with scripted CLI
runs against the dev forum (see session notes), cleaned up by exact
transaction id with `rebuildAllBalances()` afterwards. The supported path to
automating these is hampel/xenforo-test-framework v3.x (built for XF 2.3)
with a dedicated test database.

## Composer (dev only, never shipped)

`qa/vendor/` is gitignored. `qa/composer.json` requires only `phpunit`
for now.
