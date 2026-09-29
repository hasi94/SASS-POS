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
- *A guard that refuses a stale object.* Do the same moves on a connection name the guard does
  not cover and watch the unguarded object serve the wrong data. Without that half, "it threw"
  only proves something was wrong, not that the guard is what stopped it.

**Measure the installed thing, never a fresh copy of it.** The worst false green in T-003 was
mine: two credential-leak tests built their own `VarCloner` and dumped through it, while `dump()`
uses a cloner the framework constructed and captured **at provider-registration time**. A fresh
copy picks up whatever we registered afterwards; the live one never does. Same trap wherever a
framework captures a collaborator early - dumpers, the event dispatcher, the exception handler,
the connection factory. If a helper is what production calls, the test has to call that helper,
which usually means a child process that boots the app and reads its **stdout** (`ob_start()` does
not capture a dumper writing through `ConsoleOutput`). Two shapes that looked like coverage and
were not, both measured before discarding: `VAR_DUMPER_FORMAT=html` leaves the handler null so
Symfony's lazy fallback builds a cloner *after* our provider; and registering the HTML dumper by
hand from a CLI child inverts the very ordering that causes the bug. Guard such a probe with three
controls - a marker proving the real handler is installed, a plain value proving the capture is
not empty, and an **unprotected stand-in whose value must come back**, proving the probe could
detect a leak at all.

**One Pest trap that turns an assertion-of-absence green on its own.** `toContain()` takes
**variadic needles**, `toHaveKey()` takes `($key, $value)`, `toHaveProperty()` takes `($name, $value, $message)` and `toThrow()` takes
`($class, $message)` - none of those second arguments is a message under negation, so
`expect($x)->not->toContain($secret, 'CliDumper')` passes as soon as *any* argument fails to
match, and the harmless label always satisfies it. It cost me a false green in T-003 QA - two leak
tests passed while the credential really was in the output. Never pass a second argument to a
negated `toContain`/`toHaveKey`/`toHaveProperty`/`toThrow`. (`toBe`, `toBeTrue`, `toBeFalse` and `toHaveCount` do
take a real message.) For "none of these leaked", collect offenders into an array with
`str_contains()` and assert `toBe([])` with the list interpolated into the message: it reports
*which* surface leaked instead of only that one did. This is now linted by
`services/host/tests/Feature/QaAssertionShapeGuardTest.php` over every test file in the repo -
it excludes only itself, by exact basename, because it holds the bad shapes as fixtures.

Related: [[service-host-fragile-areas]], [[qa-assert-contract-not-symptom]],
[[tenancy-boundary-leak-surfaces]].
