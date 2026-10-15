---
name: a-prefilter-masks-the-statement
description: When a guard's terms are duplicated in a pre-filter, behavioural tests measure the pair; assert the executed statement itself.
metadata:
  type: feedback
---

When the same terms appear in a **read that chooses a row** and in the
**statement that acts on it**, no behavioural test in one process can tell you
the acting statement still carries them. Delete them from the statement only
and everything stays green.

**Why:** measured on T-008 step 10. The claim is
`UPDATE ... WHERE id = ? AND attempts < :max AND (status ...)`, and the
candidate read filters on the same terms. With both terms deleted from the
UPDATE, all seven behavioural cases passed - including "a job at the attempt
maximum is not claimed" and "an unexpired claim is not re-granted". AC-48 is a
claim about *that statement* (its affected-row count is the verdict), so every
one of those cases was evidence about the pair and none about the criterion.

**How to apply:** when the criterion names a statement, assert the statement.
Capture it from the query log (`DB::listen` gives `$query->sql`), slice from
` where `, and assert the terms are in it - that case is red on the
statement-only mutation, which no behavioural case is. Run **both** mutations
and report both: statement-only (expected red) and both-sites (expected red
everywhere). This is the same family as [[a-mutation-must-move-the-observable]]
and the mask rule in [[absence-is-a-third-state]]: the second refusal that
absorbs the first can be a constraint, an earlier guard, **or a pre-filter in
your own method**.

A second instance landed the same day in T-008 step 9: FX-5 is both
*destructive* and *contract*, so with the destructive branch deleted the
contract guard refused the same run with the same exception class and the
"zero job rows" assertion stayed green. The fix was the input - put the tenant
at the version where only one guard can speak - not a different assertion.
