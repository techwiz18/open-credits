#!/usr/bin/env bash
# Smoke test for scripts/dev.sh argument handling. No Docker or PHP needed:
# `docker` and `phpunit` are stubbed, and dev.sh runs from a throwaway copy so
# the real src/.qatmp is never synced.
set -euo pipefail
cd "$(dirname "$0")/.."

# Git exports GIT_DIR/GIT_WORK_TREE into hooks; the throwaway repo below must
# resolve its own config, not the checkout that invoked the hook.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE

fail() { echo "FAIL: $*" >&2; exit 1; }

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
repo="$tmp/repo"
log="$tmp/argv"

mkdir -p "$tmp/bin" "$repo/scripts" "$repo/qa/tests" "$repo/src/.qatmp/vendor/bin"
cp scripts/dev.sh "$repo/scripts/dev.sh"
cp qa/phpunit.xml qa/phpunit-integration.xml qa/phpstan.neon \
  qa/composer.json qa/composer.lock qa/seed-dev-content.php "$repo/qa/"
touch "$repo/src/.qatmp/vendor/autoload.php"
printf 'WEB_PORT=8080\n' > "$repo/.env.example"

# A pre-existing sync copy keeps dev.sh on its rsync branch.
cat > "$repo/src/.qatmp/vendor/bin/phpunit" <<'EOF'
#!/usr/bin/env bash
: > "$ARGV_LOG"
for a in "$@"; do printf '%s\n' "$a" >> "$ARGV_LOG"; done
EOF
chmod +x "$repo/src/.qatmp/vendor/bin/phpunit"

# Stand-in for `docker compose exec -T web bash -c <payload>`: runs the payload
# locally, pointing the container path at the throwaway copy.
cat > "$tmp/bin/docker" <<'EOF'
#!/usr/bin/env bash
if [ "${1:-}" = "compose" ] && { [ "${2:-}" = "up" ] || [ "${2:-}" = "ps" ]; }; then
    exit 0
fi
payload="${!#}"
default=/var/www/html/.qatmp
exec bash -c "${payload//$default/$STUB_QATMP}"
EOF
chmod +x "$tmp/bin/docker"

run() {
  PATH="$tmp/bin:$PATH" ARGV_LOG="$log" STUB_QATMP="$repo/src/.qatmp" \
    "$repo/scripts/dev.sh" "$@"
}

# Bare `test` defaults to the unit suite and forwards no arguments.
rm -f "$log"
run test >/dev/null 2>&1 || fail "bare 'test' failed"
[ -f "$log" ] || fail "bare 'test' did not run phpunit"
[ ! -s "$log" ] || fail "bare 'test' forwarded argv: $(tr '\n' ' ' < "$log")"

# A quoted value with a space stays one argument, and stays literal.
marker="$tmp/pwned"
rm -f "$log"
run test unit --exclude-group "a b\$(touch $marker)" >/dev/null 2>&1 \
  || fail "quoted argument run failed"
mapfile -t got < "$log"
[ "${#got[@]}" -eq 2 ] || fail "expected 2 forwarded args, got ${#got[@]}"
[ "${got[0]}" = "--exclude-group" ] || fail "arg 0 was '${got[0]}'"
[ "${got[1]}" = "a b\$(touch $marker)" ] || fail "arg 1 was '${got[1]}'"
[ ! -e "$marker" ] || fail "forwarded argument was executed"

# The composite target refuses arguments it cannot honour, before running.
rm -f "$log"
set +e
out=$(run test check --bogus 2>&1)
rc=$?
set -e
[ "$rc" -eq 2 ] || fail "test check --bogus exited $rc, want 2"
grep -q 'takes no extra arguments' <<<"$out" || fail "unclear message: $out"
[ ! -e "$log" ] || fail "test check ran phpunit despite extra args"

# An unknown kind still reports usage instead of guessing.
set +e
out=$(run test bogus 2>&1)
rc=$?
set -e
[ "$rc" -eq 1 ] || fail "test bogus exited $rc, want 1"
grep -q 'usage: dev.sh test' <<<"$out" || fail "no usage for unknown kind: $out"

# `up` records an isolated compose project in .env for later shells and hooks.
COMPOSE_PROJECT_NAME=open-credits-test WEB_PORT=8083 DB_PORT=33061 PMA_PORT=8084 \
  run up >/dev/null 2>&1 || fail "up failed"
grep -qx 'COMPOSE_PROJECT_NAME=open-credits-test' "$repo/.env" \
  || fail "up did not record the compose project in .env"
grep -qx 'WEB_PORT=8083' "$repo/.env" || fail "up did not record WEB_PORT"
grep -qx 'DB_PORT=33061' "$repo/.env" || fail "up did not record DB_PORT"
grep -qx 'PMA_PORT=8084' "$repo/.env" || fail "up did not record PMA_PORT"

# A later plain `up` keeps the isolated ports instead of reverting to defaults.
run up >/dev/null 2>&1 || fail "plain up failed"
grep -qx 'WEB_PORT=8083' "$repo/.env" || fail "plain up lost the isolated port"

# A caller-supplied value cannot inject the .env rewrite.
marker="$tmp/pwned"
set +e
COMPOSE_PROJECT_NAME="x/;e touch $marker;#" run up >/dev/null 2>&1
rc=$?
set -e
[ "$rc" -eq 2 ] || fail "unsafe project name exited $rc, want 2"
[ ! -e "$marker" ] || fail "project name executed a command"

# Hook install/uninstall state machine, in the throwaway repo so the real
# core.hooksPath is never touched.
git init -q "$repo"
mkdir -p "$repo/.githooks"
cp .githooks/pre-commit .githooks/pre-push "$repo/.githooks/"
chmod -x "$repo/.githooks/pre-commit" "$repo/.githooks/pre-push"

hooks_path() { git -C "$repo" config --get core.hooksPath || true; }
prev_path() { git -C "$repo" config --get opencredits.previousHooksPath || true; }
expect_hooks() {
  [ "$(hooks_path)" = "$1" ] || fail "core.hooksPath is '$(hooks_path)', want '$1'"
}

# A default install points core.hooksPath at .githooks and marks the default.
run install-hooks >/dev/null 2>&1 || fail "install-hooks failed"
expect_hooks .githooks
[ -x "$repo/.githooks/pre-commit" ] || fail "install-hooks left pre-commit non-executable"
[ -x "$repo/.githooks/pre-push" ] || fail "install-hooks left pre-push non-executable"
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

# The artifact checker is shared by CI and the hook: a broken _output JSON must
# fail it, and the optional root keeps the fixture out of the real tree.
art="$tmp/artifacts"
mkdir -p "$art/addon-src/A/_output" "$art/addon-src/OpenCredits/Credits"
printf '{"version_id": 941, "version_string": "0.13.1"}\n' > "$art/addon-src/OpenCredits/Credits/addon.json"
printf '{"ok": true}\n' > "$art/addon-src/A/_output/good.json"
python3 scripts/check-artifacts.py "$art" >/dev/null || fail "artifact check rejected a valid fixture"
printf '{broken\n' > "$art/addon-src/A/_output/bad.json"
set +e
python3 scripts/check-artifacts.py "$art" >/dev/null 2>&1
rc=$?
set -e
[ "$rc" -eq 1 ] || fail "artifact check accepted broken JSON (rc=$rc)"

echo "dev.sh smoke test OK"
