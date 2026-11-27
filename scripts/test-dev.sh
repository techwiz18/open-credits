#!/usr/bin/env bash
# Smoke test for the check contract: scripts/check.sh owns every step,
# scripts/dev.sh delegates through the docker adapter, and the pre-commit hook
# install state machine. No Docker or PHP needed: `docker`, `php`, `gitleaks`,
# `phpunit` and `phpstan` are stubbed, and everything runs from a throwaway
# copy so the real src/.qatmp is never synced.
set -euo pipefail
cd "$(dirname "$0")/.."

# Git exports GIT_DIR/GIT_WORK_TREE to hooks. The fixture repos below must not
# inherit them, or git would read and write the real checkout's config.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY GIT_COMMON_DIR \
  GIT_PREFIX GIT_NAMESPACE

fail() { echo "FAIL: $*" >&2; exit 1; }

bash -n scripts/spec-frontier.sh || fail "spec-frontier.sh has a syntax error"

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
repo="$tmp/repo"
argv_log="$tmp/argv"
stan_log="$tmp/stan"
php_log="$tmp/php"
gitleaks_log="$tmp/gitleaks"

mkdir -p "$tmp/bin" "$repo/scripts" "$repo/qa/tests/Unit" "$repo/qa/vendor/bin" \
  "$repo/addon-src/OpenCredits/Credits/Service" \
  "$repo/addon-src/OpenCredits/Credits/_output" \
  "$repo/src/.qatmp/vendor/bin"
cp scripts/check.sh scripts/dev.sh scripts/check-artifacts.py \
  scripts/check-balance-writes.sh scripts/check-style.py "$repo/scripts/"
cp qa/phpunit.xml qa/phpunit-integration.xml qa/phpstan.neon \
  qa/composer.json qa/composer.lock qa/seed-dev-content.php "$repo/qa/"
chmod +x "$repo/scripts/check.sh" "$repo/scripts/dev.sh"
touch "$repo/src/.qatmp/vendor/autoload.php"

printf '{"version_id": 942, "version_string": "0.14.0"}\n' \
  > "$repo/addon-src/OpenCredits/Credits/addon.json"
printf '{"ok": true}\n' > "$repo/addon-src/OpenCredits/Credits/_output/good.json"
printf '<?php function good(): void {}\n' > "$repo/qa/tests/Unit/Good.php"
printf '<?php function good(): void {}\n' > "$repo/addon-src/OpenCredits/Credits/Service/Good.php"

# php -l stand-in: only files containing "syntax error" fail; logs every file.
cat > "$tmp/bin/php" <<'EOF'
#!/usr/bin/env bash
[ "$1" = "-l" ] || { echo "php stub: expected -l, got $*" >&2; exit 127; }
printf '%s\n' "$2" >> "${PHP_LOG:?}"
grep -q 'syntax error' "$2" && { echo "PHP Parse error in $2" >&2; exit 255; }
exit 0
EOF

cat > "$tmp/bin/gitleaks" <<'EOF'
#!/usr/bin/env bash
: > "${GITLEAKS_LOG:?}"
for a in "$@"; do printf '%s\n' "$a" >> "$GITLEAKS_LOG"; done
EOF

# Stand-in for `docker compose exec -T web bash -c <payload>`: runs the payload
# locally, pointing the container paths at the throwaway copy.
cat > "$tmp/bin/docker" <<'EOF'
#!/usr/bin/env bash
payload="${!#}"
payload="${payload//\/var\/www\/html\/.qatmp/$STUB_QATMP}"
payload="${payload//\/var\/www\/html\/src\/addons\/OpenCredits\/Credits/$STUB_ADDON}"
exec bash -c "$payload"
EOF
chmod +x "$tmp/bin/php" "$tmp/bin/gitleaks" "$tmp/bin/docker"

# phpunit/phpstan stand-ins log their argv so forwarding can be asserted.
cat > "$repo/qa/vendor/bin/phpunit" <<'EOF'
#!/usr/bin/env bash
: > "${ARGV_LOG:?}"
printf 'RUN\n' >> "$ARGV_LOG"
for a in "$@"; do printf '%s\n' "$a" >> "$ARGV_LOG"; done
EOF
cat > "$repo/qa/vendor/bin/phpstan" <<'EOF'
#!/usr/bin/env bash
: > "${STAN_LOG:?}"
printf 'RUN\n' >> "$STAN_LOG"
for a in "$@"; do printf '%s\n' "$a" >> "$STAN_LOG"; done
EOF
cp "$repo/qa/vendor/bin/phpunit" "$repo/qa/vendor/bin/phpstan" "$repo/src/.qatmp/vendor/bin/"
chmod +x "$repo/qa/vendor/bin/phpunit" "$repo/qa/vendor/bin/phpstan" \
  "$repo/src/.qatmp/vendor/bin/phpunit" "$repo/src/.qatmp/vendor/bin/phpstan"

