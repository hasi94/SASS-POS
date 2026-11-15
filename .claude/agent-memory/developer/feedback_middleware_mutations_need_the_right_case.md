---
name: middleware-mutations-need-the-right-case
description: A coarse mutation of a guard reddens for the wrong reason; pick the one case where only the deleted guard decides
metadata:
  type: feedback
---

When mutating a guard to prove a control discriminates, mutate **one** decision, not the guard.

**Why:** in T-009 step 12 I deleted the whole token verification from `RequireTenantToken` and
read the unverified payload instead. The test went red - at `Expected 403 but received 500`,
because a platform token has no `tenant` claim and the mutated code crashed on an array key.
That red says nothing about verification: it is the same shape as this project's two earlier
instrument errors, red-for-the-wrong-reason. Re-run with only the *expiry* refusal falling
through to the unverified payload and the result is `Expected 401, received 200` on the expired
case, with the spy counting a call it must never have made. That is the evidence.

**How to apply:** for each guard, ask which single input distinguishes "this guard fired" from
"something else fired", and mutate only that path. A mutation that changes several outcomes at
once will redden on whichever the test checks first, which is usually not the one you meant.
Then read *which* assertion failed before calling it a pass. Companion to
[[a-mutation-must-move-the-observable]] and [[ordering-mutation-needs-a-refusable-input]].

Corollary measured the same day: a duplicated validation (controller *and* slice both refusing
the same input) is **not** observable by any mutation of either site alone - deleting the
controller's copy left all 12 endpoint cases green. Keep it if it is defence in depth, but report
it as unproven rather than implying a test covers it.
