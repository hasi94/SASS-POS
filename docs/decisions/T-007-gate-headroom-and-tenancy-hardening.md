# T-007 decisions: gate headroom and tenancy hardening

Decisions made autonomously by `ba` while writing `docs/specs/T-007-gate-headroom-and-tenancy-hardening.md`,
per the TEAM PROTOCOL. Each task numbers its own decisions; this file starts at D-1. Format:
`D-n | Question | Decision | Reason | Reversible: yes/no`.

D-1 | How is "enough headroom" for the process timeout expressed? | As a **ratio**: the timeout in force
for the slowest single process the gate spawns must be at least **2 times** that process's wall time on a
cold run measured on a recorded date. No absolute number of seconds is written into prose. | FP-171 forbids an
absolute figure without a date and a re-measure mechanism, and a timeout is a number that goes stale when the
suite grows. 2 times is the conventional margin and clears the incident (a step that needed more than 300 s
against a 300 s limit is a ratio below 1). | Reversible: yes (change the factor, re-run AC-2).

D-2 | May the fix be to disable the timeout (0 / `disableProcessTimeout`) for `check`? | No. The timeout
stays finite. | An unbounded gate turns a hung test into a hung CI job with no output. The defect was a
too-small limit that gave a wrong answer, not the existence of a limit. | Reversible: yes.

D-3 | Must a killed run exit with a status different from a failing run? | No. The discriminator required
is a **fixed sentinel line in the output**, present on a kill and absent on a failure and on a pass. The
exit status must be non-zero on a kill but may equal the status of a failure. | The requester's own
observation is that exit status alone cannot separate them. Demanding a distinct code would force a wrapper
around every invocation. Output is what a human reads on a red run. | Reversible: yes.

D-4 | Should the gate report its own margin on every run? | Yes: the last line of a completed run states
elapsed seconds, the limit in force and the ratio, and warns (exit status unchanged) when the ratio is below
the D-1 factor. | The kill is the dangerous part; a run that tells you the margin is shrinking, while it still
passes, is how the number is prevented from going stale silently. | Reversible: yes.

D-5 | Is continuous-integration configuration (job-level timeouts, cache restore) in scope? | No. Only
`modules/host/composer.json`, its scripts and the tests. | The requirement names those files; CI does not
exist in the repository yet. | Reversible: yes.

D-6 | What attacker does "unforgeable" defend against (FP-161)? | Two: code that knows the published
source (F1), and code running inside a tenant scope that can call every public API and read `config()` and
the context (F2). Code that can reflect into the live scoped object or rebuild the container (F3) is **out of
scope** and is written down as the residual. | PHP cannot defend a process against itself. F1 and F2 are the
cases the backlog row describes. | Reversible: yes.

D-7 | Which part of FP-160's promise is checked by reading and which by running? | Absence of the
`guardedConnections` map, the `ConnectionEstablished` dependency and a second attachment is checked by a
search **and** by a running test (a reflection count, an `Event::fake()` run). A search alone is not accepted
for any of them. | The requester asked for absence plus a positive control. | Reversible: yes.

D-8 | Which criteria need the diff or history? | Those marked "(diff)". `ba` has no shell in this session.
They are for `qa` or `reviewer` to run. | Same handover as T-006. | Reversible: yes.

D-10 | How is AC-4 reworded after the architect measured that a 2 s limit kills `lint` first? | AC-4 and
AC-10 become per-step (`composer test` run alone, limit forced). The whole-gate form is a second criterion,
AC-45, gated on the AC-1 numbers: a limit between the longest of the other steps and the cold test step.
AC-45 is pending until AC-1 exists, not failed. | The per-step form always works and still tests the
mechanism; the whole-gate form is the real scenario but needs measured numbers. Both can fail (AC-7 shows
the per-step one red at the T-006 tip). | Reversible: yes.

D-11 | Q2 and Q3 closed. | Q3: sentinel printable (Composer late, output survives), no change to D-3. Q2:
AC-14 unchanged, content-bound digest per the plan. | Measured by the architect, not assumed. | Reversible:
yes.

D-12 | What does "cold" mean in AC-1, AC-2 and AC-33, given dev measured two coldnesses (caches only: stan 3.33 to 43.86 s, test 85.59 to 105.46 s; machine-cold first run of a session 240.87 s against 87.34 s warm, about 2.8x; warm runs vary about 30 %)? | Both are defined and every duration must be labelled `[state: CACHE-COLD]`, `[state: MACHINE-COLD]` or `[state: WARM]` with its date; an unlabelled duration is rejected. AC-1 requires the CACHE-COLD run and reports a MACHINE-COLD run if one was observed. AC-2 uses the MACHINE-COLD S if observed, otherwise CACHE-COLD S times 3 and the words "machine-cold not observed, factor 3 applied". AC-33 requires exit 0 and no sentinel CACHE-COLD, and the same for MACHINE-COLD if observed; it never depends on a duration. AC-45 runs CACHE-COLD. | The limit protects the worst real machine, so S must not be the optimistic one; but a reboot cannot be demanded of qa on every run, so the cheap state is mandatory and the real one is either measured or covered by a stated factor. The factor 3 is rounded up from the measured 2.8 (a brief figure, not re-derived by ba). | Reversible: yes (change the factor or make MACHINE-COLD mandatory).

D-13 | Is AC-10's recipe achievable, and AC-45's window? | AC-10 now states a forced limit of at least 5 s (the criterion named no limit, so no number was wrong, but the impossible region was unstated; dev used 8). AC-45 keeps a pending-until-AC-1 gate, records dev's window (43.86 s to 105.46 s, plan's limit 53, budget 51) as a brief figure, and is marked as needing the branch; the thin margin over stan is flagged. | The warning needs limit/2 < limit - reserve with reserve at least 2 (plan lines 164-165), giving limit above 4. Reasoning derived by ba from the plan; the measurements are dev's. | Reversible: yes.

D-9 | Are the criteria that pin today's mechanisms (the reflection test of `guardedConnections`, the
Event::fake test that declines to assert the hole) allowed to change? | Yes, and only those, each listed by
name in the plan and in the QA report. | They assert the thing being removed. Any other edited test is
suspect (AC-41). | Reversible: yes.
