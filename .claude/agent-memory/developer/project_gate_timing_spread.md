---
name: project-gate-timing-spread
description: The gate's wall time on this machine varies ~30 % run to run and ~3x on the first run of a session, so one timing is never evidence for a limit or a performance claim
metadata:
  type: project
---

A single timing of `composer check` or `composer test` on this machine is not evidence.
MEASURED 2026-10-07, same tree, same warm caches, consecutive runs:

- the full suite: 85.59, 117.83, 91.44, 120.10 seconds - a spread of about 30 %;
- the **first** `composer check` of a session: 240.87 seconds against 87.34 warm, about 2.8x,
  while the five steps timed individually right afterwards summed to 82. Nothing was cold in
  the analysis caches; the operating system's file cache was.

Two consequences that already changed a decision:

- **AC-1's "cold" definition (analysis caches removed) understates a genuinely cold machine.**
  Removing `storage/framework/cache/phpstan/` and `.deptrac.cache` moved stan from 3.33 to
  43.86 and the test step from 85.59 to 105.46 - nowhere near the 240 a session-cold run cost.
  So a timeout derived only from the cache-only figure is optimistic. On T-007 that is why the
  limit was left at the plan's conservative 900 rather than the 600 the rule yielded from the
  measured figure.
- **Any claim of the form "X made the gate slower" needs the control run.** The step wrapper
  added 0.65 seconds wall (118.48 wrapped against 117.83 bare, inner durations 117.97 against
  117.63) - an order of magnitude below the run-to-run spread. Without a bare-against-wrapped
  pair measured back to back I would have reported a 40-second regression that was noise plus
  thirteen new cases.

**Why:** FP-171 and FP-172 both turn on durations, and a false performance claim in a commit
message is as hard to retract as a false test result.

**How to apply:** before quoting a duration, run the thing twice and quote both, or quote a
ratio. Before attributing a slowdown, measure the with-and-without pair consecutively. State
which caches were warm.

Related: [[project-backend-toolchain-constraints]],
[[feedback-clean-up-probe-files-immediately]].
