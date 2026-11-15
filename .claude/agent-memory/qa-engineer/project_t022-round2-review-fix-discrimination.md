---
name: t022-round2-review-fix-discrimination
description: T-022 round 2 — the test fixture's own default IP address was the hidden trigger for R-2's json_encode bug; standalone no-DB mutation checks for pure-reflection helpers; a long gate run is not hung just because its output file stays empty.
metadata:
  type: project
---

Round 2 of T-022 (FP-216) re-verified dev's fix for reviewer's 3 Criticals
(R-1/R-2/R-3) plus W-1/W-2, confined to `QaRouteCrossTenantSweepTest.php` and
`QaJobCrossTenantSweepTest.php`. All three Criticals' fixes were independently
proven discriminating by revert → red → restore → green directly on the real
test files. See [[project_t022-crosstenant-sweep-closeout]] for round 1.

**A test fixture's own innocuous-looking default value can be the thing that
actually exercises a latent bug — read the fixture, don't just read the
bug's description.** R-2's bug was "`json_encode()` on a binary hash column
collapses to `false`/`""`, masking real diffs." The obvious place to look is
a hash or signature column; the actual trigger in this suite was far more
mundane: `organization-http-fixture.php`'s `orgHttpActivate()` defaults
`$ip` to `'203.0.113.5'` (a TEST-NET-3 documentation address used all over
this repo's fixtures), and `inet_pton('203.0.113.5')` packs to bytes
`CB 00 71 05` — `0xCB` is a UTF-8 lead byte for a 2-byte sequence, and the
next byte (`0x00`) is not a valid continuation byte, so the whole string is
invalid UTF-8. `AccessLogWriter::terminalLogin()` writes that packed IP into
`access_log.ip` on every terminal activation, and every seeded world in this
QA sweep activates a terminal — so the bug fired on literally every run of
the file, not on some edge case. Confirmed by: reproducing `json_encode()`'s
`false` return standalone with that exact byte string, then reverting the
real `JSON_INVALID_UTF8_SUBSTITUTE` flag in the real file and watching AC-7's
write-finding assertion (`$writeFindings` expected non-empty) go to `[]`
instead, at the exact line the mechanism predicts.

**A pure-reflection/serialize() helper function can be discrimination-tested
in a throwaway standalone PHP script, no Laravel/DB, no Pest, and no
contention with a concurrent gate run.** R-3's `qaJobSweepPayloadFinding()`
only touches `ReflectionClass`, `serialize()` and string functions. Copied
both the old and new function bodies into a scratch script (two function
names, `_OLD`/`_NEW`), built the same planted-credential class, and compared:
old returns `null` (false green — the only needle checked was a caller-
supplied fixture password the generic builder could never produce), new
correctly flags the literal `password` property name via the forbidden-
needle list. This is strictly faster and safer than running the real Pest
suite for logic that has no framework dependency, and is worth reaching for
whenever a fix under review is in a free function with no `app()`/DB call in
it — do the revert/red/restore/green in the real file too for the record,
but prototype with the standalone script first.

**A long `composer check` run whose output file stays empty is not
necessarily hung.** The output was piped through `tail -n 80`, which buffers
and writes nothing until the whole pipeline exits — so "0 lines in the output
file" is the expected, uninformative state for the entire ~800s+ duration,
not a hang signal. The actual liveness check is `Get-Process -Id <pid> |
Select CPU` polled a few times: if CPU keeps climbing, it is working, however
long wall-clock has passed relative to a previous run's reported `elapsed=`.
Measured this run at `elapsed=833.88s` vs round 1's `783.63s` — a ~6% slower
run, plausibly explained by the concurrent ad-hoc single-file Pest runs I ran
for the discrimination checks (see next point), not evidence of a problem.
**Do not pipe a long gate command through `tail` if you intend to poll the
output file for progress — redirect to a file directly instead, or accept
you can only watch process CPU, not partial output, until it exits.**

**Running a single-file ad-hoc Pest invocation concurrently with a full
`composer check` in the same worktree is a real, if usually survivable,
collision — avoid it even though it is tempting while waiting.** I ran three
single-file `--filter` invocations (on `QaRouteCrossTenantSweepTest.php` and
`QaJobCrossTenantSweepTest.php`) while the lead/my own backgrounded full gate
was still running in the same worktree, because the wait was unexpectedly
long (~800s+) and the per-file checks are pure test-file edits. Both runs use
the same `modules/host/storage/app/tenancy-fixture/*.sqlite` fixture
directory; Windows produced `unlink(...): Resource temporarily unavailable`
and `rmdir(...): Directory not empty` warnings from the race, but every run's
own pass/fail verdict stayed correct and self-consistent (confirmed by the
final gate's `12 failed / 2750 passed / 23 skipped` matching the task brief
exactly, and `git diff --stat` empty after every revert). Treat this as
"survived, don't repeat" rather than "proven safe" — per
[[project_t022-crosstenant-sweep-closeout]]'s already-stated rule, don't
overlap gate runs; if a wait is this long, verify liveness via process CPU
(above) and actually wait, rather than filling the gap with ad-hoc runs in
the same tree.
