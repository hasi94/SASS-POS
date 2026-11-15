---
name: project-t027-round2-review-fix-verification
description: T-027 catalog round-2 review-fix verification — all 6 items PASS, a slow-but-not-hung composer check diagnosed via CPU-time growth, and the tail-piped-truncates-earlier-steps gotcha.
metadata:
  type: project
---

T-027's round-1 review (1 Critical R-1, 5 Warnings W-1 to W-5, see
[[t027_catalog_closeout]]) was folded into one fix round, committed in `103a7fc`. Round-2 QA
verified all 6 items with no defects. Three technique notes worth keeping:

**Diagnosing "is `composer check` hung or just slow" without Monitor tool access:** when a backgrounded
gate run's output file stays empty far longer than the usual ~80s warm baseline, don't assume a hang.
Sample `(Get-Process -Id <pid>).TotalProcessorTime` twice, a few minutes apart, on the actual `pest`
child process (found via `Get-CimInstance Win32_Process -Filter "name='php.exe'"`, walking the
composer → gate-step.php → pest process chain). Real CPU-time growth between samples (even a low
~15-30% utilization ratio against wall-clock) proves genuine progress under contention, not a
deadlock — this machine runs several unrelated local Laravel projects (`flexi-crm`, `Scriber`,
`SimplePos-System`) whose own PHP processes compete for CPU/disk and can stretch a normally-80s gate
to 2400s+ wall-clock with zero code-level problem. A hang would show flat CPU time between samples.

**`composer check | tail -N` loses the earlier steps' own output**, not just truncates verbosity: since
`check` runs `probes:clean, engine:clean, lint, stan, deptrac, test` in strict sequence and stops at
the first failing step, simply reaching `test`'s own completed summary line (and matching the known
baseline count exactly) is sufficient proof that lint/stan/deptrac all passed — their own `[OK]`
lines don't need to survive the tail truncation to be trusted, because a prior-step failure would have
prevented `test` from ever starting.

**Mutation-testing a "no exemption" claim (W-3) needs a planted violation inside an exempted file, not
just reading the exemption list.** The task brief's "would the scan actually catch it" question was
answered by temporarily adding a dead private method with a `$quantity * $row->factor` shape to one of
the four already-exempted files (`CreateUnitHandler.php`) and confirming the factor-multiplication
test (which takes no exemption parameter anywhere) reddened, naming that exact file. This is stronger
than confirming the exemption lists are correctly scoped by inspection alone — it proves the one rule
with *no* exemption really has none, for real files, not just by absence of a parameter in the source.

See [[t010-mutation-survivors-and-harness]] and [[negative-control-every-guard]] for the general
mutation-testing discipline this followed.
