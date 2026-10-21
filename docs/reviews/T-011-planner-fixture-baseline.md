# Code Review: T-011

Round 1, 2026-10-22, `reviewer`, read-only, over `fb4996e...HEAD`. The review did not run the gate. QA's run: `composer check` exit 0, 1590 passed, 11 skipped. QA's mutation ledger already proves the migration's pre-check semantics, the order of check then key, D-10, the sweeps and E4, so those are not repeated here.

## Verdict: APPROVE

## Critical (must fix)

None.

## Warnings (should fix)

- **W-1: four T-011 tests require this migration to stay the last file of the shipped tenant tree**, so the next tenant migration of any phase turns them red. That brings back, on a smaller scale, the coupling FP-304 removed. It fails loudly, not silently, and AC-13 does say "the highest-versioned and last in sort order", so it does not block. Sites:
  - `modules/host/tests/Feature/SettingsUpdatedByKeyTest.php:326,330-331`: checks `version == count($catalogue)` and that the file is the last path.
  - `modules/host/tests/Feature/QaSettingsUpdatedByEdgeTest.php:66,69,85,242`: checks that the last entry is a contract step, boots files 1 to N-1 and applies the last path.
  - `packages/kernel/tests/Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php:234`: checks `$v` equals the shipped highest version.
  - The same file at `:414` and `:419-420`: expects the second `--all` run to land on V, with run targets `[V-1, V]`.

  Fix:
  - QaSettingsUpdatedByEdgeTest: find the entry by name, as `SettingsUpdatedByKeyTest::settingsKeyEntry()` does, and boot with the paths below its version.
  - ShippedTenantTreeTest:234: drop the highest-version equality, because AC-9 only needs V to be the highest contract step.
  - ShippedTenantTreeTest:414 and :419-420: compare the second run with `$set->highestVersion()` and expect targets `[$v - 1, highest]`.
  - SettingsUpdatedByKeyTest:326 and 330-331: replace the check with "one above the highest version that existed before this file", or mark it as a T-011-time property.

## Suggestions (optional)

- **S-1: the gap between the check and the key on a real engine** (`2026_10_21_000401_…:60-79`). Not a defect.
  - On MySQL/MariaDB with `foreign_key_checks=1`, a row written between the count and the `ALTER` is caught by the `ALTER`, which validates existing rows. The tenant then fails alone with error 1452. That message names the constraint, not a value, and the `ALTER` has no bindings, so D-10 holds.
  - The only way in is a session with checks off, which is FP-305 territory.
  - DDL commits implicitly, so a transaction would not help.
  - Say in the docblock that on an engine the `ALTER` re-checks, so the pre-check there exists for the clean message and for parity, not as the only guard.
- **S-2: lock cost on a real engine.** Adding the key with checks on is `ALGORITHM=COPY`, which blocks writes to `settings` while it runs. `requires_offline: false` is right for a small configuration table. Say so in the docblock.
- **S-3: test noise.** `SettingsUpdatedByKeyTest.php:582` and `SettingsUpdatedByContractRunTest.php:314` print diagnostics on every run. Consider printing only on failure.
- **S-4: the six-file coupling.** It is documented in the parent `TenantMigrations/README.md`, and QA's pin guards it. A one-line `Baseline/README.md` pointing there would make it impossible to miss.
- **QA's notes N-1 and N-2 need no more than a note.**
  - N-1: the two allowed readers are the files whose subject is the shipped tree.
  - N-2: the table count, `SchemaCataloguePublishTest` and `.env.example` still catch a second tableless file.

## Done well

- **The migration is correct as written.**
  - One query-builder count runs before `Schema::table`, with no driver branch and no `down()`.
  - An empty string counts as unknown.
  - Soft-deleted employees count as existing (D-8).
  - Letter case is compared exactly on every engine: `ascii_bin` through `IdentifierColumn` on MySQL/MariaDB, and byte comparison on SQLite.
  - The message carries only the count and fixed text (D-10).
- **The corpus is independent of the product tree.**
  - `runFixtureSet()` composes only the fixture baseline, and a missing directory throws instead of falling back.
  - `runFixtureShippedSet()` is the single entry point to the shipped tree.
  - AC-4 swaps scratch trees in, with a control showing the entry point moved.
- **The config override cannot leak.** Every caller rebuilds the application before each test.
- **The pre-existing test edits are E1–E5 only.** Every hunk was checked: fleets that started at 0 still start at 0, and E5's narrowing has a replacement assertion.
- **The tests have controls that go red.** In particular:
  - "Ruling 7, measured" shows the SQLite rebuild carrying dirty data both ways.
  - AC-9 is shown to fail rather than skip.
  - AC-11 runs the remedy parsed from the refusal.
  - The D-10 checks use distinctive sentinels.
- **The decision log is honest about the six-file coupling and the AC-8 scope.**

## Lead's routing of round 1

- **W-1 is fixed now, not filed.** Leaving it would make the next tenant migration (most likely FP-209's) edit T-011's tests, which is the defect class FP-304 exists to remove.
  - `ba` amends AC-13.
  - `dev` fixes `SettingsUpdatedByKeyTest` and `ShippedTenantTreeTest`, plus S-1, S-2 and S-4.
  - `qa` fixes its own `QaSettingsUpdatedByEdgeTest`.
- **S-3 is left as is:** the printed evidence is the house style for sweeps (D-27).
