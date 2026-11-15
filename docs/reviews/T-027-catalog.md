# Review: T-027 — Catalog

## Round 1 — CHANGES REQUESTED

Reviewer ran `pest ../../packages/catalog/tests` plus `QaCatalogSourceRulesTest`,
`UomColumnSetsTest`, `TenantDecimalPrecisionTest` from `modules/host`: **239 passed (1565
assertions), 79s.** Did not re-run the full `composer check`; QA's round-1 run (13 pre-existing
failures, 3255 passed) stands as the gate result.

### Critical (must fix)

- **R-1** `packages/catalog/src/Features/UpdateProduct/UpdateProductHandler.php:151-169` — an
  update can make a product its own variant parent, breaking AC-19. A childless
  `variant_parent` X sent `{type: 'variant', parent_id: X}` passes every existing check
  (`checkParentage`, the parent lookup reading X's pre-update row, `checkTypeChange`) and ends
  up with `parent_id` pointing at itself — a self-loop no future code walking variant parents
  would ever terminate on. `CreateProduct` can't hit this (a new id always exists), and no
  `UpdateProductTest` case covers it. Fix: refuse `$newParentId === $productId` in
  `resolveChanges`, right after line 152, plus a test sending exactly this input and confirming
  the row is byte-identical afterward.

### Warnings — all folded into the fix round

- **W-1** Referenced rows are read without locking in several create/attach handlers
  (`CreateProductHandler.php:126`, `CreateCategoryHandler`'s parent-path read,
  `AttachModifierGroupHandler.php:46-47`, `CreateModifierHandler.php:79-82`,
  `AddProductComponentHandler.php:77-81`), while delete handlers do lock. On a real engine under
  REPEATABLE READ, two concurrent requests can each pass their own check and together break an
  invariant the single-process SQLite stand-in can't show — most seriously, two concurrent
  component edges can jointly close a cycle `ComponentGraph` would have refused if either alone
  had seen the other (e.g. A→B and C→D, given existing B→C and D→A — no two products in common,
  so locking just the two rows a single edge touches isn't enough).
- **W-2** `AddProductComponentHandler.php:131` checks `quantity > 0` *before* `SnapshotCalculator`
  rounds to the line unit's precision — a tiny entered quantity (`"0.00001"`, or `"0.4"` on a
  precision-0 unit) can round to a zero base quantity, producing a recipe line that depletes
  nothing. Fix: check the snapshot's own quantity for zero *after* rounding, comparing the
  decimal string rather than multiplying (keeps the source-rules scan clean).
- **W-3** `QaCatalogSourceRulesTest.php`'s 4-file exemption is legitimate today (reviewer read
  all four — none computes a resolution factor) but loosely scoped: it matches by basename
  across every package/module (a future unrelated file with the same name would silently
  inherit the exemption), and it exempts all three scan rules rather than just the one each file
  actually needs (a factor-multiplication bug added later to one of them would go unseen). Fix:
  exempt by full path under `packages/catalog/src/Features/<Slice>/`, scope each exemption to
  only the rule it needs, and assert each exempt path exists so a rename fails loudly.
- **W-4** The D-19 doc fix (`docs/database.md:717`, `docs/decisions/T-027-catalog.md:281-293`)
  correctly removes the implication that `modifiers` has a stored `unit_factor` column, but: (a)
  cites D-62, which is only about `product_components`, not modifiers; (b) states consumption-time
  resolution as settled when AC-40/§6.3 call the depletion rule itself "deliberately unspecified,
  owned by the restaurant workstream"; (c) the addendum is spliced mid-sentence into D-19's
  closing line and cites a stale line number (670, now 717). Fix: reword to say modifiers stores
  no factor and *how/when* one is resolved is the restaurant workstream's call (AC-40), drop the
  D-62 citation, move the addendum after D-19's sentence, fix the line reference.
- **W-5** The "33 feature slices" count (repeated in the plan, `docs/database.md:546`, and QA's
  report) is wrong — `ls packages/catalog/src/Features` and the plan's own per-chunk table both
  total 31 (28 write slices + 3 reads: `GetCategory`/`ListCategories`/`ListProductBarcodes`).
  The audit-completeness claim still holds independently (reviewer verified all 28 write
  handlers call `AuditTrail::record` with an action matching one of `CatalogAuditActions::ALL`'s
  28 entries). Fix: correct "33" to "31" in `docs/database.md:546`.

### Suggestions filed as follow-ups (non-blocking): FP-425 to FP-428

