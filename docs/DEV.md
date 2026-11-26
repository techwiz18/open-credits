# Development setup (Docker)

For forum-owner install instructions, see `../README.md`. This page is for
contributors running the dev stack on Linux.

## Requirements

Docker 29+ and Compose 2.40+ (`docker compose version`). No local PHP/MySQL needed.
A XenForo 2.3.x full zip is required for the runtime but is **never committed**
(`src/` is gitignored; you must supply your own licensed copy for dev only).

## First run

```bash
cp /path/to/xenforo_2.3.x.zip ../xenforo_dev.zip   # parent dir, any xf 2.3 zip
./scripts/dev.sh up        # build + start web/db/phpmyadmin
./scripts/dev.sh unpack    # unzips ../xenforo_*.zip into src/
# open http://localhost:8080/install — DB host: db, name: xf_dev, user: xf, pass: opencredits
./scripts/dev.sh enable-debug
```

The addon source (`addon-src/OpenCredits/Credits/`) is bind-mounted live into the
container; edits apply on next page load (templates recompile automatically).

## Git hooks

`./scripts/dev.sh install-hooks` points `core.hooksPath` at `.githooks/` so the
tracked `pre-commit` hook runs on every commit. It runs:

* `scripts/check-balance-writes.sh` — the ledger guardrail (also in CI).
* `scripts/test-dev.sh` — dev.sh argument smoke test (also in CI).
* `scripts/check-artifacts.py` — every `_output` JSON parses and `addon.json`
  has a version (the exact script public CI runs).
* `gitleaks dir .` — secret scan of the working tree when `gitleaks` is
  installed; prints a one-line skip note when it is not.
* `./scripts/dev.sh test check` — PHP lint, unit tests, PHPStan, only when the
  Docker `web` container is running (skipped with a note otherwise).

Required host tools: `bash`, `python3`, `git`. Optional: `docker compose` (the
PHP checks) and `gitleaks` ≥ 8.19 (the secret scan; older versions lack the
`dir` subcommand and fail the hook loudly). `.gitleaks.toml` allowlists the
unshipped runtime/build trees so the scan stays fast; tracked and untracked
project files are still scanned.

* Checks read the **working tree**, not the staged index: a partially staged
  file is checked as it sits on disk, not as it will be committed.
* `git commit --no-verify` bypasses the hook for one commit — useful for WIP or
  while the stack is down; run `./scripts/dev.sh test check` before pushing.
* `install-hooks` leaves a different `core.hooksPath` untouched and exits
  non-zero. `install-hooks --force` replaces it and records the old value in
  `opencredits.previousHooksPath` (sentinel `__default__` when it was unset).
* `./scripts/dev.sh uninstall-hooks` restores the recorded path, or unsets
  `core.hooksPath` (back to `.git/hooks`) when the old value was the default.

## Useful commands

