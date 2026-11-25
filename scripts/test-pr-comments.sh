#!/usr/bin/env bash
# Smoke test for scripts/pr-comments.sh: bot-only filtering, the seen list and
# --all. `gh` is stubbed, no network or auth needed.
set -euo pipefail
cd "$(dirname "$0")/.."

fail() { echo "FAIL: $*" >&2; exit 1; }

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/bin" "$tmp/state" "$tmp/fixtures"

cat > "$tmp/bin/gh" <<EOF
#!/usr/bin/env bash
case "\$*" in
  "repo view"*) echo "owner/repo" ;;
  *"/pulls/7/comments"*) cat "$tmp/fixtures/inline.json" ;;
  *"/pulls/7/reviews"*) cat "$tmp/fixtures/reviews.json" ;;
  *"/issues/7/comments"*) cat "$tmp/fixtures/issue.json" ;;
  *) echo "unexpected gh args: \$*" >&2; exit 1 ;;
esac
EOF
chmod +x "$tmp/bin/gh"

# Each file is one --slurp page, so the shape is [ [ comments... ] ].
cat > "$tmp/fixtures/inline.json" <<'EOF'
[[
  {"id": 1, "user": {"login": "sourcery-ai[bot]", "type": "Bot"}, "path": "a.php", "line": 3, "body": "real finding", "html_url": "https://example/1"},
  {"id": 2, "user": {"login": "alice", "type": "User"}, "path": "a.php", "line": 4, "body": "human comment", "html_url": "https://example/2"},
  {"id": 3, "user": {"login": "dependabot[bot]", "type": "Bot"}, "path": "a.php", "line": 5, "body": "", "html_url": "https://example/3"}
]]
EOF

cat > "$tmp/fixtures/reviews.json" <<'EOF'
[[
  {"id": 10, "user": {"login": "sourcery-ai[bot]", "type": "Bot"}, "body": "review summary", "html_url": "https://example/10"},
  {"id": 11, "user": {"login": "alice", "type": "User"}, "body": "", "html_url": "https://example/11"}
]]
EOF

echo '[[]]' > "$tmp/fixtures/issue.json"

run() {
  PATH="$tmp/bin:$PATH" PR_COMMENTS_STATE_DIR="$tmp/state" ./scripts/pr-comments.sh "$@"
}

out=$(run 7)
grep -q 'real finding' <<<"$out" || fail 'inline bot comment missing'
grep -q 'review summary' <<<"$out" || fail 'review body missing'
grep -q 'rc:1' <<<"$out" || fail 'inline id missing'
grep -q 'rv:10' <<<"$out" || fail 'review id missing'
grep -q 'human comment' <<<"$out" && fail 'human comment should be filtered out'
grep -q 'rc:3' <<<"$out" && fail 'empty bot body should be filtered out'

run 7 --mark-seen > /dev/null
out=$(run 7)
grep -q 'No new bot review comments' <<<"$out" || fail 'seen comments should be skipped'

out=$(run 7 --all)
grep -q 'real finding' <<<"$out" || fail '--all should ignore the seen list'

echo 'pr-comments OK'
