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
