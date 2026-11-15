# Code Review: T-021 (api-foundation)

**Round 1 · Verdict: CHANGES REQUESTED** (1 Critical, 4 Warnings, 9 Suggestions)
**Round 2 · Verdict: APPROVE** (R-1/S-1/S-2/S-9 fixed, 3 new non-blocking Suggestions)

---

## Round 1

Diff: `eb27e43` (dev's implementation, 84 files). Criteria: AC-1 to AC-54 (plus
AC-33a/33b, AC-40a). Decisions: D-1 to D-22.

### Gate, measured by the reviewer

`composer lint`/`stan`: pass. `composer deptrac`: 0 violations. `composer test`,
solo: 164 passed, 10 failed, 2573 warnings (18540 assertions), ratio 10.95. The
10 failures are a known, pre-diagnosed gap: this worktree has no `.env` file
(creating one is blocked by the harness's own permission system), so 9 nested
child-process pest runs and one session-guard test can't report clean pass/fail
text — none touch this task's own surface. `ENGINE_HOLD`/Redis-absence
correctly NOT VERIFIED where real infra is needed.

### Critical

- **R-1** `IdempotencyStore.php` (~lines 115-121): the stale-claim takeover ran
  an unconditional UPDATE and always returned `claimed()`. Two requests racing
  to take over a dead holder's claim could both match the stale row, both
  update it, and both get back `Claimed` — both then run the handler. Breaks
  AC-22's "exactly one execution" guarantee specifically on the takeover path
  (the first-claim path via the `key` primary key, and the expired-row-deletion
  path, were both already safe).

### Warnings (filed as follow-ups FP-370 to FP-375, non-blocking)

A slow-but-not-dead holder's claim being stolen and then corrupted by its own
late `release()` (W-1); unauthenticated requests that fail token validation
bypassing the IP rate-limit bucket entirely (W-2); `RedisCheck`'s real
connect/detect paths having no test coverage, though confirmed a genuine probe
not a no-op (W-3); the rate limiter costing ~5 cache round trips per bucket
against a 1-round-trip budget (W-4).

### Suggestions (3 folded into round 2, 6 filed as follow-ups)

Folded in: an undefined-index crash on `ApiResponse::failures([])` (S-1); a
PCRE trailing-newline validation bypass in `IdempotencyKey` (S-2); an empty
JSON object `{}` silently becoming `[]` on idempotency replay (S-9). Filed:
missing `request_id` on several new log lines, a literal-vs-constant config
duplication, and others (FP-374, FP-375, plus S-3/S-5/S-7 accepted as-is after
investigation).

### Done well

Fail-open wraps every limiter call before the handler runs, so a cache-store
outage can never fail a request halfway through. The error-code catalogue
validates at registration time and degrades safely in production. The generic
exception renderer never echoes the request path or exception detail. The
OpenAPI completeness test carries its own self-proving control. Three
pre-existing-invariant regressions (a tenant-isolation config-write guard, an
eventbus-source-rules guard, a fixture-baseline-path guard) were found and
fixed by `dev` during implementation, all independently re-verified against
the real guard logic by both `qa` and `reviewer` — none defeats its guard's
actual purpose.

---

## Round 2 — the R-1/S-1/S-2/S-9 fix only

Diff: `eb27e43..71765c6`, 9 files (3 source, 1 middleware, 3 test files, 2 dev
memory notes — no new test file; tests were added to existing files).

### Verdict: APPROVE

- **R-1**: the takeover UPDATE now compares on the exact `created_at` value
  read (`->where('created_at', $row->created_at)`) and checks the
  affected-rows count — only the winner gets `Claimed`. A takeover only
  happens once the claim timeout has passed, so the new timestamp always
  differs from the old one, making the affected-rows count reliable. The new
  test's `beforeExecuting()` hook reproduces the real interleaving (a
  competitor's update landing between the read and the write) and genuinely
  needs both halves of the fix to pass.
- **S-1, S-2**: straightforward, each with a test directly exercising the
  previously-broken behaviour.
- **S-9**: both round-trip points fixed (`RequireIdempotencyKey::runAndStore()`
  and `IdempotencyStore::resolveExisting()`), confirmed independently by
  reverting each one at a time — reverting either alone breaks a test.
  `reviewer` worked through all six possible top-level JSON shapes
  (object/array/scalar/null/empty/malformed) and confirmed the
  `is_object()`/`is_array()` branching is safe for every one, with two minor,
  pre-existing, non-blocking gaps filed as follow-ups (a dead `is_array`
  branch now unreachable; a literal-`null` 2xx body replaying as `{}`, which
  predates this fix and can't be reached through `ApiResponse`).

All four fixes were independently mutation-tested by both `reviewer` and `qa`
— each reverted, the corresponding test(s) confirmed red for the exact reason
described, then green again on restore. `qa` additionally proved the
object/array branching doesn't regress a bare top-level JSON array replay (not
explicitly asked for, caught proactively).

### New Suggestions (non-blocking, filed FP-376 to FP-378)

A now-dead defensive branch in the request-id patch logic; the pre-existing
null-body-replays-as-`{}` quirk; a test matcher (`toEqual`) that compares
object properties loosely rather than strictly.

### Gate, re-measured independently by both `reviewer` and `qa`

Both hit the same worktree-sharing contamination hazard this round (two
`composer check` runs landing on the same SQLite tenant fixtures at once,
producing `database disk image is malformed` and similar corruption
signatures) and both correctly identified it, waited it out, and re-ran
solo. Clean numbers, matching exactly between `reviewer` and `qa`: **164
passed / 10 failed (exactly the known `.env`-caused set, named identically
by both) / 2578 warnings**, ratio 7.49-10.95 across runs. `dev`'s own reported
"11th failure" (a plugin-sdk Encrypter test) did NOT reproduce in either
reviewer's or qa's clean run — confirmed to be an artifact of dev's own
throwaway `APP_KEY` export used only to get readable numbers, not a real gap.

### Done well

The CAS fix reuses the pattern already present in the expired-row deletion
path, rather than inventing a new locking primitive. The S-9 HTTP-level test
proves both round-trip points at once by asserting on the actual replayed
bytes. `qa` went beyond the review's own ask to directly prove the edge case
most likely to have been missed (a bare JSON array body).

---

## Final verdict

**APPROVE.** Task ready to close. R-1, S-1, S-2, and S-9 are all verified
fixed, independently, by both `reviewer` and `qa`, with both correctly
navigating a second instance of the worktree-sharing contamination hazard.
The only gate failures throughout were the known, pre-diagnosed `.env` gap.
Open follow-ups: FP-370 to FP-378.
