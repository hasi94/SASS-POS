# QA Report: T-002

Task: Service host hardening from the T-001 review (FP-146 … FP-153)
Branch: `ai/T-002-service-host-hardening` · Spec: `docs/specs/T-002-service-host-hardening.md`
Plan: `docs/plans/T-002-service-host-hardening.md` · Decisions: `docs/decisions/T-002-service-host-hardening.md`
Environment: PHP 8.2.12 (`E:\xampp\php\php.exe`), Composer 2.x, gate `cd services/host && composer check`

## Verdict: FAIL (run 3) — one criterion, AC-14, awaiting `dev`

18 of 19 acceptance criteria pass. **AC-14 fails.** It requires the root README to match
"exactly what `svc:make`'s own printed reminder says", and today the README names
`../billing/src` **and** `../billing/tests` while the reminder names only
`services/<name>/src`. That is the reviewer's finding; run 2 recorded AC-14 as PASS because my
parity test compared only three tokens and never checked `tests` — a real miss on my part, now
closed by an automated assertion. `dev` owns the reminder string, so the criterion stays red
until that lands.

Everything else is green, including the reviewer's R-1 knock-on in a file I own, which is fixed
and proven against a real CRLF working tree below. Nothing is red by choice.

```
composer check  ->  exit 1
  pint      {"tool":"pint","result":"passed"}
  phpstan   [OK] No errors
  deptrac   Violations 0 / Skipped 0 / Errors 0
  pest      Tests: 1 failed, 147 passed (744 assertions), 0 skipped
            failing: QaDocumentationAccuracyTest
                     > "says the same thing svc:make prints, so the two cannot drift again"
                     To contain: tests   (in the reminder svc:make actually printed)
```

The single failure is a test pinning a live production defect, behaving correctly. Fix needed
in `services/host/app/Console/Commands/SvcMakeCommand.php:123`: the reminder must name
`services/%s/src` **and** `services/%s/tests`, matching the README's own two bullets.
`composer check` returns to exit 0 when it does — confirmed by running the rest of the suite,
which is green.

Counting: T-001 ended at 74 passed / 222 assertions. `dev`'s T-002 work took that to
99 / 354; QA run 1 added 35 tests (134 / 627); `dev`'s BUG-1/BUG-3 fixes added 3 (137 / 651,
verified by the lead); QA run 2 added 10 (147 / 724); QA run 3 adds 1, for 148 tests and 744
assertions. No pre-existing test was removed, renamed away or silently skipped at any point.

Step 9 (FP-152, AC-18) was implemented by `qa` as assigned; see "Step 9" below.

## Acceptance criteria coverage

Every row was proven by causing the failure the criterion exists to prevent, not by reading
the code. `<pest>` below means `cd services/host && php vendor/bin/pest --filter=<name>`;
all of these also run inside `composer check`.

