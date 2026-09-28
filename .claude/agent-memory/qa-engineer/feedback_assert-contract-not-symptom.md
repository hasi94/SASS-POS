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

Related: [[service-host-fragile-areas]].
