# Code Review: T-018 (event-bus-outbox)

**Round 1 · Verdict: CHANGES REQUESTED** (2 Critical, 7 Warnings, 6 Suggestions)
**Round 2 · Verdict: CHANGES REQUESTED** (1 new Critical, R-3, introduced by the R-2 fix itself)
**Round 3 · Verdict: APPROVE** (R-3 fixed, 0 new Criticals, 2 Suggestions)

---

## Round 1

Diff: `ebd85be` (dev's implementation, 91 files). Criteria: AC-1 to AC-37. Decisions: D-1 to D-17.

### Gate, measured by the reviewer

`composer lint`: pass. `composer stan`: no errors. `composer deptrac`: 0 violations
(726 allowed). `composer test`, solo: **2278 passed, 12 failed, 19 skipped, 16697
assertions, 470.84s**, ratio 1.91 (below the 2x floor — FP-328, pre-existing and
narrowing, not a T-018 defect). The 12 failures are exactly FP-316's known cluster.
19 skips = 11 pre-existing + 8 Redis-adapter contract-dataset rows (D-1, no real
Redis connection in this environment). `ENGINE_HOLD` respected throughout.

### Critical

- **R-1** `RedisStreamsEventBus.php:331-362` (`run()`/`raw()`, used by `publish()`):
  Redis error replies (phpredis `rawCommand()` returning `false`, predis
  `executeRaw()`'s by-ref `$error`) were read as success instead of thrown. A
  `READONLY`/`OOM`/`NOAUTH` reply could make `publish()` return garbage as a
  message id, and the relay would mark the outbox row `published` even though
  the event was never written — exactly the loss the outbox pattern exists to
  prevent.
- **R-2** `RedisStreamsEventBus.php:124,265-286` (`deliveryCounts()`): queried
  `XPENDING stream group - + count($ids)` (the group's oldest N pending entries)
  instead of each claimed id specifically. A reclaimed message that wasn't the
  group's single oldest pending entry got the wrong delivery count (defaulted
  to 1), so AC-27's dead-letter-after-5-deliveries threshold never triggered on
  real Redis.

### Warnings (all fixed in round 2)

- **W-1** The "not claimable before minIdle" contract case advanced
  `RunnerClock`, which real Redis (its own wall clock) can't honor — the
  `redis` row of that case would fail against real Redis.
- **W-2** A failed `acknowledge()` after a successful `handle()` was treated as
  a handler failure, risking a false dead-letter on the 5th delivery even
  though the event was actually processed.
- **W-3** A DB failure while marking an outbox row `published` (after a real
  successful bus publish) was mis-counted as a publish failure, eventually
  landing the row in `failed` even though the bus had received it.
- **W-4** The config header promised loud validation of non-positive values;
  the handlers silently clamped with `max(1, …)` instead.
- **W-5** `relay.poll_seconds`/`consumer.poll_seconds` were declared but never
  read; both commands hardcoded `--sleep=1`.
- **W-6** The AC-5 single-inserter scan only matched a quoted string literal
  and exempted by basename, missing a `Foo::TABLE`-style second inserter or a
  same-named file elsewhere.
- **W-7** The rollback test caught every `RuntimeException`, letting a SQLite
  lock error pass the test for the wrong reason.

### Suggestions

- **S-1** Reclaim batch head-of-line blocking by oldest pending entries —
  filed FP-331.
- **S-2** `replay()` has no `MAXLEN` trim / empty-stream guard — filed FP-332.
- **S-3** Document that stream trimming can drop a still-pending message —
  filed FP-333.
- **S-4** Document dead-letter behaviour for a tenant that suspends mid-
  processing — filed FP-334.
- **S-5** `poll_seconds`/`--sleep` aren't validated against a negative value —
  fixed in round 2 alongside R-3.
- **S-6** Role note: dev ticked plan checkboxes (architect's file) — harmless,
  no action.

### Done well

Keeping the transport port internal so the outbox is the only way in for
modules (deptrac confirms it structurally). The validate-before-connect
control in `OutboxWriter`. The relay's in-process pacing with `attempts` as
the durable record. The proof-of-pipeline kept as a test fixture so
`modules/demo` stays byte-identical. An honest NOT VERIFIED framing
throughout. Two real bugs found by dev during testing, each with a
regression test.

---

## Round 2 — R-1/R-2/W-1 to W-7 fix, plus a new Critical

Diff: `ebd85be..94d895b`, 23 files changed (+?/-? — fix round).

### Round 1 items re-checked: R-1 and W-1 to W-7 all confirmed fixed by re-reading the diff.

### Critical (new)

- **R-3** `RedisStreamsEventBus.php:133` (`claim()`, calling `deliveryCounts()`
  at ~286-305): the R-2 fix itself introduced a regression. `claim()` passed
  the ids it *requested* from XCLAIM to `deliveryCounts()`, not the ids XCLAIM
  *actually returned*. XCLAIM legitimately returning fewer ids than requested
  (another consumer already took the message, or Redis trimmed it) is normal,
  not an error — but R-2's new "throw on a missing entry" branch fired for
  those un-claimed ids anyway, aborting the entire consumer group's pass with
  a false "Consuming events failed" error.

### New Warning (fixed alongside R-3)

- **W-8** No test existed for W-3's own fix (the published-update DB failure
  propagating rather than being mis-counted as a publish failure).

### Gate (round 2): contaminated, re-measured clean in round 3

The reviewer's own round-2 gate run overlapped with `qa`'s concurrent mutation
testing in the same worktree (the project's own "never spawn two agents into
one directory" hazard), producing 4 extra failures outside FP-316's cluster
that did not reproduce on a clean re-run. No code defect.

---

## Round 3 — the R-3/W-8/S-5 fix only

Diff: `94d895b..bd83ed5`, 8 files changed (3 source, 4 test, 1 dev memory note).

### Verdict: APPROVE

- **R-3**: `claim()` now parses the raw XCLAIM reply into `[id, rawFields]`
  pairs first, returns `[]` immediately if that list is empty, and only calls
  `deliveryCounts()` with ids XCLAIM actually claimed. The two failure paths
  are now correctly distinguished: a requested-but-unclaimed id never reaches
  `XPENDING` (returns `[]`); a claimed-but-pending-less id still throws
  `EventBusUnreachable` exactly as R-2 intended. Neither swallows the other.
- **W-8**: a `beforeExecuting` fault on the mark-published UPDATE (after a
  real successful bus publish) proves the failure propagates and the row's
  `attempts`/`last_error`/`status` stay unchanged — ruling out both "the
  catch boundary moved back" and "the publish actually failed" as
  explanations for a pass.
- **S-5**: both console commands now reject a non-positive `--sleep`/
  `poll_seconds` with `LogicException`, matching the W-4 convention. `qa`'s
  mutation test of the pre-fix code found this isn't merely cosmetic — the
  unvalidated path can hang a long-running worker in a genuine infinite tight
  loop when `Sleep::for()` is faked to return instantly and no
  `--once`/`--max-passes` limit is set.

All three fixes were independently mutation-tested by both `reviewer` and
`qa`: each change reverted, the corresponding test(s) went red for the exact
reason the finding described, then green again on restore.

### Gate, re-measured independently by both `reviewer` and `qa`

`reviewer`'s own run was contaminated by `qa` running concurrently in the same
worktree (4 extra failures traced to that overlap, explicitly flagged, not
counted against this task). `qa`'s own isolated re-run, clean: **2306 passed /
12 failed / 20 skipped, 16758 assertions, 473.47s**. The 12 failures are named
in full and are exactly FP-316's known cluster
(`ModulesEnvParsingTest`, `QaHealthProbeResilienceTest`,
`QaModuleNameValidationTest`, `QaModuleRegistryContractTest`). The 20 skips
match round 1/2's accounting (9 Redis-adapter D-1 skips including R-2's
dataset case, 11 pre-existing engine-group skips). `composer lint`/`stan`/
`deptrac` clean in both runs.

### Critical / new Warnings

None.

### Suggestions

- **S-6** No partial-claim test case (`[A, B]` requested, only `B` claimed) —
  the zero-claim and claimed-but-missing cases are both covered; this is the
  most realistic production shape. Optional.
- **S-7** The W-8 test could additionally assert the event reached the bus
  directly, stating "after a real successful publish" outright rather than
  only implying it. Optional — the test already proves it indirectly.

### Done well

The R-3 fix has the right structure: parse the actual XCLAIM result first,
short-circuit on empty, and only look up delivery counts for ids genuinely
claimed — with a comment explaining why the two id lists can differ. The W-8
test's single-statement fault injection rules out both possible regressions
at once. S-5 reused the existing W-4 validation convention instead of adding
a new one.

---

## Final verdict

**APPROVE.** Task ready to close. R-1, R-2, W-1 to W-7, R-3, W-8 and S-5 are
all verified fixed, independently, across three rounds, by both `reviewer`
and `qa`. The round-2 and round-3 gate contamination from a concurrent-agent
process hazard (not a T-018 defect) was identified and resolved with a clean
re-run. Open follow-ups: FP-331 to FP-334.
