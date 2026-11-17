# QA Report: T-033 (Journal entries, FP-261)

Branch `ai/T-033-journal-entries` at 6734ccf, plus one new untracked QA test file. No `.env` in the worktree (harness rule), so every test carries one `file_get_contents(.env)` PHP warning; Pest reports those as "warnings", not "passed".

## Verdict: FAIL (one minor process defect; no defect in T-033 code)

The one NEW gate failure is a process defect: the uncommitted developer memory file `.claude/agent-memory/developer/project_t033_chunk_e.md` quotes the retired vocabulary, which turns `QaVocabularyRenameSweepTest` red (BUG-1). Under the one-failing-test rule that is FAIL. Everything in the product passes. The lead can turn it to PASS by rewording that line (describe, do not quote) and re-running that one test.

## Test run summary

Commands (from `modules/host`, throwaway `APP_KEY`, stdin from `/dev/null`, one tool at a time):

| Step | Command | Result |
|---|---|---|
| Lint | `vendor/bin/pint --test .. ../../packages` | passed (`lint rc=0`). Re-run after adding my QA file: it needed 2 Pint fixers (ordered imports, strict types FQN); fixed in the test file; passed again |
| Static analysis | `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | `[OK] No errors`, before and after my QA file (result cache cleared after pest) |
| Boundaries | `vendor/bin/deptrac analyse --config-file=deptrac.yaml ...` | Violations 0, Skipped 0, Errors 0, Warnings 0, Allowed 2397, Uncovered 1384; `dep rc=0` |
| Full suite | `php artisan config:clear` then `php tools/gate-step.php test -- vendor/bin/pest` (the `composer test` script body) | **10 failed, 5210 warnings, 1208 passed (39670 assertions), Duration 3413.43s** |

Symbol count in the output: 1208 passed, 5225 warned (every one is the missing-`.env` warning; the engine tests are in this group), 10 failed. That is 6443 tests against T-032's 5939 (5864 passed + 63 skipped + 12 failed), so about 504 new tests. The 63 skipped are not shown in this summary line, so I cannot reproduce T-032's "63 skipped" figure exactly. The T-032 record has no test-seconds figure to compare with. The 3413 s here is the whole gate step on this machine with the per-test warning overhead. T-032's 5864 "passed" cannot be compared with 1208 "passed", because T-032's gate ran with a `.env` and nothing was classed as a warning.

### The 10 failures, classified

| # | Test | Class | Cause |
|---|---|---|---|
| 1 | `HostTestSuiteCollectionTest` it collects a host test directory that is neither Unit nor Feature | env-only | child `pest` prints `Tests: 1 warning`, test expects `1 passed` |
| 2 | `ModuleMakeAtomicGenerationTest` it removes the partial package when generation fails part way through... | env-only | child run prints warnings, expects `N passed` |
| 3 | `QaGeneratedModuleTestDiscoveryTest` it runs a freshly generated module own test with no further edits | env-only | same |
| 4 | `QaHostSuiteCollectionProofTest` it collects a new host test directory only because the Host suite is recursive | env-only | same |
| 5 | `QaHostSuiteCollectionProofTest` it keeps collecting the two original host directories under the single suite | env-only | same |
| 6 | `QaModuleConnectionRemovalTest` it runs every test a freshly generated module ships, with no further edits | env-only | same |
| 7 | `QaRegenerationGuardTest` it survives a modules/demo working tree checked out with CRLF line endings | env-only | child output (same warning); not a T-033 file |
| 8 | `QaRegenerationGuardTest` it turns red and names the file when a stub drifts from the committed demo package | env-only | same |
| 9 | `QaTestSuiteIsolationTest` it ignores an ambient MODULES environment variable | env-only | same |
| 10 | `QaVocabularyRenameSweepTest` it has no file whose name or contents carries the old vocabulary | baseline (2 hits) + **NEW (2 hits)** | see BUG-1 |

Findings of #10: `.claude/agent-memory/developer/project_t026_create_owner_verify_activate.md:43` and `frontend/package-lock.json:2371` are the two known baseline hits. `.claude/agent-memory/developer/project_t033_chunk_e.md:12` (two hits: former support namespace segment, former top-level directory) is NEW and comes from T-033's own memory file.

Why 1-9 are called env-only: each one launches a child Pest process and asserts `Tests: N passed`; the child prints `Tests: N warnings` because of the missing `.env`. I did not re-run them with a `.env` (not allowed). No T-033 file appears in any of them, and no stack trace points at journal code. That attribution rests on the output and is not proven by a green re-run, so it is a judgement. 10 failures is lower than T-032's 12; I do not have T-032's 12 names to line them up exactly.

Environment noise also seen: `unlink(...tenancy-fixture/tenant-a.sqlite): Resource temporarily unavailable` warnings from `tenancy-fixture.php` at teardown (the known Windows SQLite flake, not a failure).

QA-file runs (targeted):
- `packages/finance/tests/Qa/QaJournalPostingTest.php`: 8 tests, 44 assertions, all pass (reported as 8 warnings = the `.env` warning), 22 s.
- `packages/finance/tests/Journal` plus that file, used as the mutation suite: about 322 tests, 202 to 203 passed, 119 to 120 warnings, 0 failed unmutated.

## Acceptance criteria coverage

Evidence: every AC id is named in at least one test file (grep over `packages/*/tests` and `modules/host/tests`), and the full suite shows no journal test failing. Proof files, per the plan's table: B1/B2 `JournalContractsTest`, `PostingInputTest`, `ReversalInputTest`, `AmountsTest`, `FingerprintTest`; C `PostingServicePostTest`, `...IdempotencyTest`, `...GateAndAccountsTest`, `...ReferencesTest`, `...ReverseTest`; D `JournalPortsTest`, `JournalAuthorizationMatrixTest` and the four slice tests under `Features/`; A `JournalTenantSchemaTest`, `JournalWidthsTest`, host `JournalMigrationsTest`; E `QaJournalSourceRulesTest`, `QaFinanceSourceRulesTest`, `JournalSchemaEngineTest`.

| AC | Test(s) | Result |
|---|---|---|
| 1, 4, 16a | `JournalContractsTest` | PASS |
| 2, 3 | `PostingServicePostTest` (LogicException, rollback with the document) | PASS |
| 5, 47, 48 | `QaJournalSourceRulesTest` (planted-violation control); my probe for the flip in AC-48 | PASS |
| 6, 6a, 7 to 16 | `PostingInputTest`, `ReversalInputTest`, `PostingServicePostTest` (AC-16), `PostingServiceReverseTest` (AC-6a); my probe for id case folding (AC-7) | PASS |
| 15a | `PostingServiceReferencesTest` | PASS |
| 17 to 22 | `AmountsTest`, `PostingInputTest`, `PostingServicePostTest`; my probe at the 14-digit boundary (AC-18, AC-21, AC-22) | PASS |
| 23, 24, 27 to 29, 31 | `PostingServiceGateAndAccountsTest`, `PostingServicePostTest` | PASS |
| 25, 26, 30 | stand-in order and seam parts in `PostingServiceGateAndAccountsTest`; two-connection proofs in `JournalSchemaEngineTest` | stand-in PASS, engine NOT VERIFIED |
| 32, 33, 33b | `PostingServicePostTest`, `JournalTenantSchemaTest`; my probes for gap-free numbering through refusal/replay/rollback (AC-33) and a recreated sequence row rolling back with its entry (AC-33b, a clause no existing test pinned) | PASS |
| 33a | unique `number` in `JournalTenantSchemaTest`; concurrency in engine test | schema PASS, concurrency NOT VERIFIED |
| 34 to 39, 41 | `PostingServiceIdempotencyTest`, `FingerprintTest`, `JournalTenantSchemaTest` | PASS |
| 40 | stand-in seam (competing row between lookup and insert) in `PostingServiceIdempotencyTest`; real race in engine test | seam PASS, race NOT VERIFIED |
| 42 to 44, 46 | `PostingServicePostTest`, `JournalTenantSchemaTest`, `JournalWidthsTest`, `TenantDecimalPrecisionTest` | PASS |
| 45 | SQL text of the CHECKs in `JournalTenantSchemaTest`; enforcement only in the engine test | text PASS, enforcement NOT VERIFIED |
| 49 to 56, 58 | `PostingServiceReverseTest`; my probes for per-account net zero across a repeating account with dimensions, reversal of a reversal, soft-closed reversal for a null actor | PASS |
| 57 | stand-in seams in `PostingServiceReverseTest` (status flipped under the lock, lost key insert, CAS returning 0); my direct probe of the compare-and-set predicate; engine race | seams PASS, race NOT VERIFIED |
| 59 to 64, 79 | `JournalPortsTest`, `JournalTenantSchemaTest` | PASS (AC-64 query-plan assertion on the stand-in; engine plan NOT VERIFIED) |
| 65 to 67 | `PostingServicePostTest`, `PostingServiceReverseTest` | PASS |
| 68 to 73, 73a, 74 | `Features/*` tests, `JournalAuthorizationMatrixTest`, `PostingServicePostTest` | PASS |
| 75 to 78, 81 | `JournalMigrationsTest`, `JournalTenantSchemaTest`, `QaFinanceSourceRulesTest`, `MigrationTreeDisciplineTest` and the catalogue tests; all green in the full run | PASS |
| 80 | `JournalWidthsTest`; engine collation in the engine test | stand-in PASS, engine NOT VERIFIED |
| 82 | `JournalSchemaEngineTest` present, tagged `[ENGINE]`, not run | PASS as a written-and-held set; every engine clause NOT VERIFIED |

## Tests I added (new file only, no existing test or production file edited)

`packages/finance/tests/Qa/QaJournalPostingTest.php`, 8 tests, Pint and PHPStan clean:
1. numbering 1..N gap-free through a refusal, a replay and a rolled-back caller (AC-33)
2. a deleted sequence row recreated inside a rolled-back post leaves no row and no entry, then recreates as `JE-`, padding 6 (AC-33b)
3. reversal nets to exactly zero per account over a 6-line entry that repeats an account and carries two dimensions; lines mirror 1:1 (AC-49, AC-50)
4. the largest legal total `99999999999999.9999` posts; a 15-digit integer is `invalid_amount`; an over-range sum is `total_out_of_range`; a 0.0001 imbalance is `entry_unbalanced` (AC-18, AC-20, AC-21)
5. a null-actor reversal in a soft-closed period is refused and the original stays posted (AC-54, AC-74)
6. lower-case `source_id` and `entry_id` fold to upper case on post, replay and reverse (AC-7, AC-52)
7. a reversal cannot be reversed, and a second reversal of the original is `already_reversed` (AC-52)
8. a second `markReversed` on an already-reversed row changes 0 rows and keeps the first `reversed_by_id` (AC-48, AC-57); written because mutation Q6 below survived

## Mutation table (red-first; each restored with `cmp`, byte for byte; `git status` clean for all src)

Suite for each run: `packages/finance/tests/Journal` plus `Qa/QaJournalPostingTest.php`. Unmutated baseline: 0 failed.

| ID | Mutation | File | Result |
|---|---|---|---|
| Q1 | balance check `! isEqualTo` becomes `isGreaterThan` (only debit-heavy entries refused) | `Journal/Amounts.php` | killed, 3 failed |
| Q2 | replay fingerprint comparison replaced by `false` (conflict never raised) | `DatabasePostingService.php` | killed, about 19 failed |
| Q3 | replay lookup ignores `source_purpose` (uses the no-purpose key) | `DatabasePostingService.php` | killed, 1 failed |
| Q4 | account lock order: remove `ksort` | `DatabasePostingService.php` | killed, 2 failed |
| Q5 | period gate called before the replay lookup | `DatabasePostingService.php` | killed, 3 failed |
| Q7 | reversal: period gate called before the reversal replay lookup | `DatabasePostingService.php` | killed, 1 failed |
| Q6 | reversal compare-and-set: remove `where status = posted` from `markReversed` | `Journal/JournalWriter.php` | **SURVIVED** the 202 existing tests (0 failed). Existing CAS tests only stub the return value; the predicate was unpinned. Killed after my test 8 (Q6b: 1 failed, restored, then 8 of 8 green) |

Q6 survives on the stand-in and is partly redundant in production (the original is `FOR UPDATE` locked and re-read as posted just before), so I rate it a gap in pinning, not a defect. The restoration of every mutated file was confirmed with `cmp`, and `git status --short` shows only my one new test file and the memory files.

## Defects

### BUG-1 (minor, process; not a product defect)
- Steps: run the full suite. `QaVocabularyRenameSweepTest` > "it has no file whose name or contents carries the old vocabulary".
- Expected: only the two known baseline hits (the T-026 memory file and `frontend/package-lock.json`).
- Actual: 4 findings; two are new and sit in `.claude/agent-memory/developer/project_t033_chunk_e.md` line 12 ("the former support namespace segment", "the former top-level directory"). The file is untracked and uncommitted, and the gate sweeps the working tree.
- Suspected location: the developer's memory note; reword it to describe the old names without quoting them. Not mine to edit (outside test files). Expect the test to return to the 2 baseline hits.

## NOT VERIFIED (ENGINE_HOLD, no real MySQL or MariaDB)

- AC-25 two-connection lock-order proof; AC-26 concurrent deactivate/header change; AC-30 gate versus close wait
- AC-33a concurrent numbers; AC-40 concurrent same-key post; AC-57 concurrent reversal
- AC-45 CHECK enforcement (direct insert violating each CHECK rejected); only the SQL text is proved
- AC-64 engine query plan and the 10-line transaction time
- AC-80 `CHAR(26)` collation and `DECIMAL(18,4)` on a live engine; AC-39 one-row-per-key under a real unique index on the engine
- All of `modules/host/tests/Engine/JournalSchemaEngineTest.php` (each case is written and held)

## Regression risks / notes

- The `.env`-warning inflation (5210 warnings) hides the passed count; the real signal is failed = 10, all in known groups plus BUG-1.
- Lock order in `DatabasePostingService` relies on `strcmp` order of upper-case ULIDs matching `ORDER BY id` of the engine collation; only the engine test can prove that.
- Posting is correct only if every caller opens the transaction and takes its own document-number lock before calling (documented in the class docblock); FP-262 should add a test for this ordering when it wires its first caller.
- No HTTP route or console command edits a journal row; verified by the static scan, not by hand.

## History
Run 1: FAIL (BUG-1 process defect only; product clean, 7 of 8 mutants killed on first pass, the survivor killed by a new QA test).
