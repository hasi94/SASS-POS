# Migration source-rule fixtures

Controls for `tests/Feature/MigrationSourceRulesTest.php` (T-008 step 2, AC-33 to
AC-37 and AC-24's source half). Each file is a deliberately wrong - or
deliberately clean - migration, handed to
`Flexipos\Kernel\Schema\Support\SourceRules` as text.

They are the mutation that proves each rule can fail. **The checker is never
weakened to make a test red** (plan section 11.2 rule 2); a fixture is the only
thing that moves.

Nothing here is ever applied to any database: no test in this task instantiates
these classes, and they live under `tests/`, never under
`database/migrations` or `database/migrations-tenant` - which step 13 asserts by
path.

`utf8mb4_0900_ai_ci` has no fixture file on purpose. AC-35(d)'s second clause
forbids that literal in **first-party code anywhere**, and these fixtures are
first-party code, so a file carrying it would be a true positive of the
repository-wide scan. Its control is assembled from fragments inside the test
instead - the same technique the containment check and the vocabulary sweep use,
and the reason neither has to exempt itself from its own rule.
