---
name: t025-frontend-bootstrap-review-fix-round
description: T-025 round 2 — verifying a disabled-button Angular test and a CI exit-code regex pin, including a dispatchEvent-vs-click jsdom trap
metadata:
  type: project
---

T-025 (FP-219) round 2 reviewed two round-1 review Warnings (R-1/FP-405, R-2/FP-406), both of which held up under independent mutation testing with zero defects. Two reusable lessons:

1. **jsdom `.click()` vs `dispatchEvent(new Event('click'))` on a disabled element.** A native `button.click()` in jsdom is a no-op on a `disabled` button (correctly driving a `not.toHaveBeenCalled()` assertion on the handler spy); `button.dispatchEvent(new Event('click'))` fires the handler regardless of the `disabled` attribute. A test asserting a disabled control can't be activated must use `.click()`, not `dispatchEvent`, or it passes even when the component doesn't actually disable anything. Caught in `dev`'s own fix history for `context-page.spec.ts` (first attempt used dispatchEvent and didn't discriminate; rewritten to `.click()`). Verified by reading the shipped test literally, not by trusting the commit message.

2. **Pin the exact `case` branch, not the string "exit 1" anywhere in the step.** `CiWorkflowShapeTest`'s mock-exclusion case originally used a loose `toContain('exit 1')`, which an unrelated `compgen ... || { ...; exit 1; }` guard earlier in the same step also satisfies — so weakening the real `0) ... exit 1 ;;` branch to `exit 0 ;;` would stay green. Fixed with an anchored regex per branch: `toMatch('/^\s*0\)[^\n]*exit 1\s*;;/m')` and the `*)` branch likewise. Confirmed by mutating the real `ci.yml` branch to `exit 0` and watching exactly that one test (and no other) redden with the PCRE-mismatch message, then restoring and confirming `git diff` empty. See also [[project_t009-round2-ingress-and-pin-blind-spots]] for the general "select-star/loose-contains evades a column/branch-keyed pin" pattern — this is the same shape in a different idiom (shell `case` branch vs SQL column).

General takeaway for review-fix verification tasks: always do the mutation on the **real production file** (component template binding, workflow YAML), not a copy, confirm the exact shipped test reddens for the stated reason, then restore and confirm `git diff` is empty before moving on. Don't assume a described fix is correct from the review prose alone — read the actual diff and reproduce the pre-fix failure mode.
