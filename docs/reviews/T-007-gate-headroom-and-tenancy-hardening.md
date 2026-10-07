# Review: T-007 Gate headroom and tenancy hardening

**Round 1 · 2026-10-07 · Verdict: APPROVE** (no Critical findings)

Reviewed `cef6a17..HEAD` — **22 commits**, re-derived from the files as they stand at `ec1d15e`.
The reviewer ran the gate twice itself, verified every framework claim in the two unauthored
commits against the installed vendor source, and read the removed provider at `cef6a17` to check
that the residuals are not regressions.

> The choke point is genuinely one point, the content binding is the right mechanism and is proved
> rather than argued, and the test suite is the strongest reviewed on this project: every absence
> assertion carries a control, the mutation run shows the guard tests red with the guard off, and
> the four partials are declared rather than hidden.

## The reviewer's own gate runs

Both `[state: WARM]`, on a contended machine (see W-3).

| run | result | test step | last line |
|---|---|---|---|
| 1, 13:15-13:31 | **3 failed / 461 passed (2395 assertions)**, error code 2 | 635.88 s | low-headroom at ratio 1.42, then the margin line at 635.88 s |
| 2, 13:32-13:39 | **exit 0, 464 passed / 2407 assertions**, sentinel absent | 271.04 s | margin line, ratio 3.32, source named |

Run 2 reproduces the lead's 464 / 2407 exactly. Run 1's three failures were all child-process
timeouts at an unrelated 60-second default — machine contention, measured rather than guessed, and
**not a defect in this branch**. See W-3, which is what that run actually found.

## Critical

None.

## Warnings

### W-1 · `packages/kernel/tests/Feature/TenantStanzaForgeryTest.php:538-539`

The false framework claim that `78ae627` corrected in the authority's docblock **survives here
verbatim**: that the framework makes the caller supply the connection name. It does not — it
defaults the key and invents a hashed name when none is given. The conclusion the comment draws is
unaffected, but the reason is the one this task has already recorded as wrong, in the file that is
the published proof of FP-161. FP-176's class, inside the task that filed against it.

### W-2 · `packages/kernel/tests/Support/tenancy-fixture.php:322-326`

"Absent today, so this is a no-op until FP-161 adds the key" was true at step 2 and **false from
step 4 onward**. The key is in every live stanza, and a live assertion three files away now asserts
the substitution *fires*. A comment that contradicts a passing assertion elsewhere is worse than no
comment.

### W-3 · The suite's own child-process timeouts are the FP-172 defect one layer in

17 toolchain files spawn child processes; **only `GateStepTimerTest` sets a timeout**, so the other
sixteen inherit a 60-second default. In run 1 that produced three red tests on a clean tree with no
code defect. The cause was measured, not inferred: unrelated suites from other projects started
mid-run, plus a development server holding 20.5 s of CPU.

A clean tree that reports red under load is exactly what FP-172 exists to abolish, and the 60 is an
absolute undated number in prose, which FP-171 forbids. **Out of T-007's scope** — a backlog row as
FP-172's sibling: give every child-spawning test an explicit, dated or derived timeout.

### W-4 · The process-wedge finding has no backlog row

Building a connection under the tenant name — *including with the genuine stanza* — poisons the
framework's retained dynamic configuration for the life of the process, so every later tenant entry
is refused. Fail-closed, pre-existing, pinned by a test, and a worker-wide outage if a module ever
does it. FP-163 covers the escape hatches but not this. Add the row before hand-off with the
candidate fix, noting that it is **name-specific**, which is what separates it from the property in
R12.

## Suggestions

- **S-1 — keep the UTC timestamp, and pin it.** Measured: this machine's PHP CLI timezone is
  Europe/Berlin, so local formatting would print `+02:00` — matching neither the commits (+05:30)
  nor UTC, and drifting with configuration. The reserved question's premise is false. The real gap
  is that **no test asserts the timestamp field at all**; add one to the kill case so a later edit
  reddens instead of silently shifting every timestamp.
- **S-2 — two stale line citations** (the name-keyed extension check, and the manifest read since
  step 1b). Cite the symbol rather than the line; both facts are already pinned by test, which is
  what makes the citation decoration rather than evidence.
- **S-3 — swap a defeasible justification for a structural one.** A forger *can* drop the strong
  reference the comment relies on. The residual is still harmless for a better reason: every value
  that selects a database is inside the digest, so an identifier collision alone cannot redirect a
  connection.