| Command | Purpose |
|---|---|
| `./scripts/dev.sh help` | every command and test kind |
| `./scripts/dev.sh up/down/logs` | lifecycle |
| `./scripts/dev.sh fix-perms` | **run after any `xf-make:*`** — generators run as root; Apache needs writable `_output` |
| `./scripts/dev.sh install-hooks [--force]` | install the tracked pre-commit hook (see [Git hooks](#git-hooks)) |
| `./scripts/dev.sh uninstall-hooks` | restore the hooks path recorded at install |
| `./scripts/dev.sh test check` | CI parity without integration: lint, unit, PHPStan |
| `docker compose exec web php /var/www/html/cmd.php xf-addon:upgrade OpenCredits/Credits` | import new `_output` (bump version first) |
| `docker compose exec web php /var/www/html/cmd.php oc-credits:rebuild` | rebuild balances from ledger |
| `docker compose exec web php /var/www/html/cmd.php oc-credits:demurrage --preview` | preview the next demurrage pass |
| `docker compose exec -T web php /var/www/html/cmd.php <cmd>` | non-interactive (pipe `y` for confirms) |

* Forum: http://localhost:8080 · phpMyAdmin: http://localhost:8081 (root/rootpass)
* Port clash (something else on 8080)? Copy `.env.example` to `.env` and set
  `WEB_PORT=8082` — the compose file honors it, defaulting to 8080.
* Parallel agent session? Isolate the whole stack — project name, all ports,
  and (consequently) a separate DB volume needing its own forum install:
  `COMPOSE_PROJECT_NAME=open-credits-b WEB_PORT=8083 DB_PORT=33061 PMA_PORT=8084 docker compose up -d`.
  Record it in `HANDOFF.md` open threads so sessions don't chase each other's
  containers. Claim the stack there before seeding or running integration
  tests, and release it when done.
* `src/src/config.php` holds dev DB creds + `debug`/`development.enabled` (gitignored).

## Release checklist (local — needs the licensed XF runtime, so not in CI)

Public CI covers `php -l` (8.2/8.3/8.4), `_output` JSON validity, gitleaks, and
the balance-write guardrail (`scripts/check-balance-writes.sh`). Everything
below runs on the dev stack before any release:

```bash
docker compose exec -T web php /var/www/html/cmd.php xf-addon:validate-json OpenCredits/Credits
./scripts/dev.sh test seed        # content-dependent integration tests
./scripts/dev.sh test unit
./scripts/dev.sh test integration
./scripts/dev.sh test stan
./scripts/dev.sh test lint
docker compose exec -T web php /var/www/html/cmd.php xf-addon:build-release OpenCredits/Credits
# release notes (docs/adr/0003-what-1-0-promises.md):
#   Breaking (forum owners) - data meaning changes, manual admin action
#   Breaking (integrators)  - API, event, criteria, or stable-surface ids
#   Behavior changes        - fixes that restore documented behaviour
#   Fixes                   - everything else
# fresh-install smoke on a scratch copy, then upgrade smoke 0.9.x → current
# (for pre-release 0.12 dev installs, also verify the 937 charge-key migration)
# also confirm the Top credit holders widget renders when placed and a balance exists
./scripts/dev.sh fix-perms   # after every generator/import/upgrade
```

## AI agent guidance

XenForo 2.3.10 ships `xf-dev:install-ai-agents`, which writes the upstream agent
conventions into the dev root (`src/`). The committed copies live at `AGENTS.md`
(project preamble) and `.agents/skills/` (XF skills: `xf-shared-policy`,
`xf-addon-dev`, `xf-core-dev`, `xf-scaffold-make`, `xf-template-phrase`).

- Load `xf-shared-policy` for all XF work and the task-specific skill alongside it.
- **Do not run `xf-dev:export` manually** — it is destructive and only
  `xf-addon:build-release` invokes it.
- Use `xf-make:*` for artifacts that have a command; widgets and BBCodes are
  manual `_output` artifacts.
- This project's runner is `docker compose exec -T web php /var/www/html/cmd.php`
  (the skill's `compose.yaml`/`xf` detection does not apply here).

See `qa/tests/README.md` for the test setup.

* Test convention: assert verdicts and ledger outcomes, never SQL text —
  query shapes are transport, and asserting them breaks behavior-preserving
  refactors (see the `BackfillTest` preview-vs-action rewrite).
* When a computation moves behind a delegate or shim, pin the old function's
  boundary and sentinel inputs (zero, negative, disabled-sentinel) in unit
  tests — the shim must preserve them exactly.
* Review reads the committed diff: commit the finished change first, then run
  the review. An amend after review needs a re-run.

## Granting the credit permissions (dev shortcut)

Fresh installs auto-allow both for Registered. To re-grant manually:

```bash
docker compose exec db mysql -uxf -popencredits xf_dev -e \
  "INSERT INTO xf_permission_entry (user_group_id, user_id, permission_group_id, permission_id, permission_value, permission_value_int) VALUES (2, 0, 'general', 'ocView', 'allow', 0), (2, 0, 'general', 'ocTransfer', 'allow', 0), (3, 0, 'general', 'ocView', 'allow', 0), (3, 0, 'general', 'ocTransfer', 'allow', 0);"
docker compose exec -T web php /var/www/html/cmd.php xf-rebuild:users
```

## Git workflow (do not push features straight to main)

* `main` is always release-ready and mirrors the public GitHub repo.
* All new work happens on `dev/*` branches (e.g. `dev/integrator-hooks`),
  pushed to origin for backup as you go.
* Merge to `main` only after the feature is fully tested on dev;
  cut releases from `main` afterwards.

## Release

```bash
docker compose exec -T web php /var/www/html/cmd.php xf-addon:build-release OpenCredits/Credits
# → addon-src/OpenCredits/Credits/_releases/OpenCredits-Credits-x.y.z.zip (gitignored)
```

See `ARCHITECTURE.md` for the code map and `XF-DEV-GOTCHAS.md` before touching `_output/`.
