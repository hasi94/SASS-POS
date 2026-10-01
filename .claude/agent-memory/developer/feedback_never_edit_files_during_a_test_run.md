---
name: feedback-never-edit-files-during-a-test-run
description: Never edit repo files while a background suite run is in flight, and never treat a handful of green runs as proof a Windows teardown flake is fixed
metadata:
  type: feedback
---

Two lessons from the same wasted cycle on T-004.

**Do not edit any repository file while a background `composer test`/`check` run is in flight.**
Pest collects at process start, so an edit lands in some runs and not others: five "consecutive"
runs then report different assertion counts and one exits non-zero, and the first instinct is
that the edit broke something. Queue the edit until the run reports done, or accept that the
whole batch has to be re-run. A batch of full-suite runs is ~10 minutes here; re-running one
because of an edit made during it costs more than waiting.

**A handful of green runs does not prove a Windows directory-teardown flake is fixed.** The
mechanism, measured on this machine: while any handle inside a directory is open, `unlink()` on
the files **succeeds** but `rmdir()` on the directory **fails** — so the files go and the
directory stays, and `svc:make` then refuses the name and the *next* test in the file fails for
an unrelated reason. A retry loop with no delay completes in microseconds and therefore cannot
outlast the holder: three immediate passes still lost `services/trustfixture` on a full run,
after 13 consecutive green runs had convinced me it was closed. What works is
`gc_collect_cycles()` plus a real `usleep()` between attempts, with the first attempt never
sleeping so the normal case stays free.

**Why:** the first fix shipped with a tight loop and a claim of "13 consecutive green runs",
which was luck, not evidence. The flake reappeared on the very next batch.

**How to apply:** for any intermittent-teardown fix, get evidence cheaply by looping the *one
file* that exhibits it (20 runs of an 8-case file is under a minute) before spending 10 minutes
per full-suite run — and report the two numbers separately. Also reproduce the condition in a
unit test by holding a file handle open, and assert a *lower bound* on the time the helper takes
to give up, which is what catches a revert to a tight loop.
Related: [[feedback-guard-must-not-disturb-what-it-measures]],
[[project-backend-toolchain-constraints]].
