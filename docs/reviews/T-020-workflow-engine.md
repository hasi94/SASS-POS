# Code Review: T-020 (workflow-engine)

**Round 1 · Verdict: CHANGES REQUESTED** (2 Critical, 7 Warnings, 4 Suggestions)
**Round 2 · Verdict: APPROVE** (R-1/R-2/W-4/W-6/S-4 fixed, 1 new non-blocking Warning)

---

## Round 1

Diff: `f29e7e8` (dev's implementation, 110 files). Criteria: AC-1 to AC-31. Decisions: D-1 to D-13.

### Gate, measured by the reviewer

`composer lint`/`stan`: pass. `composer deptrac`: 0 violations. `composer test`,
solo: 2615 passed, 13 failed, 22 skipped — 12 are FP-316's known cluster, the
13th (`QaRegenerationGuardTest`, CRLF) was later identified as caused by a
file-ending mismatch, not T-020. Gate headroom ratio 1.59 (D-1 floor is 2x —
flagged, addressed separately as FP-328, see below).

### Critical

- **R-1** `ListWorkflowDefinitionsCommand.php`/`ListPendingWorkflowTasksCommand.php`:
  used `$this->components->info()` per row, which dropped an AC-required
  column each — AC-23 needed `name`, AC-24 needed `instance_id`. Both
  handlers already returned these fields; only the output formatting
  dropped them. The claim that `twoColumnDetail()` "writes nothing
  `Artisan::output()` can see" didn't hold up against ~10 other commands in
  this codebase using it successfully.
- **R-2** `docs/qa/T-020-workflow-engine.md` (QA's own round-1 report)
  claimed tests existed for AC-18's exact event sequence and AC-20's four
  terminal statuses that didn't actually exist in
  `DecideWorkflowTaskTest.php` — only `cancelled` was tested in the AC-20
  dataset, and the mid-chain "still open" vote event (written at
  `DecideWorkflowTaskHandler.php:111`) was never asserted anywhere; deleting
  that line would have passed the whole suite.

### Warnings (3 fixed in round 2, 5 filed as follow-ups)

- **W-4** (fixed) `decide()` could throw `WorkflowEvaluationFailed` in
  addition to `WorkflowActionRefused` when advancing to the next step, but
  the contract docblock and the console command only handled the latter —
  an operator would see a raw stack trace.
- **W-6** (fixed) `workflow:define --as` wasn't validated against
  employee-liveness or folded to uppercase before storage.
- **S-4** (fixed, test-only) Several tests resolved `WorkflowGate::class` to
  call `decide()`, which belongs to the `WorkflowApprovals` contract — only
  worked because both happened to bind to the same engine instance.
- **W-2, W-3, W-5, W-7, S-2** (filed FP-364 to FP-368): `before`/`filter`
  hooks committing outside the dispatch transaction; `decide()` not
  re-checking the deciding employee is still active; the engine freezing
  the entire context instead of only the matched fields; several missing
  plan-step-8 test cases; console-acting-as-assignee not distinguished in
  the audit trail.

### Done well

Fail-closed is real — `WorkflowEngine::evaluate()` has a single
`catch (Throwable)` that rethrows, no code path returns "not required" from
inside a catch. A priority tie throws rather than silently picking one.
Money never goes through floats. `WorkflowEventLog` is the only writer of
`workflow_events`, insert-only. The decide path uses `lockForUpdate` plus a
conditional update with an affected-rows check.

---

## Round 2 — the R-1/R-2/W-4/W-6/S-4 fix only

Diff: `f29e7e8..d1e9a17` (+ a follow-up commit `55fccec` for one file the
lead missed staging). 13 files total: production commands/handlers, test
files, one contract docblock, plus an unrelated PHPStan exhaustiveness fix
the gate's own toolchain-coverage test caught in the same commit.

### Verdict: APPROVE

- **R-1**: both commands now use `$this->table()` with every AC-required
  column present; new command tests assert `Artisan::output()` contains the
  previously-dropped value (a real `name`, a real `instanceId`), not just
  exit code 0.
- **R-2**: the AC-18 test now asserts the exact 9-event sequence including
  the mid-chain vote (mutation-tested by both `reviewer` and `qa` — deleting
  the vote-event write makes the new test fail for exactly the right
  reason); AC-20 is now a 4-case dataset covering every terminal status.
- **W-4, W-6, S-4**: all independently confirmed fixed by both `reviewer`
  and `qa` — correct docblock, correct validation/folding, correct contract
  resolved in tests.
- One commit-hygiene miss: the `WorkflowApprovals.php` docblock half of W-4
  was left uncommitted (a different path than the rest of the fix round,
  missed when staging `d1e9a17`) — caught by `reviewer` (W-7) and committed
  separately as `55fccec`.

### New Warning (non-blocking, filed FP-370)

- **W-7** (reviewer round 2) — the commit-hygiene miss above; already fixed
  by the time this review closed, kept as a record.

### Gate

A real infrastructure problem surfaced while re-verifying this round: the
gate's own test-step timeout (900s) had fallen behind the suite's actual
runtime (1232s) and started failing outright with no result produced —
tracked for years as the narrowing FP-328, now a real failure. Fixed
directly by the lead (separate from T-020's own diff) across four
iterations, each catching a constraint `GateStepTimerTest` enforces that the
previous attempt missed: landed on `process-timeout: 7800`. A clean,
exclusively-held run after that fix: **2629 passed / 12 failed (exactly
FP-316's known cluster) / 22 skipped, ratio 9.75**, lint/stan/deptrac all
clean (one `QaRegenerationGuardTest` CRLF failure traced to another session
sharing this worktree, not T-020).

Both `reviewer`'s and `qa`'s own later full-suite attempts this round were
voided by that same other session's increasingly destructive activity in
the shared worktree (concurrent `pest` processes, then a wholesale deletion
of the `modules/demo` tree) — neither could get an exclusive run. Both
independently confirmed every T-020-touched file passes cleanly in
isolation, plus clean `stan`/`deptrac`, and had no reason to expect the
full-suite number to differ from the lead's own prior clean run.

### Done well

Each new test names the finding it closes and asserts the exact value that
used to be missing, not just an exit code. The W-6 check runs before any
write, so a refusal leaves nothing behind, exactly as the handler's own
docblock promises.

---

## Final verdict

**APPROVE.** Task ready to close. R-1, R-2, W-4, W-6, and S-4 are all
verified fixed, independently, by both `reviewer` and `qa`, across two
rounds. A separate, serious gate-infrastructure problem (FP-328) was found
and fixed along the way. Open follow-ups: FP-364 to FP-370.
