---
name: t035-ts-engine-closeout
description: T-035 TypeScript calc engine QA - PASS with 0 defects; technique notes for mutation-testing a frontend twin without disturbing a parallel reviewer, and the survivor classes found
metadata:
  type: project
---

T-035 (FP-432, TS twin of the PHP pricing engine) passed QA with no defects; 6 of 10 mutation survivors were real suite gaps, closed with new specs, 4 were equivalent mutants.

**Why:** the shipped suite (964 tests, shared fixtures replay) was strong on fixtures but missed boundary and targeting cases the fixtures do not pin: fixed-tax total equal to gross, `gross - F - K < 0`, a discount naming only brands, century leap years, and non-BMP vs high-BMP code ordering driven *through the engine* (the byte-order unit test existed but no engine-level test used it, so swapping `compareBytes` for JS `<` survived).

**How to apply:**
- Mutation-test in `git clone --no-hardlinks` of the worktree plus a `mklink /J` junction for `frontend/node_modules`, never in the live tree while a reviewer reads it. `--local` hardlinks fail on this Windows setup.
- Run `npx ng test shared --watch=false` (17 s) not `npm test` (builds admin and pos too, about 2 min under contention). Never start two drivers: a `( cmd & )` launch from Bash silently survived my "failed" attempt and two drivers wrote one clone.
- A tool-moved-to-background `until` wait is fine; the completion notification is just the wait script ending.
- Independent oracle recipe that worked: Python `Fraction` re-implementation of the PHP algorithm (round half away from zero, same check order), emit compact tuples into a `testing/*.ts` data file, expand in a spec. 342 vectors matched first time; it also produced the exact expected values for boundary tests.
- Equivalent-mutant classes to recognise: a sign-ignoring range check when `text()` also refuses negatives; JS `<` vs bytes when ids are ASCII-only after the ULID check; a year limit above what a `\d{4}` pattern admits; a range check on a value that cannot exceed its input.
- `git diff main...HEAD` is useless here (main stale, 1858 files); scope checks use the task's base commit.
- The test file `Edit`s I made went through `prettier --write` because the gate runs `prettier --check` over the engine directory.