| AC | Test(s) / command run | Result |
|----|-----------------------|--------|
| AC-1 | `QaManifestTrustAttackTest` → "refuses an autoloadable class that is not a service provider without ever constructing it". Manifest points at the package's own `PingController`; asserts `UnknownServiceException` (**catching `Throwable`**, so a `TypeError` escaping the container would fail the test), message names the class + "is not a Illuminate\Support\ServiceProvider" and is **not** the "could not be autoloaded" message, `class_exists()` on the named class is **true** (so the type check, not a failed autoload, stopped it) and no `Qatrustfixture` provider is in `getLoadedProviders()`. Run 2 adds the shapes AC-1's wording does not reach: "refuses a provider that passes the type check but cannot be constructed" (abstract / private ctor / protected ctor) and "refuses every other shape a provider key can name" (enum / trait / interface), each asserting its own message and the absence of the other two. Also `ServiceRegistryManifestTrustTest` (dev) | PASS |
| AC-2 | `QaManifestTrustAttackTest` → "never donates a source directory to another service package namespace" and "never extends the host own App namespace…". Each plants a real class file under the claimed namespace, then asserts after the refused boot: (a) `UnknownServiceException` naming service + declared + allowed namespace, (b) `getPrefixesPsr4()` has no such key / `App\` is byte-identical to before, (c) the planted class is still not `class_exists()`, (d) the payload was genuinely live — the same file resolves through a throwaway `ClassLoader` with that prefix added, so (c) is caused by the missing registration and nothing else. Run 2 adds the multi-key shapes closed by the BUG-1 fix: entitled-key-first, foreign-key-first, neither-entitled, empty map, numeric key and a repeated JSON key — each asserting the specific message **and** the no-prefix invariant. Also `ServiceRegistryManifestTrustTest` (dev) | PASS |
| AC-3 | `QaManifestTrustAttackTest` → "refuses a provider declared outside the package own namespace and registers nothing for it": correct namespace, provider `Flexipos\Service\Sales\QatrustfixtureServiceProvider` → exception naming the provider, "outside its own namespace" and the required prefix; `Flexipos\Service\Sales\` still absent from `getPrefixesPsr4()`; service not booted | PASS |
| AC-4 | (b) `QaManifestTrustAttackTest` → "rejects a trailing newline in the shared name pattern itself": `preg_match(ServiceRegistry::NAME_PATTERN, …)` returns `0` for `"billing\n"`, `"demo\n"`, `"http-client\n"`, `"billing\r"`, `"billing\r\n"`, `"billing\n\n"`, `"billing\nhost"` and `1` for the valid forms. (a) "rejects a trailing newline at the real command line and creates nothing": a real child process `php artisan svc:make "billing\n"` → exit 1, `Invalid service name [billing⏎]`, output proves the child received the newline, `services/` directory listing unchanged. Also `SvcMakeValidationTest` (dev) dataset "trailing newline" | PASS |
| AC-5 | `QaAtomicGenerationProofTest` → "leaves nothing behind when generation fails on an early file…": the **second** of the six stubs is moved away (dev's own test fails on the last one), so `composer.json` is genuinely written before the failure → exit ≠ 0, `services/qaatomicfixture/` absent, `composer.json` absent, `File::directories(servicesPath())` identical to before. Also `SvcMakeAtomicGenerationTest` (dev) | PASS |
| AC-6 | Same test: after restoring the stub, `svc:make qaatomicfixture` → exit 0, output does **not** contain "already exists"; the package is then booted into the running host and `GET /api/v1/qaatomicfixture/ping` → 200, `/health/ready` → `["demo","qaatomicfixture"]`. Happy-path file set compared against the committed `services/demo` (studly segment normalised) → identical | PASS |
| AC-7 | `QaHealthProbeResilienceTest`. `SESSION_DRIVER=database` is confirmed to be the shipped `.env.example` value; the default connection is repointed at an sqlite file in a directory that does not exist. **Negative control first**: a route registered inside the `web` group under the same configuration → HTTP 500. Then `/health/live` → 200 `{"status":"ok"}`, `application/json`. Also "sets no session cookie on either probe" | PASS |
| AC-8 | Same test, dead-database cases for `/health/ready` → 200 `{"status":"ok","services":["demo"]}` and, with `SERVICES=""`, 200 `{"status":"ok","services":[]}`. Also `HealthEndpointTest` (dev) | PASS |
| AC-9 | `QaCacheGuardBranchesTest` → "stays silent for every ambient value that parses to the frozen list, and fails for every one that does not". Real child processes: `config:cache` with `SERVICES=demo,qacachefixture`, then `route:list` with ambient `demo` (subset), `demo,qacachefixture,billing` (superset), `billing` (unrelated) and `""` (explicitly empty) → each exits ≠ 0 and names **both** values plus `config:clear`. Also `ConfigCacheGuardTest` (dev) | PASS |
| AC-10 | Same test, the legitimate cases: ambient identical, **different order**, surrounding whitespace, duplicated entry, trailing separator → all exit 0 with no guard message; ambient variable absent entirely → exit 0. Plus "never fires while the configuration is not cached" → an unknown service still fails with the ordinary `Unknown service "billing"` message, never the cache message | PASS |
| AC-11 | `QaCacheGuardBranchesTest` → "documents all four caching and autoloader constraints in one findable place": `config/servicehost.php` **and** root `README.md` each name `config:cache`, `mergeConfigFrom`, `route:cache` and `--classmap-authoritative`, each says which is guarded and which is documented only; exactly 3 "documented only" labels in the config; the named method `guardAgainstStaleCachedServices` exists in `ServiceHostServiceProvider` | PASS |
| AC-12 | `QaHostSuiteCollectionProofTest` → "collects a new host test directory only because the Host suite is recursive". A probe is written to `tests/Integration/`; a child run against a **generated copy of `phpunit.xml` in the pre-T-002 shape** (literal `tests/Unit` + `tests/Feature` suites) reports `No tests found`; the same probe against the shipped config → `Tests: 1 passed`, exit 0. Probe and temp config deleted. Also `HostTestSuiteCollectionTest` (dev) | PASS |
| AC-13 | Same file → "proves a nothing-matching filter still performs the whole collection, then that collection is duplicate free". **`dev`'s deviation verified, not accepted**: against a generated config with `<exclude>../host/tests</exclude>` removed and the *same* nothing-matching filter, PHPUnit **does** emit `Cannot add file … as it was already added to test suite "Host"` together with `No tests found` — so collection demonstrably precedes filtering and dev's filtered run is a valid way to assert the warnings' absence. Against the shipped config: no duplicate warning, no `WARN`. Third test confirms `tests/Unit` and `tests/Feature` are both still collected | PASS |
| AC-14 | `QaDocumentationAccuracyTest` → "no longer tells a developer that no file inside the host ever changes": every occurrence of the sentence now carries the qualifier "for that"; the README names `phpstan.neon.dist`, `deptrac.yaml`, `Service_Billing`, "ruleset entry" and `QaToolchainRegistryCoverageTest`. Plus "says the same thing svc:make prints": the generator's real printed reminder and the README are asserted to name the same registries, layer, ruleset entry **and both directories** (`src` and `tests`) — the `tests` token was added in run 3 after the reviewer found the drift this test had missed | **FAIL** (see verdict; `dev` owns the reminder string) |
| AC-15 | Same file → "asserts no MIT licence anywhere in first-party code or documentation": `services/host/README.md` contains no `\bMIT\b` and states proprietary; all 9 first-party `composer.json` files declare `"license": "proprietary"`; a Finder sweep of everything committed under `services/` and `packages/` (minus `vendor`, `node_modules`, `storage`, `public`, `composer.lock`) finds no `\bMIT\b`. The single remaining hit in the repository is the inlined `/*! tailwindcss v4.0.7 \| MIT License \| … */` banner in the stock welcome view — a third-party asset's own header, not a claim about this codebase, matched narrowly rather than by skipping the file | PASS |
| AC-16 | `QaTestCodeAnalysisCoverageTest` → "fails PHPStan on a real level 6 defect planted inside a service test directory". A probe in `services/demo/tests/Feature/` containing an untyped parameter **and** `$this->get()` → `composer stan` equivalent exits 1 naming the file, `…has parameter $value with no type specified` (a level-6-only finding) and `Call to an undefined method PHPUnit\Framework\TestCase::get()`. The same file rewritten the way the stub now writes it → exit 0. Probe removed. Configuration half asserted too (`../demo/tests` in `paths`, and required for every service package that ships tests) | PASS |
| AC-17 | Two proofs. (a) `DeptracBoundaryEnforcementTest` (dev): a probe class under `Flexipos\Service\Demo\Tests` in `services/demo/tests/` importing `App\…` → the **shipped** `deptrac.yaml` exits ≠ 0 naming `Service_Demo`, `HostApp`, "must not depend on"; removed → `Violations 0`. (b) `QaTestCodeAnalysisCoverageTest` → "reports a boundary violation when one service test file imports another service namespace": AC-17 to the letter (service→service, from a `tests/` directory) on an isolated two-service fixture, because the shipped config has only one service layer today; the identical fixture **without** the tests path — the pre-T-002 state — reports `Violations 0`, which is the negative control | PASS |
| AC-18 | **Implemented by `qa` (Step 9).** `QaToolchainRegistryCoverageTest` → "does not skip a legitimately named service just because its name contains the word fixture": a real `services/data-fixtures` package generated by the real generator is **not** skipped, and all three registry checks report it (`phpstan.neon.dist` paths, `deptrac.yaml` paths + layer, and `phpunit.xml` correctly reports no gap because its suites are globbed). Companion test proves the throwaway names are still skipped by exact match | PASS |
| AC-19 | `QaRegenerationGuardTest` → "turns red and names the file when a stub drifts from the committed demo package": `routes.api.php.stub` is deliberately edited, a child run of `SvcMakeDemoRegenerationTest` exits ≠ 0 with `1 failed` and prints `routes/api.php`; stub restored (checksum re-verified); `services/demo` fingerprint identical to before, proving the **failing** run still restored the committed tree; re-run → `Tests: 1 passed`, no leftover backup directory. Run 3 adds "survives a services/demo working tree checked out with CRLF line endings" (reviewer R-1): the tree is rewritten to CRLF, the real regeneration test still exits 0, and a two-hash negative control shows raw hashing would have reported exactly the six rewritten files while normalised hashing reports none . Run 3 adds "survives a services/demo working tree checked out with CRLF line endings" (reviewer R-1): the tree is rewritten to CRLF, the real regeneration test still exits 0 in a child run, and a two-hash negative control shows raw hashing reports a difference for exactly the six rewritten files while normalised hashing reports the trees identical | PASS |

**Totals (run 3): 18 PASS, 1 FAIL (AC-14).**

## Step 9 — implementation (FP-152 qa half, AC-18, D-23)

`services/host/tests/Feature/QaToolchainRegistryCoverageTest.php`: the
`str_contains($name, 'fixture')` skip is replaced by `in_array($name, qaFixturePackageNames(), true)`,
an exact list of the eleven throwaway package names the suite generates — including `dev`'s new
`trustfixture` and `atomicfixture`, and `qa`'s new `qatrustfixture`, `qacachefixture`,
`qaatomicfixture` and `qadocfixture`. The list is documented with the file that owns each name.

Choice of mechanism (D-23 left this to `qa`): an exact list rather than a reserved prefix,
because the failure direction matters more than the convenience. If someone adds a test that
generates a package under an unlisted name, the guard goes **red mid-run** — loud, immediate and
attributable. A prefix convention fails the other way: a package that happens to match the
prefix is exempted silently, which is the exact defect AC-18 exists to remove. The maintenance
cost (one line per new fixture name) is noted in the file's own docblock and in "Regression
risks" below.

Two of the three coverage checks were refactored into `qaPhpstanGaps()` / `qaPhpunitGaps()`
helpers (alongside the existing `qaDeptracGaps()`), so the AC-18 probe asserts through exactly
the same code the guard tests assert through — a probe that used its own copy of the logic
would prove nothing about the guard.

## Rulings on the two items `dev` raised

### 1. `QaServiceRegistryContractTest`'s fixture no longer tested what its name claimed — confirmed, fixed

`dev` was right, and was right not to touch a `Qa*.php` file. The case
`it('fails fast when the named provider class cannot be autoloaded')` declared namespace
`Qa\Broken\`, which after T-002 fails at the new namespace-trust check (AC-2), several lines
before `class_exists()`. The test still passed because it only asserted the service name
appeared in the message.

Fixed in place by `qa`: a `QA_BROKEN_NAMESPACE` constant scopes every fixture in that file to
`Flexipos\Service\Qabrokenfixture\`, the namespace its own directory name earns it, so each case
still fails at the branch its description names. The autoload case now asserts the exception's
`service()`, that the message contains the provider FQCN and `could not be autoloaded`, **and
that it does not contain `may only declare` or `outside its own namespace`** — so it can never
again slide to an earlier check and stay green. The other four cases gained their own specific
message assertions (`no service package found at`, `is not valid JSON`,
`declares no autoload.psr-4 namespace`, `declares no extra.flexipos.provider class`) for the
same reason. The unused `$withProvider` parameter and its dead `Qa\Broken\Provider` file were
removed.

This follows the rule that cost a round in T-001: assert the decided contract (D-1: nothing is
silently skipped; every failure names the offending service), not the symptom observed.

### 2. The stub that ships into every future service — the fix is real, and the two copies are identical

Both halves verified independently of `dev`'s own tests.

**Real, not cosmetic.** Planting `$this->get('/api/v1/demo/ping')` inside a Pest closure in
`services/demo/tests/Feature/` makes the shipped PHPStan configuration exit 1 with
`Call to an undefined method PHPUnit\Framework\TestCase::get()` — reproduced by hand first and
now pinned by `QaTestCodeAnalysisCoverageTest`. The same file written with
`use function Pest\Laravel\get;` exits 0. So the error was genuine, level-6 coverage of test
directories is what surfaced it, and the helper-function form is what removes it — no
suppression, no baseline, no weakening of the analysis. Deleting the probe returns
`composer stan` to `[OK] No errors`.

**Identical.** `QaTestCodeAnalysisCoverageTest` → "keeps the generated test stub and the
committed demo copy identical" renders `stubs/service/PingRouteTest.php.stub` with the same six
replacements `SvcMakeCommand` uses and asserts byte equality with
`services/demo/tests/Feature/PingRouteTest.php`, that no `{{placeholder}}` survives, that the
helper import is present and that no `$this->get(` **call statement** remains (the docblock
still names it, deliberately, which is why the match is line-anchored). AC-19's regeneration
test covers the same invariant from the other direction, and `QaRegenerationGuardTest` proves
that test actually goes red when a stub drifts.

## T-001 regression results

Every T-001 criterion the spec names under a "Regression" line, re-proven on this tree:

| T-001 AC | How re-proven now | Result |
|---|---|---|
| AC-3 (README purpose, layout, three links) | `QaDocumentationAccuracyTest` → "states the purpose, the layout and the three links T-001 AC-3 requires" | PASS |
| AC-9, AC-12, AC-14 (lint / stan / deptrac green on the shipped tree, now including test directories) | `composer check` → pint passed, `[OK] No errors`, `Violations 0`, exit 0 | PASS |
| AC-16 (package and generated-service test discovery) | `QaToolchainRegistryCoverageTest` collection check; `QaGeneratedServiceTestDiscoveryTest`; `QaHostSuiteCollectionProofTest` → both original host directories still collected | PASS |
| AC-18, AC-21 (only the named service boots; whitespace/duplicates collapse) | Real process: `SERVICES=" demo , demo " php artisan route:list` → exactly `api/v1/demo/ping`, `health/live`, `health/ready`, exit 0. Plus `ServicesEnvParsingTest` | PASS |
| AC-19 (boot fails fast naming the offending entry) | Real process: `SERVICES="demo,ghost" php artisan route:list` → exit 1, `Unknown service "ghost" listed in SERVICES: no service package found at …\services\ghost.` Plus `QaServiceRegistryContractTest` (5 malformed-manifest paths, each now asserting its own message) | PASS |
| AC-20, AC-22, AC-23 (exact `/health/ready` JSON shapes, unaffected by leaving the `web` group) | `HealthEndpointTest`, `QaServiceRegistryContractTest`, `QaHealthProbeResilienceTest` — `{"status":"ok"}` and `{"status":"ok","services":[…]}` unchanged, route names `health.live` / `health.ready` unchanged. Hostile entries still fail fast: real process `SERVICES="demo,Demo"` → exit 1 naming `Demo` | PASS |
| AC-24 (unlisted package is not routed) | `UnlistedServiceReturns404Test` | PASS |
| AC-25, AC-26 (generated package complete and passing out of the box) | `SvcMakeValidationTest`, `QaGeneratedServiceTestDiscoveryTest`, and `QaAtomicGenerationProofTest` boots a freshly generated package and serves its route | PASS |
| AC-27 (generator writes nothing inside `services/host`) | `SvcMakeGeneratedServiceReachableTest` (happy path) **and** `QaAtomicGenerationProofTest` → "writes nothing inside services/host on the failure path either": md5 fingerprint of every file under `services/host` (stubs excluded, since this test borrows one) identical across a failed generation and its rollback | PASS |
| AC-28 (invalid names rejected, now including a trailing newline) | Real CLI: `Bad Name!`, `billing-`, `bill--ing` → exit 1 each, nothing created; `svc:make "billing\n"` → exit 1. Plus `SvcMakeValidationTest`, `QaServiceNameValidationTest` | PASS |
| AC-29 (existing package refused, untouched) | Real CLI `svc:make demo` → exit 1, `already exists. Nothing was created or modified.`; `QaAtomicGenerationProofTest` → "refuses a genuinely existing package before writing or deleting anything" compares the file list and the manifest checksum, because T-002 put a `deleteDirectory()` on that code path | PASS |
| AC-30 (reserved name) | Real CLI `svc:make host` → exit 1, `reserved name: services/host is the service host application itself…` | PASS |
| AC-31 (demo regenerates byte-identical) | Now automated: `SvcMakeDemoRegenerationTest`, itself verified by `QaRegenerationGuardTest` | PASS |
| AC-32 (demo ping route + `svc_demo` connection) | `ServiceRegistryManifestTrustTest`, `services/demo` own `PingRouteTest`, and real `route:list` | PASS |
| Suite isolation (T-001 BUG-4) | Full suite re-run with a hostile ambient variable exported: `SERVICES=ghost php vendor/bin/pest` → `Tests: 134 passed (627 assertions)`, exit 0 | PASS |

## Test run summary

- Commands:
  - `cd services/host && composer check` (lint → stan → deptrac → test) — **exit 0**
  - `cd services/host && composer lint` / `stan` / `deptrac` run individually — exit 0 each
  - `SERVICES=ghost php vendor/bin/pest` — exit 0, 134 passed
  - real-process spot checks: `php artisan route:list` under five `SERVICES` values,
    `php artisan svc:make` with `demo`, `host`, `Bad Name!`, `billing-`, `bill--ing`, `"billing\n"`
- Passed / failed / skipped: **147 passed (724 assertions) / 0 failed / 0 skipped** (run 2;
  run 1 ended at 134 / 627)
- New tests added by this report: 45 — 35 in run 1, in 8 new `Qa*.php` files plus 2 added cases
  in `QaToolchainRegistryCoverageTest`; 10 more in run 2, all in `QaManifestTrustAttackTest`,
  covering the BUG-1 and BUG-3 fixes
- Every fixture is removed in an `afterEach` **and** in a `finally` inside the test body.
  Verified after the full run: `services/` contains only `demo` and `host`;
  `bootstrap/cache/` contains only `packages.php` and `services.php` (no `config.php`);
  no `phpunit.qa-*.xml`, no `tests/Integration/`, no probe files under `services/demo/tests/`,
  no `storage/app/qa-*` or `demo-regen-backup` directories. `git status` shows only files
  owned by `dev` and the `Qa*.php` files owned by `qa`.

## Run 3 — reviewer findings landing in `qa`-owned files

### R-1 knock-on — `QaRegenerationGuardTest` hashed raw bytes → **FIXED, and proven**

The reviewer's claim was verified before being acted on, not taken on trust:
`git config core.autocrlf` → `true`; `find . -name .gitattributes` → exactly one,
`services/host/.gitattributes` (`* text=auto eol=lf`), whose scope is that directory only;
every file in `services/demo` is currently LF **because it was generated and never
re-checked-out**. So on a fresh clone, or after switching branches and back, git writes CRLF
into `services/demo` while `svc:make` still writes LF, and any raw-byte comparison between the
two goes red for someone who changed nothing.

Fixed as directed: `qaTreeFingerprint()` now hashes
`md5(str_replace("\r\n", "\n", file_get_contents($path)))`, the same shape
`QaTestCodeAnalysisCoverageTest` already uses on both sides. A second helper,
`qaRawTreeFingerprint()`, keeps raw hashing for the one comparison where exact bytes *are* the
claim — "the test moved `services/demo` aside and put it back untouched" — because comparing a
tree against itself is safe byte for byte, while comparing generated output against a
checked-out tree is not. Both the drift test and the new one assert restoration with the raw
map, so the fix did not cost that assertion its teeth.

**Proven, not argued** — new test, "survives a services/demo working tree checked out with CRLF
line endings":

1. Backs up every file in `services/demo` byte for byte to `storage/app/qa-crlf-backup`, then
   rewrites all six text files LF → CRLF, reproducing a fresh-clone working tree.
2. Confirms the state is real: every rewritten file's **raw** hash changed, while the
   **normalised** map is unchanged.
3. End to end: runs the real `SvcMakeDemoRegenerationTest` in a child process against that CRLF
   tree → exit 0, `Tests: 1 passed`.
4. **Negative control**, which is what makes step 3 evidence rather than a coincidence: performs
   that test's own comparison in-process, with both hash functions, against a genuinely
   regenerated tree (demo moved aside, `svc:make demo`, compare, restore). Raw hashing — the
   shape R-1 reported — reports a difference for **exactly** the six rewritten files; normalised
   hashing reports the two trees identical. So the hazard was real and normalisation is what
   neutralises it, demonstrated without touching `dev`'s copy of the fix.
5. Restores the original bytes in a `finally` **and** in an `afterEach`, then asserts the raw
   fingerprint equals the one taken at the start. Verified afterwards: all 11 files back to LF,
   `git status` clean on `services/demo`, no `storage/app/qa-*` left.

The durable fix is a root `.gitattributes` (FP-156), deliberately not in this round; until it
lands, normalised hashing is what keeps the gate honest on a fresh clone.

### Reminder/README parity — token loop widened → **now catches the drift (currently red)**

`QaDocumentationAccuracyTest` compared only `phpstan.neon.dist`, `deptrac.yaml` and `ruleset`,
so it missed that the reminder names only `src` while the README names `src` and `tests`. The
loop now also requires `src` and `tests` in both texts, and additionally asserts the reminder
names `services/<name>/src` and `services/<name>/tests` as paths rather than merely containing
the words. That is red today against the unfixed reminder, with the message
`To contain: tests` — the assertion doing exactly its job. See the verdict block for the
one-line fix `dev` needs.

## Re-test of the run-1 defects (run 2)

`dev` fixed BUG-1 and BUG-3 in `services/host/app/Support/ServiceHost/ServiceRegistry.php`.
Both were re-tested against the shapes `dev` verified **and** against the awkward ones, and
the full suite was re-run. `qa` changed no production code.

### BUG-1 — psr-4 map must hold exactly one pair → **FIXED, verified**

`psr4()` now takes the expected namespace, refuses any map whose `count()` is not 1 before
anything is registered, and names the unentitled keys specifically. Verified:

| Manifest shape | Result |
|---|---|
| entitled key first + `App\ => src/` | refused: `declares 2 autoload.psr-4 namespaces, but a service package must declare exactly one, [Flexipos\Service\Qatrustfixture\]. Remove [App\].` `App\` prefix byte-identical afterwards; planted `App\QaSmuggledBySecondSlot` still unloadable while the same file *does* resolve through a throwaway loader — so the payload was real and the registration is what stopped it |
| foreign key **first** (`Flexipos\Service\Sales\`) + entitled key second | refused with the count message and `Remove [Flexipos\Service\Sales\].` — the advice names the foreign key, **not** the package's own, confirming the "names the unentitled keys" claim rather than "everything after the first". Asserted to *not* contain `Remove [Flexipos\Service\Qatrustfixture\]` |
| two keys, neither entitled | `Remove [Flexipos\Service\Sales\], [App\].` — both named |
| empty map `{}` | still the older, more specific `declares no autoload.psr-4 namespace`; asserted **not** to contain `must declare exactly one`, so the count check did not swallow it |
| numeric key `{"0":"src/"}` | still `declares a malformed autoload.psr-4 namespace` |
| duplicated JSON key | `json_decode` keeps the last value, so a repeated key never reaches the count check as two entries. Sound rather than a hole: a repeated key is the same namespace twice, so there is no second namespace to smuggle. A genuinely different second key alongside the duplicate *is* counted and refused, which the test asserts |

**Ordering change confirmed, not assumed.** The count check lives in `psr4()`, which runs
before the namespace-equality check, so a two-entry map whose foreign key is listed first now
reports the count message instead of `may only declare`. `dev` was right that my existing tests
absorb this — they assert the exception type and the no-prefix invariant — but "it still
passes" is exactly how a case stops testing what its name claims, which is the drift `dev`
found in my own file last round. Both affected tests now **pin the specific message**, so the
next reordering cannot pass silently. Single-key fixtures (mine and `dev`'s, in four files)
are unaffected and still report `may only declare`; confirmed green in the full run.

### BUG-3 — provider must be instantiable → **FIXED, verified**

A second check using `ReflectionClass::isInstantiable()` sits after `is_a()`, so the two
mistakes stay distinguishable. Verified, each asserting its own message **and** the absence of
the other two, plus that the service is not in `booted()`:

| Provider named in the manifest | Result |
|---|---|
| abstract subclass of `ServiceProvider` | `cannot be instantiated (it is abstract, or its constructor is not public)` — was a raw `Error: Cannot instantiate abstract class` that never named the service |
| class with a **private** constructor | same message |
| class with a **protected** constructor | same message |
| enum (is a class, so it reaches the type check) | `is not a Illuminate\Support\ServiceProvider`, **not** `cannot be instantiated` |
| trait | `could not be autoloaded` (`class_exists()` is false for traits), **not** `cannot be instantiated` |
| interface | `could not be autoloaded`, same reason |

Every one of the nine shapes above fails fast with an `UnknownServiceException` naming the
service. No shape reaches the container, and none produces a raw PHP `Error` any more.

### BUG-2 — **still open, deliberately: out of T-002's scope**

Unchanged and not expected to change here. See the entry below; it belongs to the T-001
review's Minor list (plan risk R-6) and wants a backlog row, not a T-002 fix.

## Defects

Status after run 2: **BUG-1 fixed, BUG-3 fixed, BUG-2 open (out of scope).** None of the three
ever failed an enumerated acceptance criterion; all were found by exploratory testing around
the criteria. Production code is `dev`'s — `qa` changed none of it.

### BUG-1 (minor) — **FIXED in run 2** — a manifest may declare a second PSR-4 namespace it is not entitled to, and boot succeeds silently

- `BUG-1 | AC-2 | failing test: none (behaviour pinned by services/host/tests/Feature/QaManifestTrustAttackTest.php → "registers nothing for a second psr-4 entry the package is not entitled to") | expected: a manifest declaring `{"Flexipos\Service\Qatrustfixture\":"src/","App\":"src/"}` is refused with an UnknownServiceException, per plan judgement call 1 ("the manifest's **single** autoload.psr-4 key is exactly …") | actual: the package boots normally; only `array_key_first($map)` is validated and registered, the second entry is silently ignored`
- Steps: generate a package, rewrite `autoload.psr-4` to two keys with the *entitled* namespace
  first and `App\ => src/` second, plant `src/QaSmuggledBySecondSlot.php` declaring `namespace App;`,
  boot it.
- Expected: boot fails naming the service and the namespace it may not declare.
- Actual: `BOOTED OK`. The security half of AC-2 is intact and proven — the `App\` prefix is
  byte-identical afterwards and `App\QaSmuggledBySecondSlot` is still not loadable, while the
  same file *does* resolve through a throwaway loader with that prefix added, so the payload was
  real. The fail-loudly half of AC-2 is not enforced for this shape.
- Why it is not an AC-2 FAIL: AC-2's observable proof obligation ("the declared (mismatched)
  namespace is never registered … proven by asserting that no PSR-4 prefix was added") holds
  under test. Whether AC-2's *"declares a PSR-4 namespace that is not exactly …"* also covers a
  manifest that declares a correct key **and** an extra one is a spec-reading question, not
  something `qa` should settle silently — `ba`/`architect` should rule. `ServiceRegistry.php`
  is `dev`'s file either way.
- Latent risk: today the extra key is inert. A later, innocuous-looking refactor that iterates
  the psr-4 map instead of taking the first key would turn it into the exact M-2 attack. The
  pinning test is written as an invariant ("no prefix, no loadable class") so it stays correct
  whichever way the rule is decided, and goes red if that refactor ever lands.
- Suspected location: `services/host/app/Support/ServiceHost/ServiceRegistry.php`, `psr4()`
  (`array_key_first($map)`) — a `count($map) !== 1` check would close it.
- **Resolution (run 2):** fixed exactly that way, and the fix names the unentitled keys rather
  than "everything after the first". Re-tested across six map shapes — see
  "Re-test of the run-1 defects" above. `qa`'s pinning test was upgraded from the type-only
  invariant to the specific message, and now also covers the empty, numeric-key,
  neither-entitled and repeated-JSON-key shapes.

### BUG-2 (minor, out of T-002 scope) — a manifest may point its own namespace at any directory, including outside the package

- `BUG-2 | AC-2 (adjacent; no AC covers it) | failing test: none | expected: a service package's autoload.psr-4 target resolves inside services/<name>/ | actual: `{"Flexipos\Service\Qatrustfixture\":"../demo/src"}` is accepted and registered as `…\services\qatrustfixture\../demo/src``
- Steps: generate a package, set its psr-4 target to `../demo/src`, boot it, inspect
  `getPrefixesPsr4()`.
- Expected: rejected, or at least confined to the package directory.
- Actual: `BOOTED OK`, prefix registered at the traversed path. Because PSR-4 *includes* the
  candidate file before deciding the class is absent, a manifest can cause an arbitrary
  reachable `<dir>/<ProviderName>.php` to be executed during `class_exists()`, even though boot
  then fails with "could not be autoloaded".
- Impact is modest — an attacker able to write this manifest already controls files inside the
  package — but it is the same "trusts the manifest" family as M-1/M-2.
- Explicitly out of scope for T-002: this is the T-001 review's own Minor item
  ("`servicePath()` is public and concatenates an unvalidated string"), which plan risk R-6
  lists among the Minor items with no FP row. **Recommend a backlog row**, not a T-002 fix.
- Suspected location: `ServiceRegistry::boot()`, `$source = servicePath($name).'/'.trim($relativeSource,'/\\')`.

### BUG-3 (minor) — an abstract provider inside its own namespace still kills the process with an unnamed `Error`

- `BUG-3 | AC-1 (rationale, not letter) | failing test: none | expected: `UnknownServiceException` naming the service, as for every other unusable provider | actual: `Error: Cannot instantiate abstract class Flexipos\Service\Qatrustfixture\QaAbstractProvider` — a raw PHP Error that never names the service`
- Steps: generate a package, add `abstract class QaAbstractProvider extends ServiceProvider`
  in its own namespace, name it in `extra.flexipos.provider`, boot.
- Expected: the failure identifies the offending service, per D-1 and the spec's non-functional
  requirement that every new or changed error message names it.
- Actual: `is_a($provider, ServiceProvider::class, true)` returns `true` for an abstract
  subclass, so the check passes and `$this->app->register()` fatals.
- Why it is not an AC-1 FAIL: AC-1's precondition is a class that **does not extend**
  `ServiceProvider`; an abstract subclass does extend it, so the criterion's "Given" never
  applies. But it reproduces precisely the outcome AC-1 exists to prevent — "crash the whole
  host process with an error that does not identify the offending service".
- Suspected location: `ServiceRegistry::boot()`, the `is_a()` check — adding
  `(new ReflectionClass($provider))->isInstantiable()` would cover abstract classes,
  interfaces and private constructors in one condition.
- **Resolution (run 2):** fixed with a separately worded `isInstantiable()` check after the
  `is_a()` one, so "not a service provider" and "cannot be instantiated" stay distinguishable.
  The promised coverage is now in `QaManifestTrustAttackTest`: two datasets covering abstract,
  private-constructor, protected-constructor, enum, trait and interface providers, each
  asserting its own message and the absence of the other two.

## Regression risks / notes

- **The exact-name fixture list is now a maintenance point (by design).** A new test that
  generates a service package must add its name to `qaFixturePackageNames()`. If it does not,
  `QaToolchainRegistryCoverageTest` fails during that run. That is the backstop working, not a
  regression — the previous substring rule would have exempted a real service instead. AC-18
  required trading a silent failure mode for a loud one.
- **Two tests write real, shared state and must keep their `finally` blocks.**
  `ConfigCacheGuardTest` and `QaCacheGuardBranchesTest` both create `bootstrap/cache/config.php`,
  which every other test in the run depends on being absent; `SvcMakeDemoRegenerationTest` and
  `QaRegenerationGuardTest` both move or mutate committed content (`services/demo`, a stub).
  Each asserts a clean pre-flight state and deletes before asserting, so a failed assertion
  cannot leave the run poisoned. Both were verified in isolation and in the full suite.
- **Line endings are a live hazard until FP-156 lands.** `core.autocrlf` is `true` here and the
  only `.gitattributes` in the repository covers `services/host/` alone, so `services/demo`,
  `packages/*` and anything else outside that directory get CRLF in a fresh working tree while
  every generator writes LF. Two tests now normalise before hashing
  (`SvcMakeDemoRegenerationTest`, `QaRegenerationGuardTest`) and one proves it end to end
  against a CRLF tree, but **any new test that compares generated output with committed content
  must normalise too** — raw `md5_file()` across that boundary passes only until someone
  re-checks-out the tree. A root `.gitattributes` is the durable fix (FP-156).
- **Manifest validation order is now load-bearing and only partly pinned.** `psr4()`'s count
  check runs before the namespace-equality check, and `class_exists()` before `is_a()` before
  `isInstantiable()`. Which message a malformed manifest gets therefore depends on the order,
  and a reordering leaves every "it throws" assertion green. Every `Qa*` case that exercises a
  refusal now asserts its specific message, and several assert the *absence* of the
  neighbouring messages; keep that discipline when adding cases.
- **A trait or an interface named as a provider reports "could not be autoloaded".** Accurate
  in the sense that `class_exists()` is false for both, but the file does exist and was
  included. No action requested: it fails fast and names the service, and the message is one
  T-001 QA already pinned. Noted only so a future reader is not surprised by it.
- **AC-17's letter is only partly provable against the shipped configuration.**
  `deptrac.yaml` has exactly one service layer (`Service_Demo`), so a service→service violation
  cannot be expressed against it until a second real service exists. Proven on an isolated
  two-service fixture instead, with the shipped config proving the service→host case. When
  Phase 1 adds a second service, this is worth re-proving directly.
- **`route:cache` is still broken** by the stock closure route on `/` — deliberate, documented
  in `config/servicehost.php` and the README (AC-11 item 3, D-20), and out of scope here.
  It remains a real gap for whoever builds the container work (FP-015/FP-019).
- **The manual PHPStan/Deptrac registry remains manual.** Neither tool globs, so a new
  `services/*` package is invisible to both until someone edits two files. That is guarded by
  `QaToolchainRegistryCoverageTest` (now with the narrowed skip rule) and by `svc:make`'s
  printed reminder, and the README now says so too (AC-14) — but the edit itself is still by hand.
- **`--classmap-authoritative` has no runtime detection** (D-20, plan judgement call 5, AC-11
  item 4: documented only). If a future reviewer expects code here, the answer is the decision,
  not a silent code change.
- Untested by anything in this task, and worth keeping in view: the readiness endpoint is still
  unauthenticated and enumerates internal topology (T-001 review Minor list), and the generated
  connection still falls back to a global database user. Both are out of T-002's scope.

---
Run 1: PASS (19/19 AC, 3 minor defects raised, gate green at 134 passed / 627 assertions).
Run 2: PASS -> PASS (BUG-1 and BUG-3 fixed by `dev` and re-tested across 15 manifest and
provider shapes; BUG-2 stays open as an out-of-scope backlog recommendation; gate green at
147 passed / 724 assertions).
Run 3: PASS -> FAIL (reviewer R-1 knock-on fixed in `QaRegenerationGuardTest` and proven
against a real CRLF working tree; the widened README/reminder parity check now catches the
drift AC-14 forbids, so AC-14 is red at 18/19 until `dev` adds `tests` to `svc:make`'s printed
reminder — 1 failed / 147 passed, 744 assertions).
