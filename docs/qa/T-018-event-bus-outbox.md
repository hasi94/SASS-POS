# QA Report: T-018

## Verdict: PASS

Event bus and outbox (FP-212). 37 acceptance criteria, 13-step plan. Steps 0-11 were
dev's; step 12 (mandatory mutation testing and close-out) is `qa`-accountable per the
plan's own convention and is the core of this report.

## Acceptance criteria coverage

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 | `EventBusDriverTest`, `EventBusContractTest` (both drivers) | PASS |
| AC-2 | `QaEventbusSourceRulesTest` — re-verified with a real planted violation in `modules/demo/src` (a real scanned dir, not a throwaway fixture) | PASS |
| AC-3 | NOT VERIFIED (Redis, D-1) — stand-in half proven in `EventBusContractTest` | NOT VERIFIED (expected) |
| AC-4 | `RedisStreamsEventBusUnreachableTest` (ran live: fake factory **and** a real `RedisManager` on `127.0.0.1:1`), `EventBusDriverTest`, mutation 10 | PASS |
| AC-5 | `QaEventbusSourceRulesTest` — re-verified as AC-2 | PASS |
| AC-6 | `OutboxTransactionTest`; mutation 6 | PASS |
| AC-7 to AC-13 | `OutboxWriterTest`, `OutboxCorrelationHttpTest`; mutation 5 | PASS |
| AC-14 to AC-17, AC-19 | `RelayOutboxTest`; mutations 1-4 | PASS |
| AC-18 | Statement only (D-4), no test — matches plan | PASS (as designed) |
| AC-20 | NOT VERIFIED (Redis, D-1) — stand-in half in `RelayOutboxTest` | NOT VERIFIED (expected) |
| AC-21, AC-22, AC-24 | `EventBusContractTest`, `ConsumeEventsTest`; mutation 8 | PASS |
| AC-23 | `ConsumeEventsTest` (with discriminating `$skipIdempotencyCheck` control), `ProofOfPipelineTest`; mutations 7 and 11 | PASS |
| AC-25 | NOT VERIFIED (Redis, D-1) — stand-in half in `EventBusContractTest`/`ConsumeEventsTest` | NOT VERIFIED (expected) |
| AC-26 | By construction — one stream, no sequencing code | PASS |
| AC-27, AC-28 | `ConsumeEventsTest`; mutation 9 | PASS |
| AC-29, AC-31, AC-32 | `ListFailedOutboxEventsTest`, `RetryFailedOutboxEventTest`, `ReplayDeadLetterTest`, `ReportOutboxAgeTest` | PASS |
| AC-30 | `ListDeadLettersTest` (stand-in); real-Redis read NOT VERIFIED (D-1) | PASS / NOT VERIFIED (expected) |
| AC-33 | `QaEventbusNoHttpSurfaceTest` — ran live with its own control | PASS |
| AC-34 | `ProofOfPipelineTest` — ran live | PASS |
| AC-35 | Statement + `QaEventbusSourceRulesTest`'s AC-5 scan (no second caller of `OutboxWriter`) | PASS |
| AC-36 | Full `composer check`, run twice independently (see below) | PASS |
| AC-37 | step 1's deptrac narrowing + independently re-run negative control (red on `OutboxWriter` import, green on `Outbox` contract import) | PASS |

## Mutation testing (step 12, mandatory)

All 11 plan-specified mutations applied to the real source files, confirmed red for the
right reason, reverted, and confirmed byte-identical via `git diff --stat` (zero output)
before moving to the next. Fast, targeted `vendor/bin/pest` runs were used per mutation
(not the full gate), with no other gate run active at the same time.

