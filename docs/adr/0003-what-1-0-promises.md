# 1.0 promises stability, not features

0.x releases ship with green gates but have no defined exit, because "1.0" was
being asked to mean both quality and feature completeness. This records the
decision that 1.0 is the first release under a stability promise over the
feature set already shipped in 0.13.1, and that the promise binds the stable
surface defined in `GLOSSARY.md`; everything off that list may change in a
minor release.

Status: accepted

## What 1.0 is not

Not a feature milestone. Nothing is added to reach it, and no open feature is a
gate. README already tells users 0.13 is the complete core scope, so a feature
promise would contradict text that has shipped. Interest, taxation and paychecks
(#6) and payment profiles and shop integration (#7) remain separate add-ons on
the existing service and event, and are out of scope by decision, not by
schedule.

## The one irreversible item

The encoded version-ID convention and its tested upgrade path must land
*before* the first 1.0 release and be its last change. Once a released install
stores `1000070`, renumbering again would break every later comparison, so this
is the only pre-1.0 decision that cannot be taken back. `1.0.0` encodes as
`1000070`, matching XenForo's own `2|03|00|7|0` shape.

Phrase-boundary markers are deliberately *not* a gate. They change source
strings, not installed data, and are recoverable at any time; they become the
first 1.x i18n task.

## Blocking set

Shipped as `0.14.x` before the tag, so that `1.0.0` contains the version-identity
cut and nothing else and can be re-tagged cheaply if the migration is wrong:

- **#25** — a documented integrator purchase leaves the buyer facing the paywall
  they just paid for, and the access verdict is duplicated so render and unlock
  can disagree.
- **#27** — the demurrage preview prints a number the burn will not produce;
  admins act on it.
- **Currency-precision change guard** — lowering a currency's decimals rounds
  existing balances silently (`Admin/Controller/Currency.php:72`). A stability
  promise that lets an admin destroy balances through the normal UI is not a
  promise.

Deliberately not blocking, and free to land in 1.x: #24, #26, #28, #29, #30,
#31, completing the `LedgerWrite` cutover, the other three README hardening
items (postbit balance lookup, write-endpoint rate limiting, resumable rebuilds
and pruning), and an update-check `update_url`. #28 is rejected by name: its run
path is single-sourced in `pruneHistory`, so only the preview count can drift.
`LedgerWrite` completion is internal and outside the stable surface; until the
last writer moves, ADR 0001's allowlist comment stays truthful.

1.0 is shippable when the blocking set is merged and the usual release gates are
green — not when the 1.x backlog is empty.

## Breaking changes and deprecation after 1.0

Breaking changes happen only at a major release. A removal must be marked
`Deprecated:` in `docs/API.md` (or the relevant option/route reference) and in
the release notes for at least one minor before it disappears. There is no
runtime deprecation channel: XenForo has no standard one, and `trigger_error`
from a money path is noise. Security fixes backport to the latest minor only.

A fix that restores already-documented behaviour is not a breaking change, even
when an individual member's outcome changes — that is the carve-out that lets
#23 and #25 ship without a major.

## Telling people

Publish-only. GitHub release notes are the single record, under the headings
`Breaking (forum owners)`, `Breaking (integrators)`, `Behavior changes` and
`Fixes`. `1.0.0` additionally carries a consolidated "changed since 0.13.1"
section, because a jump from an older 0.x otherwise delivers every intermediate
behavior change at once with no incremental notice.

`build.json` has no `update_url`, so XenForo never tells an existing install that
a new version exists. This ADR records that gap honestly rather than pretending
the promise reaches sleeping installs; pushing notices is an optional 1.x
feature, not a gate.

## Consequences

`docs/DEV.md`'s release checklist gains the four release-note headings and the
precision-change guard. README's 1.0 section must describe the stability promise
rather than the compatibility-and-i18n pass it lists today. The deep-module work
that motivated the `dev/v1.0-hardening` branch continues through 1.x without
holding the release.
