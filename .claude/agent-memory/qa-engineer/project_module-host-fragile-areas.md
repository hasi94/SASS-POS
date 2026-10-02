---
name: service-host-fragile-areas
description: Fragile areas in services/host found during T-001 and T-002 QA - which are fixed and guarded, which guards are only as good as their negative control, and what still trusts the service manifest
metadata:
  type: project
---

Recurring defect patterns in the `services/host` skeleton, all found by **causing** the
failure, none visible in a passing suite. T-001 QA (2026-09-28) found four; T-002 QA
(2026-09-29) found three more in the manifest-trust family.

**Still manual — check on every new package:** PHPStan and Deptrac list their `paths`
literally; neither resolver accepts wildcards. A `services/*` package missing from them is
invisible, not failing. `svc:make` prints a reminder, the root README names both files
(T-002 AC-14), and `QaToolchainRegistryCoverageTest` fails the gate — but the edit is by
hand. Since T-002 that guard skips fixtures by **exact name** (`qaFixturePackageNames()`),
not by `str_contains('fixture')`, so a real service called `data-fixtures` is checked like
any other. Cost of the trade: a test that generates a package under an unlisted name turns
the guard red mid-run. That is the intended direction — loud, not silent.

**Still trusts the manifest — one item left.** The psr-4 *target directory* is unvalidated,
so a package can map its own namespace outside itself (`"…\Qatrustfixture\\": "../demo/src"`
is accepted) and, because PSR-4 includes the candidate file before deciding the class is
absent, get an arbitrary reachable file executed during `class_exists()`. Reported as T-002
BUG-2 and deliberately left: it is the T-001 review's own Minor item ("`servicePath()`
concatenates an unvalidated string"), plan risk R-6, wanting a backlog row. The two sibling
findings were fixed in T-002 run 2 — `psr4()` now refuses a map that is not exactly one pair
(naming the unentitled keys), and a `ReflectionClass::isInstantiable()` check after `is_a()`
catches abstract classes and non-public constructors that used to fatal without naming the
service.

**Line endings break any generated-vs-committed comparison until FP-156 lands.**
`core.autocrlf` is `true` on this machine and the only `.gitattributes` in the repo covers
`services/host/` alone (`* text=auto eol=lf`), so `services/demo`, `packages/*` and anything
else outside it get CRLF in a fresh working tree while every generator writes LF.
`services/demo` is LF today only because it was generated and never re-checked-out — a raw
`md5_file()` comparison therefore passes until someone clones or switches branches, then goes
red for a developer who changed nothing. Any test comparing generated output with committed
content must hash `md5(str_replace("\r\n", "\n", file_get_contents($p)))`. Raw hashing is still
the right choice when comparing a tree against *itself* (proving a move-aside test restored it).
Found by the reviewer, not by me, in T-002 round 3.

**Manifest validation order is load-bearing.** count → namespace equality → provider prefix →
`addPsr4` → `class_exists` → `is_a` → `isInstantiable`. Which message a malformed manifest
gets depends on that order, and any "it throws" assertion stays green through a reordering —
which is how a fixture stops testing its own name. Assert the specific message, and assert the
absence of the neighbouring ones. Taxonomy worth remembering: an enum reaches `is_a` ("is not
a ServiceProvider"), a trait and an interface stop at `class_exists` ("could not be
autoloaded"), and a duplicated JSON key collapses in `json_decode` before any check sees it.

**Fixed and guarded (verify the guard, not the behaviour):**
- `phpunit.xml` is one recursive `<directory>tests</directory>` suite plus globbed
  `../*/tests` and `../../packages/*/tests`, with `<exclude>../host/tests</exclude>`. That
  exclude is a path, not a prefix. It is the second place (after the reserved `host` name)
  that assumes the host directory is called `host`.
- `SERVICES` entries are validated against `ServiceRegistry::NAME_PATTERN` (now `/D`-anchored,
  so `"billing\n"` is rejected at both the generator and the registry) plus
  `directoryIsNamedExactly()` so Windows gives the Linux answer.
- `phpunit.xml`'s `<env force="true">` does **not** isolate the suite alone: PHPUnit's force
  writes `putenv()`/`$_ENV` but not `$_SERVER`, which Laravel's Env repository reads first.
  `TestCase::setUp()` bridges it. Re-check by exporting a hostile `SERVICES` before the suite.
- Manifest namespace/provider trust and `svc:make` rollback: see `docs/qa/T-002-…` AC-1..AC-6.

**Shared, real state that tests mutate — keep the `finally` blocks:**
`bootstrap/cache/config.php` (two tests create it; every other test needs it absent),
`services/demo` (moved aside and restored by the regeneration test), and
`stubs/service/*.stub` (borrowed by the atomicity and drift tests). Each asserts a clean
pre-flight state and deletes *before* asserting, so a failed assertion cannot poison the run.

**How to apply.** (1) Expect `QaToolchainRegistryCoverageTest` to go red when a row adds a
`services/*` package until PHPStan/Deptrac are updated — backstop working, not a regression.
(2) Re-run the suite once with an odd `SERVICES` exported. (3) Never accept an
assertion-of-absence without a negative control — see [[qa-negative-control-every-guard]].
Full evidence: `docs/qa/T-001-monorepo-service-host.md`, `docs/qa/T-002-service-host-hardening.md`.
