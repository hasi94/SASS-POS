---
name: project-gate-timing-spread
description: The gate's wall time on this machine varies ~30 % run to run and ~3x on the first run of a session, so one timing is never evidence for a limit or a performance claim
metadata:
  type: project
---

A single timing of `composer check` or `composer test` on this machine is not evidence.
MEASURED 2026-10-07, same tree, same warm caches, consecutive runs:

- the full suite: 85.59, 117.83, 91.44, 120.10, 95.98 seconds - **range 34.51 s, sd 14.11 on a
  mean near 102**. So no difference of two single timings smaller than about 35 s is evidence of
  anything here;
- the **first** `composer check` of a session: 240.87 seconds against 87.34 warm, about 2.8x
  (`ba` rounded it to a factor of 3 in D-12). Nothing was cold in the analysis caches; the
  operating system's file cache was.

**Correction, 2026-10-07:** this file used to say the five individually-timed steps "summed to
82". They summed to **96.58**, against one `composer check` at 87.34. I then used that 9.24 s gap
over four extra Composer boots (~2.31 s each) to claim the architect's ~1.9 s per-invocation boot
estimate "holds" - and it does not, because 9.24 is a difference of two single timings inside the
±17 s band in the bullet above. That is the same objection I had just applied correctly to my own
0.65 s wrapper measurement, and failed to apply to this one. The boot cost stays an **ESTIMATE**,
nothing in the limit's derivation depends on it (recipe 1 takes S as the largest per-step wall
time, which already includes that step's own boot), and `composer --version` at 0.21 s is not a
proxy for it.

**"Cold" is two states and the vocabulary matters** (T-007 D-12). CACHE-COLD means the analysis
caches removed; MACHINE-COLD means the operating system's file cache cold too. Every duration
quoted on this project carries a `[state: ...]` tag for that reason.

Two consequences that already changed a decision:

- **CACHE-COLD understates MACHINE-COLD badly.** Removing
  `storage/framework/cache/phpstan/` and `.deptrac.cache` moved stan from 3.33 to 43.86 and the
  test step from 85.59 to 105.46 - nowhere near the 240.87 a session-cold whole-gate run cost. So
  a timeout derived only from the cache-cold figure is optimistic. On T-007 that is why the limit
  stayed at 900: D-12 defines the headroom input S as the cache-cold slowest step **times 3**
  while no per-process machine-cold figure has been measured, so S is 318 and not 106, and 600
  fails the criterion on both its limit (600 < 636) and its budget (588 < 636). The manifest key
  set is state-explicit for the same reason - a single `cold-` key made the standing assertion
  demand 882 >= 212 when the criterion demanded 882 >= 636.
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
