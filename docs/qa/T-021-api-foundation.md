# QA Report: T-021 API foundation

## Verdict: PASS (Run 2 — review round 1 fix verification)

## Summary

Read spec (AC-1 to AC-54, AC-33a/33b, AC-40a), decisions D-1 to D-22, plan (15 steps, rulings 1-9,
sections 6/9), and `git show --stat eb27e43` (84 files). Reviewed production code
(`packages/kernel/src/Api/*`, `modules/host/app/{Http,Support,Console}/*`, `bootstrap/app.php`,
`config/api.php`, the tenant migration) and every test file the plan's test-strategy table names, line
by line against the AC it claims to cover. Ran the full gate (`composer check`) from `modules/host`
myself. Mutation-tested AC-8 directly against the real renderer. Independently re-derived and confirmed
all four "regression found and fixed during implementation" claims from the guard tests they were
violating, not from dev's narrative alone.

## Acceptance criteria coverage

All of AC-1 to AC-54 (plus AC-33a, AC-33b, AC-40a) map to a passing, genuinely-exercising test, per the
plan's section 6 table. Spot checks below; the remainder were read directly in the listed test files and
match the spec clause for clause.

| AC group | Test(s) | Result |
|---|---|---|
| A. Envelope (AC-1 to AC-8) | `ApiResponseTest`, `ApiFailureRenderingTest` | PASS — AC-8 mutation-tested directly (see below) |
| B. Catalogue (AC-9 to AC-15, AC-40a) | `ErrorCatalogueTest`, `KernelErrorCodes` read directly | PASS — all 14 codes present with correct statuses |
| C. Idempotency (AC-16 to AC-27) | `IdempotencyStoreTest`, `IdempotencyHttpTest`, `IdempotencyKeysSchemaTest`, `PruneIdempotencyKeysTest` | PASS — AC-20/AC-25 read in full, genuinely exercise cross-actor and cross-tenant non-disclosure |
| D. Rate limits (AC-28 to AC-33b) | `ApiRateLimitTest` | PASS — AC-33a/33b use real throwing/counting fake stores with `Log::shouldReceive('warning')->once()` |
| E. OpenAPI (AC-34 to AC-40) | `OpenApiDocumentTest`, `OpenApiCompletenessTest` | PASS — AC-37's own control plants a scratch route and asserts it is reported missing |
| F. Health (AC-41 to AC-47) | `HealthReadinessTest`, six edited exact-JSON files | PASS — AC-44 bound fake proves the failure path; AC-46 binds throwing `TenantFleet`/`Tenancy` |
| G. Non-regression (AC-48 to AC-54) | AC-48 regression set (step 4), `PingRouteTest`, `QaActivationCodeSecrecyTest`, `TerminalActivationHttpTest` | PASS — all ran green in the full gate; none of their bodies or statuses changed |

## AC-8 mutation test (performed directly, not just read)

Edited `modules/host/app/Http/Responses/ApiFailureRenderer.php`'s final branch to
`ApiResponse::failure(KernelErrorCodes::SERVER_ERROR, $e->getMessage())` (leaking the exception message
into `detail`). Ran `ApiFailureRenderingTest.php --filter=AC-8`: both datasets (`debug on`, `debug off`)
went red with `Expecting '...' not to contain 'SELECT secret'`. Reverted the one-line change; re-ran the
same filter: 2 warnings (the known `.env` warning), 12 assertions, green. `git status` confirmed clean
before and after. The AC-8 test genuinely discriminates.

## The four "regressions found and fixed" — independently verified sound

1. **`MasterDatabaseCheck` building its own connection via `ConnectionFactory::make()`, never writing
   `database.connections.master`.** Found the actual guard: `QaTenantIsolationProofTest::'holds exactly
   one production write to the tenant connection configuration'`
   (`packages/kernel/tests/Feature/QaTenantIsolationProofTest.php:483`), which scans every non-test
   `.php`/`.stub` file under `packages/` and `modules/` for `->set('database.connections.` or
   `config(['database.connections.` and asserts exactly one writer
   (`packages/kernel/src/Tenancy/Tenancy.php`). `MasterDatabaseCheck.php` reads
   `config('database.connections.master')` (a read, not a write) and never calls `->set(` or `config([`
   on that key — it genuinely does not trip the guard, not by coincidence.
