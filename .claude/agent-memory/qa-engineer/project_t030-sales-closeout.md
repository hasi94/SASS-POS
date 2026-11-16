---
name: t030-sales-closeout
description: T-030 Sales QA round 1 - FAIL on one approval-bypass defect; seeded-approval blind spot, isolating one field in a binding test, mutation-driver group-fatal artefact
metadata:
  type: project
---

T-030 round 1 FAIL, 1 major defect. Gate 15 failed (13 baseline + 2 deliberate QA red tests) / 55 skipped / 5142 passed, 1216 s. 147 mutation runs, 6 survived the shipped suite (4 real gaps closed by QA tests, 2 equivalent).

- **BUG-1:** `LineApprovals::priorConsumed()` matches a consumed override/discount approval by sale+type+branch+payload only, and `AddSaleLine` reaches it, so one approval covers unlimited new lines (AC-55/D-59 say "same line", `UpdateSaleLine` only). Found only by going through the REAL RequestApproval -> DecideApproval -> consuming write chain with a second `AddSaleLine`; every shipped test seeded approval rows by hand. **How to apply:** when a feature has a request/decide/consume lifecycle, test the cheapest repeat use of the consumed token (a second line, a second call) not just the replay of the same call.
- **Dataset rows that change two fields at once prove neither:** the shipped gate row changed reference_type AND reference_id; the tender-replay row changed method AND reference. Mutants dropping either single comparison survived. Isolate one field per row.
- **Unreachable-by-design guards** (an overpay check when AddTender can never overpay) need a raw-seeded row to be tested at all.
- Mutation driver: a combined test-path list of two groups crashed pest with rc 255 and no summary (G10); re-run on one subtree before trusting or discarding a "kill". rc!=0 with no `Tests:` line is not a kill.
- Writing a report `.md` into the scratchpad is blocked by the harness; writing it to the project docs/qa path is allowed.

Report: docs/qa/T-030-sales.md. Related: [[project_t029-pricing-closeout]].
