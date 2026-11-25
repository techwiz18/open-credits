#!/usr/bin/env bash
# Guardrail: balances are written only by Service/LedgerWrite.php (legacy
# callers still go through Service/Transact.php, plus one-time Setup
# migrations). See docs/ARCHITECTURE.md "Conventions for new work".
set -euo pipefail
cd "$(dirname "$0")/.."

violations=$(grep -rnE \
  '(INSERT[[:space:]]+INTO|UPDATE|DELETE[[:space:]]+FROM)[[:space:]]+xf_oc_balance|UPDATE[[:space:]]+xf_user[[:space:]]+SET[[:space:]]+oc_credits' \
  addon-src --include='*.php' \
  | grep -vE '/Service/(Transact|LedgerWrite)\.php:|/Setup\.php:' || true)

if [ -n "$violations" ]; then
  echo "$violations"
  echo "Direct balance writes are only allowed in Service/LedgerWrite.php," >&2
  echo "Service/Transact.php (legacy callers), or a Setup migration." >&2
  exit 1
fi

echo "OK: no balance writes outside Service/LedgerWrite.php / Setup.php."