| # | Mutation | File | Confirmed red via | Result |
|---|----------|------|--------------------|--------|
| 1 | Drop `where status='pending'` | `RelayOutboxHandler.php` | `RelayOutboxTest` — AC-15 "second pass republishes nothing" and the 5th-failure case, 3 failed | RED → reverted → GREEN |
| 2 | `order by id` replaces `occurred_at` | `RelayOutboxHandler.php` | `RelayOutboxTest` — AC-14, wrong row published first | RED → reverted → GREEN |
| 3 | Remove the pacing check | `RelayOutboxHandler.php` | `RelayOutboxTest` — AC-17 "immediate second pass skips" | RED → reverted → GREEN |
| 4 | `>=` becomes `>` on `max_attempts` | `RelayOutboxHandler.php` | `RelayOutboxTest` — 5th failure no longer exhausts | RED → reverted → GREEN |
| 5 | Writer validates after inserting | `OutboxWriter.php` | `OutboxWriterTest` — "no tenant resolved" control throws `NoTenantResolved` instead of `OutboxEventRefused` (10 of 18 cases failed) | RED → reverted → GREEN |
| 6 | Writer opens its own transaction on a fresh connection | `OutboxWriter.php` | `OutboxTransactionTest` — see note below | RED → reverted → GREEN |
| 7 | Consumer runner acks before `handle()` | `ConsumeEventsHandler.php` | `ConsumeEventsTest` — AC-24 reclaim and AC-27/AC-28 dead-letter cases (3 failed): an acked-then-thrown message vanishes from the pending list and is never retried or dead-lettered | RED → reverted → GREEN |
| 8 | Stand-in `read()` delivers to every consumer | `InMemoryEventBus.php` | `EventBusContractTest` — AC-21, both consumers see all 3 messages | RED → reverted → GREEN |
| 9 | `deadLetter()` skips the ack half | `InMemoryEventBus.php` | `EventBusContractTest` — "acknowledged in the original" claim check | RED → reverted → GREEN |
| 10 | `EventBusDriver` falls back to memory when Redis "fails" | `EventBusDriver.php` | `EventbusProviderTest` — resolved bus is `InMemoryEventBus` instead of `RedisStreamsEventBus` | RED → reverted → GREEN |
| 11 | `PingedConsumer` drops its idempotency check | `PingedConsumer.php` (test support) | `ConsumeEventsTest` — AC-23 end state: `effectsApplied` 2 instead of 1 | RED → reverted → GREEN |

