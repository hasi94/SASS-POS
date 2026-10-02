---
name: module-host-fragile-areas
description: Fragile areas in modules/host found during T-001 and T-002 QA - which are fixed and guarded, which guards are only as good as their negative control, and what still trusts the module manifest
metadata:
  type: project
---

Recurring defect patterns in the `modules/host` skeleton, all found by **causing** the
failure, none visible in a passing suite. T-001 QA (2026-09-28) found four; T-002 QA
(2026-09-29) found three more in the manifest-trust family. The directory, the generator, the
environment variable and the class names were renamed in T-005 (2026-10-02); the mechanics below
are unchanged, only the words are new. The pre-T-005 wording survives in
`docs/qa/T-001-monorepo-service-host.md` and `docs/qa/T-002-service-host-hardening.md`, which are
historical records and are not rewritten.

**Still manual — check on every new package:** PHPStan and Deptrac list their `paths`
literally; neither resolver accepts wildcards. A `modules/*` package missing from them is
invisible, not failing. `module:make` prints a reminder, the root README names both files
(T-002 AC-14), and `QaToolchainRegistryCoverageTest` fails the gate — but the edit is by
hand. Since T-002 that guard skips fixtures by **exact name** (`qaFixturePackageNames()`),
not by `str_contains('fixture')`, so a real module called `data-fixtures` is checked like
any other. Cost of the trade: a test that generates a package under an unlisted name turns
the guard red mid-run. That is the intended direction — loud, not silent. T-005 FP-169 closed
the last hole in that pair: `modules/host/tests` itself is now in both registries, with a
`HostTests` Deptrac layer that is deliberately granted no `Module_*` layer.

**Still trusts the manifest — one item left.** The psr-4 *target directory* is unvalidated,
so a package can map its own namespace outside itself (`"…\Qatrustfixture\\": "../demo/src"`
is accepted) and, because PSR-4 includes the candidate file before deciding the class is
absent, get an arbitrary reachable file executed during `class_exists()`. Reported as T-002
BUG-2 and deliberately left: it is the T-001 review's own Minor item ("`modulePath()`
concatenates an unvalidated string"), plan risk R-6, wanting a backlog row. The two sibling
findings were fixed in T-002 run 2 — `psr4()` now refuses a map that is not exactly one pair
(naming the unentitled keys), and a `ReflectionClass::isInstantiable()` check after `is_a()`
catches abstract classes and non-public constructors that used to fatal without naming the
module.

**Line endings: the repository-wide rule exists now, and that changes what a raw hash proves.**
`core.autocrlf` is `true` on this machine. Until T-004 the only `.gitattributes` covered
`modules/host/` alone, so everything outside it got CRLF in a fresh working tree while every
generator writes LF — a raw `md5_file()` comparison passed in a tree that had never been
re-checked-out and went red for a developer who changed nothing (found by the reviewer, T-002
round 3). T-004 FP-156 added the **root** `.gitattributes` (`* text=auto eol=lf`, `*.bat`/`*.cmd`/
`*.ps1` CRLF, `*.ico` binary), measured 88 → 0 CRLF files in a fresh clone. So a raw byte
comparison is now the right instrument and the normalised hashes that `ModuleMakeDemoRegenerationTest`
and `QaTestCodeAnalysisCoverageTest` still use are belt-and-braces, not the proof. Prove the rule
from a real `git clone --local --no-hardlinks`, never from this working tree: `git checkout`
honours an **untracked** working-tree `.gitattributes`, which is how T-004 BUG-1 passed every
in-tree check while a fresh clone stayed broken.

**Manifest validation order is load-bearing.** count → namespace equality → provider prefix →
`addPsr4` → `class_exists` → `is_a` → `isInstantiable`. Which message a malformed manifest
gets depends on that order, and any "it throws" assertion stays green through a reordering —
which is how a fixture stops testing its own name. Assert the specific message, and assert the
absence of the neighbouring ones. Taxonomy worth remembering: an enum reaches `is_a` ("is not
a ServiceProvider" — the framework's own word, which T-005 deliberately did not rename), a trait
and an interface stop at `class_exists` ("could not be autoloaded"), and a duplicated JSON key
collapses in `json_decode` before any check sees it.

**Fixed and guarded (verify the guard, not the behaviour):**
- `phpunit.xml` is one recursive `<directory>tests</directory>` suite plus globbed
  `../*/tests` and `../../packages/*/tests`, with `<exclude>../host/tests</exclude>`. That
  exclude is a path, not a prefix. It is the second place (after the reserved `host` name)
  that assumes the host directory is called `host` — and T-005's rename of the directory
  *above* it left both untouched, because `host/` did not move.
- `MODULES` entries are validated against `ModuleRegistry::NAME_PATTERN` (now `/D`-anchored,
  so `"billing\n"` is rejected at both the generator and the registry) plus
  `directoryIsNamedExactly()` so Windows gives the Linux answer.
- `phpunit.xml`'s `<env force="true">` does **not** isolate the suite alone: PHPUnit's force
  writes `putenv()`/`$_ENV` but not `$_SERVER`, which Laravel's Env repository reads first.
  `TestCase::setUp()` bridges it. Re-check by exporting a hostile `MODULES` before the suite.
- Manifest namespace/provider trust and `module:make` rollback: see `docs/qa/T-002-…` AC-1..AC-6.

**Shared, real state that tests mutate — keep the `finally` blocks:**
`bootstrap/cache/config.php` (two tests create it; every other test needs it absent),
`modules/demo` (moved aside and restored by the regeneration test), and
`stubs/module/*.stub` (borrowed by the atomicity and drift tests). Each asserts a clean
pre-flight state and deletes *before* asserting, so a failed assertion cannot poison the run.

**How to apply.** (1) Expect `QaToolchainRegistryCoverageTest` to go red when a row adds a
`modules/*` package until PHPStan/Deptrac are updated — backstop working, not a regression.
(2) Re-run the suite once with an odd `MODULES` exported. (3) Never accept an
assertion-of-absence without a negative control — see [[qa-negative-control-every-guard]].
(4) After any repository-wide rename, the absence proof is
`modules/host/tests/Feature/QaVocabularyRenameSweepTest.php`, not a grep by hand — see
[[gate-split-and-shared-state]] for why its corpus must respect `.gitignore`.
Full evidence: `docs/qa/T-001-monorepo-service-host.md`, `docs/qa/T-002-service-host-hardening.md`,
`docs/qa/T-005-rename-modules.md`.
