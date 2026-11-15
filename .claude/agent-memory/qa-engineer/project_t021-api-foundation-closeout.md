---
name: t021-api-foundation-closeout
description: T-021 API foundation close-out — verifying "found and fixed" regression claims against the actual guard test (not the narrative), AC-8 mutation technique on a renderer's last branch, and a .env-caused known-failure count that matched dev's report to the assertion
metadata:
  type: project
---

**Verify a dev's "I found and fixed a regression against guard test X" claim by locating guard test X
yourself and reading what it actually scans, not by trusting the class name in the report.** T-021's dev
report named `QaTenantIsolationProofTest` and `QaEventbusSourceRulesTest` as the guards two fixes avoided
tripping — neither name matched a file found by a naive grep for the literal class name in
`packages/*/tests` (the eventbus one actually lives in `modules/host/tests/Feature/`, not
`packages/eventbus/tests/`). Finding the real file and reading its actual regex/scan logic
(`QaTenantIsolationProofTest`'s "exactly one writer of `database.connections.*`" scan over every non-test
`.php`/`.stub` under `packages/` and `modules/`; `QaEventbusSourceRulesTest`'s "no file outside
`packages/eventbus/src` contains `Facades\Redis`/`Redis::`/`Illuminate\Redis`/`Contracts\Redis`/a Streams
verb/the stream name" scan) is what makes "the fix genuinely passes, not by coincidence" a checkable
claim: `MasterDatabaseCheck` reads `config('database.connections.master')` (a read) and only calls
`ConnectionFactory::make()`, never `->set(`/`config([` on that key; `RedisCheck` assigns the string
`'Redis'` to a variable and calls `new $extensionClass`, so none of the four literal guard strings
appear. Same technique applies to the third claimed fix (a file moved because of
`QaFixtureBaselineSweepsTest`'s "one entry point reads the shipped tenant tree in the kernel test corpus"
rule) — read that guard's own list of allowed readers, not just "dev says it's fixed."

**A renderer's final catch-all branch is the one AC-8-shaped mutation that matters, and it is a one-line,
safely revertible edit.** For "no exception detail leaks to the client," the discriminating mutation is
adding the real exception's message to the `detail` argument of the generic 500 response
(`ApiResponse::failure(KernelErrorCodes::SERVER_ERROR, $e->getMessage())` in place of
`ApiResponse::failure(KernelErrorCodes::SERVER_ERROR)`), then running the AC-8-tagged test file filtered
to that test name. Both `debug on`/`debug off` datasets must go red identically — a renderer that behaves
differently under `APP_DEBUG` for this path is itself a smell. Revert immediately and confirm
`git status` is clean and the same filtered run returns to green (warnings-only, from the project's
known `.env`-missing gap) before moving on. This is a 30-second, fully safe way to make "mutation-tested
AC-8" true rather than asserted.

**A `.env`-missing worktree's known-failure count is reproducible to the exact number across dev and QA
runs, and is worth citing precisely rather than approximately.** This task's gate (`composer check`,
745.97s, full suite) returned **164 passed / 10 failed / 2573 warnings (18540 assertions)** — identical
to dev's own self-reported numbers to all four figures. The 10 failures were nine nested child-process
`pest` runs (each spawns a second PHP process via `Illuminate\Support\Facades\Process`, which needs a
real `APP_KEY` from a real `.env` that this harness's permission system blocks creating) plus one
`MissingAppKeyException` from `QaHealthProbeResilienceTest`'s session-backed route. One of the nine,
`QaTestSuiteIsolationTest`, carries its own docblock stating "Currently RED" with a link to a pre-existing
bug row (T-001 BUG-4) — an unprompted, in-repo confirmation that a failure predates the task under review,
independent of anything the dev or lead said. Cross-checking the FP-316 rotating cluster
(`QaModuleRegistryContractTest`, `QaModuleNameValidationTest`, `ModulesEnvParsingTest`) by name in the
same log confirmed it did not fire this run — so the 10 failures and the FP-316 cluster are two disjoint,
independently named sets, both accounted for, with nothing left over. See
[[project_t014-branches-terminals-closeout]] and [[project_t017-audit-framework-closeout]] for FP-316's
prior sightings; this is the first time I've also matched a dev's reported failure *count* to the
assertion against a truly held-constant environment gap (not a flaky rotation) across two independent runs.
