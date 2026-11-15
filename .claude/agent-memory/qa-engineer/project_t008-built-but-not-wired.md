---
name: t008-built-but-not-wired
description: T-008 QA defect patterns - classes built and unit-tested with no production caller, planner/gate vocabulary mismatches, the PHPUnit env precedence trap on ENGINE_HOLD, source-rule evasion shapes, and how to report a held [E] matrix
metadata:
  type: project
---

Run 1 of T-008 (2026-10-15) found 5 major defects, all of one family: **a piece is built and tested in isolation but nothing connects it.**

- `PerTenantLock::acquire()` has no caller in `packages/kernel/src` (AC-49 unmet), `MigrateTenantQueueJob` is never dispatched (AC-41), and `Tenancy::enter()` has no evict/re-read/retry (AC-16). Each had a green unit test of its own surface. **How to apply:** for every new class a criterion depends on, `grep -rn "ClassName::" src` for a caller outside the class and its tests before believing a handover. A red *wiring* test (comment-stripped scan with a planted-call control) is the cheapest honest repro; label it as the weakest evidence.
- **Two vocabularies guarding one door.** The planner accepted `read_only`/`provisioning`/`failed` while `Tenancy::enter()` (also on the Migrating purpose) refuses all but `active`; no test used any other status in the execution path. When two components each filter on a status/enum, run the full cross product through the integration path.
- **PHPUnit `<env>` does not beat the shell and does not set `$_SERVER`.** `ENGINE_HOLD=0` in the shell cleared the hold; `force="true"` on `<env>` alone still left `$_SERVER` at 0 because the reader checks `$_SERVER` first. Probe with a one-line read-only test run under `ENGINE_HOLD=0|off|unset` and a scratch `--configuration`. The repo already documented this trap for `DB_MASTER_DRIVER` and guarded only that one.
- **Static scans miss alternate spellings of the same Laravel call**: `foreignUlid()->references()->on()` creates a FK (ForeignIdColumnDefinition::references calls Blueprint::foreign) but the AC-34 rule matched only `->foreign(`/`->constrained(`; raw `DB::statement('alter table ... tenant_id ...')` and raw `timestamp` evade the AC-24/AC-35(c) rules. Feed `SourceRules::inspect()` in-memory snippets with a same-family control.
- **Blocking sets narrower than the spec**: AC-58's guard reads only `active`/`read_only`, the spec says whole fleet minus `provisioning`.

**Reporting a held matrix.** Only 10 of 52 [E] criteria had any engine test (skipped); 42 had none, so lifting the hold verifies nothing by itself. Say so per criterion. PARTIAL = a stand-in/static half answers a stated clause; NOT VERIFIED = no passing non-engine evidence; FAIL = a finding that needs no engine (missing implementation). Never PASS an [E]. Counts must sum to 68 and be re-derived (13/49/4/2).

**Measurement traps.** A scratch `git worktree` + `composer install` under the deep scratchpad path made 10 path-dependent tests fail (invalid baseline); use recorded figures from the previous QA report and say so. The repo's own `QaAssertionShapeGuardTest` rejects a two-argument `->not->toContain(a, b)` - one needle per assertion. Mutation of the claim statement: prepended autoloader serving a scratch copy, byte-identical control first, three mutants (drop both terms, drop status arm, `<` to `<=`) each left exactly the statement pin red and six behavioural cases green.

Related: [[module-host-fragile-areas]], [[qa-negative-control-every-guard]], [[gate-split-and-shared-state]], [[t007-measurement-and-test-traps]].

**Round 3 (2026-10-15): printed instructions are claims.** A test asserting `toContain('tenant:migrate --x')` proves nothing about the command. Sweep: grep src for `tenant:`/`--flag` inside message strings, then write a round-trip test that parses the instruction out of the real output and calls it via Artisan, using the command's own refusal sentence as the instrument (with a control that a bare call produces it). Found `contractStepBlocked()` telling the operator to run `tenant:migrate --to=N` alone, which the arg guard refuses (BUG-12). A policy-object authority makes mutation proof work where a source scan cannot: a second literal copy reddens exactly the moved-key cases.
