# OpenCredits — open-source credits for XenForo 2.3+

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/releases/8.2/) [![XenForo 2.3+](https://img.shields.io/badge/XenForo-2.3%2B-orange)](https://xenforo.com/) [![License: MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE)

A free (MIT) credits system for XenForo 2.3+: reward activity, show balances,
let members transfer credits, gate trophies on wealth.

## What members get

* **Earn credits** for posting threads/replies, receiving reactions, registering,
  and daily visits — amounts managed in AdminCP → Credits → Earning events.
* **Multiple currencies** with a primary and per-currency visibility, all
  managed in AdminCP → Credits → Currencies.
* **Wallet everywhere** — balances in postbit, in the account menu, and on
  member profiles (primary stat plus a full currencies tab); per-currency
  history at `/credits/`, reachable from the Credits nav tab.
* **Transfers** — send credits to another member at `/credits/transfer`
  (username autocomplete included; transfers can never overdraw).
* **Trophies** — "has at least / fewer than X credits" criteria work with
  trophies, notices, and user-group promotions.

## Requirements

* XenForo 2.3.0+ (any 2.3.x, e.g. 2.3.10), PHP 8.2+, MySQL 8.0+
* No other add-ons required.

## Install on your forum

Use the release zip from the [releases page](../../releases).
Two ways to install, pick one:

**A — from AdminCP (fastest).** AdminCP → Add-ons → **Install from archive**,
upload the release zip, and click **Install**. If that option isn't available
on your setup, use manual upload below.

**B — manual upload.** Download the release zip and unzip it on your computer.
Inside you'll find an `upload/` folder. Upload the **contents** of `upload/`
into your forum root (the folder with `index.php`, `src/`, `data/`) via
FTP/cPanel File Manager, merging folders. Then AdminCP → Add-ons, find
**OpenCredits** and click **Install**.

Then, either way:

1. Grant permissions: AdminCP → **Groups & permissions** → **User groups** →
   **Registered** → **Credits** tab → allow **View own credit wallet and history**
   and **Transfer credits to other users**. Repeat for any other groups.
2. Members start earning on the next post/reaction/visit. Balances appear in
   postbit automatically.

To upgrade later: upload the newer release the same way, then AdminCP →
Add-ons → OpenCredits → **Upgrade**.

## AdminCP access

The **Credits** AdminCP section (currencies, earning events) is gated by the
`Manage credits` admin permission:

* Super admins (the forum owner account is one by default) always have access.
* Other admins need the toggle, and it only appears for the right account type:
  AdminCP → **Groups & permissions** → **Administrators** → click the admin's
  name → set **Administrator type** to **Regular administrator** (the Permissions
  checkbox list is hidden for super admins, who already hold everything) →
  check **Manage credits (currencies and earning events)** → Save.

## Earning defaults

| Action | Default |
|---|---|
| New thread | $5.00 |
| Reply | $1.00 |
| Content reacted to | $2.00 |
| Registration | $10.00 |
| Daily visit (once/day) | $5.00 |

Change amounts, add triggers, or add currencies any time in AdminCP →
Credits (Earning events / Currencies).

## Troubleshooting

* **Balances look wrong** — AdminCP → Credits → **Rebuild balances**, or CLI
  (`php cmd.php oc-credits:rebuild`); both recompute every balance from the
  append-only transaction log.
* **No permission errors on `/credits/`** — the two Credits permissions default
  to deny except on fresh installs, which auto-allow them for Registered;
  check the **Credits** tab if access fails.
* **Uninstalling deletes everything** — balances, ledger, events, and
  currencies are wiped (disabling is not offered; re-install starts from zero).

## For developers

* [docs/DEV.md](docs/DEV.md) — Docker dev environment in 5 minutes.
* [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — code map, schema, trigger matrix
  (written as an LLM/contributor reference).
* [docs/XF-DEV-GOTCHAS.md](docs/XF-DEV-GOTCHAS.md) — hard-won XF 2.3 dev-output
  and API conventions.
* CI lints PHP + validates `_output` JSON on every push.

## Roadmap

Tracked as [issues and milestones](../../milestones): v0.10 candidates are
alerts on earn, redeem codes, and admin adjust/take tools; bigger epics
(charge BBCode, interest/tax/paychecks, payments/shop) sit in Future. File or
vote on issues to shape what lands next.

## Built with AI

This project is vibe-coded: the code, docs, and much of the testing plan were
produced with AI assistance and reviewed by a human. If you deploy it, treat it
like any community add-on — review security-sensitive changes and keep backups.

## License

MIT — see [LICENSE](LICENSE).
