---
name: defence-in-depth-needs-a-discriminating-input
description: Deleting a second line of defence stays green when the first one already answers the test's input; find the input where the two programs differ before believing the control
metadata:
  type: feedback
---

Before reading a green mutation as evidence, find an input on which the mutated
program and the original **differ as executed**. For a redundant guard that means an
input the inner defence does not already handle.

**Why:** T-010 step 19, M19-4. Deleting `RefusePinInTheUrl` from the PIN route left the
whole AC-48 file green, because the case sent `?pin=X` with an **empty body**: the
body-only reader found nothing, `PinPolicy` refused the null, and both programs
answered 422 with no state change. They were the same program on that input. The
middleware only differs where the body *also* carries a valid PIN (refuse the whole
request, versus quietly accept the body's) and where the caller lacks the permission
(refused before the resolver writes an `access_log` row, versus after). With those two
inputs the mutation is red.

**How to apply:** when a control's subject is an outer guard, an ordering, or any
belt-and-braces check, write down what the inner layer does with the same input first.
If the answers match, the test is measuring the inner layer. This is the shape-8 rule
(`where($col, null)` is `whereNull()`) one level up: there, the two programs were
textually different and semantically identical; here they are semantically different
and indistinguishable on the chosen input.

Related: [[mutation-must-move-the-observable]],
[[ordering-mutation-needs-a-refusable-input]], [[enumerate-the-sinks-not-the-scans]].
