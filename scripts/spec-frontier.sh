#!/usr/bin/env bash
# Print a spec issue's startable tickets: open sub-issues with no open blocker
# and no assignee. Read-only; uses the compact issue list, never full bodies.
#
# Usage: scripts/spec-frontier.sh <spec-issue>
# Exit: 0 printed, 2 bad usage, 1 gh failure.
set -euo pipefail
cd "$(dirname "$0")/.."

usage() {
  echo "usage: scripts/spec-frontier.sh <spec-issue>" >&2
  exit "${1:-2}"
}

case "${1:-}" in -h|--help) usage 0 ;; esac
[ "$#" -eq 1 ] || usage
spec=$1

if [ -n "${GH:-}" ]; then
  gh=$GH
elif command -v gh >/dev/null 2>&1; then
  gh=gh
else
  gh="$HOME/.local/bin/gh"
fi
command -v "$gh" >/dev/null 2>&1 || { echo "spec-frontier: gh not found" >&2; exit 1; }

repo=$("$gh" repo view --json nameWithOwner -q .nameWithOwner)
title=$("$gh" issue view "$spec" --json title -q .title)
printf '#%s %s\n\n' "$spec" "$title"

frontier=()
waiting=()
while IFS=$'\t' read -r number title labels assignees blocked; do
  [ -n "$number" ] || continue
  [ "$labels" = "-" ] && labels=
  [ "$assignees" = "-" ] && assignees=
  blocked=${blocked:-0}
  line="#$number"
  [ -n "$labels" ] && line+=" [$labels]"
  line+=" $title"
  if [ "$blocked" -gt 0 ] || [ -n "$assignees" ]; then
    [ "$blocked" -gt 0 ] && line+=" blocked_by=$blocked"
    [ -n "$assignees" ] && line+=" assigned=$assignees"
    waiting+=("$line")
  else
    frontier+=("$line")
  fi
done < <("$gh" api --paginate "repos/$repo/issues/$spec/sub_issues" \
  --jq '.[] | select(.state == "open")
    | [.number, .title,
       ([.labels[].name] | join(",") | if . == "" then "-" else . end),
       ([.assignees[].login] | join(",") | if . == "" then "-" else . end)] | @tsv' \
  | while IFS=$'\t' read -r number title labels assignees; do
      blocked=$("$gh" api "repos/$repo/issues/$number" \
        --jq '.issue_dependencies_summary.blocked_by')
      printf '%s\t%s\t%s\t%s\t%s\n' "$number" "$title" "$labels" "$assignees" "$blocked"
    done | sort -n)

echo "Startable (open, unblocked, unassigned):"
if [ "${#frontier[@]}" -eq 0 ]; then
  echo "  (none)"
else
  printf '  %s\n' "${frontier[@]}"
fi

echo
echo "Blocked or assigned:"
if [ "${#waiting[@]}" -eq 0 ]; then
  echo "  (none)"
else
  printf '  %s\n' "${waiting[@]}"
fi