clear_logs() { rm -f "$argv_log" "$stan_log" "$php_log"; : > "$php_log"; }

run_check() {
  PATH="$tmp/bin:$PATH" ARGV_LOG="$argv_log" STAN_LOG="$stan_log" \
    PHP_LOG="$php_log" GITLEAKS_LOG="$gitleaks_log" \
    "$repo/scripts/check.sh" "$@"
}

run() {
  PATH="$tmp/bin:$PATH" ARGV_LOG="$argv_log" STAN_LOG="$stan_log" \
    PHP_LOG="$php_log" GITLEAKS_LOG="$gitleaks_log" \
    STUB_QATMP="$repo/src/.qatmp" STUB_ADDON="$repo/addon-src/OpenCredits/Credits" \
    "$repo/scripts/dev.sh" "$@"
}

# --- check.sh CLI contract ------------------------------------------------

run_check --help | grep -q 'usage: scripts/check.sh' || fail "--help prints no usage"

run_check list > "$tmp/list"
for step in lint style unit stan artifacts balance-writes test-dev test-pr-comments \
            secrets check host list; do
  grep -qE "^${step}([[:space:]]|$)" "$tmp/list" || fail "step table omits $step"
done

# Entry points may only call steps the contract defines (a typo must not turn
# into a silently skipped check).
for step in $(grep -hE '^[[:space:]]*(run:[[:space:]]+)?\./scripts/check\.sh ' \
    .githooks/pre-commit .github/workflows/lint.yml scripts/dev.sh \
    | grep -oE 'check\.sh (--docker )?[a-z-]+' | awk '{print $NF}'); do
  grep -qE "^${step}([[:space:]]|$)" "$tmp/list" \
    || fail "entry point calls unknown check step '$step'"
done

set +e
out=$(run_check bogus 2>&1)
rc=$?
set -e
[ "$rc" -eq 1 ] || fail "unknown step exited $rc, want 1"
grep -q 'unknown step' <<<"$out" || fail "unknown step message unclear: $out"

# Composite steps take no arguments, and refuse them before running anything.
clear_logs
set +e
out=$(run_check check --bogus 2>&1)
rc=$?
set -e
[ "$rc" -eq 2 ] || fail "check --bogus exited $rc, want 2"
grep -q 'takes no extra arguments' <<<"$out" || fail "unclear refusal: $out"
[ ! -e "$argv_log" ] || fail "check --bogus ran phpunit despite extra args"

# The lint file set is owned here: qa + addon-src, vendor/_releases skipped.
clear_logs
mkdir -p "$repo/addon-src/OpenCredits/Credits/_releases"
printf '<?php syntax error\n' > "$repo/qa/vendor/Bad.php"
printf '<?php syntax error\n' > "$repo/addon-src/OpenCredits/Credits/_releases/Bad.php"
run_check lint >/dev/null || fail "lint rejected the skipped file sets"
grep -q 'qa/tests/Unit/Good.php' "$php_log" || fail "lint skipped the qa tree"
grep -q 'addon-src/OpenCredits/Credits/Service/Good.php' "$php_log" \
  || fail "lint skipped the addon tree"

printf '<?php syntax error\n' > "$repo/qa/tests/Unit/Bad.php"
set +e
run_check lint >/dev/null 2>&1
rc=$?
set -e
[ "$rc" -ne 0 ] || fail "lint accepted a broken qa file"
rm -f "$repo/qa/tests/Unit/Bad.php"

printf '<?php syntax error\n' > "$repo/addon-src/OpenCredits/Credits/Service/Bad.php"
set +e
run_check lint >/dev/null 2>&1
rc=$?
set -e
[ "$rc" -ne 0 ] || fail "lint accepted a broken addon file"
rm -f "$repo/addon-src/OpenCredits/Credits/Service/Bad.php"

