---
name: feedback_subagenthandback_is_terminal_not_a_status_update
description: do not call SubagentHandback to report "still waiting on the gate" - wait for the real result first
metadata:
  type: feedback
---

`SubagentHandback` is documented as a one-shot hand-off, not a messaging
channel, and should only be called once real work is complete. Mid-task I
called it anyway to send a "waiting on composer check, here's progress so
far" note while a backgrounded gate run was still in flight, instead of
just waiting for the run to finish and sending one real final report.

**Why:** the tool description is explicit that plain text at the end of a
run is not delivered to the caller and that the call ends the run - using
it for an interim status update risks the caller treating a provisional,
unverified report as final, and splits one report across two calls for no
reason.

**How to apply:** when a long-running command (e.g. `composer check`) is
still backgrounded, do not call `SubagentHandback` yet - either keep working
on other in-scope items or simply wait for the completion notification, then
compose and send exactly one final report once real numbers are in hand. See
[[project_fp316_gate_cluster_and_backgrounding]] for why this gate in
particular takes long enough to tempt an early handback.

**Measured consequence (T-028 chunk E, 2026-11-10): the tool is not just
"should be one-shot", it is mechanically one-shot.** After the first
mid-task call, every later `SubagentHandback` call - even with real,
complete, final numbers - was refused outright: `"Nothing was sent: an
earlier report of yours was already delivered ... Stop now."` There is no
way back into that channel for the rest of the run. The coordinator's
repeated "your report isn't reaching me" messages were therefore not a
delivery glitch at all: plain assistant text genuinely does not reach the
caller (per the tool's own description), so once the one real call is
spent early, the only options left are an unreachable text reply or
silence. Treat the first `SubagentHandback` call as the *only* call this
run gets, full stop - not "the first of possibly several if something goes
wrong."
