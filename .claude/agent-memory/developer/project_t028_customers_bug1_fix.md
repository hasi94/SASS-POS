---
name: project-t028-customers-bug1-fix
description: T-028 Customers BUG-1 (QA round 1) - missing CustomerStatus::Inactive case and the generalized consistency check
metadata:
  type: project
---

QA round 1 on T-028 found `CustomerStatus` enum shipped only `Active`/`Blocked`, so
`status: 'inactive'` (named in AC-6/AC-9) could never be reached through
`CreateCustomer`/`UpdateCustomer`.

Fix: added `case Inactive = 'inactive';` to `packages/customers/src/Customers/CustomerStatus.php`.
That alone was not enough — `CustomerRules::checkStatusConsistency()` had hardcoded its
"every other status requires blocked_reason null" branch to `=== CustomerStatus::Active`
only, which would have silently let an `Inactive` row carry a non-null `blocked_reason`
once the case existed. Generalized to `!== CustomerStatus::Blocked` (the actual AC-9
wording: "every other status requires blocked_reason to be null").

**Why this matters beyond this one task:** a missing enum case bug commonly hides a
second bug in whatever "all other cases" branch assumed the case set was closed at two.
Always re-check every match/if-chain that enumerates a sister enum's cases by name
(not via `cases()`) after adding a case — grep the enum's name repo-wide, not just in
its own file.

**Gate note:** `composer check` on this repo returns a nonzero exit (observed exit 2)
whenever the Pest step has ANY failing test, even when every failure is in the
documented 13-failure pre-existing baseline (ModulesEnvParsingTest, QaHealthProbeResilienceTest
x2, QaModuleNameValidationTest x3, QaModuleRegistryContractTest x6,
QaVocabularyRenameSweepTest x1). "Exit 0" is NOT the signal for "gate is clean" on this
repo — compare the failing class/test names against the exact accepted baseline set
instead. A QA report that says "exit 0" with that baseline present may be describing exit
status loosely; trust the enumerated failure list, not the raw exit code.
