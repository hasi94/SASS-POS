---
name: mutation-must-move-the-observable
description: A mutation proof is only evidence once you have checked the mutation actually changes the output the assertion reads - floors and clamps absorb edits that look decisive, and a green run then reads as a hole in the test
metadata:
  type: feedback
---

Before reporting that a mutation "did not redden the case", check that the mutation changed the
value the assertion reads at the inputs the case uses. A green run under a mutation is evidence
about the test only if the mutation moved the observable.

**Why:** on T-007/R18 I mutated the gate wrapper's budget rule from
`max(1, $limit - $reserve)` to `max(1, $limit - $reserve + 1)` to prove a derived-value assertion
was load-bearing. It passed. Not because the assertion was weak: the case forces limit 2, where
the reserve is 2, so the budget is pinned at the floor of 1 for every reserve of 1 or more. Both
the clamp and the ratio are invisible at that input. The mutation that works there is one that
moves the floor itself (`max(2, ...)`), which printed `budget=2s` against an expected `budget=1s`
and reddened at the right line. Reported as it first looked, that pass would have read as a hole
in a test that has none - the mirror of
[[guard-must-not-disturb-what-it-measures]], where an assertion looks strong and is not.

**How to apply:** when the target is a *derived* number, compute the mutated value by hand at the
case's inputs before running anything, and pick a mutation that changes it. Floors (`max`),
ceilings (`min`), clamps and integer rounding are where candidate mutations die - and the cases
that force tiny limits to stay fast are exactly the ones sitting on a floor.

Two corollaries from the same proof:

- **Pest reports the first failed expectation in a case and stops**, so a mutation that breaks the
  mechanism early tells you nothing about the assertions further down the case. To evidence one of
  those, invoke the subject directly under the mutation and show the output the assertion reads
  (`php tools/gate-step.php probe -- -r '...'`), rather than inferring it from a redden.
- **Some absence assertions cannot be isolated by any mutation of our own code** - if the kill
  lives in a vendored component, no edit of ours both produces the kill's sentinel and lets the
  child finish. Say that plainly and give the direct-invocation evidence instead; do not dress a
  corroborating assertion up as an independently proven one.

Mutate from a pristine copy kept outside the repo, restore by copying it back, and print
`md5_file()` after each restore: the hash in the report is what makes "restored byte-identically"
checkable. Related: [[clean-up-probe-files-immediately]],
[[never-edit-files-during-a-test-run]].
