# Code Review: T-014 (branches-warehouses-terminals)

**Round 1 · Verdict: APPROVE** (0 Critical, 7 Warnings, 2 Suggestions)
**Round 2 · Verdict: APPROVE** (0 Critical, 0 new Warnings, 2 Suggestions carried forward)

---

## Round 1

Diff range: `b42c738..HEAD` (the T-011 tip, not `main` — `main` has unrelated tasks
merged into it from other sessions since this branch forked; diffing against it
would show their work as if this task had done it). **18 commits, 246 files
changed, +21734/-118.** Criteria: **AC-1 to AC-64**. Decisions: **D-1 to D-50**, no
gaps.

### Gate, measured by the reviewer

`composer check` from `modules/host`, solo run: exits non-zero (2), **only because
of FP-316** (see below). Pint passed. PHPStan: no errors. Deptrac: 0 violations, 0
errors, 546 uncovered, 513 allowed. Tests: **12 failed, 11 skipped, 1920 passed
(14512 assertions)**, 671.93s. All 12 failures are **FP-316**, a known, filed,
pre-existing defect confirmed independent of this task — a deterministic state
leak in the module-registry/bootstrap test cluster, rotating across which
specific file carries it. No T-014 test failed. Excluding FP-316, the gate is
green. `ENGINE_HOLD` respected throughout; AC-6, AC-16 and the concurrent half of
AC-43 are correctly NOT VERIFIED `[ENGINE]`.

### Critical

None.

### Warnings (all fixed in round 2, see below)

