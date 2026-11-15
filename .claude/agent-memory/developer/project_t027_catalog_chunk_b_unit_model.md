---
name: t027-catalog-chunk-b-unit-model
description: T-027 Chunk B (steps 5-8) - contracts, SnapshotCalculator, UnitLadder, units CRUD; the one cross-package test a new DeclaresAuditActions provider always breaks.
metadata:
  type: project
---

Measured 2026-11-08 finishing T-027 plan Chunk B (steps 5-8) on branch `ai/T-027-catalog`.
All five mutation checks named in the plan (M1-M8, skipping none) reddened for the exact
stated reason and were reverted clean.

**A new `DeclaresAuditActions` provider always breaks `packages/access/tests/Support/
AuditCatalogueTest.php`.** That file hardcodes the literal list of booted modules
(`['access', 'organization']` etc., two assertions) as of whenever it was last touched.
`CatalogServiceProvider` implementing the interface (to declare `catalog`'s two audit
actions) turned two of its cases red. This file was **not** in T-027's plan section 9
predicted-red list - the plan's own predicted list is written per-task and this cross-cutting
sweep is easy to miss. Fix is mechanical: add the new module name in sorted position in both
assertions. Flagged to the lead as a plan gap; same shape will recur for every future package
that declares audit actions.

**brick/math `RoundingMode::HalfUp` matches MySQL `ROUND()` exactly**, including ties away
from zero on negatives (`-2.5` -> `-3`, verified by direct script before writing
`SnapshotCalculator`). `BigDecimal::dividedBy($x, 6, RoundingMode::Unnecessary)` throws
`RoundingNecessaryException` (a `MathException`) on an inexact quotient - this is the
mechanism behind `inexact_factor`.

**Design actually shipped** (packages/catalog/src/Units): `Dimension` enum, `ConversionRow`
(one candidate row, already looked up by exact key), `UnitCatalogue` (in-memory map built by
whoever loads it - the pure `UnitLadderTest` builds it directly, `DatabaseUnitResolver` is
deferred to step 12), `UnitLadder::resolve()` (pure, five ordered steps, the ≥1 and
≤999999999999.999999 output guard applied uniformly in one `guard()` helper after every
step), `SnapshotCalculator::compute()` (pure, rounds entered quantity to line precision
first via `toScale(precision, HalfUp)` then rescales to 4, then computes
`base = roundedQty * factor` to 4 decimals, Q-11 checked only when the **stock** unit's
precision is 0).

Contracts shipped in `packages/contracts/src/Catalog/`: `UnitRefusal` (10 cases),
`FactorSource` (4 cases), `UnitResolutionRefused` (named constructors, `OrganizationRefused`
shape), `ResolvedFactor` and `UnitSnapshot` (readonly DTOs, shape-only validation: ULID fold,
DECIMAL(18,6) factor with regex `[1-9]\d{0,11}\.\d{6}` enforcing ≥1 at the DTO boundary too,
DECIMAL(18,4) amounts with `-?\d{1,14}\.\d{4}`), `UnitResolver` interface.

Units CRUD (`packages/catalog/src/Features/{CreateUnit,UpdateUnit}`) follows the
`CreateBranchHandler`/`UpdateBranchHandler` shape exactly: `Fields::only()` first, one
transaction, one audit row via `Contracts\Audit\AuditTrail::record()`, case-insensitive
`lower(code)` pre-check backed by the real unique index via
`UniqueConstraintViolationException` catch. `UpdateUnitHandler`'s cycle guard walks the
**whole** candidate chain (not one step) with a visited-set, because `CreateUnit` cannot
produce a cycle (a fresh row's id is referenced by nobody yet) but `UpdateUnit` can by
re-pointing `base_unit_id`.

Gate after Chunk B (steps 5-8): lint pass, phpstan 0 errors, deptrac 0 violations, **13
failed (exact pre-existing baseline) / 3105 passed / 25 skipped**, full `composer check`
~1818s warm. See [[gate-reads-live-filesystem-never-edit-during-it]] for why this run was
started only after confirming no php.exe process touched this worktree, and why the exit
code was read from a log file rather than a piped `tee`.
