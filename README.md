# xenforo-open-credits

Open-source alternative to DragonByte Credits for XenForo 2.3+ (MVP).

MIT licensed. Vibe-coded, community owned.

## Scope — v0.1 MVP

* 1 currency to start (schema supports N): `Credits`, prefix `$`, 2 decimals, negatives allowed. Prefix/suffix configurable per currency.
* Wallet column on `xf_user.oc_credits`, transaction log, admin adjust.
* 6 event triggers only: `thread`, `post`, `reaction_received`, `register`, `daily_login`, `admin_adjust` + user-to-user `transfer`.
* No payments / shop / redeem codes / `[CHARGE]` BBCode in v1.

## Dev setup (Linux Mint, Docker)

Requirements: Docker 29+ + Compose 2.40+ (already verified on host).
XenForo 2.3.10 Patch 2 zip is dev-only and gitignored — never committed.

```bash
cd open-credits
cp .env.example .env
./scripts/dev.sh up        # builds web, starts db + phpmyadmin
./scripts/dev.sh unpack    # unzips ../xenforo_*.zip into src/
# then open http://localhost:8080/install and install XF
# DB host: db, name: xf_dev, user: xf, pass: opencredits
./scripts/dev.sh enable-debug
./scripts/dev.sh link-addon  # symlinks addon-src into src/src/addons
```

* Forum: http://localhost:8080
* phpMyAdmin: http://localhost:8081

## Addon source of truth

`addon-src/OpenCredits/Credits/` is the canonical addon. It gets symlinked to
`src/src/addons/OpenCredits/Credits/` for live dev. Build releases with:

```bash
docker compose exec web php /var/www/html/cmd.php xf-addon:build-release OpenCredits/Credits
```

## Permissions (grant after install)

The wallet is gated by two flags in the `general` group (default: deny):

* `general / ocView` — view `/credits/` history
* `general / ocTransfer` — use `/credits/transfer`

Grant them at AdminCP → Users → Groups & permissions → [group] → General,
or via CLI + rebuild:

```bash
docker compose exec db mysql -uxf -popencredits xf_dev -e \
  "INSERT INTO xf_permission_entry (user_group_id, user_id, permission_group_id, permission_id, permission_value, permission_value_int) VALUES (2, 0, 'general', 'ocView', 'allow', 0), (2, 0, 'general', 'ocTransfer', 'allow', 0);"
docker compose exec -T web php /var/www/html/cmd.php xf-rebuild:users
```

Currency prefix/suffix/decimals live in `xf_oc_currency` (per-currency,
unlimited currencies supported by schema; AdminCP manager UI is on the roadmap).

## Project layout

```
open-credits/
  docker-compose.yml Dockerfile .env.example
  src/                 # XF runtime (gitignored, licensed)
  addon-src/OpenCredits/Credits/
    addon.json Setup.php Listener.php
    Service/Transact.php Cron/DailyLogin.php Job/RebuildBalances.php
  scripts/dev.sh
```

## Roadmap

* Phase 0: env ✅ (this scaffold)
* Phase 1: skeleton install + tables
* Phase 2: Transact service + adjust + log viewer
* Phase 3: 6 event triggers
* Phase 4: frontend wallet + transfer + trophy criteria + rebuild job
* Phase 5: release zip + CI lint
