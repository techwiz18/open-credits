# OpenCredits — AI agent guidance

OpenCredits is a free (MIT) credits system add-on for XenForo 2.3+, developed in
`addon-src/OpenCredits/Credits/`. This file is the project-specific steering;
the upstream XenForo 2.3.10 guidance installed by `xf-dev:install-ai-agents`
lives in `.agents/skills/` (load it as described below).

## Project shape

- **Add-on source:** `addon-src/OpenCredits/Credits/` (bind-mounted into the dev forum).
- **Runtime `src/`:** licensed XenForo 2.3.10, gitignored — never commit it.
  The XF tree unpacks at `src/src/XF/` (not `src/XF/`); see `docs/DEV.md`.
- **`_output/`:** the development surface (templates, phrases, options, routes, etc.).
- **`_data/`:** generated release artifacts (gitignored); never edit by hand.
- **Tests:** `./scripts/dev.sh help` lists every command and test kind; test
  details (including arg forwarding) are in `qa/tests/README.md`. The tracked
  pre-commit hook runs `test check` plus the host checks; `install-hooks` wires
  it up.
- **Docs:** `docs/DEV.md`, `docs/ARCHITECTURE.md`, `docs/XF-DEV-GOTCHAS.md`, `docs/API.md`.
- **`HANDOFF.md`:** local-only session notes (gitignored).

## Runner

The XF skill's `compose.yaml`/`xf` runner detection does not match this project
(we use `docker-compose.yml` + the `web` container). Use the documented runner:

```bash
docker compose exec -T web php /var/www/html/cmd.php <command>
```

`./scripts/dev.sh` wraps the common lifecycle/packaging helpers.

The host has no PHP; run every PHP command (including `php -l`, PHPUnit, and
PHPStan) inside the `web` container. Inside it the addon is
`/var/www/html/src/addons/OpenCredits/Credits`; the synced test copy is
`/var/www/html/.qatmp`.

## Workflow

- Start each issue from `origin/main` after `git fetch`: `git switch -c
  dev/<issue>-<slug> origin/main`. Never stack a new branch on another feature
  tip; if the session starts detached or ahead, rebase your commit onto
  `origin/main` before pushing.
- Features on `dev/*`; merge to `main` only after full testing; cut releases
  from `main`. `main` is always release-ready.
- An implemented issue is finished when it is committed, pushed, and opened as
  a PR against `main` that links the issue (`Closes #<n>`). Leave the issue
  open until the PR merges; the merge closes it.
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
- A diff that moves or removes a module, public constant, or documented
  convention updates `docs/ARCHITECTURE.md` (file map + conventions) in the
  same commit.
- Release: build from a clean/scratch install, run `xf-addon:validate-json`,
  `./scripts/dev.sh test all`, fresh-install + upgrade smokes, then tag/publish. See `docs/DEV.md`.

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

## Agent skills

### Issue tracker

Issues live in GitHub Issues for `techwiz18/open-credits`. See `docs/agents/issue-tracker.md`.

### Triage labels

Triage uses the five default labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.

### PR review comments

Bot review comments are triaged with a fixed rubric instead of read ad hoc. See `docs/agents/pr-comments.md`.
