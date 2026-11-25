#!/usr/bin/env bash
# List bot review comments on a PR, skipping the ones this workspace already
# reviewed. Read-only: it never posts, fixes or edits anything.
#
#   scripts/pr-comments.sh 21              # unseen comments only
#   scripts/pr-comments.sh 21 --all        # everything, ignore the seen list
#   scripts/pr-comments.sh 21 --mark-seen  # remember everything fetched
#
# Workflow and verdict guidance: docs/agents/pr-comments.md.
# The seen list lives in ${PR_COMMENTS_STATE_DIR:-.scratch/pr-comments}/<pr>.seen
set -euo pipefail
cd "$(dirname "$0")/.."

pr="${1:-}"
[[ "$pr" =~ ^[0-9]+$ ]] || {
  echo "usage: scripts/pr-comments.sh <pr-number> [--all|--mark-seen]" >&2
  exit 2
}
mode="${2:-}"
case "$mode" in
  ""|--all|--mark-seen) ;;
  *) echo "usage: scripts/pr-comments.sh <pr-number> [--all|--mark-seen]" >&2; exit 2 ;;
esac

repo=$(gh repo view --json nameWithOwner --jq .nameWithOwner)
state_dir="${PR_COMMENTS_STATE_DIR:-.scratch/pr-comments}"
mkdir -p "$state_dir"
seen="$state_dir/$pr.seen"

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# --slurp keeps paginated pages valid JSON; the reader below flattens them.
gh api --paginate --slurp "repos/$repo/pulls/$pr/comments" > "$tmp/inline.json"
gh api --paginate --slurp "repos/$repo/pulls/$pr/reviews" > "$tmp/reviews.json"
gh api --paginate --slurp "repos/$repo/issues/$pr/comments" > "$tmp/issue.json"

python3 - "$tmp" "$seen" "$mode" "$pr" <<'PY'
import json
import sys

tmp, seen_path, mode, pr = sys.argv[1:5]
sources = (('inline', 'rc', 'path'), ('reviews', 'rv', 'review body'), ('issue', 'ic', 'conversation'))

items = []
for name, kind, where_kind in sources:
    with open(f'{tmp}/{name}.json') as handle:
        pages = json.load(handle)
    for page in pages:
        for comment in page:
            user = comment.get('user') or {}
            body = (comment.get('body') or '').strip()
            if user.get('type') != 'Bot' or not body:
                continue
            if where_kind == 'path':
                where = '%s:%s' % (
                    comment.get('path', '?'),
                    comment.get('line') or comment.get('original_line') or 0,
                )
            else:
                where = where_kind
            items.append({
                'id': '%s:%s' % (kind, comment.get('id')),
                'author': user.get('login', '?'),
                'where': where,
                'body': body,
                'url': comment.get('html_url', ''),
            })

try:
    with open(seen_path) as handle:
        seen_ids = {line.strip() for line in handle if line.strip()}
except FileNotFoundError:
    seen_ids = set()

if mode == '--mark-seen':
    seen_ids.update(item['id'] for item in items)
    with open(seen_path, 'w') as handle:
        handle.write('\n'.join(sorted(seen_ids)) + ('\n' if seen_ids else ''))
    print('marked %d bot comments seen' % len(items))
    sys.exit(0)

if mode != '--all':
    items = [item for item in items if item['id'] not in seen_ids]

if not items:
    print('No new bot review comments.')
    sys.exit(0)

print('# PR #%s — %d %s\n' % (pr, len(items), 'bot comments' if mode == '--all' else 'unseen bot comments'))
for number, item in enumerate(items, 1):
    print('### [%d] %s — %s' % (number, item['author'], item['where']))
    print('id: %s' % item['id'])
    print('url: %s\n' % item['url'])
    print('%s\n' % item['body'])
PY
