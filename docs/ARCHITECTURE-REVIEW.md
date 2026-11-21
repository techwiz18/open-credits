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

## 3. Collapse the premium-content price — Worth exploring — OPEN

- Files: `BbCode/Charge.php`, `Pub/Controller/Credits.php` (`actionUnlock`),
  `Service/Transact.php` (`chargeForContent()`)
- Problem: "highest block wins, one purchase per post" agreed across
  render / unlock / ledger seams. Price re-derived in the controller.
- Direction: one content-charge module owning parse, price, and purchase.

## 4. Deepen the demurrage pass runner — Strong — OPEN

- Files: `Service/Transact.php` (pass-token protocol), `Cron/Demurrage.php`,
  `Admin/Controller/Tools.php`, `Cli/Command/Demurrage.php`
- Problem: token/cursor protocol leaks into three runners; zero integration
  coverage of races (`busy`, stale-claim recovery, cursor advance).
- Direction: runners become thin adapters over one budgeted
  `run(budget) → busy | done | resume` interface. Freeze token semantics;
  add concurrent-runner tests first — highest blast radius of the five.

## 5. Fold history labels into the ledger module — Speculative — OPEN

- Files: `Pub/Controller/Credits.php` (`HISTORY_LABELS`), `Entity/CreditEvent.php`
  (`TRIGGER_LABELS`), note/trigger marker strings shared with prune/dedupe.
- Problem: two label maps + load-bearing marker strings; new triggers need
  three updates. Cosmetic; worst case is a mislabeled row.
