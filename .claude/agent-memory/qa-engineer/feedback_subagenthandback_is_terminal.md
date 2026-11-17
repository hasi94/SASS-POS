---
name: feedback_subagenthandback_is_terminal
description: SubagentHandback is a one-shot call, not a status-ping channel - never use it for "still waiting on the gate" interim updates
metadata:
  type: feedback
---

While a backgrounded `composer check` was still running (T-028 round 3
re-verification), the coordinator asked for a status update mid-task. I
called `SubagentHandback` with an interim "here's what I've verified so far,
gate still running" message instead of waiting for the real result. The
call succeeded and was delivered — which is the trap: it *looks* like a
normal message round-trip, but it ends the run. Every subsequent
`SubagentHandback` call, even with complete final numbers, was refused with
"an earlier report of yours was already delivered ... Stop now." The
coordinator's further "please send your final verdict now" requests could
not be answered through the tool at all after that point; only plain text
(not delivered to the caller) was left.

**Why:** the tool description says plain text at the end of a run is not
delivered, and that the call itself ends the run — i.e. it is mechanically
one-shot, not "one-shot unless something comes up." A developer agent on
this same project independently hit and recorded the identical failure
(`.claude/agent-memory/developer/feedback_subagenthandback_is_terminal_not_a_status_update.md`),
so this is a recurring trap for every role on this team, not a one-off.

**How to apply:** when a coordinator or lead asks for status while a
long-running gate/build is still in flight, answer in whatever channel
delivered their message if one exists, or simply keep working and wait for
the real completion — never call `SubagentHandback` until the actual final
result (real gate numbers, real verdict) is in hand. Treat the first
`SubagentHandback` call as the only call the run gets, full stop. If a
coordinator message arrives while a background task is pending and no other
messaging tool is available, the only safe move is to keep waiting (or do
other in-scope verification work) rather than treat the mid-task check-in as
a reason to hand back early.
