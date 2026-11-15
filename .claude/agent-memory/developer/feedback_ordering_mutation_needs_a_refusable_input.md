---
name: ordering-mutation-needs-a-refusable-input
description: Moving a guard later in a handler stays green against a fixture that succeeds; only an input the later code would refuse tells two orderings apart.
metadata:
  type: feedback
---

A mutation that moves a statement **later** in a handler is invisible unless the
test's input makes the two positions disagree. Twice on T-009 I moved
`keyring->signingKey()` after the judgement and the suite stayed green, because
the fixture was a *successful* case: the judgement passed, signing failed, and
every absence the test asserted held under both orderings.

The discriminating input is one the **later** code would refuse. With a tenant
that is not selectable, key-first throws the operational failure and key-last
throws the business refusal and writes a reasoned row for a request it could
never have answered. That single case is what the whole ordering exists for.

Corollary measured the same day: moving the statement only *one* line later is
not a mutation at all if the intervening line decides nothing (a bare `SELECT`
whose result is not yet examined). Move it past the first branch that acts.

**Why:** a green mutation reads as "this guard is redundant" and invites a later
reader to delete it. Both times the guard was load-bearing and the test was
thin.

**How to apply:** before claiming an ordering is proved, ask which input makes
the two orders produce different *observables*, and write that case. See
[[mutation-must-move-the-observable]] and [[a-prefilter-masks-the-statement]].
