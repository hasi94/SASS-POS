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

Related: [[negative-control-every-guard]], [[gate-split-and-shared-state]],
[[t014-branches-terminals-closeout]].
