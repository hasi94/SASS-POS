---
name: laravel-where-null-is-already-wherenull
description: a mutation justified by "where($col, null) matches no row" stayed green - Illuminate maps a null value onto whereNull() itself
metadata:
  type: feedback
---

Do not justify a helper by claiming `where($column, null)` matches no row in
Laravel. It does match: `Illuminate\Database\Query\Builder::where()` converts
a null value into `whereNull()` before it builds anything.

**Why:** T-010 step 13 shipped `BranchScope::at()` with that reason written
into three docblocks. The mutation that rewrote it as a plain
`where('branch_id', $branchId)` stayed **green across all 93 cases**, because
the two are the same statement. The claim was false in three files at once and
only the mutation found it. The mutation that *does* redden - 9 cases across
four slices - is dropping the scope clause entirely (`return $query;`), which
is the real hazard: an operation acting on every scope when one was asked for.

**How to apply:** when a `Support/` helper's stated reason is "the framework
cannot express X", run the mutation that writes X by hand before shipping the
reason. If it stays green the helper may still be right - one home for a rule
is a reason on its own - but the justification has to be the one that
survives measurement. See [[feedback_mutation_must_move_the_observable]] and
[[feedback_correct_a_claim_in_every_copy]].
