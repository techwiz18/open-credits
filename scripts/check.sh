#!/usr/bin/env bash
# One check contract. Every check this repo runs is a step here, so a new check
# lands in one edit and cannot miss an entry point. Adapters only choose where
# the step executes: `--docker` (scripts/dev.sh, after it syncs the test copy)
# or the default host-PHP environment (public CI). Host-only steps always run
# on the checkout host. `./scripts/check.sh list` prints the canonical table.
#
# Usage: scripts/check.sh [--docker] <step> [args]
# Exit: 0 pass, 1 failed check, 2 bad usage.
set -euo pipefail
cd "$(dirname "$0")/.."

usage() {
  cat <<'EOF'
usage: scripts/check.sh [--docker] <step> [args]

  lint                  php -l over addon-src and qa (*.php; vendor/_releases skipped)
  unit [args]           PHPUnit unit suite (args reach PHPUnit)
  stan [args]           PHPStan on Service/ + tests/Unit (args reach PHPStan)
  artifacts             _output JSON parses; addon.json has a version
  balance-writes        only the ledger module writes balances
  test-dev              check.sh/dev.sh CLI contract smoke test
  test-pr-comments      pr-comments.sh smoke test
  secrets               gitleaks working-tree scan (skipped when not installed)
  check                 lint + unit + stan
  host                  artifacts + balance-writes + test-dev + secrets
  list                  print the canonical step table
EOF
}

list_steps() {
  cat <<'EOF'
step              runs
lint              php -l addon-src + qa (vendor/_releases skipped) [adapter]
unit [args]       qa/vendor/bin/phpunit [args] [adapter]
stan [args]       phpstan analyse --no-progress Service tests/Unit [args] [adapter]
artifacts         python3 scripts/check-artifacts.py [host]
balance-writes    scripts/check-balance-writes.sh [host]
test-dev          scripts/test-dev.sh [host]
test-pr-comments  scripts/test-pr-comments.sh [host]
secrets           gitleaks dir . (skipped when not installed) [host]
check             lint + unit + stan [adapter]
host              artifacts + balance-writes + test-dev + secrets [host]
list              this table [host]
EOF
}

adapter=host
case "${1:-}" in
  --docker) adapter=docker; shift ;;
  --host) adapter=host; shift ;;
  -h|--help) usage; exit 0 ;;
esac

if [ "$adapter" = docker ]; then
  # Paths inside the web container; dev.sh has already synced qa/ to .qatmp.
  addon=/var/www/html/src/addons/OpenCredits/Credits
  qa=/var/www/html/.qatmp
  lint_roots=(/var/www/html/.qatmp /var/www/html/src/addons/OpenCredits/Credits)
else
  addon="$PWD/addon-src/OpenCredits/Credits"
  qa=qa
  lint_roots=(addon-src qa)
fi

step="${1:-}"
[ "$#" -gt 0 ] && shift

# Runs one shell string where the step executes.
run() {
  if [ "$adapter" = docker ]; then
    docker compose exec -T web bash -c "$1"
  else
    bash -c "$1"
  fi
}

# Re-quotes each argument so the adapter's inner `bash -c` parses back exactly
# the same argv (a value with spaces stays one argument, `$(...)` stays literal).
quote() {
  local out='' arg
  for arg in "$@"; do
    printf -v arg '%q' "$arg"
    out+="$arg "
  done
  printf '%s' "${out% }"
}

step_lint() {
  run "find ${lint_roots[*]} -path '*/vendor' -prune -o -path '*/_releases' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null"
  echo "PHP lint OK (addon-src, qa)"
}

step_unit() {
  run "cd $(printf '%q' "$qa") && env OC_ADDON_SRC=$(printf '%q' "$addon") ./vendor/bin/phpunit $(quote "$@")"
}

step_stan() {
  run "cd $(printf '%q' "$qa") && ./vendor/bin/phpstan analyse --no-progress $(printf '%q' "$addon/Service") tests/Unit $(quote "$@")"
}

step_artifacts() {
  python3 scripts/check-artifacts.py
}

step_balance_writes() {
  ./scripts/check-balance-writes.sh
}

step_test_dev() {
  ./scripts/test-dev.sh
}

step_test_pr_comments() {
  ./scripts/test-pr-comments.sh
}

step_secrets() {
  if command -v gitleaks >/dev/null 2>&1; then
    # `dir` needs gitleaks >= 8.19; older versions fail loudly under set -e.
    gitleaks dir . --no-banner --redact
  else
    echo "check.sh: gitleaks not installed; skipped working-tree secret scan."
  fi
}

step_check() {
  step_lint
  step_unit
  step_stan
}

step_host() {
  step_artifacts
  step_balance_writes
  step_test_dev
  step_secrets
}

case "$step" in
  "") usage >&2; exit 2 ;;
  list) list_steps; exit 0 ;;
  unit|stan) ;;
  lint|artifacts|balance-writes|test-dev|test-pr-comments|secrets|check|host)
    if [ "$#" -gt 0 ]; then
      echo "check.sh $step takes no extra arguments (got: $*)" >&2
      exit 2
    fi
    ;;
  *) usage >&2; echo "check.sh: unknown step '$step'" >&2; exit 1 ;;
esac

# The case statement doubles as dispatch; names are the CLI contract.
case "$step" in
  lint) step_lint ;;
  unit) step_unit "$@" ;;
  stan) step_stan "$@" ;;
  artifacts) step_artifacts ;;
  balance-writes) step_balance_writes ;;
  test-dev) step_test_dev ;;
  test-pr-comments) step_test_pr_comments ;;
  secrets) step_secrets ;;
  check) step_check ;;
  host) step_host ;;
esac
