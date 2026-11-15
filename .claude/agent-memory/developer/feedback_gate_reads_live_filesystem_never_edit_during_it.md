---
name: gate-reads-live-filesystem-never-edit-during-it
description: Several suite helpers (tenantTreeTables, tenantMigrationFileCount, AccessTenantFixture::topVersion) glob the filesystem live at test-run time, not at collection time - a 35-minute background gate run was corrupted by drafting T-027's files concurrently. Also, piping `composer check` through `tee | tail` swallows its real exit code.
metadata:
  type: feedback
---

Measured 2026-11-08 on T-027 step 0. Started `composer check` in the background as the
mandated clean baseline, then - per the plan's own "pacing" instruction to keep working -
spent the ~35 minutes it took (consistent with the documented 1232s warm `test` step) drafting
the twelve new tenant migrations and editing `tests/Helpers.php`'s `tenantTreeTables()`. Pest
collects test *files* once at the start, so new test files are safe to add mid-run - but several
already-collected tests call helpers that re-glob the tenant migration directory and re-read
`tests/Helpers.php` **at assertion time**, not at collection time. `tenantMigrationFileCount()`
and `tenantTreeTables()` are plain PHP functions re-evaluated every call. The result: 28 failures
of the shape "Failed asserting that 31 is identical to 43" in `AccessTenantFixtureTest` and
others, entirely a measurement artifact of my own concurrent edits, not a real defect.

**Second, compounding mistake:** the background command was `composer check 2>&1 | tee file |
tail -100`. In a bash pipeline the exit status is the *last* command's (`tail`, always 0), not
`composer check`'s. The background-task notification therefore reported "exit code 0" even
though the gate had 28 failures - I only found this by grepping the log for `FAILED` myself.
Never trust a background task's reported exit code when the command was piped through `tee` or
`tail`; capture `${PIPESTATUS[0]}` (bash) or run `tee` via `set -o pipefail` instead, or avoid the
pipe and read the file back separately.

**Why:** [[never-edit-files-during-a-test-run]] already covered editing *inside* this worktree's
own test tree mid-run; this is the same hazard from an angle that note didn't name - a helper
function (not a fixture or a committed file) that re-reads live state - and it is specific to
this backlog's `tenantTreeTables()`/`tenantMigrationFileCount()` pattern (FP-181's "one number,
one home" design, which is a virtue for correctness and a liability for an in-flight baseline
measurement).

**How to apply:** Step 0 (or any "measure the clean baseline" instruction) must run to
completion, verified by actually reading the exit code (not a backgrounded pipe's), **before**
touching a single file - including test helpers, migrations, or anything `tenantTreeTables()`-
adjacent - even when the plan's own pacing note encourages continuing to draft. If the gate is
going to take 20-35 minutes, either wait it out doing nothing file-touching, or accept that the
baseline will need a second, clean remeasurement later and say so plainly rather than let a
contaminated number stand in for one. Related: [[never-edit-files-during-a-test-run]],
[[gate-timing-spread]], [[worktree-env-and-gate-baseline]].
