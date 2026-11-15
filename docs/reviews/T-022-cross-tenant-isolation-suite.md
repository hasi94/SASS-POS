# Review: T-022 — Cross-tenant isolation suite

## Round 1 — CHANGES REQUESTED (commit `a9dafe4`)

**Criticals**

- **R-1** | `QaRouteCrossTenantSweepTest.php` ~168-183, 601, 664 | AC-3/AC-4's read sweeps iterated hardcoded constants (`QA_ROUTE_SWEEP_BY_ID_READS`, `QA_ROUTE_SWEEP_COLLECTIONS`) instead of the live route registry — a new tenant-gated GET route could leak with the gate staying green. | Fix: derive both lists from `qaRouteSweepRoutes()` at run time; keep the constants only as a regression floor.
- **R-2** | `QaRouteCrossTenantSweepTest.php` ~856-901 | AC-7's planted-route test asserted directly on the plant's response, never on the sweep's own detector logic — a broken detector would still pass. | Fix: extract the attack bodies into findings-returning functions shared by the real AC-3/AC-4/AC-5 tests and AC-7's planted-route test.
- **R-3** | `QaJobCrossTenantSweepTest.php` ~279-296, 380 | `qaJobSweepPayloadFinding()` searched for a fixture credential value the generic test payload could never contain by construction — structurally unable to fail. | Fix: check the payload for a tenant-id sentinel and the plan's forbidden-needle list (`env://`, `password`, `credential`, `secret`).

**Warnings folded into the fix round:** W-1 (AC-9's negative control never ran the real detector), W-2 (job scan silently skipped an unloadable class instead of reporting a finding).

**Warnings/suggestions filed as follow-ups (out of scope for the fix round):** FP-379 through FP-387 (W-3 to W-8, S-1 to S-6) — cache-key site-detector gaps, route write-sweep coverage gaps, `terminal.settings` exclusion, the `PlanLimits` scoped-singleton production bug, T-019's own non-discriminating mutation, a missing recycled-worker job scenario, after-mode plugin-hook delivery, and two documentation drift items.

## Round 2 — APPROVE (fix commit `ed974d7`)

No new Critical items. All three round-1 Criticals independently re-verified closed; W-1/W-2 confirmed folded in correctly.

**R-1 closed.** `qaRouteSweepDerivedReads()` builds the by-id/collection lists from the live registry (class `tenant`/`terminal`, method `GET`, split on `{` in the URI). The old constants are now a floor checked unconditionally by `qaRouteSweepAssertReadFloors()` in both AC-3 and AC-4.

**R-2 closed.** The extracted `qaRouteSweepByIdAttempt()`/`qaRouteSweepCollectionAttempt()`/`qaRouteSweepWriteAttempt()` match the old inline logic line-for-line, and AC-7 now feeds the planted routes through the same functions the real checks use. The AC-7 write plant was switched from a fixture-only `marker` table (invisible to the shared detector) to a real tenant-tree table (`AccessSeed::branch()`) — a sound substitution that makes AC-7 prove something real. While wiring this up, dev found and fixed a genuine latent bug: `qaRouteSweepTenantState()`'s `json_encode()` returned `false` (cast to `""`) on any row with a binary hash column, making snapshot comparisons silently always-equal. This had been masking a hole in all 28 of AC-5's write checks (invisible there because AC-5's real routes never actually cross tenants) — AC-7's planted write, which is *supposed* to differ, is what caught it. Confirmed independently by QA with a standalone reproduction (`inet_pton()` on the fixture's default activation IP packs to invalid UTF-8, which every seeded world writes into `access_log.ip` on every run).

**R-3 closed**, matches the plan exactly. `qaJobSweepTenantIdSentinel()` plus the forbidden-needle scan both independently re-derived and reproduced.

**W-1/W-2 closed** per the round-1 fix description, confirmed by both qa and reviewer.

### New Warnings (non-blocking, filed as follow-ups)

- **W-A** (FP-388) | `QaRouteCrossTenantSweepTest.php:943` | The `JSON_INVALID_UTF8_SUBSTITUTE` fix maps every invalid byte to the same replacement character, so two rows differing only inside a binary hash column can still encode identically — the docblock's "byte-for-byte-sensitive" claim is false for those columns. Any other `json_encode` failure mode (depth, INF/NAN) still collapses silently to `""`. Fix: use a lossless, loudly-failing encoding (`serialize()`, or `JSON_THROW_ON_ERROR` plus `bin2hex()` on known binary columns), and correct the docblock.
- **W-B** (FP-389) | `QaRouteCrossTenantSweepTest.php:1052-1061` | AC-7's planted by-id/write assertions check for *any* non-empty finding, not specifically the leak/diff finding — a status-only finding would also satisfy them. Tighten to assert the specific "cross attempt leaked" / "changed tenant B" text.
- **W-C** (FP-390) | `QaRouteCrossTenantSweepTest.php:350,360` | `qaRouteSweepWriteAttempt()`'s non-id branch hard-codes `'tenant-b'` as the victim; a caller passing a swapped world (as AC-5 already does elsewhere) could diff the attacker's own tenant and miss a leak. Fix: carry the victim fixture name in `$world` instead of hard-coding it.
- **W-D** (FP-391) | `QaOrganizationTenantIsolationTest.php:138`, `QaAccessGuardIsolationHttpTest.php:181` (outside T-022's files) | The same silent-`""` `json_encode` pattern exists in these older isolation tests; if any snapshotted table has a binary column, their "nothing changed" checks are vacuous for that table. Needs its own follow-up, not fixed under T-022.

### New Suggestions (non-blocking, filed as follow-ups)

- **S-A** (FP-392) | `QaRouteCrossTenantSweepTest.php:840-859` | AC-4 sends each collection request twice (once for its own control, once inside the attempt function). Have the attempt return its responses, or move the own-control inside it.
- **S-B** (FP-393) | `QaJobCrossTenantSweepTest.php:132-209` | `qaJobSweepScanFindings()` duplicates `qaJobSweepQueuedClasses()`'s file walk almost line for line; a single walker returning both the found list and findings would stop them drifting apart.
- **S-C** (FP-394) | `QaJobCrossTenantSweepTest.php:450` | `class_exists('QaJobSweepAmbientTrustingJob', false)` checks a global name in a file under `namespace Tests\Feature`, so the guard never actually matches; harmless today since Pest loads the file once, but should be `class_exists(__NAMESPACE__.'\\QaJobSweepAmbientTrustingJob', false)` to say what's meant.

## QA

- Round 1: PASS, 0 defects (`docs/qa/T-022-cross-tenant-isolation-suite.md`).
- Round 2: PASS, 0 defects. Independently re-verified all three Critical fixes via revert→red→restore→green, including a standalone reproduction of the `json_encode`/binary-IP mechanism outside Laravel. Full gate: 2750 passed / 12 failed (pre-existing `.env`/MODULES baseline gap, unrelated) / 23 skipped — matching round 1's numbers.

## Final verdict

**APPROVE.** T-022 is DONE.