**Note on mutation 6.** The plan's literal wording ("on a fresh connection name") was
implemented as a genuinely separate `Connection`/PDO instance pointed at the same SQLite
fixture file, inside its own `DB::transaction()`. SQLite's single-writer file lock turned
this into `SQLSTATE[HY000]: database is locked` rather than a clean "row silently
survives" outcome — because `QueryException extends PDOException extends
RuntimeException`, the forced-rollback case's `catch (RuntimeException)` swallowed the
lock exception and still passed (for the wrong proximate reason), while the committed-twin
control case failed outright with the lock error (no try/catch there). The file still went
red (1 of 2 failed), which is sufficient to demonstrate the mutation breaks the "one
transaction" guarantee: a real MySQL/Redis-backed environment has no such single-file lock
and would show the defect even more cleanly, as a row that survives a rollback. Recorded
here instead of silently reported as a clean pass, per "report the real mechanism, not a
tidied-up one."

Each mutation's file was confirmed restored exactly (`git diff --stat` empty) before the
next mutation was applied, and the full `packages/eventbus/tests` suite was green (95
passed, 8 skipped) after all reverts.

## Qa-accountable files — independent verification

Per the plan's convention (files named `Qa*` are qa-accountable) and the T-017-round-1
lesson (a planted-string control that only checks its own fixture proves nothing), both
`QaEventbusSourceRulesTest` and `QaEventbusNoHttpSurfaceTest` were re-verified with a
**real violation planted in a real scanned directory**, not dev's own temp-directory
fixture:

- Planted `modules/demo/src/QaScratchEventbusViolation.php` containing a genuine
  `Redis::command('XADD', ...)` call and a genuine `DB::table('outbox_events')->insert()`
  call. Ran `QaEventbusSourceRulesTest` live: both the AC-2 and AC-5 scans caught it by
  its real path. Deleted immediately; `git status --porcelain modules/demo` empty
  afterward; re-ran the file — green again.
- `QaEventbusNoHttpSurfaceTest` already carries its own live-route-table control (mounts
  a real `GET api/v1/outbox` route and asserts it is reported) — ran it live, confirmed.

This is the "real scratch file in the real scanned dir" technique from the T-017
close-out, applied here independently of dev's own (already-adequate) scratch-directory
controls inside the test file itself.

## AC-37 — deptrac negative control, independently re-run

Per the task's instruction, re-ran the control myself rather than trusting dev's report:

1. Planted `modules/demo/src/QaScratchEventbusDeptracProbe.php` importing
   `Flexipos\Eventbus\Support\OutboxWriter` directly. `vendor/bin/deptrac analyse
   --no-cache`: **1 violation** — `Flexipos\Module\Demo\QaScratchEventbusDeptracProbe must
   not depend on Flexipos\Eventbus\Support\OutboxWriter ... (Package_Eventbus)`.
2. Changed the same file to import only `Flexipos\Contracts\Events\Outbox`. Re-ran:
   **0 violations**, allowed edges 727 (726 + the one new allowed edge the probe itself
   added).
3. Deleted the probe. Re-ran deptrac: **0 violations, 726 allowed** — exactly the
   pre-probe baseline. `git status --porcelain` on the whole repo: empty.

**On whether this needs a permanent committed test (raised by the task brief):** no. The
plan's own step 10 text states this explicitly — "a recorded, re-run copy of step 1's
demo negative control ... goes in the qa report, not a committed test. Committing a
deptrac probe test would join the toolchain group for one assertion" — and a generic
instrument already exists and passes: `DeptracBoundaryEnforcementTest` ("exits non-zero
and names both layers when a module depends on another module") proves the boundary-check
*mechanism* fires correctly in general; it does not need an eventbus-specific sibling
re-asserting the same mechanism for one more edge. I agree with the plan's reasoning and
do not require a new test. Noted as a non-blocking **Suggestion** only: a future task that
adds a third or fourth package-specific boundary edge might reconsider the "report only"
convention under the three-use rule — not blocking for T-018.

## ENGINE_HOLD and Redis handling

- **ENGINE_HOLD respected.** Gate log, `clean-engine` step: *"the engine suite is on
  hold (ENGINE_HOLD), so nothing was read and no connection was opened."* No MySQL/MariaDB
  connection attempted anywhere in either gate run.
- **In-memory stand-in refused outside local environments.** `EventBusDriverTest`
  ("refuses the stand-in outside a local environment") run live: PASS.
- **Redis unreachable → `EventBusUnreachable`, no silent fallback.** Ran
  `RedisStreamsEventBusUnreachableTest` live against **two genuinely bad configurations**,
  not just a fake class: a `RedisFactory` stub whose `connection()` throws, **and** a real
  `Illuminate\Redis\RedisManager` pointed at `127.0.0.1:1` (privileged, non-listening port,
  0.5s timeout) — both throw `EventBusUnreachable` naming the connection and keeping the
  original exception; a third case confirms the in-memory bus holds zero messages
  afterward (no fallback). Mutation 10 above additionally proves the driver-selection layer
  has no fallback path either.

## Bug fixes spot-check

Two bugs dev reported finding while building tests — read the actual diffs, confirmed
both are real and both have a regression test that would have caught them:

- **`ReportOutboxAgeHandler`'s signed `diffInSeconds()`.** The fix passes `absolute: true`
  explicitly (`$this->clock->now()->diffInSeconds(CarbonImmutable::parse(...), true)`)
  rather than relying on operand order. `ReportOutboxAgeTest`'s first case inserts a row
  90 seconds old by the fixed clock and asserts the handler returns exactly `90` (not `0`)
  — a real regression test, not a tautology; confirmed passing live.
- **The `AccessTenantFixture::boot()` re-entry wipe.** `eventbus-fixture.php`'s
  `eventbusEnterTenant()` deliberately calls only `AccessTenantFixture::enter()`, never
  `boot()`, with a docblock explaining why (`boot()` deletes and re-copies every tenant
  database on each call). This is a test-fixture-design fix, not product code; confirmed
  by reading the implementation and its docblock, and corroborated by every multi-tenant
  test in the package (e.g. `RelayOutboxCommandTest`'s tenant-a/tenant-b cases) passing
  without data loss between `enter()` calls.

## Test run summary

- **Commands:** `cd modules/host && composer check` (lint, PHPStan, deptrac, full Pest
  suite), run twice independently, plus targeted `vendor/bin/pest` runs for every mutation
  and every independent scratch-file verification above.
- **Run 1** (`composer check`, full): lint PASS, PHPStan 0 errors, deptrac 0
  violations / 726 allowed, tests **2278 passed / 12 failed / 19 skipped (16697
  assertions)**, test step 434.95s, `ratio=2.07`.
- **Run 2** (`composer check`, full, independent re-run from a clean tree): identical —
  lint PASS, PHPStan 0 errors, deptrac 0 violations / 726 allowed, tests **2278 passed /
  12 failed / 19 skipped (16697 assertions)**, test step 427.89s, `ratio=2.10`.
- **The 12 failures, enumerated in full from the untruncated log, both runs
  identical:** `QaModuleRegistryContractTest` (6), `QaModuleNameValidationTest` (3),
  `QaHealthProbeResilienceTest` (2), `ModulesEnvParsingTest` (1) — exactly FP-316's known,
  rotating-by-name, stable-by-count cluster (12 failures, this family of
  module-registry/bootstrap-adjacent files), per my own memory note from T-014's
  close-out. No failure outside this cluster in either run. **Not filed as a T-018
  defect.**
- **The skip count (19, up 8 from T-017's 11)** is exactly the 8 `redis`-row contract
  cases in `EventBusContractTest`, confirmed by running that file alone (8 skipped, 8
  passed) — matches Ruling 9's prediction and D-1's framing, not a gap.
- **`FLEXIPOS-GATE-LOW-HEADROOM`:** neither of my runs crossed the ratio<2.0 trigger
  (2.07 and 2.10); dev's own report cited `ratio=1.98` from their run. This is
  environmental run-to-run variance (machine load), not a regression — FP-328's
  already-known narrowing condition, unchanged by this task.
- **`composer.lock`** confirmed unchanged by the branch (`git diff --stat` against the
  pre-T-018 tip: empty).
- **Working tree** confirmed clean (`git status --porcelain`: empty) both before and
  after all QA activity — every scratch file and mutation was reverted/deleted.
- **Passed / failed / skipped:** 2278 / 12 (pre-existing FP-316, not T-018) / 19 (8
  expected new Redis skips + T-017's 11).

## Defects

None found. Zero `BUG-n` filed this round.

## Regression risks / notes

- **`TenantConnection` is now a third copy** (access, organization, eventbus) — flagged by
  dev/architect as accepted debt with a follow-up row proposed (risk 8 in the plan).
  Confirmed present, not a defect, not blocking.
- **Nothing runs `eventbus:relay`/`eventbus:consume` in production yet** (no supervisor
  config, no Redis infrastructure) — same pre-existing class as FP-319, out of scope for
  this task, already named in the plan's risk 5.
- **The AC-37 deptrac instrument stays manual** (see above) — judged acceptable, matches
  plan's explicit reasoning and T-017 precedent for when a boundary-check re-proof does
  and does not need a committed test.
- **Mutation 6's demonstration mechanism (SQLite file-lock vs. a clean "survives
  rollback") is worth remembering for future tasks using `AccessTenantFixture`'s SQLite
  backing:** a "writer opens its own transaction on a fresh connection" mutation is
  genuinely destructive to prove cleanly on SQLite; a MySQL/Redis environment would show
  the defect more directly (silent survival, not a lock error). Saved to agent memory.
- No new HTTP surface, no new tenant-schema migration, no new Composer dependency —
  confirmed by reading the diff and by the gate's own deptrac/PHPStan paths.

## Run 2: review round 1 fix verification

Reviewer found two Criticals (R-1, R-2) in round 1 of code review, both in
`RedisStreamsEventBus`; `dev` fixed both plus seven Warnings (W-1 to W-7) in
`94d895b`. This run independently re-verifies that fix commit rather than trusting
`dev`'s own report.

### Diff scope check

`git show --stat 94d895b`: 23 files, 1050 insertions / 57 deletions. Every file matches
a named R/W item: `RedisStreamsEventBus.php` (R-1, R-2), the new `PredisRawClient.php`
(R-1's PHPStan-only interface), `ConsumeEventsHandler.php`/`ConsumeEventsCommand.php`
(W-2, W-4, W-5), `RelayOutboxHandler.php`/`RelayOutboxCommand.php` (W-3, W-4, W-5), the
new test fixtures (`FakePhpRedisRawErrorClient`, `FakePredisRawErrorClient`,
`FakeRedisConnection`, `FakeXClaimRedisClient`, `FailingAcknowledgeEventBus`), the
updated test files for every R/W item, `QaEventbusSourceRulesTest.php` (W-6),
`OutboxTransactionTest.php` (W-7), and two `dev`-owned agent-memory files (not
production/test code, no concern). Nothing extraneous. Confirmed by reading every
diff, not just the stat line.

### R-1 — independently re-verified

Read the new fake fixtures: `FakePhpRedisRawErrorClient::rawCommand()` returns `false`
without throwing and carries the error text through `getLastError()`/`clearLastError()`
— genuinely mimics phpredis' real error-reply shape, not a pre-caught exception.
`FakePredisRawErrorClient::executeRaw()` returns the error text and sets its by-ref
`$error` — genuinely mimics predis' shape.

Mutation-tested myself, two ways:

1. **Reverted only `raw()`'s error-reply check** (restored the pre-fix body: plain
   `return $client->rawCommand(...$args);` / `return $client->executeRaw($args);`, no
   `assertStreamId` change). Result: the two low-level unit tests and the
   through-the-relay test still went red, but **not for the clean "wrong error
   message" reason** — `assertStreamId()` (the second, independent half of R-1's fix)
   caught the resulting malformed/empty id first, so the failure read "malformed stream
   id" rather than the real Redis error text. This is a legitimate defense-in-depth
   red, not a false pass, but it meant this partial revert alone did not reproduce the
   *original* review-round-1 symptom cleanly.
2. **Reverted the whole file to the pre-fix commit** (`raw()` and `assertStreamId`
   both gone — exactly the code reviewer read). Re-ran the through-the-relay test with
   a temporary debug line added to print the row: confirmed the **exact original
   defect** — `"status":"published"`, `"attempts":0`, `"last_error":null`. The row was
   marked published with no error recorded, on a Redis `READONLY` reply, which is
   precisely "the relay would mark the row published even though the event was never
   written" from the review finding. Removed the debug line before restoring. All four
   low-level unit tests and the through-the-relay test failed in this state.

Restored `RedisStreamsEventBus.php` via `git checkout --`, re-ran the full R-1/R-2 set:
green (`git status --porcelain` empty both before and after).

### R-2 — independently re-verified

Read `RedisStreamsEventBusClaimTest.php` and `FakeXClaimRedisClient`: the script gives
the group's oldest pending id the *larger* delivery count (7) and the newer, claimed id
the *smaller* one (3) — deliberately the opposite of what a silent fallback-to-1 or a
mixed-up-with-the-oldest-entry bug would produce by coincidence, so a pass is
discriminating, not accidental. `FakeXClaimRedisClient::rawCommand()` for `XPENDING`
keys its reply strictly off `$args[3]` (the specific id slot in `XPENDING stream group
<id> <id> 1`), so it can tell a per-id query from a group-wide one.

Mutation-tested: reverted `deliveryCounts()` to the pre-fix group-wide
`XPENDING stream group - + n` query (kept `claim()`'s strict `isset()` check as part of
the committed fix). Result: RED — the fake's `args[3]` for a group-wide call is the
literal `'-'`, which matches no key in `$deliveryCountsById`, so `XPENDING` returns `[]`
and `claim()` now throws `EventBusUnreachable` ("No delivery count resolved") instead of
returning delivery count 3. This proves the query shape changed and the test catches it,
though via the fix's own "fails loudly" guard rather than a silently-wrong-value
mismatch — the fake does not model real Redis's actual "return the oldest N entries"
semantics for an unmatched id, so a value-mismatch (returning 7 instead of 3) was not
separately reproduced here. Noted as a minor **Suggestion**, not a defect: the real
production fix (ask `XPENDING` per claimed id, never the group-wide range) is correct
and well covered by the positive test's discriminating 7-vs-3 scenario; only the
revert-to-prove-the-exact-wrong-value path depends on a fake that is not quite a full
Redis simulator.

Restored via `git checkout --`, re-ran: green (2 passed, 5 assertions).

### W-1, W-2, W-3, W-4, W-5 — spot-checked by reading and running

Read the diffs for `ConsumeEventsHandler.php` and `RelayOutboxHandler.php`: `W-2`
(`acknowledge()` moved outside the `try` around `handle()`), `W-3` (the relay's catch
boundary narrowed to wrap only build-and-publish, with the "mark published" block now
outside it), and `W-4` (`positiveInt()` throwing `LogicException`, used identically in
both handlers, matching `config/eventbus.php`'s header comment) match the review
description exactly, with no unrelated changes. `W-5`'s `--sleep` default now reads
`eventbus.consumer.poll_seconds` / `eventbus.relay.poll_seconds` when the option is
omitted — read and confirmed in both command files. `W-1`'s `EventBusContractTest`
change branches the minIdle case by driver, proving only the `minIdleMs=0` positive half
on `redis` and keeping the full 59s/60s `RunnerClock` boundary proof on `memory` — read
and matches.

Ran all twenty committed R/W-named tests plus `W-4`'s four-case dataset for each
handler, isolated (`vendor/bin/pest ../../packages/eventbus/tests --filter
"R-1|R-2|W-1|W-2|W-3|W-4|W-5|W-6|W-7"`): 20 passed, 2 skipped (the `redis` dataset rows,
expected under D-1). Confirmed `W-4` genuinely throws `LogicException` (not silently
clamped) for both the consumer and the relay handler, across all eight non-positive
config-value cases (four keys each) — all eight passed.

### W-6 — mutation-tested with a real planted file

Planted `packages/eventbus/src/QaScratchW6SecondInserter.php` (a real file in the real
scanned directory, not a test's own temp fixture) with a class constant
`TABLE = 'outbox_events'` and a method calling `$connection->table(self::TABLE)
->insert(...)` — the exact "second inserter written as `table(Foo::TABLE)->insert`"
shape the review flagged as out of reach for the original scan. Ran
`QaEventbusSourceRulesTest`:

- **AC-5's original scan (`qaEventbusInsertOffenders`) still did not see it** — passed
  green, confirming the stated, deliberately-unfixed limitation is real, not
  accidentally already fixed.
- **The new W-6 allow-list literal scan (`qaEventbusOutboxEventsLiteralFiles`) caught it
  by its real path** — failed with the planted file listed in the diff
  (`packages/eventbus/src/QaScratchW6SecondInserter.php`), exactly as the fix's own test
  predicts.

Deleted the scratch file; re-ran: 7 passed, green again; `git status --porcelain`
confirmed empty.

### W-7 — mutation-tested, reproducing the original trap

Applied mutation 6 from the original close-out (`OutboxWriter::record()` rewritten to
insert on a fresh, separately-resolved connection to the same SQLite file inside its own
`transaction()`) together with reverting `OutboxTransactionTest.php`'s catch back to
plain `RuntimeException` (the pre-W-7 shape). Result: **the exact trap recurred** — "it
rolls back the outbox row..." passed (66.15s) for the wrong reason (the SQLite
`SQLSTATE[HY000]: database is locked` `QueryException`, which extends `RuntimeException`,
was silently swallowed), while the separate, uncaught committed-twin control failed
loudly with the same lock error (64.88s). This reproduces the exact finding recorded in
this task's own prior mutation-6 note, confirming the trap was real before W-7.

Restored the test to the committed `ForcedRollback`-based catch, kept the `OutboxWriter`
mutation: both cases now **failed loudly** with the lock error (66.41s and 65.86s) — the
specific-exception catch no longer swallows an unrelated lock failure, which is exactly
W-7's fix. Restored `OutboxWriter.php` via `git checkout --`; re-ran: 2 passed, green
(0.56s — the fast path, since the pre-fix-identical mutation is gone).

### Full gate, independently re-run

`cd modules/host && composer check` from a confirmed-clean tree (`git status
--porcelain` empty before and after). Took considerably longer than dev's reported
462.10s under this session's machine load (confirmed via `Get-CimInstance Win32_Process`
that the gate's own `pest` process was the only one progressing, not stalled, and not
overlapping a second gate run) — real elapsed 444.20s for the test step alone, consistent
with the historical range.

- **Lint:** `{"tool":"pint","result":"passed"}`.
- **PHPStan:** `[OK] No errors`.
- **Deptrac:** `Violations 0`, `Skipped violations 0`.
- **Tests: 2300 passed / 12 failed / 20 skipped (16747 assertions)**, test step
  444.20s, `ratio=2.03` (above the 2.0 `FLEXIPOS-GATE-LOW-HEADROOM` trigger — no
  low-headroom warning).
- **The 12 failures, enumerated in full from the untruncated log:**
  `QaModuleRegistryContractTest` (6), `QaModuleNameValidationTest` (3),
  `QaHealthProbeResilienceTest` (2), `ModulesEnvParsingTest` (1) — exactly FP-316's
  known, rotating-by-name, stable-by-count cluster, identical to both of my Run 1
  numbers and to `dev`'s own report this round. No failure outside this cluster.
  **Not a T-018 defect.**
- **The 20 skips**, independently broken down by re-running `EventBusContractTest`
  alone (9 skipped, 9 passed, confirmed directly): 9 `redis`-row contract-dataset
  skips (up from 8 in Run 1, exactly the one new R-2 dataset case) + 11 pre-existing
  engine-group skips (T-017's count, unchanged). Matches `dev`'s claimed breakdown
  exactly.
- `dev`'s own numbers this round (2300/12/20, 16747 assertions) are confirmed
  identical.

### Verdict

**PASS.** Both Criticals (R-1, R-2) are correctly fixed and independently
mutation-confirmed, including reproducing the exact original failure shapes (a
silently-published row with no error for R-1; a swallowed lock exception masking a
broken transaction guarantee for W-7, read together with R-2's discriminating
7-vs-3 proof). All seven Warnings (W-1 to W-7) are implemented as described, with
W-6 and W-7 independently mutation-tested per the task's instruction and W-1 through
W-5 spot-checked by reading the diff and running the committed tests. No defect filed
this round. One non-blocking **Suggestion**: `FakeXClaimRedisClient`'s R-2 mutation
test proves the query-shape change via a "fails loudly" guard rather than a
silently-wrong-value mismatch, because the fake does not model real Redis's exact
group-wide `XPENDING` reply semantics for an unmatched id — not a gap in the shipped
fix, only in how cleanly a future revert-and-prove exercise can reproduce the *original*
symptom's value (as opposed to its failure).

## Run history

Run 1: PASS (first and only QA cycle — no defects found, no `dev` round-trip needed).
Run 2: PASS (review round 1 fix verification — R-1, R-2, W-1 to W-7 all independently
confirmed; 2300/12/20 gate numbers re-measured and matched; no defects filed).
