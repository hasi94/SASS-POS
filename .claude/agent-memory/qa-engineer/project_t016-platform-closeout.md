---
name: qa-t016-platform-closeout
description: T-016 (subscriptions/plans/licensing) QA close-out - the concurrent-gate contention trap, the console-command gap found, and what full mutation verification of 10 mutations looks like
metadata:
  type: project
---

**Never run ad-hoc `pest` in a worktree while `composer check` is running in the background in
that same worktree.** Both touch the same `modules/host/storage/app/tenancy-fixture/*.sqlite`
files. The collision does not look like a lock error - it looks like real defects: wrong HTTP
status codes (`QaAccessGuardIsolationHttpTest` got 401 where 422/200 were expected),
`MissingAppKeyException`, and a `PlanLimitReached` thrown against a limit that should not have
been hit yet (debugged down to a leftover branch row with a wall-clock `created_at` that could
not have come from the fixed `RunnerClock` in my own test - it came from another process's
fixture writes landing in the same file). The tell is a `Resource temporarily unavailable` or
`Directory not empty` warning during fixture teardown in the log. Treat any gate run that shows
one as contaminated and re-run it alone before trusting its failure list - one whole gate run
was discarded this way on T-016. See also [[gate-split-and-shared-state]].

**A source-scan test that keeps passing "too easily" is worth checking its own directory list.**
Found BUG-1 this way on T-016: `QaPlatformSourceRulesTest.php`'s four `packages/*/src` scan paths
were all one `dirname()` level short and resolved to nothing, so the AC-15/AC-4 scans had only
ever been reading `modules/host/app`. See [[negative-control-every-guard]] for the mechanism and
the permanent fix (an `is_dir()` assertion over the directories list itself).

**A "ships as console commands, no HTTP surface" design needs the console commands checked for
existence, not just the handlers.** T-016's AC-33 named four operations (change plan, change
subscription status, change licence status, set a tenant flag override) as needing "internal
handlers plus one console command each" because no HTTP surface exists by design (D-21). The
handlers were all built and well-tested; none of the four got a console command - only the
unrelated scheduled sweep (`platform:enforce-deadlines`) exists as an Artisan command. `grep -rn
"extends Command" packages/<pkg>/src` plus "does any test call `Artisan::call()` against it"
is the two-second check that would have caught this; it is cheap enough to run on every task
that claims a console-only operator surface.

**Full mutation verification (10/10, not 1/10 extrapolated) is tractable in one sitting.** T-016's
plan named 10 mandatory mutations; `dev` had mechanically run only #1. Running all 10
(apply -> run the specific test(s) -> confirm red -> revert -> confirm green) for this task's
`Entitlements`/`CommercialStatus`/`GracePeriod`/`PlatformLicence`/`FlagLadder`/handler layer took
under 15 minutes of tool calls because each mutation is a one-line edit and the targeted test
file is small and fast (<2s). Do not accept "mutation #1 passed, the rest are asserted by design
coverage" from a developer report when the remaining 9 are each a few minutes of work - the
discipline is the point (project pattern: red-first, docs/patterns.md).

**Closed the loop:** `dev` fixed BUG-2 (4 thin `Command` wrappers + provider registration + 9
tests) in one round. Re-verification found the fix genuine on inspection, but I still
mutation-tested it myself (removed one command's registration, confirmed its two tests threw
`CommandNotFoundException`, reverted, confirmed green) rather than trust "the tests pass" alone -
cheap (one array-entry edit, one targeted test run) and it is the only way to know the new tests
depend on the registration rather than passing by construction.

**Reviewer round 1 found a second instance of the same BUG-1 shape, in a scan I had NOT routed
through the shared directory-list helper.** `QaPlatformSourceRulesTest.php`'s AC-23 test built
its own local `dirname(__DIR__, 3)` list instead of reusing `qaPlatformSourceDirectories()`, so
my own BUG-1 directory-existence guard never covered it. **Lesson: when a fix adds a guard over
"the one shared list", audit every place in the same file (and sibling files) that builds a
directory/path list independently - a guard that only covers the function it was written against
leaves every sibling that duplicates the same literal uncovered.** Fixed by deriving the AC-23
list from the shared one (filtered + routes added) rather than writing a second list.

