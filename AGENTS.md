# OpenCredits — AI agent guidance

OpenCredits is a free (MIT) credits system add-on for XenForo 2.3+, developed in
`addon-src/OpenCredits/Credits/`. This file is the project-specific steering;
the upstream XenForo 2.3.10 guidance installed by `xf-dev:install-ai-agents`
lives in `.agents/skills/` (load it as described below).

## Project shape

- **Add-on source:** `addon-src/OpenCredits/Credits/` (bind-mounted into the dev forum).
- **Runtime `src/`:** licensed XenForo 2.3.10, gitignored — never commit it.
- **`_output/`:** the development surface (templates, phrases, options, routes, etc.).
- **`_data/`:** generated release artifacts (gitignored); never edit by hand.
- **Tests:** `qa/tests/Unit` (no XF boot) and `qa/tests/Integration` (real XF boot
  via `OC_XF_ROOT=/var/www/html`). See `qa/tests/README.md`.
- **Docs:** `docs/DEV.md`, `docs/ARCHITECTURE.md`, `docs/XF-DEV-GOTCHAS.md`, `docs/API.md`.
- **`HANDOFF.md`:** local-only session notes (gitignored).

## Runner

The XF skill's `compose.yaml`/`xf` runner detection does not match this project
(we use `docker-compose.yml` + the `web` container). Use the documented runner:

```bash
docker compose exec -T web php /var/www/html/cmd.php <command>
```

`./scripts/dev.sh` wraps the common lifecycle/packaging helpers.

## Workflow

- Features on `dev/*`; merge to `main` only after full testing; cut releases
  from `main`. `main` is always release-ready.
- Read `docs/DEV.md` (release checklist) and `docs/XF-DEV-GOTCHAS.md` before
  touching `_output`.
- **Never run `xf-dev:export` manually.** It is destructive and is only invoked
  internally by `xf-addon:build-release`.
- Use `xf-make:*` for artifacts that have a command (templates, phrases,
  options, entities, services, controllers, routes, listeners, jobs, etc.).
  There is **no** `xf-make` for widgets or BBCodes — create those in `_output`
  manually.
- After manual `_output` edits: `xf-dev:import --addon=OpenCredits/Credits`
  (scoped), then `./scripts/dev.sh fix-perms`.
- Release: build from a clean/scratch install, run `xf-addon:validate-json`,
  PHPUnit, fresh-install + upgrade smokes, then tag/publish. See `docs/DEV.md`.

## Deferred to the 1.0.0 cycle

- Adopt XenForo's encoded version-ID convention
  (`{major}{minor:02d}{patch:02d}{stability}{level}`) and plan the upgrade-path
  migration; OpenCredits currently uses sequential IDs.
- Adopt the `~~...~~` phrase-boundary marker convention (and consider migrating
  existing strings) as part of the 1.0.0 i18n pass.

## XenForo skills

Load the relevant skill from `.agents/skills/` for the task at hand:

| Skill | When to use |
|---|---|
| `xf-shared-policy` | Always — runner detection, artifact boundaries, import/export, `xf-make`, phrase conventions |
| `xf-addon-dev` | Add-on code, `Setup.php`, class extensions, add-on templates/phrases |
| `xf-core-dev` | Changes under `src/XF/`, `js/xf/`, `src/addons/XF/` (usually not needed here) |
| `xf-scaffold-make` | Creating artifacts with `xf-make:*` |
| `xf-template-phrase` | Templates, phrases, template modifications |
| `opencredits-ux` | UI/UX and IA for the credits wallet and profile tab; read before any member-facing layout change |

Load only the skills relevant to the task; combine when work spans areas. The
universal rules in `xf-shared-policy` take precedence, except where this file
documents a project-specific runner or path.