2. **`RedisCheck` using the raw `\Redis` extension class through a variable, never a literal
   Laravel-Redis reference.** Found the actual guard: `QaEventbusSourceRulesTest::'AC-2: outside
   packages/eventbus/src, no file references Redis directly'`
   (`modules/host/tests/Feature/QaEventbusSourceRulesTest.php:378`), which flags
   `Facades\Redis`, `Redis::`, `Illuminate\Redis`, `Contracts\Redis`, the Streams command verbs, or the
   configured stream names. `RedisCheck.php` assigns the string `'Redis'` to a variable and calls
   `new $extensionClass`/`$client->ping()` — none of those literals appear in the file; it genuinely
   passes the scan, not by luck.
3. **`IdempotencyKeysSchemaTest.php` placed in `modules/host/tests/Feature/`, not
   `packages/kernel/tests`.** Found the actual guard:
   `QaFixtureBaselineSweepsTest::'finds one reader of the shipped tree in the kernel tests, through one
   entry point (AC-8)'` (`packages/kernel/tests/Schema/Features/PlanMigrationRun/QaFixtureBaselineSweepsTest.php:260`),
   which asserts `fixtureSweepUnexpectedReaders($sources)` is empty for the kernel's own test tree — only
   `Support/migration-run-fixture.php` may compose the shipped tenant tree there. The test file reads the
   shipped tree path (`schema.tenant_migration_path`) directly, so it correctly cannot live in
   `packages/kernel/tests`; its new home matches the precedent of `FixtureMigrationSetsTest` and
   `TenantBaselineBuildsTest`, which are also in `modules/host/tests`.
4. **`$exceptions->context()` wrapping `app(RequestId::class)->current()` in try/catch.** Read directly
   in `bootstrap/app.php` lines 154-159: the comment and code match the claim exactly — a context
   callback that would otherwise mask a provider-registration-time exception's own message with a
   binding-resolution failure if `RequestId` is not yet bound.

All four check out as genuine, mechanism-verified fixes, not narrative-only claims.

## Security-sensitive ACs — direct verification

