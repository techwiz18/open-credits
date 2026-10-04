# OpenCredits — open-source credits for XenForo 2.3+

![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue) ![XenForo 2.3+](https://img.shields.io/badge/XenForo-2.3%2B-orange) ![License: MIT](https://img.shields.io/badge/License-MIT-green)

A free (MIT) credits system for XenForo 2.3+: reward activity, show balances,
let members transfer credits, gate trophies on wealth.

## What members get

* **Earn credits** for posting threads/replies, receiving reactions, registering,
  and daily visits (amounts configurable in the DB seed; AdminCP UI roadmap).
* **Wallet everywhere** — balance in postbit, in the account menu, full history
  at `/credits/`.
* **Transfers** — send credits to another member at `/credits/transfer`
  (username autocomplete included).
* **Trophies** — "has at least / fewer than X credits" criteria work with
  trophies, notices, and user-group promotions.

## Requirements

* XenForo 2.3.0+ (any 2.3.x, e.g. 2.3.10), PHP 8.2+, MySQL 8.0+
* No other add-ons required.

## Install on your forum

Use the release zip from the [releases page](../../releases).
Install it like any XenForo add-on — upload the files, then finish in AdminCP:

1. Download `OpenCredits-Credits-x.y.z.zip` and unzip it on your computer.
   Inside you'll find an `upload/` folder.
2. Upload the **contents** of `upload/` into your forum root (the folder with
   `index.php`, `src/`, `data/`) via FTP/cPanel File Manager, merging folders.
3. In AdminCP → Add-ons, find **OpenCredits** and click **Install**.
4. Grant permissions: AdminCP → Users → Groups & permissions →
   **Registered** → General → allow **View own credit wallet and history**
   and **Transfer credits to other users**. Repeat for any other groups.
5. Members start earning on the next post/reaction/visit. Balances appear in
   postbit automatically.

To upgrade later: upload the newer release the same way, then AdminCP →
Add-ons → OpenCredits → **Upgrade**.

## Earning defaults

| Action | Default |
|---|---|
| New thread | $5.00 |
| Reply | $1.00 |
| Content reacted to | $2.00 |
| Registration | $10.00 |
| Daily visit (once/day) | $5.00 |

Changing these amounts currently requires editing the database directly —
a point-and-click settings screen is planned for a future release.

## Troubleshooting

* **Balances look wrong** — run the rebuild (CLI:
  `php cmd.php oc-credits:rebuild`), which recomputes every balance from the
  append-only transaction log.
* **No permission errors on `/credits/`** — the two `general` permissions above
  default to deny; grant them per group.

## For developers

* [docs/DEV.md](docs/DEV.md) — Docker dev environment in 5 minutes.
* [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — code map, schema, trigger matrix
  (written as an LLM/contributor reference).
* [docs/XF-DEV-GOTCHAS.md](docs/XF-DEV-GOTCHAS.md) — hard-won XF 2.3 dev-output
  and API conventions.
* CI lints PHP + validates `_output` JSON on every push.

## Roadmap / out of scope for v1

AdminCP currency/event manager, alerts, redeem codes, `[CHARGE]` BBCode,
interest/tax/paycheck schedules, payment profiles, shop integration.

## License

MIT — see [LICENSE](LICENSE).
