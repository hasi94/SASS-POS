---
name: reachability-is-a-closure-not-a-grep
description: A "nothing calls this" check must be a transitive closure and must name a third outcome for public-but-test-only methods; one-hop grep counts helpers of reachable helpers as dead.
metadata:
  type: feedback
---

Before building any reachability check, run its rule over the whole tree and
count the reds **by cause**, not just in total. One-hop detection produces three
kinds of red and only one of them is a finding:

1. **called only by a reachable sibling or by its own class** - `forFile`,
   `checksumOf`, `appliedTo`, `RunnerClock::now()`. Not dead: the entry point
   that reaches them is in the caller set. The fix is a **transitive closure
   seeded by the caller set**, which is a rule, not an exemption.
2. **public but only the suite calls it** - a test clock's `fixedAt()`, a
   builder only fixtures use, an exception's accessor. No kind in a
   caller-set model fits these; they need either a declared, *counted* kind or
   an artefact change (make it non-public, move the seam into the test tree).
3. **genuinely nothing calls it** - the finding the check exists for.

**Why:** T-008 step 13d was ruled green-on-arrival after BUG-3/BUG-4 were
fixed. Measured: 14 red methods, **zero** of them defects - 8 of cause (1), 6 of
cause (2). The first wording had been withdrawn at 27 red for 3 defects; the
replacement was still unbuildable for a different reason, and only running it
showed which.

**How to apply:**

- The unit must be the **method**, not the class, whenever the proof is "delete
  one call site and it reddens": a class-level rule stays green while its
  siblings are still called.
- `->m(` is receiver-blind. Pest's `->with(...)` dataset call made a test-only
  builder look called from 23 files. It did not produce a false green in the
  *production* caller set here, but assume it can.
- Report the measurement before building. Related:
  [[feedback-exempt-by-property-not-by-name]],
  [[feedback-mutation-must-move-the-observable]].
