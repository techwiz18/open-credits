#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

cmd="${1:-help}"
test_kinds="lint|unit|integration|stan|check|all|seed|clean"
ZIP=$(ls ../xenforo_*.zip 2>/dev/null | head -n 1 || true)

# Tests run from a synced copy under the mounted src/ tree (qa/ itself is not
# mounted in the container). Tests are re-synced every run so a stale copy can
# never report a false green; dev dependencies are installed only when the
# copy is first created.
# Takes one shell string (pipelines allowed) and runs it inside .qatmp.
docker_qatmp_shell() {
  docker compose exec -T web bash -c "cd /var/www/html/.qatmp && $1"
}

# Runs a command inside .qatmp with every argument passed through intact:
# printf %q re-quotes each one, so the inner `bash -c` parses back exactly the
# same argv (a value with spaces stays one argument, a `$(...)` stays literal).
qatmp() {
  local line
  printf -v line '%q ' "$@"
  docker_qatmp_shell "${line:?qatmp: no command given}"
}

qa_integration() {
  qatmp env OC_XF_ROOT=/var/www/html ./vendor/bin/phpunit -c phpunit-integration.xml \
    --display-skipped --fail-on-skipped "$@"
}

# Skips count as failures, but PHPUnit prints them as "OK, but some tests were
# skipped!", which reads like a pass; name the usual cause and the fix.
integration_failed() {
  echo "integration failed. Skips count as failures: the seeded fixtures are" >&2
  echo "missing or stale. Run './scripts/dev.sh test seed' and retry." >&2
  exit 1
}

# A symlinked src/ breaks PHPStan's class discovery under the nested Docker
# binds. Refuse it early with the fix instead of failing the PHP gate.
require_real_src() {
  if [ -L src ]; then
    echo "dev.sh: src/ is a symlink; copy the licensed tree instead —" >&2
    echo "  cp -a ../<worktree-with-src>/src ./src (docs/DEV.md#worktrees-and-the-dev-stack)" >&2
    exit 1
  fi
  for stale in src/addon-src src/addon-test; do
    if [ -e "$stale" ]; then
      echo "dev.sh: $stale is a stale add-on copy inside src/; it shadows the" >&2
      echo "  bind-mounted add-on for PHPStan. Remove it: rm -rf src/addon-src src/addon-test" >&2
      exit 1
    fi
  done
}

seed_fixtures() {
  qatmp env OC_XF_ROOT=/var/www/html php seed-dev-content.php "$1"
}

# Syncs qa/ into the mounted copy; dev dependencies are installed on first use.
sync_qatmp() {
  mkdir -p src
  if [ ! -f src/.qatmp/vendor/autoload.php ]; then
    rm -rf src/.qatmp
    cp -r qa src/.qatmp
    docker_qatmp_shell "composer install --no-interaction --quiet"
  else
    rsync -a --delete qa/tests/ src/.qatmp/tests/
    cp qa/seed-dev-content.php qa/phpunit.xml qa/phpunit-integration.xml \
      qa/phpstan.neon qa/composer.json qa/composer.lock src/.qatmp/
  fi
}

