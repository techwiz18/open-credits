#!/usr/bin/env bash
# Smoke test for scripts/dev.sh argument handling. No Docker or PHP needed:
# `docker` and `phpunit` are stubbed, and dev.sh runs from a throwaway copy so
# the real src/.qatmp is never synced.
set -euo pipefail
cd "$(dirname "$0")/.."

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

echo "dev.sh smoke test OK"
