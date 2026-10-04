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
* `src/src/config.php` holds dev DB creds + `debug`/`development.enabled` (gitignored).

## Granting the credit permissions (dev shortcut)

```bash
docker compose exec db mysql -uxf -popencredits xf_dev -e \
  "INSERT INTO xf_permission_entry (user_group_id, user_id, permission_group_id, permission_id, permission_value, permission_value_int) VALUES (2, 0, 'general', 'ocView', 'allow', 0), (2, 0, 'general', 'ocTransfer', 'allow', 0), (3, 0, 'general', 'ocView', 'allow', 0), (3, 0, 'general', 'ocTransfer', 'allow', 0);"
docker compose exec -T web php /var/www/html/cmd.php xf-rebuild:users
```

## Release

```bash
docker compose exec -T web php /var/www/html/cmd.php xf-addon:build-release OpenCredits/Credits
# → addon-src/OpenCredits/Credits/_releases/OpenCredits-Credits-x.y.z.zip (gitignored)
```

See `ARCHITECTURE.md` for the code map and `XF-DEV-GOTCHAS.md` before touching `_output/`.