- **FP-425** `CreateUnitConversionHandler.php` accepts a stored factor between 0 and 1, which the
  ladder will always refuse — worse, it can shadow a valid step-3 chain answer for the same unit
  pair by refusing outright at step 1/2 first. Consider refusing a sub-1 factor at write time too
  (D-9's "dead on arrival" reasoning), tied to Q-9 so both guards relax together if the owner
  ever overturns it.
- **FP-426** `DatabaseUnitResolver::snapshot()` loads the full `units` table twice (5 queries vs.
  the plan's own Ruling-3 budget of 3) — reuse the catalogue `resolve()` already loaded. Also:
  FP-233 will want a batch-resolve API for a multi-line document, or a 50-line sale costs 250
  queries.
- **FP-427** `UnitLadder::walkToRoot()` trusts a hand-inserted zero or negative `units.factor`
  from the database — a zero factor surfaces as a division-by-zero caught generically as
  `inexact_factor`, and two negative factors divide to a positive result that passes the ≥1
  guard. Treat any factor ≤ 0 encountered mid-walk as a broken chain (return null); add a
  `CHECK (factor > 0)` engine constraint alongside the existing precision one.
- **FP-428** The update handlers (e.g. `UpdateProductHandler.php:65`) read their target row
  without `whereNull('deleted_at')`, so a soft-deleted product can still be edited today. No
  real-world exposure yet (no HTTP surface), but decide the policy explicitly — refuse or allow
  and document — before FP-240 adds HTTP access to these handlers.
- Noted, no row needed: `UnitSnapshot`'s docblock references a `snapshotInherited()` method that
  doesn't exist yet (correctly assigned to FP-233/FP-254) — mark it "future" in the docblock.

### Done well

- `UnitLadder` holds up under an adversarial read: the dimension check runs before every step,
  conversion rows are read only by the exact `(from, to)` key, the chain step divides with
  `RoundingMode::Unnecessary` so an inexact quotient is refused rather than rounded, a cycle
  falls through to `no_conversion_path`, and no path anywhere defaults to 1. `ResolvedFactor`/
  `UnitSnapshot`'s own shape validation re-rejects a sub-1 factor even if a caller tried to build
  the DTO directly, bypassing the ladder.
- `SnapshotCalculator`: floats refused outright; the rounding order is round-to-line-precision
  (HALF_UP) → rescale → multiply → round HALF_UP; the Q-11 fractional-base check reads the
  **stock** unit's precision and correctly handles negative quantities.
- `ComponentGraph`'s BFS is correct: starts from the proposed component, follows parent→component
  edges, returns true exactly when the component can already reach the parent — no off-by-one,
  no reversed edge direction, correctly accepts a diamond.
- The 8 deferred FK columns and `custom_fields` are cleanly absent from every `packages/*/src`
  file, confirmed by direct inspection, not just the schema test's own negative assertions.
- Deptrac gives `Package_Catalog` only kernel+contracts and deliberately omits the edge from
  `Module_Demo`; the contracts-purity scan has its own planted-file control proving it can fail.
- Every handler follows the same shape: `Fields::only` refuses unknown fields (verified AC-27a
  this way too), ids folded upper-case, one transaction with one audit row per write, a unique
  index backing every case-insensitive pre-check, every refusal naming its cause.

## QA

- Round 1: **PASS**, 0 defects. `docs/qa/T-027-catalog.md`.

## Round 2 — APPROVE (fix commit `103a7fc`, QA `262388c`)

Reviewer read every changed line and ran targeted tests (`pest --filter="R-1|W-2|AC-17"`: 55
passed, 320 assertions, 0 failed). All 6 round-1 items confirmed fixed:

- **R-1** refused at the earliest point — `$productId`/`$newParentId` are folded identically
  (`Identifier::fold`/`foldNullable`) before the `===` check, so a case/format variant can't
  slip past it. Test uses the real exploit shape and checks the row is byte-identical afterward.
- **W-1** fixed for every read named. The `product_components` whole-table lock genuinely
  closes the two-edge (A→B/C→D) cycle race under InnoDB REPEATABLE READ's gap-locking behavior
  — reviewer traced through why: a whole-table `SELECT ... FOR UPDATE` locks every existing
  edge plus the gaps around them (including an empty table), so any transaction that could
  complete a cycle must contend on at least one already-locked row.
- **W-2** fixed — `SnapshotCalculator` always returns quantity at `toScale(4)`, so the zero-check
  regex matches every zero form it can produce; runs after rounding, test matches the exact
  case raised.
- **W-3** fixed — two full-path exemption lists, each scoped to only its own rule, the
  factor-multiplication rule gets none; the new scoping test proves the exemption itself (not
  an accident of the scan's shape) is what keeps it green.
- **W-4** fixed — D-62 citation gone from the corrected locations, the addendum no longer
  splices a sentence, cites the current line.
- **W-5** fixed — 31 everywhere in the live docs (historical `docs/qa/`/`docs/reviews/` records
  correctly left untouched, since those describe what was true when written).

**New Warning (non-blocking, filed as a follow-up): W-6** `UpdateProductHandler.php:165` has
the same kind of gap as W-1, missed in round 1 — the new-parent read in `UpdateProduct` is
still unlocked, unlike `CreateProduct`'s now-locked equivalent. Not blocking (no HTTP surface
yet, FP-240).

**New Suggestions (non-blocking):**
- `AddProductComponentHandler.php:72-73` — concurrent adds of A→B and B→A take the two product
  locks in opposite order (a classic deadlock shape); MySQL detects and rolls one back with
  error 1213 rather than a named refusal — sorting the two ids before locking would avoid it.
- The edge-set lock reasoning assumes InnoDB REPEATABLE READ's gap locks — revisit if tenant
  connections ever move to READ COMMITTED.
- The whole-table component lock serializes all component writes in a tenant — fine for
  catalog write volume, noted so the pattern isn't copied onto a hot path without re-checking.

## Final verdict

**APPROVE. T-027 is DONE.**

## Verdict after Round 1

**CHANGES REQUESTED.** R-1 plus all 5 Warnings folded into one fix round — R-1 is a real
correctness bug, and each Warning is either a real data-integrity gap (W-1, W-2) or a factual
error worth fixing precisely (W-3 through W-5), not a style note.
