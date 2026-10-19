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

## Useful commands

| Command | Purpose |
|---|---|
| `./scripts/dev.sh up/down/logs` | lifecycle |
| `./scripts/dev.sh fix-perms` | **run after any `xf-make:*`** — generators run as root; Apache needs writable `_output` |
| `docker compose exec web php /var/www/html/cmd.php xf-addon:upgrade OpenCredits/Credits` | import new `_output` (bump version first) |
| `docker compose exec web php /var/www/html/cmd.php oc-credits:rebuild` | rebuild balances from ledger |
| `docker compose exec -T web php /var/www/html/cmd.php <cmd>` | non-interactive (pipe `y` for confirms) |

* Forum: http://localhost:8080 · phpMyAdmin: http://localhost:8081 (root/rootpass)
* Port clash (something else on 8080)? Copy `.env.example` to `.env` and set
  `WEB_PORT=8082` — the compose file honors it, defaulting to 8080.
* `src/src/config.php` holds dev DB creds + `debug`/`development.enabled` (gitignored).

## Release checklist (local — needs the licensed XF runtime, so not in CI)

Public CI covers `php -l` (8.2/8.3/8.4), `_output` JSON validity, and
gitleaks. Everything below runs on the dev stack before any release:

```bash
docker compose exec -T web php /var/www/html/cmd.php xf-addon:validate-json OpenCredits/Credits
rm -rf src/.qatmp && cp -r qa src/.qatmp
docker compose exec -T web bash -c "cd /var/www/html/.qatmp && composer install && OC_ADDON_SRC=/var/www/html/src/addons/OpenCredits/Credits ./vendor/bin/phpunit"
docker compose exec -T web bash -c "cd /var/www/html/.qatmp && OC_XF_ROOT=/var/www/html ./vendor/bin/phpunit -c phpunit-integration.xml"
docker compose exec -T web php /var/www/html/cmd.php xf-addon:build-release OpenCredits/Credits
# fresh-install smoke on a scratch copy, then upgrade smoke 0.9.x → current
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

See `addon-src/OpenCredits/Credits/tests/README.md` for the test setup.

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
