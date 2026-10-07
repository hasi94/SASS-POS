---
name: project-gate-timing-spread
description: The gate's wall time on this machine varies ~30 % run to run, ~3x session-cold and ~5x under contention from other projects, so one timing is never evidence - and a red gate here is often another project's suite
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

**Before believing a red gate, list the other PHP processes.** MEASURED 2026-10-07 (T-007 fix
round 1), the same tree twice, 30 minutes apart:

- run 1: **3 failed / 470 passed**, error code 2, analysis steps alone taking 750 s before the
  test step even started. `Get-CimInstance Win32_Process -Filter "Name like '%php%'" |
  Select ProcessId,CreationDate,CommandLine` showed **another project's full suite**
  (`D:\Flexiata\Scriber\backend`, `artisan test` + phpunit, running 20 minutes), a `php -S`
  holding 123 s of CPU, an `artisan serve` and a `queue:work`;
- run 2, caches warm and the competitor finished: **exit 0, 473 passed, 86.27 s**, ratio 10.41.

None of the three failures was a code defect, and two of them were the inherited 60 s child
timeout (FP-172's sibling). The third is worth knowing on its own: **`GateStepTimerTest`'s kill
case asserts `elapsed < 2.0` because its limit is 2 s, and under contention the kill landed at
2.22 s.** Symfony's `checkTimeout()` only runs between read-loop iterations, so detection latency
is unbounded under load while the assertion's ceiling is not - a false red in the very test that
exists to prevent false reds. **Resolved 2026-10-07 by R18 (commit `3b93389`): the ceiling was
removed, not widened.** The case now asserts the sentinel, exit 3, the resolved
`budget=/limit=/source=` triple with the budget derived in the test from the forced limit, the
child's post-sleep completion line absent, and `elapsed >= budget` as a lower bound. No
wall-clock upper bound on a kill is assertable on this platform, and refusing to widen the
number was the right first move: it is what got the assertion itself re-examined.

The process list is the cheap half of the measurement and it comes first: it costs one command
and it separates "my change broke the gate" from "this machine is busy" before any re-run.

**Why:** FP-171 and FP-172 both turn on durations, and a false performance claim in a commit
message is as hard to retract as a false test result.

**How to apply:** before quoting a duration, run the thing twice and quote both, or quote a
ratio. Before attributing a slowdown, measure the with-and-without pair consecutively. State
which caches were warm.

Related: [[project-backend-toolchain-constraints]],
[[feedback-clean-up-probe-files-immediately]].
