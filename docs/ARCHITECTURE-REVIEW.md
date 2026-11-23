# Architecture review — November 2026

Five deepening candidates against the 0.13.1 tree, ranked by friction in
recently-changed code (earn paths, backfill, `[charge]`, wallet/profile).
Vocabulary: module / interface / seam / adapter / leverage / locality.
Domain terms per `GLOSSARY.md` (award eligibility vs award limits).

## 1. Collapse the earn-scope triplication — Strong — IMPLEMENTED

- Files: `Listener.php`, `Service/Transact.php` (`awardByTrigger`),
  `Admin/Controller/Tools.php` (`backfillContentQuery`), `Entity/CreditEvent.php`
- Problem: live award, eligibility check, and backfill each re-encoded
  visible/banned/forum/group/first-post rules; backfill could drift from live.
- Solution: one pure predicate behind one seam — `Service/AwardScope::eligible()`
  + `countEligible()` tally. Live path delegates (`eventAppliesTo()` deleted);
  backfill verdicts every row through it (`backfillEligibleCounts()` serves
  preview, run, and tests). Ledger shape, batches, resume markers unchanged.
- Proof: unit 43/570; integration Backfill 4/4 (incl. new preview-vs-action
  agreement test), Eligibility 6/6; full suite 67 tests / 201 assertions, 0 failures.
- Residuals (cosmetic): dual node lookups in `Listener`, register double-check,
  group-parse duality, `needsForumContext` probe.

## 2. Deepen the inflation clamp — Strong — IMPLEMENTED

- Files: `Service/Transact.php` (`adjust()`, `redeem()`, `calculateCredit()`,
  daily-claim marker), new `Service/InflationClamp.php`
- Problem: `redeem()` re-implemented the cap+faucet check inline while earn
  took partial credit — the two verdicts could drift. Faucet/marker writes
  lived in callers, untested.
- Solution: one pure clamp module behind one seam — `credited()` (partial
  math) + `fitsFull()` (redeem all-or-nothing, defined as `credited()` covering
  the amount). `adjust()` delegates bit-identically; `redeem()`'s inline copy
  is gone; both faucet writes share `trackFaucetMint()`; the marker verdicts
  (`shouldMarkClaimed()`, `allFaucetBound()`) live in the module while the
  writes stay in `Transact` (needs `$db`). `calculateCredit` stays as a BC shim.
- Product notes (decided, not cleanup): redeem's cap verdict now floors
  headroom exactly like earn (stricter in sub-unit edges only); redeem tracks
  the faucet whenever inflation is enabled; only faucet-bound clamps mark the
  day, so freed cap headroom retries instead of finding a stale marker.
- Proof: new `InflationClampTest` (partial-vs-capped matrix, marker verdicts);
  rewritten cap/faucet daily-login integration tests; full suite unit 53/601,
  integration 68/209, 0 failures.
- Residuals (deliberate): the "thick module" end-state (writes inside the
  clamp object) skipped — cost exceeds value; cap-bound members retry a
  rolled-back write transaction per pageview until the faucet exhausts.

## 3. Collapse the premium-content price — Worth exploring — IMPLEMENTED

- Files: new `Service/ContentCharge.php`, `BbCode/Charge.php`
  (renderer), `Pub/Controller/Credits.php` (`actionUnlock`),
  `Service/Transact.php` (factory + legacy shims)
- Problem: "highest block wins, one purchase per post" agreed across
  render / unlock / ledger seams. Price re-derived in the controller and the
  purchase lived in `Transact`.
- Solution: one content-charge module behind one seam. It owns the
  parser-based price (`priceForMessage()`), the pure price rules
  (`priceOf()`: highest valid block at the currency's precision, malformed /
  non-positive / over-precise ignored), the idempotent per-(content, member,
  currency) purchase, and the buyer/unlock-count reads the renderer uses.
  Render, unlock, and the ledger call the same price; the unlock endpoint
  charges the module price and calls `purchase()` directly instead of
  trusting the submitted amount. `BbCode\Charge` keeps the render gates and
  delegates price/access/stats; `Transact` keeps `chargeForContent()` /
  `hasPaidForContent()` / `unlockCountFor()` as shims for existing callers
  and tests.