# Style owns the one mechanical slip php -l cannot see.
run_check style >/dev/null || fail "style rejected a clean fixture"
printf '<?php function bad(): void  {\n}\n' > "$repo/qa/tests/Unit/Bad.php"
set +e
run_check style >/dev/null 2>&1
rc=$?
set -e
[ "$rc" -eq 1 ] || fail "style accepted double spaces before a brace (rc=$rc)"
rm -f "$repo/qa/tests/Unit/Bad.php"

# A quoted value with a space stays one argument, and stays literal.
marker="$tmp/pwned"
clear_logs
run_check unit --exclude-group "a b\$(touch $marker)" >/dev/null \
  || fail "unit forwarding run failed"
mapfile -t got < "$argv_log"
[ "${#got[@]}" -eq 3 ] || fail "unit forwarded ${#got[@]} lines, want RUN + 2 args"
[ "${got[0]}" = "RUN" ] || fail "unit stub did not run"
[ "${got[1]}" = "--exclude-group" ] || fail "unit arg 0 was '${got[1]}'"
[ "${got[2]}" = "a b\$(touch $marker)" ] || fail "unit arg 1 was '${got[2]}'"
[ ! -e "$marker" ] || fail "unit forwarded argument was executed"

clear_logs
run_check stan --debug >/dev/null || fail "stan forwarding run failed"
mapfile -t got < "$stan_log"
[ "${#got[@]}" -ge 1 ] && [ "${got[-1]}" = "--debug" ] \
  || fail "stan did not forward --debug: ${got[*]}"

# `check` is lint + style + unit + stan, over both lint trees.
clear_logs
rm -f "$argv_log" "$stan_log"
run_check check >/dev/null || fail "check failed on a clean fixture"
grep -q 'qa/tests/Unit/Good.php' "$php_log" || fail "check linted no qa files"
grep -q 'addon-src/OpenCredits/Credits/Service/Good.php' "$php_log" \
  || fail "check linted no addon files"
[ -s "$argv_log" ] || fail "check ran no unit suite"
[ -s "$stan_log" ] || fail "check ran no phpstan"

printf '<?php function bad(): void  {\n}\n' > "$repo/qa/tests/Unit/Bad.php"
set +e
run_check check >/dev/null 2>&1
rc=$?
set -e
[ "$rc" -eq 1 ] || fail "check skipped the style step (rc=$rc)"
rm -f "$repo/qa/tests/Unit/Bad.php"

# Host steps are wired, not re-spelled.
clear_logs
run_check artifacts >/dev/null || fail "artifacts step rejected a valid tree"
printf '{broken\n' > "$repo/addon-src/OpenCredits/Credits/_output/bad.json"
set +e
run_check artifacts >/dev/null 2>&1
rc=$?
set -e
[ "$rc" -eq 1 ] || fail "artifacts step accepted broken JSON (rc=$rc)"
rm -f "$repo/addon-src/OpenCredits/Credits/_output/bad.json"

clear_logs
run_check balance-writes >/dev/null || fail "balance-writes rejected a clean tree"
printf '<?php $db->query("UPDATE xf_oc_balance SET balance = 1");\n' \
  > "$repo/addon-src/OpenCredits/Credits/Service/Other.php"
set +e
run_check balance-writes >/dev/null 2>&1
rc=$?
set -e
[ "$rc" -eq 1 ] || fail "balance-writes accepted a direct write (rc=$rc)"
rm -f "$repo/addon-src/OpenCredits/Credits/Service/Other.php"

clear_logs
run_check secrets >/dev/null || fail "secrets step failed"
grep -q '^dir$' "$gitleaks_log" || fail "secrets did not scan the working tree"
grep -q '^\.$' "$gitleaks_log" || fail "secrets did not scan the repo root"

# --- dev.sh is the docker adapter -----------------------------------------

# Bare `test` defaults to the unit suite and forwards no arguments.
clear_logs
run test >/dev/null 2>&1 || fail "bare 'test' failed"
[ -f "$argv_log" ] || fail "bare 'test' did not run phpunit"
[ "$(tr '\n' ' ' < "$argv_log")" = "RUN " ] \
  || fail "bare 'test' forwarded argv: $(tr '\n' ' ' < "$argv_log")"

# Forwarding survives the dev.sh -> check.sh --docker -> inner bash path.
clear_logs
run test unit --exclude-group "a b\$(touch $marker)" >/dev/null 2>&1 \
  || fail "docker-adapter forwarding run failed"
