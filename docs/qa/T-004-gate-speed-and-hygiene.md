# QA Report: T-004

Branch `ai/T-004-gate-speed-and-hygiene` · spec `docs/specs/T-004-gate-speed-and-hygiene.md`
(AC-1 … AC-20) · plan `docs/plans/T-004-gate-speed-and-hygiene.md` (11 steps + §12 rulings) ·
decisions `docs/decisions/T-004-gate-speed-and-hygiene.md` (D-34 … D-42).
**Run 2, 2026-10-01 — re-verified from scratch by a second `qa` agent.**

> **FOUND 2026-10-02, written here before I started verifying it**, per the process rule adopted
> after run 1's finding was lost: `services/host/.deptrac.cache` — 1 MB, **untracked and not
> git-ignored** — exists in the working tree the lead is about to commit. A bare
> `vendor/bin/deptrac analyse` (without the `--cache-file` flag `composer deptrac` passes) writes
> Deptrac's default cache into the current directory, and nothing in `services/host/.gitignore`
> covers it, so `git add -A` would commit it. *Subsequently verified — see **BUG-6**. The line
> above is left as written, unedited, as the record of the rule being followed.*

## Verdict: PASS

*Run 2 was re-measured on 2026-10-02 after the BUG-4 fix and reviewer finding R-1. Every figure
below describes the tree as it now stands — **304 cases / 1358 assertions** — not the 303/1352
tree the first version of this report certified.*

**20 of 20 acceptance criteria pass.** Everything below was measured by me on this tree; no
number in this run is carried over from run 1, from `dev`'s hand-off or from the plan's §12
table.

**Open at hand-off: one minor defect.** BUG-6 — an untracked, un-ignored 1 MB
`services/host/.deptrac.cache` sitting in the tree about to be committed. BUG-4 is **fixed and
verified**; BUG-5 is with the lead. Reviewer finding **R-1 is fixed in my own file and the family
it belongs to is closed** by a property-based sweep with a negative control (below).

**Run 1's three defects are independently confirmed fixed:**

- **BUG-1 (was critical).** The line-ending rule is now commit `643e220`, one file, 23
  insertions. Re-measured against real committed history, not against the working tree: a
  genuine `git clone --local` of `643e220` has **0** `w/crlf` files of 215 tracked; a clone of
  the parent `e6c1934` has **88** — so the commit is demonstrably what fixed it. A fresh
  checkout of `services/demo` and a fresh `svc:make demo` are identical on **all 11 files by
  raw `md5sum`**, while the same comparison against the pre-fix checkout differs on exactly the
  5 files that were CRLF there. Pint's `line_ending` fixer still rejects a CRLF file from that
  pre-fix checkout, which is what used to kill `composer check` at step 1 of 4.
- **BUG-2 (was minor).** The tautology is gone and the replacement can fail: I demonstrated
  that `expect($shared)->toBe([])` and `expect(count($fullFiles))->toBe(count($fullClasses))`
  both go red on a basename collision, which is reachable (every generated package ships
  `tests/Feature/PingRouteTest.php`).
- **BUG-3 (was minor).** Both delete-before-restore sites now use the non-throwing helper with
  the restore inside the conditional, and the backup is removed only after a content match.
  Repository-wide sweep: those are the **only two** sites in the suite where a delete precedes
  a restore. The new lower-bound assertion was **mutation-tested**: reinstating the fake fix (3
  attempts, no backoff) makes it red in 0.001 s with a message naming the cause — so yes, it
  would have caught it.

**The developer's "32 consecutive green runs" claim is not relied on.** My own repetitions, on
the file that actually flaked: **25/25 green** single-file, **11/11 green** `test:fast`,
**5/5 green** full (two of them inside `composer check`) — **41 suite executions that include
`ServiceRegistryManifestTrustTest`, 0 failures, 0 leftover fixture directories**.

## Acceptance criteria coverage

