---
name: service-host-fragile-areas
description: Fragile areas in services/host found during T-001 QA - which ones are now fixed and guarded, and the one manual registry that can still let a new service through unchecked
metadata:
  type: project
---

Four recurring defect patterns in the `services/host` skeleton, found during QA of T-001
(2026-09-28, runs 1 and 2). All four produced a **green gate that proved nothing**.

**Still live — check on every new package:** PHPStan and Deptrac list their `paths`
literally, because neither tool's path resolver accepts wildcards. A `services/*` package
missing from them is invisible, not failing: a real level-6 error passed `composer stan`, and
a real service-to-service import passed `composer deptrac` counted only as "Uncovered".
`svc:make` now prints a reminder and `QaToolchainRegistryCoverageTest` fails the gate, but
the edit itself is still manual.

**Fixed and now guarded (verify the guard, not the behaviour):**
- `phpunit.xml` used to list test directories literally, so a generated package's tests never
  ran while the suite stayed green. Now globbed (`../*/tests`, `../../packages/*/tests`) with
  `<exclude>../host/tests</exclude>`. That exclude is a path, not a prefix — a package named
  `hosted` is unaffected — but it is the second place (after the reserved `host` name) that
  assumes the host directory is called `host`.
- `SERVICES` entries were taken as raw directory names, so `demo/../demo` and (on Windows
  only) `Demo` "booted" and were reported by `/health/ready`. Now validated against
  `ServiceRegistry::NAME_PATTERN`, which `SvcMakeCommand` references rather than copies, plus
  `directoryIsNamedExactly()` so Windows gives the Linux answer.
- `phpunit.xml`'s `<env force="true">` does **not** isolate the suite on its own: PHPUnit's
  force writes `putenv()` and `$_ENV` but never `$_SERVER`, and Laravel's Env repository reads
  `$_SERVER` first. `TestCase::setUp()` now bridges it. Re-check by exporting a hostile
  `SERVICES` before running the suite.

**Why:** every one of these was found by deliberately causing the failure; none was visible
in a passing suite.

**How to apply:** when QA-ing a later row that adds a `services/*` or `packages/*` package,
(1) expect `QaToolchainRegistryCoverageTest` to go red until PHPStan/Deptrac are updated —
that is the backstop working, not a regression; (2) re-run the suite once with an odd
`SERVICES` value exported; (3) compare a `--log-junit` file list against the filesystem if
test discovery is ever in doubt. Full evidence: `docs/qa/T-001-monorepo-service-host.md`.

A QA lesson from the same task worth keeping: I wrote a test asserting a bad `SERVICES` entry
should be *silently dropped*, which contradicted decision D-1's fail-fast rule and another
test in my own file. Assert the **contract** (fail fast, per D-1), not the **symptom** you
happened to observe. See [[qa-assert-contract-not-symptom]].
