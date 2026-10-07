# XF 2.3 dev gotchas (learned building OpenCredits)

Read the corresponding importer in `src/src/XF/DevelopmentOutput/*.php` before
hand-writing any `_output` file. The keys below are load-bearing.

## 1. Numeric columns are UNSIGNED by default

`$table->addColumn('amount', 'decimal', '10,2')` creates `decimal(10,2) unsigned`.
Any debit/negative insert fails with `Out of range value`. Fix at creation:

```php
$table->addColumn('amount', 'decimal', '10,2')->unsigned(false)->setDefault('0.00');
```

And for existing installs, an upgrade step with `changeColumn(...)->unsigned(false)`.
`xf_user.oc_credits` survived only because `alterTable` preserved signedness.

## 2. `_output` filename conventions differ per type

The JSON importers parse identity out of the file *path*, not just contents:

| Type dir | Required layout | Identity source |
|---|---|---|
| `code_event_listeners/` | any unique `*.json` | content fields |
| `template_modifications/` | `{type}/{key}.json` (e.g. `public/foo.json`) | path segments — a flat file fatals with `Undefined array key 1` |
| `permissions/` | `{groupId}-{permissionId}.json` | path segments |
| `templates/` | `{type}/{title}.html` + entry in `templates/_metadata.json` | path + metadata |
| `phrases/` | `{title}.txt` + entry in `phrases/_metadata.json` | path + metadata |
| `routes/` | `{type}_{prefix}_{sub}.json` (generator-made) | content fields |

Prefer `xf-make:*` generators (route, template, phrase, command) — they write
correct files + metadata. Hand-write only listeners/permissions/template-mods.

## 3. New `_output` files need a version bump + upgrade

`xf-addon:sync-json` reports "no changes" for brand-new files. Reliable import path:

1. Bump `version_string`/`version_id` in `addon.json`.
2. `xf-addon:upgrade OpenCredits/Credits` (pipe `y` to the confirm, or run attached).
3. Verify rows in `xf_code_event_listener` / `xf_template_modification` / etc.

`xf-addon:rebuild` ("cannot be rebuilt" for this shape) does not import dev output.

## 4. `_output` file ownership vs Apache writes

`xf-make:*` runs as root in Docker, so new files are root-owned. XF dev mode
rewrites `templates/_metadata.json` on page render as `www-data` → red
`Template errors` banner with `Permission denied`. Fix:

```bash
./scripts/dev.sh fix-perms   # chmod 0777 dirs / 0666 files under _output, via container
```

## 5. CLI services: use `\XF::app()`, know the DB API

* In `Cli\Command` classes `$this->app` does **not** exist — use `\XF::app()->service(...)`.
  (`XF\Job` subclasses do have `$this->app`.)
* `Db\Adapter::query()` returns a statement; affected rows are
  `$stmt->rowsAffected()` — there is no `affectedRows()` or `rowCount()`.

## 6. EntityManager `findOne` takes a primary key, not conditions

```php
// wrong: silently misbehaves
$em->findOne('XF:User', ['username' => $name]);
// right:
$this->finder('XF:User')->where('username', $name)->fetchOne();
```

## 7. No login code event — use `visitor_setup` for daily awards

XF 2.3 fires no dedicated post-login event usable from add-ons. `visitor_setup`
(`UserRepository::getVisitor`, `&$user` param) runs on every request for
logged-in users; pair it with an exactly-once guard (ledger check for today).

## 8. Direct-SQL permission entries need a combination rebuild

Inserting into `xf_permission_entry` by hand bypasses the rebuild job. Follow with:

```bash
php cmd.php xf-rebuild:users   # rebuildPermissionCombination() per user
```

## 9. Template anchors worth knowing

* Postbit extras: `message_macros` macro `user_info`, after the `reaction_score` pair.
* Account dropdown stats: `account_visitor_menu`, `<!--[XF:stats_pairs:...]-->` comments.
* Trophy/promotion criteria form: `helper_criteria`, `<!--[XF:user:content_after_trophies]-->`.
* Username autocomplete endpoint: `members/find?q=` + `data-xf-init="auto-complete"` on a textbox.
* Template-mod `find` strings are exact-whitespace `str_replace`: any core
  indentation change silently disables the mod. Keep anchors on XF-provided
  `<!--[XF:...]-->` comments, which are stable by convention.
* Never insert `_output` rows via raw SQL: upgrade orphan-cleanup deletes them
  and the data-registry caches go stale. Use `xf-dev:import-*` or a version bump.
* CLI runs as root in Docker: re-run `./scripts/dev.sh fix-perms` after every
  generator and upgrade, or Apache hits permission errors writing `_output`.
* Never reference XFCP extension classes from services: the alias may not exist
  in CLI context. Keep shared state (caches) in the service itself.
