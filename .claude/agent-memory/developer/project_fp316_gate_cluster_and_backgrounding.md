---
name: project_fp316_gate_cluster_and_backgrounding
description: FP-316's known 12-failure signature for composer check, and that the gate auto-backgrounds and its captured output can drop early FAILED headers
metadata:
  type: project
---

`composer check` from `modules/host` takes ~450-550s warm, well past the
tool's 180s auto-background threshold on this machine. It gets moved to a
background task automatically; trying to work around that with a polling
loop (`until grep ...; do sleep; done`) also gets auto-backgrounded, so just
let it run and wait for the completion notification rather than inventing a
wait mechanism.

The captured output file for a long `vendor/bin/pest` run can lose some of
its earliest `FAILED` block headers (observed: `grep -c FAILED` undercounted
the `Tests: N failed` summary line by 2, on a run with 12 failed). Likely a
`\r`-based progress line clobbering earlier text before it reaches the log.
Trust the final `Tests: N failed, M skipped, K passed` summary line as
authoritative, not a count of visible `FAILED` blocks.

FP-316 is a standing, filed, pre-existing failure cluster unrelated to any
single task: exactly 12 failures, 11 skipped, rotating across
`QaModuleRegistryContractTest` (×6), `QaModuleNameValidationTest` (×3),
`QaHealthProbeResilienceTest` (×2), `ModulesEnvParsingTest` (×1) - all about
module-host bootstrap/registry validation, never about the feature under
test. If a gate run shows exactly this split (or a subset of these class
names totalling 12, with the rest of the output not showing it due to the
truncation above), that's FP-316, not a regression - don't chase it, per
[[feedback_defer_in_a_comment_or_file_a_row]]. Any *other* failing class name
in the same run is a real regression and must stop the task.

**Why:** T-017's review-round-1 fix needed a real gate number distinguishing
"my change broke something" from "the standing FP-316 cluster is still
there" - this is the reference shape to compare against.

**How to apply:** after any `composer check` on this repo, read the tail
summary line first, then check every failing class name against this list
before reporting red/green to the lead.