| AC | Test(s) / evidence (all measured in run 2) | Result |
|----|--------------------------------------------|--------|
| AC-1 baseline recorded by name | No `dev`-produced artefact survives on disk (`services/host/storage/app/` is git-ignored and empty). I rebuilt the baseline **from git, not from a report**: file-set diff `git ls-tree -r HEAD` vs disk, plus a per-file diff of every `^(it\|test)(` declaration and every `expect(`/`->and(`/`assert` line between each surviving file's HEAD blob and its working copy. Baseline = **279 cases / 1224 assertions** at `e6c1934`; itemised below as "the 304 cases I measured, minus the 28 named additions, plus the 3 named removals". Corroborated by the `docs/reviews/T-003-…` header, written by a different role | PASS (artefact reconstructed by `qa`; see note on the process gap) |
| AC-2 coverage proven by name | My own `full2.xml` (304/1358); file-set diff (exactly 2 removed, exactly 6 added, nothing else); case-declaration diff over **all 40 surviving test files** → exactly **three** differences (2 additions, 1 declared rename); assertion-line diff over the same 40 files, **with comments stripped** → the **same three** files and nothing else, every change additive except the rename; reconciliation 304 − 28 + 3 = **279** and 1358 − 138 + 4 = **1224** | PASS |
| AC-3 fast exclusion set is exact | `fast ⊎ slow === full` by `classname::name` (0 in full not in the union, 0 in the union not in full, intersection **0**); `--group=toolchain` collected exactly the **15** files of `toolchainGroupFiles()` — set equality computed from my own `slow.xml`, both directions empty; exclusion not vacuous (53 cases, 425 assertions) | PASS |
| AC-4 gate still runs the tagged group | All 53 slow cases appear in `full2.xml` with **identical per-case `assertions` attributes — 0 mismatches over 304 cases**; `test` and `check` carry no group flag, `phpunit.xml` declares no `<groups>` (unmodified by this task); `ToolchainGroupCoverageTest` layer 1 asserts that with a positive control on `test:fast`/`test:slow` | PASS |
| AC-5 credential-leak tests never excluded | **31 credential-leak cases in `full.xml`: 31 in `fast.xml`, 0 in `slow.xml`** (`TenancyCredentialLeakTest` 24/79, `QaCredentialSurfaceSweepTest` 7/35). Both directions probed: (a) the exemption list is what keeps the spawning sweep file out — removing it from the simulation makes the lint demand its registration; (b) if `TenancyCredentialLeakTest` ever started spawning, layer 2 would demand it **and** the AC-5 case forbids satisfying that by registering it, forcing a deliberate exemption instead of a silent exclusion | PASS |
| AC-6 fixture hoisting preserves outcomes | Only the 3 files that `require_once` `packages/kernel/tests/Support/tenancy-fixture.php` are hoisted; `QaTenantIsolationProofTest` and `QaCredentialSurfaceSweepTest` carry **their own** fixtures (own `$pdo->exec('create table marker …')`), so the plan's "do not hoist" list is honoured and the per-test recreation that `qa_iso_scratch` depends on is intact. Grepped the 3 hoisted files for `create table`/`insert into`/`update`/`delete from`/`drop`/`->statement(`/`->exec(`/`->unprepared(`: **zero** hits. Function-by-function diff of the relocated helpers against their HEAD originals: 12 of 15 byte-identical, 3 changed and all 3 changes are the hoist itself (memoised root, idempotent creation, deletion moved to `afterAll`). Per-file case and assertion counts unchanged | PASS |
| AC-7 hoisting does not leak state | `TenancyResolutionTest > it still starts every case from a fixture directory holding only the two databases` — declared **last** (case 12 of 12), asserts the directory holds exactly `tenant-a.sqlite`/`tenant-b.sqlite`, that `no-such-database.sqlite` (the `tenant-unopenable` target, whose absence other files' refusal cases depend on) is **still absent**, and that both shared databases still read back their own marker. 4 assertions. Post-run check: `storage/app/` holds only `.gitignore`, `private`, `public` — `afterAll` really fires | PASS |
| AC-8 root rule exists | `/.gitattributes` **committed** in `643e220` with `* text=auto eol=lf` (+ `*.bat/*.cmd/*.ps1 text eol=crlf`, `*.ico binary`). In a fresh clone every tracked text file reports `attr/text=auto eol=lf`; `ToolchainGroupDetectorTest`'s last case also now asserts the file exists | PASS |
| AC-9 renormalisation is its own commit | `git show --stat 643e220` → **`.gitattributes` \| 23 +++, 1 file changed**, nothing else. Renormalisation is complete, not merely declared: no tracked blob reports `i/crlf` or `i/mixed`, and `git add --renormalize .` **inside a fresh clone stages 0 files** — the tree already matches the rule. The FP-155/157/164 changes are not in it (they are still working-tree only; see the scope note) | PASS |
| AC-10 fresh-checkout proof | Three-way raw-byte comparison, no normalisation: fresh `git clone --local` of `643e220` → live tree → `rm -rf services/demo && git checkout -- services/demo` → `svc:make demo`. **All 11 files identical by `md5sum` across all four states.** Negative control: the same regeneration against a fresh checkout of the parent `e6c1934` differs on exactly `composer.json`, `routes/api.php`, `src/DemoServiceProvider.php`, `src/Http/Controllers/PingController.php`, `tests/Feature/PingRouteTest.php` — the defect, reproduced from committed history. `services/demo` restored and re-verified byte-identical | PASS |
| AC-11 no line-ending warning | Fresh clone of `643e220`: **0** `w/crlf`, **0** `w/mixed` of 215 tracked files (vs **88** `w/crlf` at the parent). Live tree: `git add -A .` over all 61 working-tree changes, run against a **copy** of the index (`GIT_INDEX_FILE`) so the real index was never touched — 61 files staged, **0 lines matching "will be replaced by"**. The `fatal: unable to stat` abort run 1 saw with `--renormalize` does not occur with `git add -A` | PASS |
| AC-12 scaffolding gone | All 7 named paths plus `package.json` absent; `resources/views/.gitkeep` kept so the default view path still exists; `StockScaffoldingRemovedTest` asserts it with a positive control (`routes/web.php` must be found) and also asserts no `npm `/`npx ` survives in any Composer script. `.env.example` lost only its `VITE_APP_NAME` line | PASS |
| AC-13 no closure route | `routes/web.php` declares `Route::get('/', RootController::class)->name('root')`; `RouteCachingTest` checks the **source** for `function (`, `fn (`, `fn(` and then resolves the named route and asserts the action names `RootController`. `App\Http\Controllers\RootController::__invoke()` returns `204 No Content` | PASS |
| AC-14 route caching pinned, before **and** after | **After:** `route:cache` exit 0, `INFO Routes cached successfully`, no `for serialization` / `Uses Closure` in the output, cached table usable (`route:list` from a child shows `health/live`). **Before — I re-derived the architect's premise rather than accepting it:** restored HEAD's stock closure route into `routes/web.php` and ran `route:cache` → **exit 0, "Routes cached successfully"**. The documented claim was false. `routes/web.php` restored byte-exact (md5 verified). The framework fact is also an executing assertion (`Route::prepareForSerialization()` turns a Closure action into a non-empty string) guarded by the live table still containing closure routes | PASS |
| AC-15 no route-cache pollution | Pre-flight absence assertion derived from `app()->getCachedRoutesPath()` (not hard-coded), child process, `finally` with delete-before-assert, plus `afterEach`. After every one of my 4 full runs and 11 fast runs `bootstrap/cache/` held only `packages.php` and `services.php` — no `routes-v7.php`, no `config.php`. My own `route:cache`/`route:clear` probe also left it clean | PASS |
| AC-16 documentation corrected | **Mutation-tested in four directions, all red, both documents restored byte-exact (md5 `-c` OK):** (a) re-inserting "fails outright" into `README.md` → red, naming the file and the phrase; (b) re-inserting "stock closure route" into `config/servicehost.php` → red, same; (c) deleting the corrected point 3 from `README.md` → red on `toContain('route:cache')`; (d) deleting it from `config/servicehost.php` → red likewise. Repository-wide grep: the false claim survives only in T-001/T-002 historical records and in `docs/backlog.md` (BUG-5). `'documented only'` still occurs **exactly 3** times in `config/servicehost.php` and `QaCacheGuardBranchesTest` passes unchanged in every run; deleting point 3 drops it to 2, so that assertion is a second, independent guard | PASS |
| AC-17 `TenancyCredentialLeakTest` in isolation | `pest …/TenancyCredentialLeakTest.php` → **24 passed, 79 assertions** — identical to its `full.xml` figures, no undefined-function fatal | PASS |
| AC-18 `TenancyTenantSwitchTest` in isolation | **10 passed, 40 assertions** — identical to `full.xml` | PASS |
| AC-19 `TenancyResolutionTest` in isolation | **12 passed, 63 assertions** — identical to `full.xml`; no duplicate-declaration fatal (`require_once`) | PASS |
| AC-20 whole directory in isolation | All **12** kernel test files run individually (6 Feature, 6 Unit, i.e. more than the 5 AC-20 names): 12/63, 10/40, 24/79, 15/63, 7/35, 7/28, 8/21, 12/32, 8/15, 3/6, 3/10, 16/41 — **every file equal to its `full.xml` figures**. Negative control: a throwaway kernel Feature file calling `tenancyBootFixture()` **without** the `require_once` dies with `Error: Call to undefined function tenancyBootFixture()`, so the five files pass by the relocation, not by luck. Probe deleted | PASS |

### Scope note on "after this task"

Only the FP-156 rule is committed (`643e220`); the FP-155/157/164 work is still working-tree
only, which is the state the lead confirmed. AC-8…AC-11 are the criteria whose *meaning*
depends on git — those are verified against **committed history**, with a negative control at
the parent commit. Every other criterion is verified against the working tree, because that is
where the deliverable is. AC-9's "it is not in them" clause can only be fully re-checked once
the remaining commits exist; nothing I measured suggests it will be a problem, since
`.gitattributes` is already tracked and the renormalisation stages nothing.

## Independent coverage proof (AC-1/AC-2), rebuilt from git

Everything here comes from JUnit logs I produced myself and from `git`. I did not check
`dev`'s arithmetic and I did not paste the plan's §12 table — that table predates the three
bug-fix rounds and is now stale (see note N-2).

**Measured now** (`pest --log-junit` ×3, from `services/host`):

| Run | Cases | Assertions | Duration |
|---|---|---|---|
| full (`pest`) | **304** | **1358** | 107.3 / 132.7 s (2 runs on this tree; 80.4–154.8 s over 5 runs on the 303/1352 tree) |
| slow (`--group=toolchain`) | 53 | 425 | 87.0 s (51.4 s on the earlier tree) |
| fast (`--exclude-group=toolchain`) | **251** | **933** | 39.7 s (26.8–30.8 s over 11 runs on the earlier tree) |
| fast ⊎ slow | **304** | **1358** | — |

- `fast ∪ slow === full` by `classname::name`: **0** in full and not in the union, **0** in the
  union and not in full.
- `fast ∩ slow === ∅`: **0**.
- Per-case `assertions` attributes identical between `full2.xml` and whichever half each case
  landed in: **0 mismatches over 304 cases**. The sums therefore mean what they look like.
- `slow2.xml`'s 15 distinct classes equal `toolchainGroupFiles()` exactly.
- 251 + 53 = 304 and 933 + 425 = 1358.
- The BUG-4 fix's new case landed in **fast** (`SvcMakeDemoRegenerationTest` is not in the
  registry), which is where the 250 → 251 and 927 → 933 deltas come from; the slow half is
  byte-for-byte the same 53 cases / 425 assertions as before, so the fix round did not move a
  single test across the split.

