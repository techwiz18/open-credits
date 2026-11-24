#!/usr/bin/env python3
"""Validate generated artifacts: every _output JSON parses and addon.json has a
version. Shared by the pre-commit hook and public CI so the two cannot drift.

Usage: python3 scripts/check-artifacts.py [root]
Exit: 0 valid, 1 on any invalid artifact.
"""

import glob
import json
import sys
from pathlib import Path

root = Path(sys.argv[1] if len(sys.argv) > 1 else Path(__file__).resolve().parent.parent)

bad = 0
count = 0
# glob follows symlinked directories, so coverage matches the old CI check.
for name in sorted(glob.glob(str(root / 'addon-src/**/_output/**/*.json'), recursive=True)):
    path = Path(name)
    try:
        json.loads(path.read_text(encoding='utf-8'))
        count += 1
    except Exception as exc:
        print(f'{path.relative_to(root)}: {exc}')
        bad = 1

try:
    addon = json.loads((root / 'addon-src/OpenCredits/Credits/addon.json').read_text(encoding='utf-8'))
    if not addon.get('version_id') or not addon.get('version_string'):
        raise ValueError('version_id/version_string missing or empty')
    print(f"addon.json: {addon['version_string']} ({addon['version_id']})")
except Exception as exc:
    print(f'addon.json: {exc}')
    bad = 1

if bad:
    sys.exit(1)
print(f'OK: {count} _output JSON file(s) parsed.')
