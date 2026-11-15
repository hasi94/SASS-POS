---
name: say-what-a-fix-does-not-fix
description: When a fix changes structure but not sequential behaviour, name the weakness in the commit - the reviewer graded the filing's reasoning, not just the code
metadata:
  type: feedback
---

A change that makes a property structural while leaving observable behaviour
identical must say so, and must say which test would actually see it.

**Why:** T-009 W-1. The lockout's `SELECT` + unconditional reset-and-lock became
an `UPDATE ... where('failed_attempts','>=',$threshold)` whose affected-row count
is the decision - the shape `RefreshTokenHandler::rotate()` already used. The
tempting claim was "fixes the lockout race". It does not: `isLocked()` is read at
the *start* of a request, so N simultaneous wrong passwords all clear the check
and all reach the hasher however atomic the counter is. The residue was duplicate
`lockout` event rows, not a bypass; the control for parallel guessing is per-IP
throttling. Every existing sequential lockout test stayed green, which is exactly
why the claim had to be bounded.

**How to apply:** for this kind of change, the evidence is a **statement pin**
(query log: the same writes from a sub-threshold request and a crossing one, the
predicate present, no counter read in either) plus an explicit sentence that
sequential behaviour is unchanged and the concurrent test is owed by another task.
Pair it with [[feedback_statement_pin_needs_an_input]] - the two requests are the
input that makes the predicate bite - and
[[feedback_defer_in_a_comment_or_file_a_row]].
