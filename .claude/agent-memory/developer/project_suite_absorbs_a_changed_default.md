---
name: suite-absorbs-a-changed-default
description: When a new shipped default would break tests owned by another role, change phpunit.xml's environment rather than their files - and assert the shipped default from the config file instead
metadata:
  type: project
---

Changing a shipped configuration default can break dozens of existing cases
that predate the feature. When those cases live in files another role owns
(anything named `Qa*` here, and `docs/` of any kind), the suite's own
environment in `modules/host/phpunit.xml` is where the change is absorbed -
never their files.

**Why:** T-008 step 6 made `tenancy.mapping.source` default to `master` (a
joined read of the master schema). 34 cases in `QaTenantIsolationProofTest` and
`QaCredentialSurfaceSweepTest` set `tenancy.databases` directly and have no
master schema behind them; they are the T-003/T-007 isolation proofs and they
predate the master tables entirely. phpunit.xml already encodes three decisions
of exactly this shape (`DB_MASTER_DRIVER=sqlite`, `DB_MASTER_DATABASE=:memory:`,
`TENANCY_ALLOWED_DRIVERS` adding sqlite), so `TENANCY_MAPPING_SOURCE=config`
belongs beside them with the reason in the comment.

**How to apply:** add the variable with a comment naming what the shipped
default is and why the suite differs; then assert the *shipped* default from the
config file itself, or the criterion's "no test override" clause is simply
unmet. Declare it as a deviation in the report either way - the lead has upheld
declared deviations and this one changes what a criterion's evidence is.
`ENGINE_HOLD`'s value in that file is the owner's, not an agent's; everything
else in it is editable.

Related: [[prove-an-authority-by-moving-it]],
[[defer-in-a-comment-or-file-a-row]].
