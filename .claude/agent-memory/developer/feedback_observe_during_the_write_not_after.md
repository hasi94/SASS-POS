---
name: observe-during-the-write-not-after
description: A control that only looks after the operation cannot see a transaction around it; measured on T-009 AC-24, where the plan's named control passed through the mutation it existed to catch.
metadata:
  type: feedback
---

A control that inspects state **after** an operation cannot prove anything about
how that operation was framed. If the claim is about *when* or *inside what* a
write happened, the probe has to run during the statement.

**Why:** T-009 AC-24 is "the login history row is persisted in spite of the
error", and the plan's named positive control was a handler decorated to throw
after the attempt is recorded. Measured: wrapping the handler's own insert in
`beginTransaction()`/`commit()` — the exact design the criterion forbids — left
that control **green**, because the throw happens after the commit. The control
proves the row is not rolled back by *this* throw; it does not prove the writer
opened nothing. What discriminates is a `DB::connection(...)->listen()` probe
recording `transactionLevel()` while each statement runs, asserted to be `0`
(1 failed / 18 passed under the mutation). That probe needs its own control,
because "level 0" is also what a probe reading nothing reports: make the same
write inside a transaction the test opens and require it to report `1`.

**How to apply:** whenever an acceptance criterion is about placement —
outside a transaction, before the response, before another statement — ask what
the probe would report if the forbidden shape were present. Run the forbidden
shape and read which assertion failed; a control that stays green under it is
decoration. See [[feedback_mutation_must_move_the_observable]] and
[[feedback_a_prefilter_masks_the_statement]] for the two neighbouring shapes,
and [[feedback_guard_must_not_disturb_what_it_measures]] for the probe's own
control.
