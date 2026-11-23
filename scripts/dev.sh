#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

cmd="${1:-help}"
ZIP=$(ls ../xenforo_*.zip 2>/dev/null | head -n 1 || true)

# Tests run from a synced copy under the mounted src/ tree (qa/ itself is not
# mounted in the container). Tests are re-synced every run so a stale copy can
# never report a false green; dev dependencies are installed only when the
# copy is first created.
docker_qatmp() {
  docker compose exec -T web bash -c "cd /var/www/html/.qatmp && $1"
}

sync_qatmp() {
  mkdir -p src
  if [ ! -f src/.qatmp/vendor/autoload.php ]; then
    rm -rf src/.qatmp
    cp -r qa src/.qatmp
    docker_qatmp "composer install --no-interaction --quiet"
  else
    rsync -a --delete qa/tests/ src/.qatmp/tests/
    cp qa/seed-dev-content.php qa/phpunit.xml qa/phpunit-integration.xml \
      qa/phpstan.neon qa/composer.json qa/composer.lock src/.qatmp/
  fi
}

# Lints the synced qa tree and the bind-mounted addon tree (the same files
# public CI lints), so a syntax error fails before commit, not after push.
qa_lint() {
  docker_qatmp 'find . -path ./vendor -prune -o -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null' \
    && echo "qa lint OK"
}

addon_lint() {
  docker compose exec -T web bash -c \
    'find /var/www/html/src/addons/OpenCredits/Credits -path "*/_releases" -prune -o -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null' \
    && echo "addon lint OK"
}

case "$cmd" in
  up)
    cp -n .env.example .env || true
    docker compose up -d --build
    docker compose ps
    ;;
  down)
    docker compose down
    ;;
  unpack)
    if [ -z "$ZIP" ]; then echo "No xenforo_*.zip found in parent dir"; exit 1; fi
    mkdir -p src
    echo "Unpacking $ZIP -> src/"
    rm -rf tmp_unpack && mkdir -p tmp_unpack
    unzip -o "$ZIP" -d tmp_unpack >/dev/null
    cp -r tmp_unpack/upload/. src/
    rm -rf tmp_unpack
    chmod -R 0777 src/data src/internal_data || true
    ls src/ | head
    ;;
  enable-debug)
    CFG="src/src/config.php"
    if [ ! -f "$CFG" ]; then echo "Install XF first (src/src/config.php missing)"; exit 1; fi
    grep -q "debug.*true" "$CFG" || cat >> "$CFG" <<'PHP'

$config['debug'] = true;
$config['development']['enabled'] = true;
PHP
    echo "debug enabled in $CFG"
    ;;
  link-addon)
    # addon-src is bind-mounted into the web container (see docker-compose.yml).
    docker compose exec web ls -la /var/www/html/src/addons/OpenCredits/Credits/
    docker compose exec web php /var/www/html/cmd.php xf-addon:rebuild OpenCredits/Credits || true
    ;;
  fix-perms)
    # CLI generators run as root in the container; Apache (www-data) must be
    # able to rewrite _output on dev-mode export (e.g. saving ANY option
    # re-exports option JSONs). Files are 0666 (not 0777): git only tracks the
    # exec bit, so 666 shows as clean 644 while staying www-data-writable.
    # Never chmod _output files to 644 — Apache writes will fail (E_WARNING).
    docker compose exec web bash -c "find /var/www/html/src/addons/OpenCredits/Credits/_output -type d -exec chmod 0777 {} + -o -type f -exec chmod 0666 {} +"
    ;;
  test)
    kind="${2:-unit}"
    mkdir -p src
    # One copy is shared by every test command; serialize runs so a sync or a
    # first-run install cannot race another run's PHPUnit process.
    exec 9>src/.qatmp.lock
    flock -w 300 9
    sync_qatmp
    case "$kind" in
      lint)
        qa_lint
        addon_lint
        ;;
      unit)
        docker_qatmp "OC_ADDON_SRC=/var/www/html/src/addons/OpenCredits/Credits ./vendor/bin/phpunit"
        ;;
      integration)
        docker_qatmp "OC_XF_ROOT=/var/www/html ./vendor/bin/phpunit -c phpunit-integration.xml --display-skipped --fail-on-skipped"
        ;;
      stan)
        docker_qatmp "OC_ADDON_SRC=/var/www/html/src/addons/OpenCredits/Credits ./vendor/bin/phpstan analyse --no-progress /var/www/html/src/addons/OpenCredits/Credits/Service tests/Unit"
        ;;
      seed|clean)
        docker_qatmp "OC_XF_ROOT=/var/www/html php seed-dev-content.php $kind"
        ;;
      all)
        qa_lint
        addon_lint
        docker_qatmp "OC_ADDON_SRC=/var/www/html/src/addons/OpenCredits/Credits ./vendor/bin/phpunit"
        docker_qatmp "OC_XF_ROOT=/var/www/html ./vendor/bin/phpunit -c phpunit-integration.xml --display-skipped --fail-on-skipped"
        docker_qatmp "OC_ADDON_SRC=/var/www/html/src/addons/OpenCredits/Credits ./vendor/bin/phpstan analyse --no-progress /var/www/html/src/addons/OpenCredits/Credits/Service tests/Unit"
        ;;
      *)
        echo "usage: dev.sh test {lint|unit|integration|stan|all|seed|clean}" >&2
        exit 1
        ;;
    esac
    ;;
  logs)
    docker compose logs -f "${2:-web}"
    ;;
  *)
    echo "usage: dev.sh {up|down|unpack|enable-debug|link-addon|fix-perms|test|logs}"
    ;;
esac
