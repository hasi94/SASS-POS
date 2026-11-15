---
name: sqlite-fixture-costs-and-pest-properties
description: Two costs measured writing the T-008 runner fixtures - SQLite fsyncs every bare statement, and an ad-hoc $this-> property in a Pest test is a PHPStan error.
metadata:
  type: project
---

Two things that cost a run each while building the step 11/12 fixtures on this
project.

**SQLite autocommits and fsyncs every bare statement.** MEASURED: eight
unwrapped `PDO::exec()` calls against a file database cost about 0.9 s on this
machine, per tenant. The drift slice's tests went from 8.78 s to 2.27 s by
putting `beginTransaction()`/`commit()` around the fixture's writes. Any
fixture that seeds a file-backed SQLite tenant should wrap its writes.

**An ad-hoc `$this->whatever` in a Pest test is a static-analysis failure
here.** Pest's test object is a plain `PHPUnit\Framework\TestCase`, so
`$this->applier = ...` in `beforeEach` gives `property.notFound` for every
read, and `composer check` runs stan before the suite. The shapes that work:
a method on `Tests\TestCase`, a plain function in a required fixture file, or a
static handle on the fixture class reset in `beforeEach`.

**Why:** both were discovered by a red gate rather than by reading, and
neither is visible in the existing test files - the ones that use `$this->`
are calling TestCase methods, which looks identical at a glance.

**How to apply:** when adding a fixture file under
`packages/kernel/tests/Support/`, reach for functions and a static handle, not
test-case properties; and transaction-wrap seed writes. Related:
[[suite-rules-for-new-test-files]], [[sqlite-standin-vs-engine]].
