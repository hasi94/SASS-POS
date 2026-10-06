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

D-9 | Are the criteria that pin today's mechanisms (the reflection test of `guardedConnections`, the
Event::fake test that declines to assert the hole) allowed to change? | Yes, and only those, each listed by
name in the plan and in the QA report. | They assert the thing being removed. Any other edited test is
suspect (AC-41). | Reversible: yes.