- **W-1** AC-52's QA row didn't record that an old T-010 criterion ("a well-formed
  id for which no branch exists is accepted") was withdrawn *on purpose*, as AC-52
  itself requires.
- **W-2** Two more pre-existing T-010 test files (`AttachBranchTest.php`,
  `SetEmployeePermissionTest.php`) were edited and never declared in the plan's
  section 3/9 tables; two already-declared rows had the wrong step number.
- **W-3** `ActivationThrottle`'s counter read-then-write was not atomic —
  concurrent failures could overwrite each other's count under the production
  `database` cache store.
- **W-4** AC-30's "nothing written" check compared row *counts*, which misses an
  UPDATE (an insert/delete-safe check, not a content-safe one).
- **W-5** AC-60's domain assertion didn't include the five new organization
  tables in its `toContain` list, even though the scan's domain did.
- **W-6** Two stale statements in the spec: the header said "D-1 to D-45" against
  a real range of D-1 to D-50; AC-4 said "six keys" against the shipped
  migration's actual nine.
- **W-7** Gate time headroom: 671.93s against the 900s limit (ratio 1.34,
  LOW-HEADROOM), worse than QA's own 536s shortly before — the same shrinking
  margin FP-314 already tracks, not a new defect.

### Suggestions (not blocking, filed as follow-ups)

- **S-1** `ActivationThrottle::recordFailure`'s `block(5, …)` throws
  `LockTimeoutException` if the lock is held 5s+, which would turn a normal
  refusal into a 500 and leave the attempt uncounted. Unlikely (needs a holder
  dying mid-lock), but worth a catch-and-fallback.
- **S-2** The activation code's SHA-256 hash is unsalted; an HMAC with a
  server-side key would close an offline-brute-force window within the 24h
  lifetime. Low priority — such an attacker already has the shop's data.

### Done well

The guards examined (`Escalation::requireActor`, `SetPinHandler`'s authorization
order, `SystemRole::isHeldBy`, `BranchScope::requireUsable`, the contract
migration's pre-checks, `SettingsRules`, the activation code parser) all refuse on
missing or malformed input rather than permitting. AC-30's single refusal comes
from the code's structure — one throw site, one renderer — not just from tests.
`QaActivationCodeSecrecyTest` genuinely covers the domain its name claims: every
column of both databases, every sink, a live ERROR-level log check, and planted
controls for six sink kinds. Revocation has a clean tenant-then-master split, safe
to retry.

### Not checked (round 1)

Did not re-run QA's nine mutations. Did not read all 74 organization source files
line by line (covered the activation/refresh/revoke/throttle/middleware/
settings/branch-status/access/migration paths; skimmed the rest). Spot-checked
AC-27, AC-30, AC-31, AC-52, AC-60, AC-61 individually rather than every AC. Did
not investigate FP-316. Could not read `modules/host/.env` (permission denied);
irrelevant since `ENGINE_HOLD` is a code constant.

---

## Round 2 — changed items only

Six commits since round 1 (`94765b7`, `8d1fc9d`, `f2585de`, `a1071ca`, plus two
backlog-only commits not in scope for review). HEAD `a1071ca`, tree clean.

**All six round-1 Warnings confirmed fixed, each independently re-diffed by the
reviewer rather than taken on trust:**

- **W-6** — `docs/decisions/…` runs D-1 to D-50 with no gaps/duplicates, verified
  by reading every heading. The shipped migration's `KEYS` array has exactly nine
  entries, matching the spec's corrected AC-4 list name-for-name.
- **W-2** — `git diff --numstat b42c738 HEAD` confirms +18/-0 and +21/-0 for the
  two files, each a `use` import plus one new case, no existing assertion
  touched. Both landed in `9e65db6` (step 5), matching the plan's label. The two
  seeding helpers' own docblocks say "T-014 step 6", matching their relabel.
- **W-3** — read `ActivationThrottle.php:87-107`: both the read and the write are
  inside the `$write` closure, and only that closure runs under
  `lock(...)->block(5, $write)`, so the lock covers both steps. Every cache store
  this project configures (`database`, used in production; `array`, used in
  tests) implements `LockProvider`; the unlocked fallback is reachable only on
  the unused `octane` driver. Laravel releases the lock in a `finally`. **The
  reviewer did not run the mutation itself** (read-only role — reverting the
  wrapper means editing a tracked file) but confirmed by reading that a reverted
  wrapper produces `['get','put']`, which fails the test's exact-sequence
  assertion; QA's independent fresh re-run of this mutation (reported separately,
  verified by the lead) is the actual executed proof.
- **W-4** — read `activationTableDigest()`: `serialize()` on each row (which
  distinguishes `null`/`''`/`0`/`"0"`, preserves binary data byte-for-byte, and
  uses exact-round-trip float precision), sorted before joining, then `md5()`'d.
  Column order is stable (same `get()` against the same schema each time), and
  the test's `CACHE_STORE=array` means the throttle's own cache writes can't
  perturb the digest. No collision or false-difference path found.
- **W-5** — ran `QaPinResetRuleTest.php` directly: the printed scan domain
  ("AC-60 scanned 50 tables, 515 cells") includes all five new organization
  tables.
- **W-1** — `docs/qa/…`'s AC-52 row now states the withdrawal was intended, not a
  regression, exactly as the criterion requires.

### Gate, re-measured by the reviewer

`composer check`, solo, 8 other `php.exe` processes present beforehand: **12
failed, 11 skipped, 1921 passed (14518 assertions)**, 584.18s — matches the
lead's and QA's numbers exactly. The 12 failures are again FP-316, this run
spread across `ModulesEnvParsingTest` (1), `QaHealthProbeResilienceTest` (2),
`QaModuleNameValidationTest` (3), `QaModuleRegistryContractTest` (6) — one of the
rotation shapes already on file. `FLEXIPOS-GATE-LOW-HEADROOM ratio=1.54` printed
(FP-314's record, not new). `composer check` stopped at the failing test step
(exit 2), so `probes:clean`/`engine:clean` did not run in this solo invocation.

### New findings

None blocking. One minor note on the test double: `RecordingLock::block` logs
`'lock-released'` just before the real lock releases — still proves the
read/write are bracketed, label is slightly early. Not a defect.

### Not checked (round 2)

Did not run the W-3 mutation directly (role constraint, see above — QA's
independent run is the executed proof). `probes:clean`/`engine:clean` did not run
in this gate invocation. Did not re-investigate FP-316 or the backlog-only
commits, as instructed.

---

## Final verdict

**APPROVE.** Task ready to close.