case "$cmd" in
  up)
    require_real_src
    cp -n .env.example .env || true
    # Persist the settings that isolate this stack, so later shells and the
    # hooks address the same project and ports. printf/grep rather than sed:
    # the values are caller input, and sed would evaluate `e` in a replacement.
    for key in COMPOSE_PROJECT_NAME WEB_PORT DB_PORT PMA_PORT; do
      value=$(printenv "$key" || true)
      [ -n "$value" ] || continue
      if [[ ! "$value" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
        echo "dev.sh up: $key has characters Compose cannot use: $value" >&2
        exit 2
      fi
      { grep -v "^$key=" .env || true; printf '%s=%s\n' "$key" "$value"; } > .env.tmp
      mv .env.tmp .env
    done
    docker compose up -d --build
    docker compose ps
    ;;
  down)
    docker compose down
    ;;
  unpack)
    require_real_src
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
  install-hooks)
    # Tracked hook in .githooks; never silently replace someone else's setup.
    shift
    force=0
    for arg in "$@"; do
      case "$arg" in
        --force) force=1 ;;
        *) echo "usage: dev.sh install-hooks [--force]" >&2; exit 2 ;;
      esac
    done
    chmod +x .githooks/pre-commit .githooks/pre-push
    current=$(git config --get core.hooksPath || true)
    if [ "$current" = ".githooks" ]; then
      echo "Git hooks already installed (core.hooksPath=.githooks)."
    elif [ -z "$current" ] || [ "$current" = ".git/hooks" ]; then
      git config opencredits.previousHooksPath __default__
      git config core.hooksPath .githooks
      echo "Git hooks installed from .githooks (previous: default .git/hooks)."
    elif [ "$force" -eq 1 ]; then
      git config opencredits.previousHooksPath "$current"
      git config core.hooksPath .githooks
      echo "Git hooks installed from .githooks (previous: $current)."
    else
      echo "core.hooksPath is already set to '$current'; left untouched." >&2
      echo "Re-run with --force to replace it and record it for uninstall." >&2
      exit 1
    fi
    ;;
  uninstall-hooks)
    current=$(git config --get core.hooksPath || true)
    if [ "$current" != ".githooks" ]; then
      echo "core.hooksPath is '${current:-unset}', not .githooks; nothing to uninstall."
      exit 0
    fi
    previous=$(git config --get opencredits.previousHooksPath || true)
    case "$previous" in
      ""|__default__)
        git config --unset core.hooksPath || true
        echo "Removed core.hooksPath; git uses the default .git/hooks again."
        ;;
      *)
        git config core.hooksPath "$previous"
        echo "Restored core.hooksPath to '$previous'."
        ;;
    esac
    git config --unset opencredits.previousHooksPath || true
    ;;
  test)
    shift                       # the "test" subcommand
    kind="${1:-unit}"
    if [ "$#" -ge 1 ]; then shift; fi
    # Only unit/integration/stan forward extra args to PHPUnit/PHPStan; the
    # composite targets refuse them up front instead of paying for a resync
    # and then silently running everything.
    case "$kind" in
      unit|integration|stan) ;;
      lint|check|all|seed|clean)
        if [ "$#" -gt 0 ]; then
          echo "dev.sh test $kind takes no extra arguments (got: $*)" >&2
          exit 2
        fi
        ;;
    esac
    require_real_src
    mkdir -p src
    # One copy is shared by every test command; serialize runs so a sync or a
    # first-run install cannot race another run's PHPUnit process.
    exec 9>src/.qatmp.lock
    flock -w 300 9
    sync_qatmp
    case "$kind" in
      lint)
        ./scripts/check.sh --docker lint
        ;;
      unit)
        ./scripts/check.sh --docker unit "$@"
        ;;
      integration)
        qa_integration "$@" || integration_failed
        ;;
      stan)
        ./scripts/check.sh --docker stan "$@"
        ;;
      check)
        ./scripts/check.sh --docker check
        ;;
      seed|clean)
        seed_fixtures "$kind"
        ;;
      all)
        ./scripts/check.sh --docker check
        # The content-dependent tests need their fixtures, and the run leaves
        # them consumed; seeding here keeps `test all` green twice in a row.
        seed_fixtures seed
        qa_integration || integration_failed
        ;;
      *)
        echo "usage: dev.sh test {$test_kinds}" >&2
        exit 1
        ;;
    esac
    ;;
  logs)
    docker compose logs -f "${2:-web}"
    ;;
  *)
    cat <<EOF
usage: dev.sh <command> [args]

  up / down / logs    start, stop, or follow the stack (logs: web by default)
  unpack              unzip ../xenforo_*.zip into src/ (first setup)
  enable-debug        add debug/development flags to src/src/config.php
  link-addon          show the mounted addon dir and rebuild the addon
  fix-perms           make _output writable after a generator/import
  install-hooks [--force]
                      install the tracked hooks; --force replaces a
                      different core.hooksPath (recorded for uninstall)
  uninstall-hooks     restore the hooks path recorded at install
  test <kind> [args]  run the suite; kinds: $test_kinds
                      extra args reach PHPUnit/PHPStan for unit, integration
                      and stan; the other kinds reject them
EOF
    ;;
esac
