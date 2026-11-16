---
name: t029-pricing-closeout
description: T-029 taxes/pricing QA round 1 - PASS; AC clauses a green suite did not pin, equivalent mutants, mutation-driver traps
metadata:
  type: project
---

T-029 round 1 PASS, gate 13 failed (baseline set) / 36 skipped / 4018 passed, 942 s.

- A property test like "net + total = gross" cannot catch a wrong divisor because the residue absorbs it; pin the NET with a hand-derived value. AC-30.6 (non-compound 5%+10% on 115 -> 100) and the tax-id tie-break half of AC-30.4 and the inactive-group half of AC-30.8 had NO shipped test: mutants survived (tie comparator returning 0 survived all 645 pricing tests). **How to apply:** for an AC with two clauses ("lists or taxes", "unknown or inactive"), mutate each half separately.
- Two equivalent mutants (a redundant `fixedTotal > gross` check, a redundant explicit-null guard behind `Identifier::fold`): same refusal reason from the next line, so a surviving mutant is not automatically a gap; check what refuses instead.
- Catalogue `is_destructive` flag is read from the tree by the migration test, but the committed JSON is guarded by `SchemaCataloguePublishTest`; mutate the JSON and run that test, not the Schema dir.
- Mutation driver traps: Python heredoc `"\\n"` collapses, pest with a nonexistent path exits 2 with an empty summary (looks like a pass-by-silence), run mutation batches in the background and wait on the python PID with Wait-Process.
- SQLite collation is already bytewise, so an adapter `orderBy('code')` mutant is caught only by the query-log and source-scan tests; the end-to-end order proof is the held MySQL test.

Report: docs/qa/T-029-taxes-pricing.md. Related: [[project_t022-round2-review-fix-discrimination]].