- **AC-8** (no exception leak): mutation-tested above, confirmed discriminating.
- **AC-20/AC-25** (idempotency cross-actor/cross-tenant non-disclosure): read `IdempotencyHttpTest.php`
  in full. AC-20 has four separate cases (different body, path, method-via-second-route, actor-via-a-
  second-owner-token-of-the-same-tenant), each asserting the second response is 422
  `kernel.idempotency_key_reused` and `not->toContain($firstBodyMarker)`. AC-25 uses two genuinely
  separate tenant fixture databases (`orgHttpWorld('j')` plus a hand-built `idemHttpWorldB()`, explicitly
  avoiding `orgHttpWorld()`'s single-tenant-a mapping), asserts one row per tenant database and 2 total
  handler calls. `IdempotencyStore::resolveExisting()` checks `hash_equals($row->request_hash, $hash)`
  before ever reading `response_code`/`response_body` into a reply — a reused key never reaches the
  replay branch.
- **AC-33b/AC-44** ([REDIS]-tagged fail paths): `ApiRateLimitTest`'s AC-33b binds a real
  `QaThrowingStore` behind `Illuminate\Cache\RateLimiter` and asserts exactly one `Log::warning` call, a
  200, and no rate headers — a genuine exception path, not a flag. `HealthReadinessTest`'s AC-44 binds a
  `QaFixedReadinessCheck(status: Failed)` in place of the real `RedisCheck` and asserts 503 with
  `checks.redis === 'failed'`; the default (no redis configured) path separately asserts `skipped`/200.
  Both are deterministic proofs of the documented failure branch, not just a happy-path read.
- **AC-51** (404 never echoes the path): read `QaActivationCodeSecrecyTest.php` lines 97-114 directly.
  A POST to `/api/v1/terminal/activate/<url-encoded live activation code>` (a 404, no such route) asserts
  `errors.0.code === 'kernel.not_found'` and `(string) $viaPath->getContent())->not->toContain($live)`
  with `app.debug` both forced false. This is the actual activation-code secrecy test, strengthened per
  D-19, not a generic 404 test.

## Non-regression (AC-48/AC-49) and the drift gate (AC-37)

The AC-48 regression set named in plan step 4 (`HttpAuthEndpointsTest`,
`AuthCredentialsAreNeverReadFromTheUrlTest`, `TenantTokenMiddlewareTest`,
`TenantSchemaBehindRenderingTest`, `QaTenantSelectionClaimsTest`, `PlanLimitHttpTest`,
`OrganizationBranchHttpTest`, `OrganizationTerminalHttpTest`, `TerminalRevocationHttpTest`,
`TerminalActivationHttpTest`, `QaSuspendedMappingGateTest`, `QaAuthHttpSurfaceTest`,
`UnlistedModuleReturns404Test`, `QaAccessHttpSurfaceTest`) all ran green in the full gate run I executed
— none reported as failed or in the known-10 set. `OpenApiCompletenessTest` carries its own self-proving
control (`'reports a scratch route planted by the test as missing from the document (control)'`), which
plants `GET /api/v1/qa-drift/planted` and asserts it is reported as missing from the committed document.
Since this control already runs inside `composer check` on every gate run and genuinely discriminates
(it is a live route that is provably absent from the committed JSON), a separate manual add/remove cycle
by me would prove nothing the suite doesn't already prove on every run; I verified the control's logic
by reading it rather than re-running it in isolation, since it ran green inside the full gate I executed.

## Test run summary (measured directly, this session)

- Command: `cd modules/host && "E:\xampp\php\php.exe" "C:\ProgramData\ComposerSetup\bin\composer.phar" check`
- Working tree: clean before and after (`git status --porcelain -uall`), no concurrent agent in this
  worktree.
- `pint --test`: passed.
- `phpstan analyse`: `[OK] No errors`.
- `deptrac analyse`: Violations 0, Skipped violations 0, Uncovered 829, Allowed 1088, Warnings 0, Errors 0.
- `vendor/bin/pest` (full suite): **10 failed, 2573 warnings, 164 passed (18540 assertions)**, duration
  745.97s. Gate margin line: `ratio=10.45` (elapsed 746.40s against a 7800s limit) — ample headroom.
- These numbers match dev's own report **exactly** (164/10/2573/18540).
- The 10 failures, named individually in the log, are exactly: `HostTestSuiteCollectionTest`,
  `ModuleMakeAtomicGenerationTest`, `QaGeneratedModuleTestDiscoveryTest`,
  `QaHealthProbeResilienceTest` (`MissingAppKeyException`), `QaHostSuiteCollectionProofTest` (×2),
  `QaModuleConnectionRemovalTest`, `QaRegenerationGuardTest` (×2), `QaTestSuiteIsolationTest` — nine
  nested child-process pest runs plus the one documented `MissingAppKeyException` session guard. All
  ten are pre-existing, unrelated to this task's surface (none touch `Api\`, idempotency, rate-limit or
  health-check logic), environment-caused (no real `.env`/`APP_KEY` in this worktree, confirmed by this
  harness's own permission system blocking creation of one), and match the task briefing's documented
  gap exactly in count and shape. `QaTestSuiteIsolationTest`'s own docblock independently states
  "Currently RED" with a reference to a pre-existing bug (T-001 BUG-4) — confirming this is not new.
  Confirmed the FP-316 rotating cluster (`QaModuleRegistryContractTest`, `QaModuleNameValidationTest`,
  `ModulesEnvParsingTest`) did **not** fire in this run (checked by name — no match in the log).
  No failure outside the known 10 and outside the FP-316 cluster was found.

## Defects

None. No `BUG-n` filed.

## Regression risks / notes

- The idempotency atomicity limitation (plan risk 3, D-7) is stated, not hidden, and no production route
  opts in yet (confirmed — no `->middleware(RequireIdempotencyKey::class)` outside test-only routes).
- AC-22's real-concurrency half and the [REDIS]/real-MySQL-timeout halves of AC-33b/AC-44/AC-43 remain
  explicitly NOT VERIFIED, as the plan states; this is consistent with every prior task in this series
  (T-018/T-019/T-020) and not a gap specific to this review.
- The gate's own 10.45 margin ratio leaves ample headroom; no action needed.

## Run 2: review round 1 fix verification (R-1, S-1, S-2, S-9)

Verified commit `71765c6` ("T-021: review round 1 fix (R-1, S-1, S-2, S-9)") against reviewer's round 1
CHANGES REQUESTED (R-1 critical) and the three filed Suggestions, independently of dev's narrative.

### Diff read against the claim

Read `git show 71765c6` in full (9 files, 137+/11-). Confirmed the diff is exactly what R-1/S-1/S-2/S-9
called for:
- **R-1**: `IdempotencyStore::resolveExisting()`'s stale-claim takeover `UPDATE` now adds
  `->where('created_at', $row->created_at)`, captures the affected-row count in `$taken`, and returns
  `Claimed` only when `$taken === 1`, else `InProgress`. A new test races a second `UPDATE` ahead of the
  takeover via `beforeExecuting()`.
- **S-1**: `ApiResponse::failures([])` now throws `InvalidArgumentException` before touching `$errors[0]`.
- **S-2**: `IdempotencyKey::PATTERN`'s trailing `$` became `\z`, closing the PCRE trailing-newline
  bypass.
- **S-9**: both `json_decode(...)` call sites in the idempotency replay round trip
  (`RequireIdempotencyKey::runAndStore()` and `IdempotencyStore::resolveExisting()`'s replay return) drop
  the `true` (assoc) flag. `RequireIdempotencyKey::replay()`'s `meta.request_id` patch gained an
  `is_object()` branch alongside the existing `is_array()` one. One pre-existing assertion
  (`IdempotencyStoreTest`) changed from `toBe(['ok' => true])` to `toEqual((object) ['ok' => true])`.

### Independent mutation testing (all four, round-tripped: red on revert, green on restore)

For each fix, I reverted exactly the line(s) the review called out, ran the new/changed test, confirmed
red, restored, confirmed green, and confirmed `git status --short` was clean before and after each
round trip (no leftover diff, no accidental drift into other files):

1. **R-1**: reverted the CAS `->where('created_at', ...)` clause and the `$taken` check back to an
   unconditional `UPDATE` + unconditional `Claimed`. The new race test
   (`'loses the stale-claim takeover race to a competing request...'`) went red exactly as the bug
   predicts: `Expected: Enum #... (InProgress)` / `Actual: Enum #... (Claimed)` — a second claimant wins
   the race and gets `Claimed` back, meaning it would also run the handler. Restored; test green again
   (same `.env`-warning shape as every other test in this worktree).
2. **S-1**: commented out the `if ($errors === [])` guard. `ApiResponseTest`'s new S-1 case went red:
   `Failed asserting that an instance of class ErrorException is an instance of class
   InvalidArgumentException` — reproducing the exact undefined-index symptom the suggestion names.
   Restored; green.
3. **S-2**: reverted `\z` to `$`. `IdempotencyKeysTest`'s new S-2 case
   (`isValid("abc\n")` expected `false`) went red: `Failed asserting that true is false` — confirming the
   trailing-newline bypass is real and the test discriminates it. Restored; green.
4. **S-9**, both round-trip points, independently:
   - Reverted only the storage-side `json_decode(..., true)` in `runAndStore()` (kept the replay side
     fixed). The HTTP-level S-9 test went red: stored/replayed body became `{"extra":[]}` instead of
     `{"extra":{}}`. Restored; green.
   - Reverted only the replay-side `json_decode($row->response_body, true)` in `resolveExisting()` (kept
     the storage side fixed). Both the HTTP-level test (same `[]` vs `{}` mismatch) **and** the
     kernel-level `IdempotencyStoreTest` S-9 case went red, the latter with `ErrorException: Attempt to
     read property "data" on array` (the test's own `$replay->responseBody->data` access, proving the
     test genuinely needs the object shape, not just a loose equality check). Restored; green.
   - This confirms dev's claim that **both** round-trip points needed the fix, not just one — a
     one-sided fix is caught by the existing tests.

### S-9 edge case: does the `is_object()`/`is_array()` branch break a top-level JSON array body?

The concern: `RequireIdempotencyKey::replay()`'s rewritten `meta.request_id` patch branches on
`is_object($body)` first, `is_array($body)` second. Laravel's envelope (`ApiResponse::success()`/
`failures()`) is always a top-level JSON **object** (`{success, data, message, errors, meta}`), so the
committed tests only exercise the object branch. I wrote a temporary scratch test
(`modules/host/tests/Feature/QaScratchIdemArrayTest.php`, not committed, deleted after use) registering a
route that returns a bare top-level JSON array (`response()->json([1, 2, 3], 201)`) behind the
idempotency middleware, and replayed it. Result: `first` and `replay` both serialize as `[1,2,3]`,
`Idempotent-Replayed: true` on the second — the array branch (`is_array($body) && array_key_exists('meta', ...)`,
which correctly finds no `meta` key on a plain list and skips the patch) passes the body through
untouched and `response()->json($body, $status)` re-encodes a PHP array as a JSON array exactly as
before. The fix does **not** regress the non-envelope-array case. Test file deleted after verification;
`git status --short` confirmed clean.

### Full gate — a genuine concurrency trap, then a clean solo run

First attempt (`composer check`, backgrounded) landed on top of a **reviewer-owned `composer check`
process already running in this same worktree** (`PID 78316`, `composer.phar check`, started while mine
was in flight — confirmed via `Get-CimInstance Win32_Process`). The two runs shared the same SQLite
tenant fixture files under `modules/host/storage/app/tenancy-fixture/`, and the result was exactly the
corruption signature that shared-file concurrency produces: 89 failed, `database disk image is
malformed`, `table marker already exists`, `Resource temporarily unavailable` on `unlink()`, and a
`TenantConnectionFailed` complaining a fixture file "does not exist" moments after another test created
it. None of this is a real product defect — it is the classic two-gate-runs-one-worktree trap. I waited
for the reviewer's process to exit (confirmed via `Get-Process -Id 78316` polling in a background task),
deleted the leftover/corrupted `tenant-a.sqlite`/`tenant-b.sqlite` fixture files, confirmed no other
FlexiPOS-related `php.exe` process was running, and re-ran the gate alone.

**Clean solo run** (`composer check`, 1041.83s elapsed, `FLEXIPOS-GATE-MARGIN ratio=7.49`):
- `pint --test`: `{"tool":"pint","result":"passed"}`.
- `phpstan analyse`: `[OK] No errors`.
- `deptrac analyse`: Violations 0, Skipped violations 0, Uncovered 829, Allowed 1089, Warnings 0, Errors 0.
- `vendor/bin/pest` (full suite): **10 failed, 2578 warnings, 164 passed (18551 assertions)**.
- The 10 failures are, by name, **exactly** the Run 1 baseline: `HostTestSuiteCollectionTest`,
  `ModuleMakeAtomicGenerationTest`, `QaGeneratedModuleTestDiscoveryTest`, `QaHealthProbeResilienceTest`
  (`MissingAppKeyException`), `QaHostSuiteCollectionProofTest` (×2), `QaModuleConnectionRemovalTest`,
  `QaRegenerationGuardTest` (×2), `QaTestSuiteIsolationTest`. No new failure, no missing failure.
- **Dev's reported "11th failure" (a plugin-sdk `Encrypter` test needing a well-formed `APP_KEY`) did
  NOT reproduce in my clean, no-APP_KEY-export, solo run.** This confirms dev's own attribution: it was
  an artifact of dev's own throwaway `APP_KEY` export used to get real numbers, not a real gap in this
  worktree or a regression from the fix commit. My count (164 passed / 10 failed) is the correct
  baseline figure, matching Run 1 exactly.
- `git status --short`: clean before and after (the deleted leftover `.sqlite` fixture files are
  gitignored test artifacts, not tracked).

### Verdict

All four review items (R-1 critical, S-1/S-2/S-9 suggestions) are genuinely fixed, each independently
mutation-tested red-then-green against the exact defect the review described. The S-9 object/array
branch does not regress the non-envelope top-level-array case. The full gate is clean: lint, stan,
deptrac all pass with zero issues; the test suite reproduces exactly the pre-existing, documented,
`.env`-caused 10-failure baseline with no new failures and no regressions. PASS stands.

## History

- Run 1: PASS (this report). Full gate matched dev's reported numbers exactly; all four regression
  claims independently verified against their actual guard tests; AC-8 mutation-tested directly; no
  defects found.
- Run 2: PASS. Review round 1 fix (R-1 critical + S-1/S-2/S-9) verified by independent mutation testing,
  all four red-then-green; S-9's is_object/is_array branch confirmed safe for a top-level-array edge
  case; full gate re-run solo after an accidental overlap with reviewer's concurrent `composer check`
  corrupted a first attempt (not a product defect — a shared-SQLite-fixture concurrency artifact,
  resolved by waiting and re-running alone); clean solo run reproduces the exact 164-passed/10-failed
  baseline, confirming dev's reported "11th failure" was their own `APP_KEY`-export artifact, not a
  regression.