**Set difference against the baseline, by name.** Two git-level facts plus the JUnit log:

1. **Test files.** `git ls-tree -r HEAD` vs disk (vendor excluded): 42 → 46.
   Removed: exactly `services/host/tests/Feature/ExampleTest.php` and
   `services/host/tests/Unit/ExampleTest.php`.
   Added: exactly `RouteCachingTest.php`, `StockScaffoldingRemovedTest.php`,
   `ToolchainGroupBindingTest.php`, `ToolchainGroupCoverageTest.php`,
   `tests/Unit/FixtureDirectoryTeardownTest.php`, `tests/Unit/ToolchainGroupDetectorTest.php`.
   Nothing else appeared or disappeared.
2. **Cases and assertions inside the 40 surviving files.** For each file I diffed its HEAD blob
   against its working copy twice — once on `^(it|test)(` declarations, once on every line
   containing `expect(`, `->and(`, `$this->assert` or `->assert`, **with comments and docblocks
   stripped first** (the plain grep version reported a fourth, phantom difference in
   `QaRegenerationGuardTest.php`, because a comment I added for R-1 contains the words
   *"an expect() call"*). **Both diffs then report exactly the same three files, and nothing
   else, across all 40:**
   - `TenancyResolutionTest.php`: **+1** case, `it('still starts every case from a fixture
     directory holding only the two databases')` (the AC-7 case). 40 → 44 assertion lines, all
     additive.
   - `SvcMakeDemoRegenerationTest.php`: **+1** case, `it('distinguishes raw bytes from normalised
     content, which is what the teardown rests on')` — the BUG-4 fix's regression test. 9 → 15
     assertion lines, **all additive**: the pre-existing case's nine assertion lines are
     byte-unchanged, and `full2.xml` confirms that case still reports exactly **9** assertions
     while the new one reports **6**.
   - `HealthEndpointTest.php`: `it('keeps the stock welcome route inside the web group')` →
     `it('keeps the root route inside the web group')` — the **declared rename pair** (D-39 /
     D-42, declared in plan §6 Step 9.3: *"rename the case … Same assertion shape, same
     guarantee"*). The only assertion change is the variable name `$welcome` → `$root`; 16
     assertion lines before and 16 after, 2 assertions on that case before and after.

   So: **no surviving file lost a case or an assertion line, and the only changes to surviving
   files are two named additions and one declared rename.** That is the strongest available form
   of "no surviving test quietly lost an assertion", and stronger than a per-case count diff for
   the 24 files that did change. My own R-1 edit to `QaRegenerationGuardTest.php` changed **no**
   assertion line and no assertion count (2 cases, 16 + 13 = 29 assertions, before and after),
   which is why that file is absent from the list above.

**Additions — 28 cases, 138 assertions, itemised** (the explicit list AC-2 (3) requires):

| Case | Assertions |
|---|---|
| `HealthEndpointTest::it keeps the root route inside the web group` *(rename-in of the D-39/D-42 pair)* | 2 |
| `TenancyResolutionTest::it still starts every case from a fixture directory holding only the two databases` | 4 |
| `SvcMakeDemoRegenerationTest::it distinguishes raw bytes from normalised content, which is what the teardown rests on` *(the BUG-4 fix's regression test)* | 6 |
| `RouteCachingTest::it caches the route table successfully and leaves no cache file behind` | 9 |
| `RouteCachingTest::it still serialises a closure route on this framework version` | 4 |
| `RouteCachingTest::it declares no closure route in routes/web.php` | 5 |
| `RouteCachingTest::it documents the route:cache constraint that is actually true` | 5 |
| `StockScaffoldingRemovedTest::it leaves none of the stock scaffolding behind` | 7 |
| `ToolchainGroupBindingTest::it binds the group to exactly the registry, and loses no test to the split` | 15 |
| `ToolchainGroupCoverageTest::it keeps every group flag out of the gate and only in the fast command` | 11 |
| `ToolchainGroupCoverageTest::it keeps the registry equal to the set of test files that really spawn a child process` | 6 |
| `ToolchainGroupCoverageTest::it reports a file that spawns a child process and is in no list` | 9 |
| `ToolchainGroupCoverageTest::it keeps every credential-leak proof out of the group` | 5 |
| `FixtureDirectoryTeardownTest::it removes a populated fixture directory and reports that it is gone` | 5 |
| `FixtureDirectoryTeardownTest::it reports failure without throwing when the directory cannot be removed` | 4 |
| `FixtureDirectoryTeardownTest::it throws and names the directory when a plain teardown cannot remove it` | 3 |
| `FixtureDirectoryTeardownTest::it still restores a moved directory when the delete before it fails` | 8 |
| `ToolchainGroupDetectorTest::it reports a source that starts a child process, one token at a time` × 6 datasets (`Process::`, `proc_open(`, `shell_exec(`, `popen(`, `passthru(`, `exec(`) | 1 each |
| `ToolchainGroupDetectorTest::it reports a source that starts no child process` | 1 |
| `ToolchainGroupDetectorTest::it does not mistake a method or static call for a bare process function` | 16 |
| `ToolchainGroupDetectorTest::it subtracts the exemption list from the spawners and nothing else` | 3 |
| `ToolchainGroupDetectorTest::it reports a registry or exemption entry that names no file on disk` | 2 |
| `ToolchainGroupDetectorTest::it computes the repository root from its own location, not from the container` | 2 |

**Removals — 3 cases, 4 assertions:**
`Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (1 assertion,
`assertStatus(200)` — read from the HEAD blob), `Tests\Unit\ExampleTest::test_that_true_is_true`
(1, `assertTrue(true)`), and the rename-out
`HealthEndpointTest::it keeps the stock welcome route inside the web group` (2).

**Reconciliation:**

```
full measured now          304 cases   1358 assertions
minus the 28 additions     -28         -138
                          -----       -----
surviving                  276        1220
plus the 3 removals         +3          +4
                          -----       -----
reconstructed baseline     279        1224
```

279 / 1224 is exactly what the `docs/reviews/T-003-…` header records and what `architect`
measured twice before implementation. Two derivations from different directions meeting on one
pair is the evidence; the run-1 correction of T-003's figure from 277/1219 to 279/1224 stands.

**Delta against the first run-2 measurement (303/1352):** +1 case / +6 assertions, entirely the
BUG-4 fix's regression case in `SvcMakeDemoRegenerationTest`. Nothing else moved — the slow half
is identical (53 / 425), every other per-case assertion count is identical, and R-1's fix changed
no assertion.

**Delta against run 1 (299/1331), fully accounted for:** +4 cases / +20 assertions from the new
`FixtureDirectoryTeardownTest` (the BUG-3 regression test), +1 case / +6 from the BUG-4 one, and
+1 net assertion from the bug-fix edits inside T-004's own new files
(`ToolchainGroupCoverageTest` 34 → 31 as the tautology was replaced,
`ToolchainGroupBindingTest` 14 → 15, `RouteCachingTest` 21 → 23,
`StockScaffoldingRemovedTest` 6 → 7). **Every one of those changes is inside a file this task
adds** — the 40 surviving files' assertion lines are byte-unchanged, so no pre-existing test
was touched by the fix round either.

## Test run summary

All commands run by me from `services/host`, serialised (never two suites in this working tree at
once). **Runs 1–9 are the 2026-10-01 tree at 303/1352; runs 10–13 are the 2026-10-02 tree at
304/1358, which is the one being committed.**

| # | Command | Result |
|---|---|---|
| 10 | `pest --log-junit full2.xml` | exit 0 — **304 passed, 1358 assertions**, 132.70 s |
| 11 | `pest --group=toolchain --log-junit slow2.xml` | exit 0 — 53 passed, 425 assertions, 86.96 s |
| 12 | `pest --exclude-group=toolchain --log-junit fast2.xml` | exit 0 — **251 passed, 933 assertions**, 39.73 s |
| 13 | **`composer check` on the committed tree** | **exit 0** — Pint `{"result":"passed"}`, PHPStan level 6 `[OK] No errors`, Deptrac `Violations 0 / Warnings 0 / Errors 0`, Pest **304 passed, 1358 assertions**, 107.34 s |
| 13a | `pest tests/Feature/QaRegenerationGuardTest.php` (after the R-1 fix) | exit 0 — 2 passed, **29 assertions — unchanged** |
| 13b | `pint --test tests/Feature/QaRegenerationGuardTest.php` | `{"result":"passed"}` |
| 1 | `pest --log-junit full.xml` | exit 0 — **303 passed, 1352 assertions**, 102.75 s |
| 2 | `pest --group=toolchain --log-junit slow.xml` | exit 0 — 53 passed, 425 assertions, 51.37 s |
| 3 | `pest --exclude-group=toolchain --log-junit fast.xml` | exit 0 — 250 passed, 927 assertions, 30.46 s |
| 4 | **`composer check`** | **exit 0** — Pint `{"result":"passed"}`, PHPStan level 6 `[OK] No errors`, Deptrac `Violations 0 / Warnings 0 / Errors 0`, Pest **303 passed, 1352 assertions**, 100.54 s; 145 s wall for the whole gate |
| 5 | 25 × `pest tests/Feature/ServiceRegistryManifestTrustTest.php` | **25/25 exit 0** — 12 passed, 71 assertions every time; no leftover `services/*fixture*` after any run |
| 6 | 10 × `pest --exclude-group=toolchain` | **10/10 exit 0** — 250 passed, 927 assertions every time, 26.84 – 30.81 s |
| 7 | 2 × `pest` (full) | **2/2 exit 0** — 303 passed, 1352 assertions, 80.44 s / 81.93 s |
| 8 | 12 × single kernel test file by path | all exit 0, figures identical to `full.xml` (AC-17…AC-20) |
| 9 | **`composer check`, final confirmation run after every probe was restored** | **exit 0** — Pint passed, PHPStan `[OK] No errors`, Deptrac `0/0/0`, Pest **303 passed, 1352 assertions**, 154.81 s; 266 s wall |
| — | filtered probes (decay guard ×4, documentation mutations ×5, teardown mutation ×2, FP-164 negative control, `route:cache` before/after) | behaved as recorded per AC above |

**Skipped tests: none, in any run. Failures: none, in any run** — including the first run of the
session, so run 1's concurrency poisoning did not recur.

**Speed, measured over 7 full runs and 12 fast runs across both trees:** fast **26.8–39.7 s**
against full **80.4–154.8 s**, where the full spread is for *the same number of tests on the same
machine* (80.4 and 154.8 s are both 303-case runs). `CLAUDE.md` quotes 23 s against 82 s and now
labels them **warm** with an explicit "a cold machine or CI pays considerably more" caveat, which
addresses plan §12's follow-up 2 — but note that 82 s is the *best* warm run I saw, not a typical
one: a 1.9× spread on load alone. Whoever does FP-165 should quote a range
rather than a point, and the ratio (roughly 3–4× faster) travels better than either absolute. The
win is skipping 15 tool-booting files, not the fixture hoist, and the deeper reason those files
are expensive is that a filtered child run still performs full collection before filtering.

## Defects

### BUG-6 (minor) | repository hygiene | an untracked, un-ignored 1 MB Deptrac cache is sitting in the tree about to be committed

- **Steps:** `git status --porcelain` lists `?? services/host/.deptrac.cache`;
  `git check-ignore -v services/host/.deptrac.cache` reports **nothing**, i.e. not ignored;
  `ls -la` gives 1 016 338 bytes, mtime 2026-10-02 00:10 — after my run 2, so it was produced by
  someone running Deptrac between then and now.
- **Expected:** a tool's cache file is either written somewhere git-ignored or ignored where it
  lands. `composer deptrac` already does the right thing — it passes
  `--cache-file=storage/framework/cache/.deptrac.cache` — and every one of the three test files
  that spawns Deptrac passes its own `--cache-file` under `storage/framework/cache/` too
  (verified: `DeptracBoundaryEnforcementTest` ×3, `QaTestCodeAnalysisCoverageTest` ×1). So
  **nothing in the suite produces this file.**
- **Actual:** a bare `vendor/bin/deptrac analyse` — what a human or an agent types when not using
  the Composer script — writes Deptrac's default cache into the current working directory, and
  `services/host/.gitignore` has no entry for it. `git add -A` would commit a megabyte of
  machine-local cache into the repository, in the task whose entire subject is repository hygiene.
- **Suspected location:** `services/host/.gitignore` (dev-owned) — it already ignores
  `.phpunit.result.cache` and `/.phpunit.cache`, so this is the same category with one entry
  missing.
- **Fix:** add `/.deptrac.cache` to `services/host/.gitignore` (`dev`), and delete the existing
  file before committing (`lead`). I deliberately left the file in place rather than deleting it:
  removing it would hide the evidence while leaving the `.gitignore` gap to recur silently on the
  next bare Deptrac run.

### BUG-4 — FIXED AND VERIFIED (was minor) | a teardown comment claimed a byte comparison that the code did not make

- **File:** `services/host/tests/Feature/SvcMakeDemoRegenerationTest.php:84` (dev-owned), in the
  BUG-3 fix's own `afterEach`.
- **Expected:** the comment and the code agree. The comment says the backup *"is removed only
  once services/demo demonstrably holds the same **bytes**, which makes the backup redundant by
  inspection rather than by assumption."*
- **Actual:** the guard is
  `demoTreeFingerprint($demo) === demoTreeFingerprint($backup)`, and
  `demoTreeFingerprint()` hashes `md5(str_replace("\r\n", "\n", …))`. It compares **normalised
  content**, not bytes, so a demo tree that came back with different line endings would satisfy
  it and the only copy would then be deleted. Unreachable today — the restore is a
  `File::moveDirectory()` rename, which cannot change bytes, and the root rule now guarantees LF
  — which is why this is minor rather than major. But it is a comment asserting a protection the
  code does not provide, in the one teardown that can destroy committed content, and that is the
  exact pattern this team has agreed to stop shipping.
- **Suspected location:** `SvcMakeDemoRegenerationTest.php:76-93`.
- **Fix as shipped by `dev`, and verified by me:** a new `demoRawTreeFingerprint()` using raw
  `md5_file()`, and the `afterEach` guard now reads
  `demoRawTreeFingerprint($demo) === demoRawTreeFingerprint($backup)`. The comment was also
  corrected to be precise about something I had got slightly wrong myself: this branch is reachable
  **only when the restore did not happen**, so the two sides are the regenerated tree and the
  committed one — two different producers, not a tree against itself. Raw equality can therefore
  report "different" for trees whose content matches, and that false negative **keeps** the backup,
  which is the safe direction.
- **And the fix is pinned, which is what makes it more than a swap:**
  `it('distinguishes raw bytes from normalised content, which is what the teardown rests on')` —
  6 assertions — builds two trees differing only in line endings, requires `demoTreeFingerprint()`
  to call them equal (so a CRLF checkout still cannot fail the gate, T-002 R-1 / FP-153) and
  `demoRawTreeFingerprint()` to call them different, **and then reads the `afterEach` source** to
  assert the guard calls the raw one and not the normalising one. That last pair is what stops the
  test becoming a QA-BUG-2-shaped proof of a property nobody uses. I re-ran the file: 2 passed,
  15 assertions, and the pre-existing case still reports exactly its original 9.

### BUG-5 (minor) | AC-16 adjacent | `docs/backlog.md` still states the disproved `route:cache` premise, and still marks FP-155/156/157 `TODO`

- **Steps:** `grep -n "FP-15[567]" docs/backlog.md` →
  `| FP-157 | Retire the remaining stock framework scaffolding, including the closure route that blocks route caching | TODO — before containers and CI |`
  and FP-155/FP-156 likewise `TODO`, while FP-164 on the next line reads `**DONE (T-004)**`.
- **Expected:** the claim "the closure route … blocks route caching" is measured false — I
  re-derived it myself this run (HEAD's closure route restored, `route:cache` exit 0). The spec
  names this backlog row explicitly as one of the places the false premise is *stated*
  (`docs/specs/T-004-…md:26-46`). A reader of the backlog still believes a defect exists that
  does not.
- **Actual:** the false clause survives in the row, and three of the four rows this task
  delivers still read `TODO` while the fourth reads `DONE (T-004)`.
- **Suspected location:** `docs/backlog.md` lines 37-39. **Owner: lead** (`docs/backlog.md` is
  not `dev`'s file), which is why this is routed through the lead rather than to `dev`.
- **Fix:** drop "including the closure route that blocks route caching" from FP-157, and settle
  the three statuses one way or the other so the row set is internally consistent.

### R-1 (reviewer, mis-assigned to `dev`; the file is mine) — FIXED, and the family closed

**What it was.** `qaRestoreDriftedStub()` in `services/host/tests/Feature/QaRegenerationGuardTest.php`
ended with the **throwing** `deleteFixtureDirectory()`. The `afterEach` called it first and
`qaRestoreCrlfBackup()` second, so one Windows delete failure would skip the restore that puts the
committed `services/demo` bytes back from the byte-for-byte backup — BUG-3's hazard, one level up,
in the file that states the rule. The reachable path is the backup-creation loop, which sits
outside any `finally`, leaving the hook as the only restore there.

**Why my own sweep missed it, which is the part worth keeping.** I swept for the **mechanism** I
had already seen — `moveDirectory`, `copyDirectory`, `rename` near a delete. R-1 has the throwing
delete and the restore it can skip **in different functions**, so no proximity-based grep can see
it. Three findings in three passes over this ground by three different readers is the argument for
closing the family rather than patching the instance.

**Fixed, in my file:**

- `qaRestoreDriftedStub(): bool` now ends in `tryDeleteFixtureDirectory()` and **cannot throw at
  all**. It also no longer uses `File::get()`, which throws `FileNotFoundException` if the file
  vanishes between the `exists()` check and the read — that throw would have skipped both the
  `File::put()` restore below it and the second restore in the hook, i.e. the identical property
  reached through a different statement. `is_file()` + `@file_get_contents()` removes it.
- `qaRestoreCrlfBackup(): bool` likewise returns rather than throws, even though nothing
  restorative currently follows it, because relying on that would put the property back at the
  mercy of statement order.
- The `afterEach` runs **every restore first, unconditionally**, then raises one
  `RuntimeException` naming whichever directories survived. Loudness is preserved without any
  helper deciding which of its siblings get to run. A throw, not an `expect()`, so no assertion
  count moves (verified: 2 cases, 29 assertions, before and after).
- The inner `finally`'s `deleteFixtureDirectory($aside)` became `tryDeleteFixtureDirectory()` too.
  It was defensible as a cleanup-only delete, but a throw there would have left
  `qaRestoreCrlfBackup()` in the *enclosing* `finally` as the thing still needing to run — relying
  on PHP executing an outer `finally` while an exception escapes an inner one is exactly the
  "depends on control flow rather than on the helper" shape R-1 reported. A surviving `$aside` is
  reported instead by the strict pre-flight delete on the next run, which names the path.

**The bounded sweep, by property, two levels deep — and its negative control.** Property: in any
teardown (`afterEach` / `afterAll` / `beforeAll` body, or a `finally` block), does anything that
**can throw** appear before anything that **restores**, counting a call to a helper as "can
throw" / "restores" when the helper's own body does? Throwers: `deleteFixtureDirectory(`,
`File::get(`, `expect(`, `Artisan::call(`, `throw new`. Restores: `File::put/move/moveDirectory/
copy/copyDirectory`, `file_put_contents`, `rename(`.

| Measurement | Result |
|---|---|
| Teardown blocks scanned | **74**, across **52** test sources; **133** top-level helpers classified |
| Hits, after the R-1 fix | **0** |
| Negative control — R-1's exact pre-fix shape through the identical code path | **REPORTED** ("`qaRestoreDriftedStub()` can throw, and `qaRestoreCrlfBackup()` comes after it"), so the zero above is evidence rather than the absence of it |
| Helpers that both restore and can throw — internal order | `qaAtomicRestoreStub()`, `atomicRestoreStub()`, `qaWriteSuiteConfig()`, `qaRestoreDriftedStub()`, `teardownUndeletableDirectory()` — **all SAFE** (last restore before first thrower) |
| Two flagged `UNSAFE` | `qaTrustFixture()` and `trustFixture()` put `File::put()` after an `expect()`. **Both are fixture *builders* called only from test bodies — grep finds no teardown call site** — where a failing `expect()` is the intended outcome, not a teardown that must put something back. Out of the property's scope, recorded so it is visibly considered rather than missed |

Two scanner defects found and fixed while doing this, both worth knowing because they are the
same class of error I look for in others' work:

1. **The first version matched its own prose.** It reported four hits that were all the words
   `deleteFixtureDirectory`, `expect` and `Restore` inside explanatory comments — including
   comments I had just written for this fix. Now it strips `T_COMMENT`/`T_DOC_COMMENT` via
   `token_get_all()` first. The same bug was in my assertion-line diff, which reported a phantom
   fifth difference for the same reason.
2. **The second version counted one statement as two.** A single call to a helper that both
   restores and can throw produced a THROW and a RESTORE event at the *same* source offset, and
   the loop read the second as "after" the first — seven phantom hits. The comparison is now
   strictly by offset, and whether such a helper is internally safe is asked separately (the table
   row above).

A permanent lint for this property is tempting and I did **not** add one: it needs call-graph
reasoning to stay honest, it would have to exempt fixture builders by name, and the reviewer asked
for the family to be closed rather than for new machinery mid-commit. Recorded as a suggested
backlog row instead — see note N-9.

### Run-1 defects — re-tested independently

| Defect | How I re-tested it | Result |
|---|---|---|
| **BUG-1** (critical) nothing committed, fresh clone broken | Real `git clone --local` of `643e220` **and** of the parent `e6c1934`; `git ls-files --eol` in both; raw `md5sum` of `services/demo` across clone / fresh checkout / regeneration; `pint --test` on a CRLF file from the pre-fix clone; `git add --renormalize` inside the fresh clone; `git add -A` against a copied index | **FIXED.** 88 → 0 `w/crlf`; 11/11 files raw-identical; the pre-fix clone still differs on exactly 5 files and still fails Pint's `line_ending`; renormalisation stages nothing; staging 61 changes prints no warning |
| **BUG-2** (minor) tautological uniqueness guard | Read the replacement; confirmed `$shared` is computed from **distinct classes** (which embed the full path, so two files can never collapse into one class) and that `count($fullFiles) === count($fullClasses)` is false under a basename collision; the collision path is reachable because every generated package ships `tests/Feature/PingRouteTest.php` | **FIXED**, and the comment now records what the old shape got wrong |
| **BUG-3** (minor) teardown could destroy the moved-aside copy | Read both sites; swept the whole suite for `moveDirectory`/`copyDirectory`/`rename` — only `SvcMakeDemoRegenerationTest` and `QaRegenerationGuardTest` ever move committed content, and **both** now use `tryDeleteFixtureDirectory()` with the restore inside the conditional; all stub-restore helpers restore before the throwing delete; **mutation-tested the new lower bound** by reinstating a 3-attempt tight loop → red in 0.001 s with the right message; `tests/Helpers.php` restored byte-exact | **FIXED** |

## The claim I was asked to challenge: the retry fix and its lower bound

**Does the lower-bound assertion actually catch the fake fix? Yes — measured, not reasoned.**
I mutated `tryDeleteFixtureDirectory()` back to the shape the developer says never worked
(`$attempt < 3`, `usleep(0)`) and ran the file:

```
⨯ it reports failure without throwing when the directory cannot be removed
  tryDeleteFixtureDirectory() gave up in 0.001s, which means it is retrying in a tight loop
  again rather than waiting for whatever holds the directory to let go
```

Unmutated, the same case takes **0.66–0.73 s** to give up against a 0.3 s bound — a ~2× margin,
so it is not at risk of flaking in the other direction on a slow machine. `toBeGreaterThan()`
does take a real `string $message` second parameter (`vendor/pestphp/pest/src/Mixins/Expectation.php:135`),
so the message is not one of the silently-swallowed shapes.

**I also re-derived the platform mechanism the fix rests on** rather than trusting the docblock:
with a read handle open on a file inside a directory, `unlink()` on that file **succeeds**,
`rmdir()` on the directory **fails**, and `rmdir()` succeeds immediately after `fclose()`. The
helper's comment is accurate, and the fixture in `FixtureDirectoryTeardownTest` reproduces the
real shape rather than mocking it.

**My own repetition count, not the developer's.** 25 × the exact file that flaked
(`ServiceRegistryManifestTrustTest`, whose 8 cases create and delete `services/trustfixture` in
turn) + 11 × `test:fast` (which also runs that file, in context, alongside the other 8
fixture-generating files that are not in the toolchain group) + 5 full runs =
**41 executions of the flaky file, 0 failures, 0 leftover fixture directories**. The 12 cases /
71 assertions figure was identical every single time.

**What I will not claim:** 41 green runs do not prove a race is closed. What is new and does
count as evidence is that the failure window grew from microseconds to ~550 ms plus a
`gc_collect_cycles()` on every retry, and that a regression back to a tight loop is now caught
by an executing assertion rather than by somebody noticing a flake.

## The decay guard, verified by causing the decay

1. Planted `services/host/tests/Feature/QaDecayProbeTest.php` — a genuinely new file calling
   `Process::path(base_path())->run(...)`, in neither the registry nor the exemption list.
2. `pest --filter="keeps the registry equal"` → **exit 1**, naming the exact path: *"these test
   files spawn a child process but are not in toolchainGroupFiles(), so composer test:fast still
   pays for them: services/host/tests/Feature/QaDecayProbeTest.php"*.
3. `pest --exclude-group=toolchain --filter="keeps the registry equal"` → **also exit 1**. The
   guard fires **inside the fast loop**, which is the half that matters.
4. And the probe really would have joined that loop:
   `pest --exclude-group=toolchain --list-tests` lists it, `--group=toolchain --list-tests` does
   not.
5. Probe deleted; the same filter green again (1 passed, 6 assertions).

**The exclusion is defined by purpose, not by mechanism** — probed both ways, in-process against
the real helpers: the exemption list is the only thing keeping the spawning
`QaCredentialSurfaceSweepTest` out of the group (remove it from the simulation and the lint
demands its registration), and if a credential-leak test ever started spawning, layer 2 would
demand it **while the AC-5 case forbids registering it** — forcing a deliberate, written-down
exemption instead of a silent exclusion. That tension is the right design.

## Assertions that cannot fail — my own sweep

Three were already found and fixed, each with a comment recording the old shape
(`ToolchainGroupBindingTest`'s de-dup guard, `RouteCachingTest`'s `getActionName()` is-a-string,
`ToolchainGroupCoverageTest`'s registry-does-not-contain-the-probe). I swept for more over all
**52** test sources in the repository, for four shapes: `expect(X)->toBe(X)` self-comparison;
`count(X)` against `count(dedup(X))`; an assertion on a literal; both sides wrapped in
`array_values`. **Two hits, both benign** — `expect(true)->toBeTrue()` inside heredocs that
*write* a throwaway probe test file whose only job is to be collected
(`HostTestSuiteCollectionTest`, `QaHostSuiteCollectionProofTest`).

Weak-but-harmless shapes that pre-date T-004 and that T-004 does not touch (confirmed by the
assertion-line diff: zero changes in these files): `expect($manifest)->toBeArray()` in
`QaModuleConnectionRemovalTest`/`SvcMakeValidationTest`, `expect($replaced)->toBeString()` in
`QaHostSuiteCollectionProofTest:82`, `expect($message)->toBeString()` in
`TenantConnectionGuardTest:96`. Each is followed in the same chain by a real assertion. Recorded
so the next reader knows they were considered rather than missed.

`QaAssertionShapeGuardTest` continues to lint the negated-matcher family over every test file in
the repository, including all six new ones, and passed in every run.

## Regression risks / notes

- **T-001 AC-16/AC-17** (`composer test`/`composer check` exit 0): re-proved — 1 `composer check`
  and 4 bare full runs, all green, plus 11 fast and 1 slow.
- **T-002 AC-12/AC-13** (each host test directory collected exactly once, no duplicate-collection
  warning): green in every run; `phpunit.xml` is **not in `git status`** — untouched by this task
  — and still declares no `<groups>`.
- **T-002 AC-16/AC-17** (test code analysed by PHPStan level 6, boundary-checked by Deptrac):
  green inside run 4. `packages/kernel/tests` is a directory entry in both registries, so the new
  `tests/Support/tenancy-fixture.php` is covered by location. `services/host/tests/Helpers.php`
  is *not* analysed, because `services/host/tests` is deliberately outside both registries — that
  is pre-existing and the plan says so.
- **T-002 AC-18** (`QaToolchainRegistryCoverageTest`'s narrow fixture-skip rule): green; the
  relocated `qaFixturePackageNames()` is byte-identical to the HEAD original (same 14 names, same
  order) and now has a second consumer, `filesSpawningChildProcesses()`.
- **T-002 D-18 / D-39** (the health routes add to `routes/web.php` rather than replacing it):
  preserved by the renamed probe, which finds the route on `/` and asserts `web` middleware. Both
  `RootController`'s docblock and `routes/web.php`'s comment record *why* a route must remain
  there, which is the right place for that reasoning.
- **T-003 AC-19…AC-23** (generator criteria): green in every run, and T-003 AC-22 re-proved for
  real against a fresh checkout **and** a fresh clone, with a raw-byte comparison and a negative
  control at the parent commit.
- **T-001 AC-31/AC-32** (`services/demo` committed and reachable): `PingRouteTest` 3/6 green in
  every run; `services/demo` verified byte-identical after my move-aside probe
  (`git status --porcelain services/demo` empty).
- **N-1 — `bootstrap/cache/config.php` is still shared, mutable and cascading.** Two tests create
  it legitimately; any *other* process touching the tree mid-suite turns it into ~100 identical,
  misleading failures. It did not recur this run (every run green, including the first of the
  session), but the mitigation suggested in run 1 — have `Tests\TestCase::setUp()` fail with one
  clear message when an unexpected cached configuration exists — is still worth a backlog row.
  `bootstrap/cache/routes-v7.php` is now a second such file, with the same discipline.
- **N-2 — plan §12's AC-2 evidence table is stale.** It records 299/1331 and seven changed files
  and says it "should be pasted into `docs/qa/T-004-…md` as-is". Pasting it now would be wrong:
  the tree is **304/1358** across nine changed files. This report's own table supersedes it. For
  `architect`, not a defect in the work.
- **N-9 — a permanent lint for R-1's property is worth a backlog row, and I deliberately did not
  build one.** "No teardown step may be able to throw before a later teardown step restores
  something" is now true repository-wide and proven by a sweep with a negative control, but it is
  proven *once*, by a script in a scratch directory, not guarded. A standing guard needs one level
  of call-graph reasoning and an exemption list for fixture builders (`qaTrustFixture()`,
  `trustFixture()`), which is real machinery — the same shape as `toolchainGroupFiles()` plus its
  exemptions. Three independent readers have now found one instance of this family each
  (BUG-3, then its sibling in my own file, then R-1), which is the argument for the row.
- **N-3 — AC-1's artefact was never preserved by `dev`.** `services/host/storage/app/` is
  git-ignored, so the baseline JUnit and its itemised list are gone. The durable artefact is now
  the addition/removal tables in this report, rebuilt from git. Cheap process fix for later
  tasks: write the baseline enumeration into the `[CODE]` hand-off, not only into
  `storage/app/`.
- **N-4 — FP-164 remains per-file discipline, now with a backlog row.** Negative control
  reproduced this run: a kernel Feature file that calls `tenancyBootFixture()` without the
  `require_once` dies with `Call to undefined function`. The five current files are correct;
  nothing stops the sixth. `docs/backlog.md` FP-166 now covers it, which closes run 1's residual
  as a tracked item rather than an unknown.
- **N-5 — the flake changed shape rather than disappearing.** `deleteFixtureDirectory()` throws
  from `afterEach`, so a Windows delete that loses the race for more than ~550 ms now turns the
  *current* test red with a message naming the directory, instead of silently poisoning the next
  test. That is the intended, louder direction, and `FixtureDirectoryTeardownTest` pins it — but
  the gate can still in principle go red for a reason that is not the change under test.
- **N-6 — one residual in my own file, kept deliberately.** In
  `QaRegenerationGuardTest.php:227-231`, if `tryDeleteFixtureDirectory($demo)` fails, the
  moved-aside copy at `storage/app/qa-crlf-aside` is left on disk and the next run clears it at
  line 190 before using it. No committed content can be lost on that path, because
  `qaRestoreCrlfBackup()` restores every original byte from an independent backup in the outer
  `finally` and the assertion after it compares raw hashes. Deleting `$aside` unconditionally
  would be strictly worse, so I am leaving the shape as it is rather than aligning it further.
- **N-7 — `FixtureDirectoryTeardownTest` asserts half of what its comment claims.** The comment
  reads *"the file is removed, the directory survives"*; only the directory half is asserted.
  The file half is a true platform fact (I measured it), so nothing passes falsely — but one
  extra `expect(File::exists($root.'/held.txt'))->toBeFalse()` would make the fixture prove it
  reproduces the real shape. Suggestion for `dev`, not a defect.
- **N-8 — `CLAUDE.md` is still modified by an agent.** Two rows added to the command table. The
  content matches my measurements and is now correctly labelled *warm* with a caveat, but the
  plan said that file is the human's. Plan §12 follow-up 1 is still open; I have not touched it.
- **Three teardowns still use the raw `File::deleteDirectory()`**
  (`HostTestSuiteCollectionTest`, `QaHostSuiteCollectionProofTest`,
  `QaTestCodeAnalysisCoverageTest`). All three target `storage/app` or `tests/Integration` probe
  directories rather than a `services/<name>` package, so `svc:make`'s "already exists" refusal
  cannot be triggered by them; a lost race there leaves an empty directory, which is harmless.
  Re-checked this run and still true.

## Worktree state

`git status --porcelain` is **62 entries**: 44 tracked modifications/deletions and 18 untracked.
Of those 18, **17 are T-004 deliverables, docs or agent memory — and one is BUG-6**,
`services/host/.deptrac.cache`, which I left in place on purpose so the `.gitignore` gap stays
visible. **That file must not be committed.** Everything else matches the state I inherited.

My own edits in this round are confined to files I own:
`services/host/tests/Feature/QaRegenerationGuardTest.php` (R-1) and
`docs/qa/T-004-gate-speed-and-hygiene.md`, plus two agent-memory files. I added no file and
deleted none.

No stray fixture directories under `services/` (only `demo` and `host`);
`services/host/storage/app/` holds only `.gitignore`, `private`, `public`; `bootstrap/cache/` holds
only `packages.php` and `services.php`; `services/host/.env` remains git-ignored. Every file I
mutated for a probe (`README.md`, `config/servicehost.php`, `routes/web.php`, `tests/Helpers.php`)
was restored and verified with `md5sum -c`; every probe file I planted (`QaDecayProbeTest.php`,
`QaFp164ProbeTest.php`) was deleted. `services/demo` was moved aside and restored, re-verified
byte-identical on all 11 files. Nothing was committed and nothing was pushed.

The AC-17…AC-20 isolation runs and the 41 flake repetitions were measured on the 2026-10-01 tree.
Neither the BUG-4 fix nor the R-1 fix touches any file under `packages/kernel/tests` or
`ServiceRegistryManifestTrustTest`, and `full2.xml` confirms every one of those files reports
exactly the same case and assertion counts as before (12/63, 10/40, 24/79, 15/63, 7/35, 12/71), so
those results carry forward unchanged rather than being re-asserted on trust.

---

Run 1: FAIL — 1 critical (nothing committed, so AC-9/AC-10 unmet and a fresh clone was still broken), 2 minor (a tautological uniqueness assertion; a teardown that could skip restoring `services/demo`). 18/20 AC pass.
Run 2: FAIL -> PASS — all 20 AC pass, re-verified from scratch by a second `qa` agent. Two `composer check` runs exit 0 (the second after every probe was restored), 5 full Pest runs, 11 fast, 1 slow, 25 single-file repetitions, 12 isolation runs — 0 failures anywhere. BUG-1/2/3 independently confirmed fixed against real committed history (88 → 0 CRLF in a fresh clone, 11/11 raw-identical regeneration, the backoff lower bound mutation-tested and it does catch the fake fix). Coverage proven by name from my own JUnit logs and from git: 250 + 53 = 303, 927 + 425 = 1352, union = full, intersection empty, 0 per-case assertion mismatches, and exactly two differences across all 40 surviving test files. 40 executions of the file that flaked, 0 failures. Gate exit 0 at 303 passed / 1352 assertions. Two minor defects remain: BUG-4 (a teardown comment claiming a byte comparison the code does not make) and BUG-5 (`docs/backlog.md` still states the disproved `route:cache` premise and still marks FP-155/156/157 `TODO`).
Run 2b (2026-10-02, post-review): PASS re-certified against the tree actually being committed — **304 passed / 1358 assertions**, `composer check` exit 0, 251 + 53 = 304 and 933 + 425 = 1358, union = full, intersection empty, 0 per-case assertion mismatches over 304 cases, 3 named differences across the 40 surviving files (2 additions, 1 declared rename), reconciling to the same 279 / 1224 baseline. Reviewer **R-1 fixed** in `QaRegenerationGuardTest.php` (both teardown helpers made non-throwing, hook restores first and reports last) and the family **closed** by a property-based two-level sweep: 0 hits over 74 teardown blocks in 52 files, with a negative control proving the sweep reports R-1's pre-fix shape. **BUG-4 verified fixed** and pinned by a new 6-assertion case. **BUG-5** still with the lead. **New: BUG-6** — untracked, un-ignored 1 MB `services/host/.deptrac.cache` in the tree about to be committed; must not be committed, needs `/.deptrac.cache` in `services/host/.gitignore`.
