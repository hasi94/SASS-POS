# Code Review: T-019 (plugin-framework)

**Round 1 · Verdict: CHANGES REQUESTED** (1 Critical, 7 Warnings, 7 Suggestions)
**Round 2 · Verdict: APPROVE** (R-1/W-1 fixed, 1 new non-blocking Warning, 2 Suggestions)

---

## Round 1

Diff: `87a30a7` (dev's implementation, 165 files). Criteria: AC-1 to AC-48. Decisions: D-1 to D-15.

### Gate, measured by the reviewer

`composer lint`: pass. `composer stan`: no errors. `composer deptrac`: 0 violations
(923 allowed). `composer test`, solo: **2499 passed, 12 failed, 21 skipped, 17714
assertions, 558.86s**, ratio 1.61 (below the 2x floor — FP-328, pre-existing and
narrowing faster than qa's own 1.92 measurement, not a T-019 defect). The 12
failures are exactly FP-316's known cluster, none touching plugin-sdk/platform/
contracts. `ENGINE_HOLD` respected; AC-28 (Redis) and AC-48's MySQL half correctly
NOT VERIFIED, not silently skipped.

### Critical

- **R-1** `Gateway.php` (five skip branches) / `HookInvoker.php`: a refused
  plugin (or a tripped breaker) was skipped in every dispatch mode BEFORE
  `HookInvoker` was ever called, so AC-31's "it logs each refusal" was never
  actually met — even though `HookInvoker` had a working refusal-logging branch
  that was simply unreachable. `runFilters` had an additional gap: refused
  plugins never appeared in its own `$skipped` list, unlike every other mode.
  Plan Ruling 10 and step 11's own test requirement were both silently missed.

### Warnings (2 folded into round 2, 5 filed as follow-ups)

- **W-1** (fixed in round 2) A slow `before`-hook that returns a `Veto` object
  fell through to the timeout check and could fail open (allowing the gated
  action through), while a thrown `VetoSignal` correctly short-circuited —
  asymmetric handling of the two legal veto forms, with real consequence for
  FP-214's future approval hooks.
- **W-2 to W-7** (filed FP-343 to FP-347): `before`/`filter` hook writes commit
  outside the dispatch transaction and aren't rolled back on veto; the
  migration-rule allow-list has real gaps (not a security boundary, but
  overstates what it guards); a manifest's `requires` field isn't validated
  at registration (fails closed at enable time instead); uninstall can delete
  tracking records without dropping/exporting tables if the package path is
  gone; `failure_count` is also written outside `CircuitBreaker`, contradicting
  its own "sole writer" docblock.

### Suggestions (all filed as follow-ups, FP-348 to FP-353)

Version-constraint edge cases in `^0`/`^0.0` and pre-release core versions; an
`override` veto silently falling back instead of counting as `error`; per-call
savepoint/write overhead on the hot path; execution-log rollback visibility
after a caller exception; a time-advance breaker test that never actually
dispatches; `ManifestCache`'s process-lifetime caching needing a worker
restart to pick up a core-version change.

### Done well

`after`-mode genuinely rides T-018's real outbox and `eventbus:relay`/
`eventbus:consume` commands — `type` is `plugin.<point>` with plugin identity
in `aggregate_id`/`actor`/`payload`, cleanly satisfying D-15. Uninstall finds a
plugin's own tables by reading its migration files, never by prefix match, and
exports before dropping. The circuit breaker uses two separate UPDATE
statements rather than one `CASE`, a careful MySQL/SQLite compatibility
choice. The runtime caches nothing about plugin *state* (trip/enable), so
another process's change is seen on the very next dispatch. `isCurrent()`
gives plugin and platform licences one shared "current" definition.

---

## Round 2 — the R-1/W-1 fix only

Diff: `87a30a7..69cf73c`, 7 files (2 source, 4 test, 1 new test fixture, 1 doc).

### Verdict: APPROVE

- **R-1**: `HookInvoker::refuse()` extracted as a public method (the existing,
  previously-unreachable write path moved into its own method, not a new write
  path) and called from all five `Gateway` skip branches; `runFilters` now
  adds the refused plugin to `$skipped`. `docs/database.md` §6.20 documents
  `refused` as a valid status, consistent with what the code writes (the
  column is a plain VARCHAR, so no schema mismatch is possible). Verified by
  both `reviewer` (read every call site) and `qa` (mutation-tested each of
  the five branches independently — `runBefore`'s call is regression-tested;
  the other four are correct by direct read but currently untested, filed as
  FP-357, non-blocking).
- **W-1**: `HookInvoker` now treats a returned `Veto` the same as a thrown
  `VetoSignal` — checked before the timeout path, not after. The new
  `SlowVetoBeforeHook` fixture combines both conditions (slow AND returns a
  veto) in one handler, and the test asserts the action was actually blocked
  (result null), not merely that no timeout was recorded. Both `reviewer` and
  `qa` independently mutation-tested this and confirmed it goes red for the
  exact reason described.

### New Warning (non-blocking, filed FP-354)

- **W-2** The returned-`Veto` short-circuit now applies to every hook mode,
  not only `before` — an `override`/`add` handler returning a `Veto` is
  silently dropped (the core action runs unopposed, or the contribution is
  dropped) rather than being an error. Consistent with how a thrown
  `VetoSignal` already behaved in those modes, but widened without an
  explicit decision.

### Suggestions (filed FP-355, FP-356)

A now-unreachable defensive check left in `Gateway::runBefore()`; the
`docs/database.md` "As built" note overstating which code paths write a
`refused` row (`PluginAfterHookConsumer`'s error-status throw doesn't call
`refuse()`).

### New minor QA finding (filed FP-357)

- **BUG-3** Only `runBefore`'s `refuse()` call is regression-tested; the other
  four branches' calls are correct by direct code read and by qa's own
  mutation testing, but have no dedicated test. A proof gap, not a behaviour
  gap.

### Gate, re-measured independently by both `reviewer` and `qa`

Both hit genuine worktree-sharing contamination this round (two agents
running `composer check` concurrently, plus a stale `config:cache` artifact
that outlived the other process's PID) and both correctly identified and
discarded the contaminated runs before reporting clean ones. Clean numbers,
matching exactly between `reviewer` and `qa`: **2500 passed / 12 failed
(exactly FP-316's named cluster, nothing else) / 21 skipped**, ratio 1.71-1.92
across runs (FP-328 continuing to narrow — tracked, not this task's to fix).

### Done well

R-1 reuses the existing write path instead of adding a second one, so
`invoke()` and the gateway now share one refusal path. All five skip branches
are covered the same way, including the `runFilters` gap. The W-1 fixture
combines both failure conditions in one handler rather than testing them in
isolation, and the test checks the user-visible outcome (action blocked), not
just an internal status flag.

---

## Final verdict

**APPROVE.** Task ready to close. R-1 and W-1 are verified fixed,
independently, by both `reviewer` and `qa`, with both correctly navigating a
worktree-contamination hazard along the way. The only gate failures throughout
were FP-316's known, pre-existing, unrelated cluster. Open follow-ups:
FP-343 to FP-357.
