# Code review — T-004 gate speed and repository hygiene

| | |
|---|---|
| Verdict | **APPROVE** — no critical findings |
| Date | 2026-10-02 |
| Reviewer | `code-reviewer` (read-only) |
| Branch | `ai/T-004-gate-speed-and-hygiene`; `643e220` is the line-ending rule committed alone, the rest followed |
| Gate, measured by the reviewer independently | exit 0 — Pint passed, PHPStan level 6 no errors, Deptrac 0 violations, Pest **304 passed, 1358 assertions** |
| QA | run 1 FAIL (3 defects), run 2 PASS from scratch after the first QA agent was lost mid-run, then two fix rounds |

Infrastructure only. Four jobs: split the suite so the inner loop is fast, add a root line-ending rule, remove framework scaffolding, and move shared test helpers. Its defining risk was that a faster suite quietly running fewer assertions looks exactly like success.

---

## Coverage is genuinely intact, verified three ways

The reviewer proved it without using either the developer's or QA's arithmetic, and added an instrument neither had used.

By test-report set algebra on name and assertion count together: the full run minus the two halves is empty, the halves do not overlap, and **no case's assertion count differs** between the full run and whichever half it landed in. A test that was tagged and then quietly ran fewer assertions would appear as a difference. None does.

By source diff against the baseline commit, for all 42 test files, on every test declaration and on assertion-line counts: exactly three differences and two removals, nothing else. The three are the new fixture-isolation case, the new raw-versus-normalised case, and the declared rename, whose body is unchanged but for a variable name.

The structural claim also holds: the test configuration is byte-unchanged and contains no group declaration, and neither the test nor gate command carries a group flag. Tagging is incapable of subtracting from the gate.

The three properties are now **permanent assertions** rather than per-task artefacts, costing about 1.7 seconds, because the architect noticed that collection rather than execution decides group membership. The reviewer called that the single best decision in the task and asked that the general rule be carried forward: a coverage property expressible over the collected set costs a collection, not a run.

## Can the fast loop decay silently?

For the direction that matters, no, and the catch fires in the fast loop rather than at the end of the gate. The reviewer traced seven decay routes and found each covered, including the dangerous one where a non-spawning file is pulled *out* of the fast loop. Two gaps remain, both failing toward running more tests rather than fewer: a test that is slow without spawning a process, and a spawning test placed under a new top-level directory.

## Findings

**Four Major, none blocking.** A throwing delete running before a restore, split across two functions so no proximity search could see it. A README shipping a stale count and unlabelled timings. A QA report certifying a tree that no longer existed after a later fix. And the project guide having been edited by an agent when the plan reserves it for the human.

**Nine Minor**, including three assertions that cannot fail. The reviewer identified the mechanism behind two of them, which is worth keeping: replacing a silently-failing cleanup with a throwing one neuters every downstream assertion that the thing is gone. Two of this task's own assertions died that way.

All four Major items were closed before the commit. A fifth, found by QA while checking the worktree, was a megabyte of tool cache left untracked and un-ignored, which a staging command would have committed in the hygiene task itself.

## The teardown that can destroy committed content

The reviewer worked the error table rather than accepting the comment. A false positive deletes the backup while the restored tree still differs, destroying the only pristine copy. A false negative keeps a redundant copy and the next run refuses to start and names it. One cost, one catastrophe, so the strict comparison is right for the decision while the tolerant one remains right for the assertion in the test body. It endorsed the developer's framing as the better general rule: the choice depends on what the comparison *decides*, not on where the data came from.

## On the lost QA finding

A QA agent was stopped mid-run having reported only that it had found something critical. That finding was never written down. A replacement re-verified from scratch, did not find it, and said plainly it could not rule out having missed something.

The reviewer judged committing acceptable, and argued it rather than asserting it. Coverage loss, the task's defining risk, is now proved by three independent instruments and could not have survived all three. The families where something could still hide are only two, teardowns touching committed content and the intermittent failure, and that bounded surface is what makes the risk acceptable. It asked for one thing first: a sweep of the teardown family **by property rather than by mechanism**, since three findings in three passes over that ground is the signature of a surface not yet exhausted. QA ran it across 74 teardown blocks with a negative control and found exactly the one instance predicted.

The deciding factor was blast radius. This task touches no tenant data, no authentication and no schema. The worst realistic cost of a missed defect is a red gate or a recoverable working tree, on a branch. The reviewer said it would weigh the same unknown very differently against the master schema or the connection work.

**Root cause, and the process change adopted:** the finding was lost because it existed only in the agent's head. A QA agent that believes it has found something critical now writes the one-line symptom into its report before beginning to reproduce it.

## The pattern this project keeps producing

The reviewer's observation after four tasks, which supersedes the earlier one about false reasoning: the recurring defect is now **absolute measurements written into prose with no date and no mechanism to re-measure them**. Three of the four stale statements it found in T-004 were introduced by T-004. Its proposed convention, recorded as a backlog row: a number in prose either carries the date and conditions it was taken under, or is replaced by a ratio, or becomes an assertion.

## Done well

The coverage proof made structural rather than procedural. The registry check catching both the forgotten entry and the file wrongly pulled out of the fast loop, each naming the offending path, with a negative control that plants a real file and runs the real comparison. The detection pattern fixed so it no longer matches an ordinary database call, which would have swept the credential-leak tests into the excluded group and caused the very harm that rule prevents. The fixture-isolation criterion that names which other file would break if it failed. And the line-ending commit message, which explains why the rule is not cosmetic, gives the measured figure, names the review finding it closes, and records how the renormalisation was verified.

The honesty record was called out explicitly: a false premise deleted rather than worked around, a first fix reported as luck rather than success, advice to another role flagged as wrong rather than buried, and a plan author ruling three of four deviations as corrections to its own plan.
