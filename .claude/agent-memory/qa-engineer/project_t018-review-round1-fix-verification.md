---
name: t018-review-round1-fix-verification
description: T-018 round 2 — verifying a dev fix for two reviewer Criticals (R-1, R-2) and seven Warnings in RedisStreamsEventBus; the partial-revert trap and the fake-client fidelity limit
metadata:
  type: project
---

T-018's code review round 1 found two Criticals in `RedisStreamsEventBus` (an error
reply from either phpredis or predis silently treated as success; `XPENDING`'s
group-wide range query instead of a per-claimed-id query). `dev` fixed both plus
seven Warnings in one commit (`94d895b`). Re-verifying a fix commit against a prior
clean PASS is a different exercise from a first-pass review — see
[[project_t018-eventbus-mutation-and-sqlite-lock-trap]] for the original close-out.

**A partial revert of a two-part fix can still go red, but for the wrong reason, and
that matters for the report.** R-1 shipped as two independent defenses: `raw()` now
throws on an error reply, and `assertStreamId()` separately refuses any
non-`<ms>-<seq>` reply. Reverting only `raw()` still produced a red test — but the
failure read "malformed stream id" (from `assertStreamId`), not the real Redis error
text the test asserts, because `assertStreamId` caught the resulting empty/garbage
string as defense-in-depth. This is a legitimate red, not a false pass, but it is not
the *clean* reproduction of the original symptom. Getting the clean symptom back
(a row marked `published` with `attempts:0` and `last_error:null` on a Redis error
reply — the literal data-loss the review described) required reverting the *whole*
file to the pre-fix commit, not just the one function the review named. When a fix
has more than one defensive layer, revert all of them together at least once to see
the original bug's real shape, in addition to the single-function revert that proves
the specific line matters.

**A scripted fake client can prove "the query shape changed" without proving "the
wrong value comes back."** R-2's `FakeXClaimRedisClient` keys its `XPENDING` reply
strictly off the exact id in the per-id call shape (`XPENDING stream group <id> <id>
1`); reverting the production code to the old group-wide shape
(`XPENDING stream group - + n`) sends it the literal `'-'` as the lookup key, which
matches nothing in the fake's id-keyed map, so it returns `[]` and the *other* half
of the fix (claim()'s strict `isset()` check) throws `EventBusUnreachable` rather
than silently returning a wrong value. The mutation still goes red — proving the
query shape is asked for correctly — but does not reproduce the original symptom's
*value* mismatch (the older id's larger count standing in for the newer id's smaller
one), because the fake does not model real Redis's actual "return the oldest N
pending entries" semantics for a query against an id that isn't in its map. Report
this as a Suggestion, not a defect: the shipped fix and its positive test (which
*does* discriminate, by giving the non-claimed id the larger count) are correct; only
the revert-and-reproduce-the-exact-wrong-value exercise is limited by fake fidelity.

**Reproducing an already-fixed test trap (W-7) needs the original mutation, not just
the test revert.** W-7 fixed a specific hole: catching plain `RuntimeException`
around a forced-rollback assertion let an unrelated SQLite "database is locked"
`QueryException` (which also extends `RuntimeException`) pass the test for the wrong
reason. To prove the fix matters, both pieces have to be back in their pre-fix state
at once — the test's catch reverted to `RuntimeException` *and* `OutboxWriter`
mutated to open its own transaction on a fresh connection to the same file (the
exact mutation that produces the lock error). Either one alone proves nothing: the
test revert alone has nothing to catch the wrong way, and the mutation alone against
the already-fixed `ForcedRollback`-only catch already fails loudly (which is the
fix working, not the trap). Expect ~65s per affected case on this SQLite fixture, per
[[project_t018-eventbus-mutation-and-sqlite-lock-trap]].

**Background-task polling during a ~130s-per-case or ~440s-full-gate wait is normal
here; the sandbox's own echo-based poll loop is cheap but the gate itself is not.**
Checking `Get-CimInstance Win32_Process` for the actual `pest`-running PID (not just
"is php.exe running", since this machine always has several unrelated php.exe
processes — other projects' dev servers) is the reliable way to confirm a long
background gate run has not silently died or been replaced by a second overlapping
one; cross-check elapsed wall time against the PID's `CreationDate` rather than
guessing from poll-call counts.

**Full re-run, same session, numbers reconfirmed:** lint passed, PHPStan 0 errors,
deptrac 0 violations/0 skipped, tests 2300 passed/12 failed/20 skipped (16747
assertions), 444.20s, ratio=2.03 — identical to `dev`'s own report and to this task's
Run 1 shape for the 12-failure FP-316 cluster. The skip count rose by exactly 1 over
Run 1 (19 -> 20), confirmed by re-running `EventBusContractTest` alone (9 skipped,
9 passed) — the one new R-2 `redis`-dataset row, not a gap.

**Why:** two Criticals fixed in one commit is a higher-stakes re-verification than a
first-pass review; a diff read alone would have missed both the defense-in-depth
masking (R-1 partial revert) and the fake-client fidelity limit (R-2), and only
re-running the original mutation 6 against the restored test proved W-7's fix is
real rather than merely plausible-looking.

**How to apply:** when a fix has two independent defensive layers, revert both
together once, not just the one the review named, to see the original bug's real
shape before reporting "confirmed". When a fake client fixture scripts replies by a
narrow key match, check whether a reverted production query would even land on a key
the fake recognizes — if not, say so as a limitation rather than letting a red
mutation imply more than it proves. When re-proving an already-fixed test trap,
revert the test AND reapply the original mutation at the same time; either alone
proves nothing. Related: [[project_t018-eventbus-mutation-and-sqlite-lock-trap]],
[[project-negative-control-every-guard]].
