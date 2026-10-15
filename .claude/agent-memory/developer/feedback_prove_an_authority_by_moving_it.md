---
name: prove-an-authority-by-moving-it
description: "One authority" is proven by changing the key at runtime and asserting the consumers follow - a test that agrees with the shipped value cannot tell a config read from a second literal
metadata:
  type: feedback
---

When a configuration key is declared to be the *single authority* for something
(a path, a prefix, a limit), the test sets the key to a different value and
asserts that every consumer follows it. Asserting that a consumer agrees with
the shipped value proves nothing: a hard-coded literal that happens to match
passes identically.

**Why:** T-008 step 5 made `config('schema.tenant_migration_path')` the only
authority for the tenant migration tree, replacing the same literal in three
test files. The lead's wording was explicit that the key "must become its one
authority - not a second literal beside the first", and the only assertion that
can tell those apart is a moved key. The same test moves the key to a scratch
tree with one file in it and asserts the published catalogue row is that file's,
which proves the *command* reads the key too, not just the suite's helpers.

**How to apply:**
- move the key with `config()->set()` inside the case, build the scratch input
  under `storage/`, assert both the helper and the production entry point
  follow, and delete the scratch input at the end;
- for an authority that must *not* be repeated (the reserved test-database
  prefix), assert its absence from the consumer file with the literal assembled
  from fragments, so the test is not itself a true positive of the rule;
- a near-miss worth remembering: a key whose shipped default must also be
  asserted, while the suite overrides it, is read by removing the variable from
  `$_SERVER`, `$_ENV` and `putenv()` and `require`-ing the config file - with
  the restore in a `finally`, because phpunit.xml's `<env>` writes only two of
  the three and leaving it unset makes every later test resolve the production
  default.

Related: [[exempt-by-property-not-by-name]], [[correct-a-claim-in-every-copy]].
