---
name: t019-plugin-framework-closeout
description: T-019 step 17 close-out — non-discriminating mutations hidden behind random test fixtures and DB-level constraints, and FP-316's cluster confirmed rotating under isolated reruns
metadata:
  type: project
---

T-019 (plugin framework) step 17's 13 mandatory mutations: 11 genuinely
discriminating, 2 survived — both confirmed as test-coverage gaps, not
production bugs, via disposable (never-committed) probe tests. See
[[project_negative-control-every-guard]] and
[[project_t014-branches-terminals-closeout]] for the prior instances of this
same class of finding.

**A test helper that randomises an identifier can quietly defeat the one
isolation case it exists to prove.** `CircuitBreakerTest`'s `breakerRow()`
generates a fresh random plugin name on every call
(`'fixture-breaker-'.bin2hex(random_bytes(3))`), so two calls for two
different tenants are *never the same plugin* even though the AC
("the same plugin for a different tenant") and the test's own name both say
they are testing exactly that. Mutating `CircuitBreaker.php` to key its
queries by `plugin_id` alone (dropping `tenant_id`) stayed green. Caught only
by writing a disposable probe that explicitly reuses one `pluginId` across
two `tenant_plugins` rows. When a plan's mutation targets "the same X for a
different Y," check whether the test's own setup helper can actually produce
two rows sharing the same X — a randomised id generator inside a shared
`rowFor()`-style helper is the shape to look for.

**A unique/primary-key column can make an idempotency *test* non-discriminating
while the *behaviour* is still correct.** `plg_fixture_echo_after_effects`
keys on `event_id` (the fixture's own design, AC-19/AC-44), and the handler
writes with `insertOrIgnore`. Removing the handler's own `exists()` check
before writing did not turn the AC-29 redelivery test red, because the
database's own primary key absorbs the duplicate regardless of the
application-level check — and the test's "control" branch (flag flipped to
skip the check) passes by coincidence too: its final assertion counts the
*cumulative* table size across two different event ids (1+1=2), not a genuine
double-insert of one id. When a fixture's marker table's own key already
provides the guarantee a test's docblock claims to be proving at the
application layer, the mutation for "drops its own check" is very often
non-discriminating — verify by actually removing the DB-level constraint too,
or by asserting on a call-count spy instead of a row count a unique index
already protects.

**Mutation 9's literal plan wording ("write the after-row outside the
transaction") was non-discriminating as a simple code-motion**, for the same
reason T-014's mutation 8 was non-discriminating (see that memory): when the
discriminating signal depends on an exception propagating past the moved
line, moving code to *after* a `transaction()` call that re-throws means the
moved line is never reached either way. The faithful version of this bug is
committing the write via its own autocommitted statement *before* the
transaction opens (so it survives a later rollback) — that version did turn
the "leaves zero outbox rows when the action throws" case red. When a plan
describes a transaction-boundary mutation only as "moved outside," check
whether the surrounding code path even reaches the moved statement under the
test's own exception-based assertion before trusting the mutation's shape.

**FP-316's rotating-cluster signature reconfirmed under a smaller, isolated
rerun.** Running just the four cluster files
(`QaModuleRegistryContractTest`, `QaModuleNameValidationTest`,
`QaHealthProbeResilienceTest`, `ModulesEnvParsingTest`) outside the full
`composer check` run produced only 2 failures (not 12), in different files
than the full run's 12 (which were all in `QaModuleRegistryContractTest`
that time). This is consistent with — not contradicting — the established
"12 failures, rotating names, same cluster" signature: isolated/parallel test
ordering changes which specific assertions see stale env/session state, but
the defect family (module-registry bootstrap environment leakage) stays the
same. Do not be alarmed by a different failure count when re-running a subset
of the cluster in isolation; it is the same underlying defect surfacing
differently under different parallelism, not a new regression. Always
corroborate by package/file (do these touch the task's own packages?) rather
than by exact test name or count alone.

**AC-47's deptrac instrument re-verified cleanly** with the standard plant/
revert technique: an internal-package import in `modules/demo/src` flagged
(`DependsOnDisallowedLayer ... Package_PluginSdk`), the same import swapped
to the published contract passed clean (0 violations), probe deleted,
`git status` clean, deptrac green again at the same baseline count (923
allowed). No surprises; recorded because every prior round in this project
checks this edge independently each time (T-009/T-010/T-018 precedent).
