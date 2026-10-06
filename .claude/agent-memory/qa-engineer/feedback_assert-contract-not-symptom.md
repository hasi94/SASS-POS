---
name: qa-assert-contract-not-symptom
description: When pinning a defect with a test, assert the decided contract (docs/decisions D-n), not the symptom observed - a symptom-shaped assertion can contradict the spec and other tests
metadata:
  type: feedback
---

When writing a test to pin a defect, assert the **contract** the project has already decided,
not the **symptom** observed while reproducing it. Check `docs/decisions/<ID>-<slug>.md`
before fixing the expected behaviour in an assertion.

**Why:** during T-001 I reproduced `/health/ready` listing three entries for one registered
provider and wrote a test asserting the bad entries were silently dropped. That contradicted
decision D-1 ("fail fast … nothing is silently skipped"), the BUG-3 fix I had myself
requested, and another test in the same file that required the same input to throw. `dev`
spotted it, refused to edit a QA-owned file, and escalated through the lead instead of
settling it by whoever edited last — which was the right call and cost one extra round.

**How to apply:** before asserting expected behaviour in a defect-pinning test, find the
decision that governs it. If no decision covers it, say so in the report and route it to `ba`
or `architect` rather than encoding a guess. Prefer invariants that survive whichever way the
decision goes — e.g. "every name reported by `/health/ready` has a matching registered
provider" holds under both fail-fast and silent-drop, whereas "the bad entry is dropped" only
holds under one.

**It recurs as a *precondition*, not only as an expectation.** In T-003 I wrote
`expect(array_keys(DB::getConnections()))->toContain('tenant::read')` after leaving a tenant
scope, commented "its survival is what makes the refusals below meaningful" - pinning a stale
connection outliving its scope as the setup for a security assertion. When the reviewer fixed that
leak, my test blocked the fix. A setup line that says "the buggy thing happened, therefore my
check is interesting" is the same error wearing different clothes, and it is harder to spot
because it reads as scaffolding rather than as an assertion. Ask of every precondition: would this
still be true once the code is *right*? If a refusal needs a stale object to be meaningful, get it
from a reference the test holds itself, not from the system failing to clean up.

**Correct a belief where it was *stated*, not only where it was discussed.** T-003 ended with the
reviewer finding five separate sites where a claim had been disproved in conversation while the
original wording survived untouched in a file - mine was a QA report paragraph still asserting a
framework ordering that had been measured false, and still justifying a defensive call that had
since been deleted as a no-op. A report is the durable record; a reader cannot tell a measured
fact from an inherited one. So: when a belief is overturned, grep for every place that states it
(report, test comment, class docblock) and fix each one - and prefer a visible, marked correction
over a silent edit, so the next reader can see the claim was actually checked. Before repeating
any mechanism claim, re-derive it: read the source *and* measure it.

Related: [[module-host-fragile-areas]], [[qa-negative-control-every-guard]].
