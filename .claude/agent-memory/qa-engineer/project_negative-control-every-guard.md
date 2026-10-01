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

**A third shape of tautology, found in T-004: a guard applied to data that was already
normalised.** `ToolchainGroupBindingTest` compared `--list-tests` output by *basename* and
protected the substitution with `expect(count($files))->toBe(count(array_unique($files)))` - but
the helper that produced `$files` had already called `array_unique()`, so the check can never
fail, and the comment above it claims a protection that does not exist. Worth an explicit habit:
when an assertion guards an *earlier* transformation, re-read that transformation and ask what
input would make the assertion red. Here the answer is "two test files with the same basename",
which happens routinely - `svc:make` writes `tests/Feature/PingRouteTest.php` into every
generated package and `services/demo` already ships that name. The generic form of the mistake is
asserting a property of the output that the producer guarantees by construction.

**A fourth shape: the comment claims more than the assertion asserts.** Found twice in T-004 run
2. `FixtureDirectoryTeardownTest`'s comment says "the file is removed, the directory survives" and
only the directory half is asserted; `SvcMakeDemoRegenerationTest`'s afterEach says the backup is
removed "only once services/demo demonstrably holds the same **bytes**" while the comparison is
line-ending-**normalised** md5. Neither makes anything pass falsely today, but a reader cannot
tell a protection that exists from one that is only described. Habit: for every comment that
states a guarantee, find the assertion that enforces it, and if there is none, say so.

**Mutation-test a timing assertion, never reason about it.** A lower bound on how long a retry
helper waits ("`expect($elapsed)->toBeGreaterThan(0.3)`") is the assertion that catches a fake
fix, so it has to be shown red. Reinstate the fake fix in the helper (T-004: `$attempt < 3`,
`usleep(0)`), run the one file, confirm the failure message names the cause, then restore the
helper and verify with `md5sum -c`. Measured margins matter too: the real helper takes 0.66-0.73 s
to give up against a 0.3 s bound, so it cannot flake in the other direction on a slow machine.
Also check the matcher takes a real message - `toBeGreaterThan(int|float|string|DateTimeInterface
$expected, string $message = '')` does
(`vendor/pestphp/pest/src/Mixins/Expectation.php`); PHP silently ignores a surplus argument to a
userland method, so a message passed to a matcher that has no `$message` parameter disappears
without any error.

**Mutate documentation in both directions.** For a "the document now says something true"
criterion, one mutation is not enough: re-insert the false claim (must go red, naming the file and
the phrase) **and** delete the corrected paragraph entirely (must also go red, on the clause that
has to stay). T-004's AC-16 needed four mutations for two documents. Back up each file, compare
`md5sum -c` after restoring, and check `git status --porcelain` is back to its starting count.

**Sweep by PROPERTY, not by mechanism — a mechanism sweep misses the same hazard split across two
functions.** T-004's delete-before-restore family was found three times by three readers: BUG-3 in
`SvcMakeDemoRegenerationTest`, then its sibling in `QaRegenerationGuardTest`, then R-1 — where the
throwing `deleteFixtureDirectory()` sat at the end of `qaRestoreDriftedStub()` while the restore it
could skip was the *next statement in the hook*. My grep for `moveDirectory|copyDirectory|rename`
near a delete could not see that, because the two halves were in different functions. The property
that does see it: *in any teardown (`afterEach`/`afterAll`/`beforeAll` body or a `finally` block),
does anything that can throw appear before anything that restores, counting a call to a helper as
"can throw"/"restores" when the helper's own body does?* Throwers here are
`deleteFixtureDirectory(`, `File::get(` (FileNotFoundException), `expect(`, `Artisan::call(`,
`throw new`. Ask it separately of each helper too (is its own restore before its own thrower?), and
exclude fixture *builders* - `qaTrustFixture()`, `trustFixture()` put `File::put()` after an
`expect()` on purpose and are never called from a teardown. Result after the fix: 0 hits over 74
teardown blocks in 52 files, with a negative control that reports R-1's pre-fix shape. The durable
fix shape: teardown helpers **return bool and never throw**; the hook runs every restore first and
raises one report last. A `RuntimeException`, not an `expect()`, so no assertion count moves.

**Two defects my own scanners had, both of which would have faked a clean sweep.**
(1) *It matched its own prose* - four hits that were the words `deleteFixtureDirectory`, `expect`
and `Restore` inside comments, including comments written for the fix under test. Strip
`T_COMMENT`/`T_DOC_COMMENT` with `token_get_all()` before scanning. The same bug appeared in my
assertion-line diff. (2) *It counted one statement as two* - a call to a helper that both restores
and can throw yields a THROW and a RESTORE event at the **same source offset**, and a naive "is
there a restore after a throw" loop read it as a hit: seven phantom findings. Compare offsets
strictly. Rule of thumb: before trusting a scanner's zero, feed it the known positive.

**Sweep the whole suite for tautologies mechanically, not by reading.** A ~50-line PHP scanner over
every `*.php` under every `*/tests` root catches four shapes cheaply: `expect(X)->toBe(X)` with
normalised whitespace; `count(X)` against `count(array_unique|array_values|array_filter(X))`; an
assertion on a literal; both sides wrapped in `array_values`. In T-004 run 2 it found only two
hits over 52 files, both `expect(true)->toBeTrue()` inside heredocs that *write* a throwaway probe
file whose only job is to be collected - so the scanner needs the "is this inside a heredoc that
generates a fixture?" judgement applied to its output, not built into it.

Related: [[service-host-fragile-areas]], [[qa-assert-contract-not-symptom]],
[[tenancy-boundary-leak-surfaces]], [[gate-split-and-shared-state]].
