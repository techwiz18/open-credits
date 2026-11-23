#!/usr/bin/env bash
# Guardrail: balances are written only by Service/Transact.php (and one-time
# Setup migrations). See docs/ARCHITECTURE.md "Conventions for new work".
set -euo pipefail
cd "$(dirname "$0")/.."

violations=$(grep -rnE \
  '(INSERT[[:space:]]+INTO|UPDATE|DELETE[[:space:]]+FROM)[[:space:]]+xf_oc_balance|UPDATE[[:space:]]+xf_user[[:space:]]+SET[[:space:]]+oc_credits' \
  addon-src --include='*.php' \
  | grep -vE '/Service/Transact\.php:|/Setup\.php:' || true)

if [ -n "$violations" ]; then
  echo "$violations"
  echo "Direct balance writes are only allowed in Service/Transact.php (or a Setup migration)." >&2
  exit 1
fi

echo "OK: no balance writes outside Service/Transact.php / Setup.php."