- Proof: new `ContentChargeTest` unit cover for the price rules (including a
  float-overflow price); existing
  `ChargeTest`/`ChargeAmountsTest`/`ChargeUnlockTest`/`ChargeRenderTest`/
  `ChargeCleanupTest` pass unchanged, including a random-order run of
  `ChargeTest`; full suite unit 59/613, integration
  76/290 (14 content-dependent skips), 0 failures; phpstan clean.
- Residuals: `Transact` still hosts the purchase/read entry points as shims;
  the renderer keeps its per-request price/buyer/stats caches, so the module
  reads stay fresh for moderation purges. The purchase key remains
  (content, member, currency), as before — v1 charges in the primary currency
  only.

## 4. Deepen the demurrage pass runner — Strong — IMPLEMENTED

- Files: new `Service/DemurrageRunner.php`, `Service/Transact.php` (factory +
  legacy shims), `Cron/Demurrage.php`, `Admin/Controller/Tools.php`,
  `Cli/Command/Demurrage.php`
- Problem: token/cursor protocol leaked into three runners; zero integration
  coverage of races (`busy`, stale-claim recovery, cursor advance).
- Solution: one module behind a budgeted
  `run(batchSize, ignoreInterval, timeBudget) → busy | done | resume` call.
  It owns pass claim, stale-claim recovery, rate/decimals snapshot, cursor
  advance, orphan purge, interval gate, and the completion write; `preview()`
  moved with it so the in-flight cursor exclusion has one owner, and the
  new-primary `last_run` seed moved too, so no caller writes pass fields.
  Cron, admin, and CLI call `Transact::demurrageRunner()` and branch only on
  the verdict; `Transact` keeps delegating `demurrageStep()` /
  `runDemurrage()` / `demurragePreview()` shims for existing callers and
  characterization tests. No caller reads or writes pass tokens or cursors.
- Proof: #13 characterization suite unchanged — `InflationControlTest` 23/23
  (161 assertions, incl. busy, stale claim, token-checked advance/completion,
  budget resume, interval skip); full integration 74 tests / 279 assertions,
  14 content-dependent skips, 0 failures; unit 53/601; phpstan clean; CLI
  `oc-credits:demurrage --preview` smoke.
- Residuals (deliberate): `Transact` still hosts the budgeted entry points as
  shims; a `busy` verdict now reports the aborted batch's progress instead of
  discarding it (the old `runDemurrage()` undercounted a lost race).

## 5. Fold history labels into the ledger module — Speculative — IMPLEMENTED

- Files: new `Service/Ledger.php`, `Pub/Controller/Credits.php`, `Entity/CreditEvent.php`,
  `Admin/Controller/CreditEvent.php`, `Admin/Controller/Tools.php`,
  `Service/Transact.php`, `Service/ContentCharge.php`, `Listener.php`
- Problem: two label maps + load-bearing marker strings; new triggers need
  three updates. Cosmetic; worst case is a mislabeled row.
- Solution: one vocabulary module. `Ledger::TRIGGER_LABELS` is the only label
  map (wallet history, admin event list, earn-alert reason, backfill preview),
  `Ledger::label()` keeps the humanised unknown-trigger fallback, and
  `Ledger::eventTriggers()` still offers only the awardable triggers to the
  admin form (the single label map minus `LEDGER_ONLY_TRIGGERS`, so a new
  awardable trigger is one map entry). Trigger names and the stored markers
  (`NOTE_BACKFILL`, `NOTE_REACTION_PREFIX`) are constants written by the
  ledger/listener and read by the prune guard via `Ledger::pruneGuardParams()`
  + `isBackfillNote()`, so old rows keep matching.
- Proof: new `LedgerTest` pins every label, the unknown-trigger fallback, the
  award-trigger subset, and the exact marker strings the prune guard/backfill
  check relies on; full suite unit 64 tests / 635 assertions, integration
  76 tests / 325 assertions, 0 failures; phpstan clean; addon files lint clean;
  `docs/ARCHITECTURE.md` file map and new-trigger checklist updated.
- Residuals (deliberate): `Setup.php` default-event seeds still spell their
  trigger names literally (install data, not a reader); the write-only
  `Demurrage` / `Charge: …` row notes are display text no code matches on, so
  they stay at their write sites; removing the old public
  `CreditEvent::TRIGGER_LABELS` / admin `TRIGGERS` constants is a pre-1.0 API
  break (nothing in-repo referenced them).
