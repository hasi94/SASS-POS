---
name: t021-round2-review-fix-verification
description: T-021 review round 1 fix (R-1 CAS takeover, S-1/S-2/S-9) verification — four independent red/green mutation round trips, an edge case the review didn't ask for, and a real concurrent-gate corruption caught and correctly diagnosed as not-a-bug
metadata:
  type: project
---

**Compare-and-set fixes are mutation-tested by reverting exactly the `WHERE` clause the review named, not
the whole feature.** T-021 R-1 (critical): `IdempotencyStore::resolveExisting()`'s stale-claim takeover
`UPDATE` gained `->where('created_at', $row->created_at)` plus an affected-rows check
(`$taken === 1 ? Claimed : InProgress`). Reverting just that one `where()` and the ternary back to an
unconditional `UPDATE`/`Claimed` reproduced the exact review-described symptom: the new race test (which
races a second `UPDATE` ahead of the takeover via `$connection->beforeExecuting()`) went from
`InProgress` (correct) to `Claimed` (the bug — two competing retries would both run the handler).
Restore, re-green, confirm `git status --short` clean. This is the fourth task in a row
([[project_t018-review-round1-fix-verification]], [[project_t019-round2-review-fix-verification]]) where
the fastest real verification of "a CAS fix is correct" is reverting the comparison predicate alone, not
reasoning about it.

**A two-round-trip-point fix (S-9 shape: decode/store at point A, decode/replay at point B) needs BOTH
points mutated independently, not just one.** T-021 S-9 fixed `json_decode(..., true)` → `json_decode(...)`
at both `RequireIdempotencyKey::runAndStore()` (storage side) and `IdempotencyStore::resolveExisting()`'s
replay return (read-back side), because an assoc-array round-trip can't distinguish `{}` from `[]`.
Reverting only the storage-side point (keeping the replay side fixed) still broke the HTTP-level S-9 test
(`{}` became `[]` in storage before the replay side ever saw it). Reverting only the replay-side point
(keeping storage fixed) broke both the HTTP test and the kernel-level one — the kernel test's own
`$replay->responseBody->data` property access threw `ErrorException: Attempt to read property "data" on
array`, which is itself proof the test needs the object shape and isn't a loose-equality tautology. Only
reverting both at once would have passed a test that checked just one point; test both independently.

**When a fix changes array-vs-object branching logic, write one throwaway test for the shape the review
didn't ask about, run it, then delete it — don't just reason about it.** S-9 added an `is_object($body)`
branch alongside the pre-existing `is_array($body)` branch in `RequireIdempotencyKey::replay()`'s
`meta.request_id` patch. The committed tests only exercise envelope objects (`ApiResponse` always wraps in
a top-level JSON object), so the array branch is dead code in the committed suite. Wrote a temporary Pest
file registering a route returning a bare top-level JSON array behind the idempotency middleware, replayed
it, confirmed `[1,2,3]` round-trips to `[1,2,3]` unchanged (the array branch correctly finds no `meta` key
on a list and skips the patch), then deleted the file and confirmed `git status --short` clean. A scratch
test that calls `orgHttpWorld()`/`AccessTenantFixture` fixtures but skips the file's own `beforeEach(fn ()
=> orgHttpBoot())` call fails with "no such table: users" / "TenantConnectionFailed" — easy to misdiagnose
as a real app bug; always copy the real test file's full `beforeEach` setup into a scratch exploration, not
just the fixture's data-building calls.

**A full-gate run that returns ~90 failures with `database disk image is malformed`, `table marker already
exists`, and `Resource temporarily unavailable` on `unlink()` is the signature of two `composer check`
runs sharing one worktree's SQLite tenant fixture files — not a regression, however alarming the first
read looks.** This happened for real on T-021 round 2: my backgrounded `composer check` landed on top of
reviewer's own `composer check` (confirmed by `Get-CimInstance Win32_Process -Filter "Name='php.exe'"` —
listed `C:/.../composer.phar check` as PID 78316, started while mine was still running) sharing
`modules/host/storage/app/tenancy-fixture/{tenant-a,tenant-b}.sqlite`. The fix: wait for the other PID to
exit (`Get-Process -Id <pid>` in a background polling loop, not a foreground sleep — standalone/chained
`sleep` is blocked by the harness; background `while (Get-Process -Id $pid) { Start-Sleep 5 }` works and
notifies on exit), delete the leftover `.sqlite` fixture files (gitignored test artifacts, safe to remove),
confirm no other FlexiPOS-related `php.exe` remains, then re-run solo. The clean re-run reproduced the
exact pre-existing 10-failure/164-passed baseline by name, proving the 89-failure run was pure contention,
not a defect. See [[project_t016-platform-closeout]] ("concurrent gate-vs-ad-hoc-pest contention looks like
real bugs") and [[project_t010-mutation-survivors-and-harness]] ("never overlap gate runs") — this is the
first time I've caught it live, mid-task, against a reviewer's own concurrent run rather than my own.

**A dev's self-reported "N+1th failure, attributed to my own throwaway env workaround" claim is verified by
reproducing it *without* that workaround, not by trusting the attribution.** Dev reported 164 passed / 11
failed, with the 11th (a plugin-sdk `Encrypter` test) attributed to dev's own ad-hoc `APP_KEY` export not
being a well-formed key — "still environmental, not a regression." My clean solo run (no `APP_KEY` export
of any kind, same missing-`.env` state as every other run in this worktree) returned exactly 164/10,
matching the Run 1 baseline by name with no 11th failure at all. This confirms the attribution was correct
without having to trust it: the extra failure was purely an artifact of dev's own measurement method, never
present in the worktree's real state.
