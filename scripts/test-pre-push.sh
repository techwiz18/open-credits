#!/usr/bin/env bash
# Smoke test for the pre-push branch-base guard. No network or Docker needed.
set -euo pipefail
cd "$(dirname "$0")/.."

# Git exports GIT_DIR/GIT_WORK_TREE into hooks; the throwaway repo below must
# resolve its own config, not the checkout that invoked the hook.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE

fail() { echo "FAIL: $*" >&2; exit 1; }

source_hook="$PWD/.githooks/pre-push"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
repo="$tmp/repo"

git init -q -b main "$repo"
mkdir -p "$repo/.githooks"
cp "$source_hook" "$repo/.githooks/pre-push"
hook="$repo/.githooks/pre-push"
cd "$repo"
git config user.email "test@example.com"
git config user.name "Test"

git commit --allow-empty -q -m "main"
git update-ref refs/remotes/origin/main HEAD

# A branch based on main passes.
git switch -q -c based-on-main
git commit --allow-empty -q -m "ours"
bash "$hook" origin url >/dev/null 2>&1 || fail "main-based branch was blocked"

# An older main base still passes after main advances.
git switch -q main
git commit --allow-empty -q -m "next"
git update-ref refs/remotes/origin/main HEAD
git switch -q based-on-main
bash "$hook" origin url >/dev/null 2>&1 || fail "older-main base was blocked"

# A branch stacked on another feature tip is refused.
git switch -q -c feature
git commit --allow-empty -q -m "feature"
git update-ref refs/remotes/origin/feature HEAD
git switch -q -c stacked feature
git commit --allow-empty -q -m "stacked"
set +e
out=$(bash "$hook" origin url 2>&1)
rc=$?
set -e
[ "$rc" -ne 0 ] || fail "stacked branch was allowed"
grep -q 'stacked' <<<"$out" || fail "no stack explanation: $out"

# No origin/main means no check, not a blocked push.
git update-ref -d refs/remotes/origin/main
bash "$hook" origin url >/dev/null 2>&1 || fail "missing origin/main blocked the push"

echo "pre-push smoke test OK"