- **S-4 — keep the distinct exit status** (answering the spec's open question). It costs nothing and
  both halves of the pair are asserted in both directions. The condition is that nothing may treat
  the status *alone* as meaning "killed", which an existing case enforces.
- **S-5 — pin the exclusion list at exactly two keys by running.** The rule is sufficient and is
  pinned in the quiet direction. One cheap strengthening: forgery rows that change only the second
  framework-written key, and only the excluded one, so "two keys, not three" is behaviour rather
  than a sentence.
- **S-6 — one fabricated payload still emulates a shape that no longer exists**, and it is now the
  only occurrence of the old key left in test code. Rename it to the live key.
- **S-7 — the whole-gate form of one criterion is nearly closed by accident.** Run 1 was a failing
  gate at the normal limit: non-zero status, failures named, no sentinel, margin line last. That is
  the discrimination the criterion asks for, on a whole gate — but the failures were not planted, so
  it is not a literal satisfaction. QA can quote it as evidence in kind or run the planted form once
  during the re-test it has to do anyway.

## The eight items reserved for the reviewer

1. **UTC timestamp — approved as shipped.** The argument for changing it rested on a premise
   measured false.
2. **Per-step-only coverage — sufficient in kind.** Both streams are proved to traverse the wrapper
   and the child's status to propagate; the sentinel, not the status, is the discriminator. Run 1
   plus S-7 closes the remainder.
3. **The kill landing outside a probe window — accepted.** The criterion's own claim is that
   cleanup running first makes a survivor harmless; that is where T-005 proved it and this branch
   does not change it. The honest statement in the report is the right outcome, not a gap.
4. **The unguarded residual — accepted, and strictly narrower than before.** The removed listener
   guarded **only** the one connection name; the new factory guards that name, its read/write
   suffixes, **and** any stanza carrying the authenticator — which also closes the factory-direct
   route. The residual is smaller than the design it replaced, its floor is written beside the
   mechanism, and a Phase 0 row owns it. QA was right not to pin a hole with a test.
5. **The two-key exclusion list — the rule is enough**, with S-5 as the cheap pin.
6. **The replaced retention test — accept the loss.** The removed half could only be asserted
   through the implementation being deleted, and what it protected is now asserted more strongly by
   the choke-point test: exactly one callback in each list, plus a query passing for the resolved
   tenant and both a query and a transaction refused for another. One point for the record: the new
   weak-reference case would also pass with the old map present, so the reflection assertion is what
   pins the map's absence and the retention case is a property check. Both exist; neither is
   redundant.
7. **The four edits to QA-owned files — all four reviewed, all correct, three strictly stronger.**
   The one flipped expected value is a changed expectation, not a loosening: the message now names
   the resolved tenant, which is the point of FP-161. Residual: S-6.
8. **The findings mapping is below.**

## Findings mapping (for the QA re-run)

| finding | severity | verified by |
|---|---|---|
| W-1 | Warning | by reading; the forgery test asserts the authority's docblock only, not this comment |
| W-2 | Warning | by reading, against the live assertion three files away |
| W-3 | Warning | backlog row; not a T-007 criterion |
| W-4 | Warning | backlog row; behaviour already pinned by an existing refusal case |
| S-1 | Suggestion | if taken: a new assertion in the kill case |
| S-5 | Suggestion | if taken: two new forgery rows |
| S-2, S-3, S-6, S-7 | Suggestion | by reading / one gate run |

**No Critical findings, so there is nothing to map as "closed on measurement" — which is the honest
answer for round 1.**

## Process note

**P-1 — the range is 22 commits, not the eleven the lead's brief stated** (21 when the review
started; the status commit landed mid-review). Nine are the plan's mechanism-and-record steps, which
is probably what was meant. It changed nothing, because the reviewer re-derived from the file text
as instructed — but the brief had warned about exactly this shape, so it is on the record. The
lead's count was wrong; the mechanism-commit count is 12, not 11 either.

## Done well

- **The choke point is one point, and that is proved rather than asserted.** The framework-contract
  test pins the three vendor facts it rests on by reading method *bodies* with whitespace collapsed;
  the choke-point test pins the two installation invariants and that the decoration delegates to the
  factory it replaced rather than to the parent, using a counting inner factory. That is the right
  way to depend on vendor internals.
- **The content binding is the correct mechanism, and the refuted alternatives are recorded with
  measurements.** The digest's choices hold up under inspection: whole-stanza rather than a
  whitelist (two vendor behaviours verified as the reason), type-tagged and length-prefixed
  encoding, constant-time comparison, and the credential encoded by class and identity so its
  plaintext never enters the digest input.
- **The commit whose author never got to check it: every claim verified** — the constructor, the
  defaulted name and invented hash, the second key added after the check, the kill mechanism on
  Windows, and both dependency versions in the lock file. All correct, and its assertion delta is
  exactly the four expectations in its new case. The docs-only companion commit reconciles too.
- **The refusal surface.** The refusal echoes nothing the forger supplied and names only the
  resolved tenant; the rename is a real improvement, because the value it used to print was
  precisely the one a forger could compute. Sensitive-parameter marking throughout, and the
  credential-free refusal swept on every path with a detector control.
- **The wrapper is written the way this kind of tool should be**: no shell, the limit resolved
  locally because the package manager does not export it, the budget a ratio with a dated floor and
  the command that re-measures it, the source named on every line, and a four-branch exit space all
  four of which are asserted. One residual honestly marked as covered by reading, with the reason it
  cannot be run.
- **Run 1 is independent, accidental evidence for choosing the larger limit.** The test step took
  **635.88 s**. At the smaller limit the budget would have been 588, so the wrapper would have killed
  a clean, correct tree and printed the sentinel — the exact false red FP-172 exists to prevent. The
  "smaller fails, larger passes" ruling was arithmetic from a derived figure; this is the first
  measurement showing it mattered. The low-headroom warning also fired at 1.42 and stayed silent at
  3.32 — both directions, on a whole gate rather than a per-step probe.
- **Honesty under pressure.** Four declared partials, two rulings amended in place by measurement
  with the half each got right marked, a developer memory file that corrects its own performance
  claim, and a QA report that says "not run by me" where that is the truth.

## Routing

SendMessage was unavailable in the reviewer's session, so nothing was routed to `dev` directly.
None of the four Warnings is a blocker and there is no `[FIX] R-n` task from the reviewer; the lead
is taking W-1 to `dev`, W-2 and S-6 to `qa`, and W-3 and W-4 as backlog rows at hand-off.