mapfile -t got < "$argv_log"
[ "${#got[@]}" -eq 3 ] || fail "docker adapter forwarded ${#got[@]} lines, want RUN + 2 args"
[ "${got[0]}" = "RUN" ] || fail "docker unit stub did not run"
[ "${got[2]}" = "a b\$(touch $marker)" ] || fail "docker arg 1 was '${got[2]}'"
[ ! -e "$marker" ] || fail "docker-forwarded argument was executed"

# `test check` still composes qa lint + addon lint + unit + phpstan.
clear_logs
rm -f "$argv_log" "$stan_log"
run test check >/dev/null 2>&1 || fail "test check failed on a clean fixture"
grep -q '.qatmp/tests/Unit/Good.php' "$php_log" || fail "test check linted no qa files"
grep -q 'addon-src/OpenCredits/Credits/Service/Good.php' "$php_log" \
  || fail "test check linted no addon files"
[ -s "$argv_log" ] || fail "test check ran no unit suite"
[ -s "$stan_log" ] || fail "test check ran no phpstan"

# The composite target refuses arguments it cannot honour, before running.
clear_logs
set +e
out=$(run test check --bogus 2>&1)
rc=$?
set -e
[ "$rc" -eq 2 ] || fail "test check --bogus exited $rc, want 2"
grep -q 'takes no extra arguments' <<<"$out" || fail "unclear message: $out"
[ ! -e "$argv_log" ] || fail "test check ran phpunit despite extra args"

# An unknown kind still reports usage instead of guessing.
set +e
out=$(run test bogus 2>&1)
rc=$?
set -e
[ "$rc" -eq 1 ] || fail "test bogus exited $rc, want 1"
grep -q 'usage: dev.sh test' <<<"$out" || fail "no usage for unknown kind: $out"

# Hook install/uninstall state machine, in the throwaway repo so the real
# core.hooksPath is never touched.
git init -q "$repo"
mkdir -p "$repo/.githooks"
cp .githooks/pre-commit "$repo/.githooks/pre-commit"

hooks_path() { git -C "$repo" config --get core.hooksPath || true; }
prev_path() { git -C "$repo" config --get opencredits.previousHooksPath || true; }
expect_hooks() {
  [ "$(hooks_path)" = "$1" ] || fail "core.hooksPath is '$(hooks_path)', want '$1'"
}

# A default install points core.hooksPath at .githooks and marks the default.
run install-hooks >/dev/null 2>&1 || fail "install-hooks failed"
expect_hooks .githooks
[ "$(prev_path)" = __default__ ] || fail "install recorded prior '$(prev_path)', want __default__"

# A pre-existing foreign path is left untouched without --force.
git -C "$repo" config core.hooksPath .custom-hooks
set +e
out=$(run install-hooks 2>&1)
rc=$?
set -e
[ "$rc" -ne 0 ] || fail "install-hooks clobbered a foreign hooks path"
expect_hooks .custom-hooks
[ "$(prev_path)" = __default__ ] || fail "refused install rewrote the marker to '$(prev_path)'"
grep -q -- --force <<<"$out" || fail "refusal did not mention --force: $out"

# --force replaces it and records it; uninstall restores it.
run install-hooks --force >/dev/null 2>&1 || fail "install-hooks --force failed"
expect_hooks .githooks
[ "$(prev_path)" = .custom-hooks ] || fail "--force recorded '$(prev_path)', want .custom-hooks"
run uninstall-hooks >/dev/null 2>&1 || fail "uninstall-hooks failed"
expect_hooks .custom-hooks
[ -z "$(prev_path)" ] || fail "uninstall left marker '$(prev_path)'"

# Default install followed by uninstall unsets core.hooksPath entirely.
git -C "$repo" config --unset core.hooksPath
run install-hooks >/dev/null 2>&1 || fail "second default install failed"
expect_hooks .githooks
run uninstall-hooks >/dev/null 2>&1 || fail "second uninstall-hooks failed"
expect_hooks ""
[ -z "$(prev_path)" ] || fail "uninstall left marker '$(prev_path)'"

# Uninstalling when nothing was installed is a no-op.
run uninstall-hooks >/dev/null 2>&1 || fail "uninstall without install was not a no-op"

echo "check contract smoke test OK"