**Reviewer round 1's harder finding: every planted-string control in two of my own QA files
asserted directly against the planted file's contents, never calling the scanner function at
all.** `expect(str_contains($plantedFileContents, $literal))->toBeTrue()` only proves PHP's
string functions work - it is not a control on the scanner, because the scanner was never
invoked. This is a stronger, more general version of [[negative-control-every-guard]]'s existing
lesson: a control must *run the subject under test*, not a stand-in computation that happens to
use the same literal. The fix pattern that held up: give every scanner function a
`$directories` parameter (defaulting to the real list), and make every control build a fresh
temp directory, plant the file there, and call the real scanner over `[$tempDir]`, asserting the
real (realpath-normalised - Windows returns backslashes) result contains it.

**Widening a text-scan regex needs a before/after false-positive sweep against the real
codebase, not just a plausibility argument.** Reviewer suggested widening the sole-writer scan
to flag `table('tenants')` + `->update(` + the column name "anywhere in the same file" - tried
literally, this produced 4 false positives (handlers that legitimately read `tenants.status`
via `->where()`/`->first()`). Tightening to "an actual assignment into an array key"
(`$x['col'] = `, not `=>`/`==`) or "an array literal initialising a variable" eliminated all four
while still catching the real risk shape. Always run the candidate widened pattern against every
file in the real scan set before committing to it, and add a negative-control test for the
specific false-positive shape once found.

**Closed the loop:** `dev` fixed BUG-2 (4 thin `Command` wrappers + provider registration + 9
tests) in one round. Re-verification found the fix genuine on inspection, but I still
mutation-tested it myself (removed one command's registration, confirmed its two tests threw
`CommandNotFoundException`, reverted, confirmed green) rather than trust "the tests pass" alone -
cheap (one array-entry edit, one targeted test run) and it is the only way to know the new tests
depend on the registration rather than passing by construction.

**Test-quality warnings in dev-authored slice tests, fixed as QA's call (same domain as
mutation-testing):** an eviction test that never warmed the cache first, so the "stale value"
half of the proof was vacuous; an AC-6 test that lowered a limit via `config()->set()` (writes
nothing) instead of a real plan-change handler call, ending in a tautological
`expect(true)->toBeTrue()`. Both fixed by routing the test through the real write path and
mutation-testing the fix (remove the eviction call / use the `>=`→`>` boundary mutation) to
confirm the rewritten assertion actually discriminates.

**A `pest` run can leave a stray, high-memory `php.exe` holding a SQLite fixture file open for
15-45s after the shell command returns**, producing `database is locked` / `disk image is
malformed` in an immediately-following run that looks like a real regression. Identify the PID
(not part of the session's baseline process list), wait for it to exit (`until ! tasklist | grep
-q "$PID"; do sleep 5; done`), then re-run - a lingering process, not a defect, both times this
was seen on T-016.

**Multiple agents editing the same shared working tree concurrently means "my gate run" and
"their gate run" are the same filesystem state, not two branches to merge later.** `dev` fixed
six reviewer findings (W-2/W-4/W-5/W-6/W-8/S-4) in the same tree while I was fixing R-1/W-1/W-3/
W-7; by the time I ran my "after my fixes" gate, `dev`'s changes were already present too (proved
by diffing two gate logs byte-for-byte except per-test timings - identical). **When told to
re-run "the combined state", diff the new gate log against the previous one (ignoring timing
noise) before assuming anything changed** - it may already have been combined, which is itself
useful information to report rather than re-describing as a fresh before/after.

**Reading every production diff before trusting a parallel dev fix round, even when told the
tests already pass.** W-6 was a genuine, independently-valuable catch inside what a check-in
message framed as pure review-response fixes: three reactivation handlers resolved `PlanLimits`
*inside* their transaction, violating Ruling 12's "outside the transaction" rule the create
handlers already followed - a real pre-existing defect, not something the reviewer round asked
for by name. Skimming diffs for "does this look like what the message says" rather than only
running the tests is what catches a bundled fix nobody flagged as a finding.

Related: [[negative-control-every-guard]], [[gate-split-and-shared-state]],
[[t014-branches-terminals-closeout]].
