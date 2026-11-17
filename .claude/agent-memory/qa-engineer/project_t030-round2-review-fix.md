---
name: t030-round2-review-fix
description: T-030 QA round 2 PASS - cap/remainder mutation survivors, Windows python text-mode CRLF trap on test files, hand-checked edge cases
metadata:
  type: project
---

T-030 round 2 PASS (gate 13 baseline failures / 55 skipped / 5177 passed, 1362 s). 33 mutants over the refund money cap, tender header and approver preference: 8 survived the shipped suite (strict `>=`, discount column of remainder, prior-refund sum over every line, per-tax breakdown base/amount, tax-id match, approval ordering, fallback) and needed new tests.

- **Single-tax fixtures cannot kill a tax-id match mutant**; a two-tax group is needed. A multi-line sale is needed to kill "sum over every line".
- **Unreachable fallbacks** (AC-55A) need a forced drifted record: edit the stored approver and rewrite ids so id order reverses insertion order.
- **Python `open(p,"w")` on Windows writes CRLF** into a PHP test file and Pint then fails the gate on `line_ending` (twice here). Use newline="" or the Edit tool.
- Pest in this repo has no `--no-ansi`; use `--colors=never`.
- Spec edge "nothing left to refund in money" is ambiguous: code writes a 0.0000 refund; pinned by a characterisation test, left for a lead ruling.

Report: docs/qa/T-030-sales.md. Related: [[project_t030-sales-closeout]].
