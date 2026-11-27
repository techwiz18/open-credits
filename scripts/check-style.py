#!/usr/bin/env python3
"""Catch the formatting slip php -l and PHPStan cannot see: two or more spaces
before an opening brace at the end of a line. The repo's brace convention is
otherwise context-dependent, so this is deliberately narrower than a style
linter."""
import re
import sys
from pathlib import Path

ROOTS = ("addon-src", "qa")
SKIP = {"_output", "_releases", "_data", "vendor"}
BAD = re.compile(r"\S {2,}\{\s*$")


def php_files():
    for root in ROOTS:
        path = Path(root)
        if not path.exists():
            continue
        for file in path.rglob("*.php"):
            if any(part in SKIP for part in file.parts):
                continue
            yield file


def main():
    failures = []
    for file in php_files():
        for number, line in enumerate(file.read_text(encoding="utf-8").splitlines(), 1):
            stripped = line.lstrip()
            if stripped.startswith(("*", "//", "#")):
                continue
            if BAD.search(line):
                failures.append(f"{file}:{number}: multiple spaces before an opening brace")
    if failures:
        print("\n".join(failures), file=sys.stderr)
        return 1
    print("style OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
