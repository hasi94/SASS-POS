---
name: reproduce-the-defect-in-its-exact-shape
description: A before/after liveness pair only proves anything if the planted input matches the old code's matching shape - my first comment plant was red on both sides.
metadata:
  type: feedback
---

When demonstrating that a fix closes a hole, the planted input must be one the
**old** code accepts. Run the mutation against the pre-change file first and
confirm it is **green** there; only then is the red under the new code evidence.

**Why:** proving R2-W3 (a URL named in a comment satisfied a regex pin), I
planted `// ... /api/v1/auth/mfa?token=...`. The old pattern required a quote
immediately before the URI, so it matched nothing and the test was red on both
sides - which reads as "the fix changed nothing". Re-planting the comment with
the URL **in quotes** reproduced the defect: old regex PASS, new regex FAIL.

**How to apply:** a liveness pair is `git show HEAD:<file>` + mutation (expect
green) then current file + the same mutation (expect red). Keep the mutated
copy in the scratchpad so the second run is the identical input. See
[[feedback_red_phase_needs_a_restore_path]] and
[[feedback_mutation_must_move_the_observable]].

Related, from the same round: when a reviewer quotes fix code whose literal
form contradicts a property they state in the same sentence (`toBe([a,b,c])`
cannot "shrink freely"), implement the property and declare the deviation -
`array_diff(array_keys($x), [a,b,c])->toBe([])` bounds growth and allows
shrinking. See [[feedback_defer_in_a_comment_or_file_a_row]].
