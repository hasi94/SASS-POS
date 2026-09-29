---
name: qa-negative-control-every-guard
description: In this repo, never accept an assertion of absence or a green tool run without first proving the same check goes red - the techniques that worked for PHPUnit collection, PHPStan/Deptrac paths, and PSR-4 registration
metadata:
  type: project
---

Every gate in `services/host` has failed green at least once, so a passing assertion here is
only evidence if the same assertion has been shown to fail. Prove the negative control first,
in the same test, then assert the positive.

**Why:** T-001's BUG-1 (a generated package's tests were collected by nothing while the suite
stayed green) and BUG-5 (a real level-6 error and a real cross-service import both passed
because the package was absent from `phpstan.neon.dist`/`deptrac.yaml`). In T-002 the same
shape reappeared three times: "no duplicate-collection warning", "no PSR-4 prefix was
registered", "the tests directory is analysed" — each is an assertion that *nothing* happened.

**How to apply — the four controls that work here:**
- *Suite collection.* Generate a copy of `phpunit.xml` in the pre-fix shape (literal
  `tests/Unit` + `tests/Feature`) next to the real one so its relative paths still resolve, and
  run the child with `--configuration=`. The probe must report `No tests found` there and pass
  against the shipped config. Removing `<exclude>../host/tests</exclude>` in another copy makes
  the duplicate-collection warnings appear — which is also the proof that **collection precedes
  filtering**, so a `--filter` matching nothing is a legitimate way to assert their absence
  without an unfiltered child run recursing into itself.
- *PHPStan / Deptrac coverage.* Plant a real defect in the directory that is supposed to be
  covered (an untyped parameter is level-6-only; `$this->get()` in a Pest closure is
  `method.notFound`), assert the shipped config exits non-zero **and names the file**, then
  delete it and assert green again.
- *PSR-4 registration.* "The class is not loadable" is weak on its own. Add
  `$loader = new ClassLoader; $loader->addPsr4($prefix, $dir); $loader->findFile($class)` on a
  throwaway, never-registered loader to prove the payload *would* have resolved. Composer's
  `ClassLoader` has no API for removing a prefix, so never mutate the live loader to test this.
- *HTTP behaviour that depends on infrastructure being down.* Repoint the default connection at
  an sqlite file in a directory that does not exist, then register a route inside the `web`
  group and assert it 500s **before** asserting the middleware-free routes still answer 200.

Related: [[service-host-fragile-areas]], [[qa-assert-contract-not-symptom]].
