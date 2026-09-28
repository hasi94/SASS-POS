# QA Report: T-001

Task: Monorepo, backend toolchain and service host skeleton (FP-003 / FP-004 / FP-005)
Branch: `ai/T-001-monorepo-service-host` · Spec: `docs/specs/T-001-monorepo-service-host.md`
Environment: PHP 8.2.12 (`E:\xampp\php\php.exe`), Composer 2.x (`C:\ProgramData\ComposerSetup\bin\composer.phar`)

## Verdict: PASS

All 32 acceptance criteria pass. The four defects raised in run 1 (BUG-1..BUG-4) are fixed
and independently re-tested; BUG-5 is mitigated and closed with a documented residual risk.
No new defects were found in run 2.

```
composer check  ->  exit 0
  pint      {"tool":"pint","result":"passed"}
  phpstan   [OK] No errors
  deptrac   Violations 0 / Skipped 0 / Errors 0 (Uncovered 23, all framework classes)
  pest      Tests: 74 passed (222 assertions), 0 failed, 0 skipped
```

Two acceptance criteria improved in substance rather than just going green: AC-16 is now a
**real** pass instead of a vacuous one (a `packages/*` test is genuinely discovered), and
AC-22's guarantee now holds under hostile input instead of only under well-formed input.

## Acceptance criteria coverage

| AC | Test(s) / command run | Result |
|----|-----------------------|--------|
| AC-1 | `ls` of repo root; `find gateway frontend print-agent plugins infra -type f` → each contains only `.gitkeep`; all 8 top-level dirs present | PASS |
| AC-2 | All 7 `packages/*` have `composer.json` + `src/` and nothing else; `composer show` lists all 7 at `0.1.0` | PASS |
| AC-3 | `README.md` + `.editorconfig` exist; README states purpose + layout table and links `[CLAUDE.md](CLAUDE.md)`, `[docs/architecture.md]`, `[docs/backlog.md]` | PASS |
| AC-4 | `stat` mtimes: `CLAUDE.md` 14:19:31, earliest dev-created file 14:40:33 — predates all T-001 work, unmodified in both runs | PASS |
| AC-5 | `git check-ignore -v` on `vendor/`, `node_modules/`, `.env`, `storage/logs/*`, `bootstrap/cache/*`, `*.log`, `.idea/` — all ignored | PASS |
| AC-6 | `composer.json` declares `"php": "^8.2"` + path repos `../../packages/*` and `../*`; `composer validate` → valid | PASS |
| AC-7 | `php -v` → `PHP 8.2.12 (cli)` | PASS |
| AC-8 | Copied the repo **without `vendor/`** to a scratch dir and ran `composer install` there: exit 0, `composer.lock` produced, all 8 `flexipos/*` path packages resolved | PASS |
| AC-9 | `composer lint` exit 0. Coverage proven by planting badly-formatted probes in `packages/kernel/src/` **and** `services/demo/src/` — Pint reported both and exited 1 | PASS |
| AC-10 | Bad-formatting probe → `composer lint` exit 1 naming the file; `composer lint:fix` exit 0 and rewrote it; `composer lint` exit 0. Re-confirmed incidentally in run 2 when my own rewritten test file failed lint, was fixed by `lint:fix`, and then passed. Also `PintLintFixRoundTripTest` | PASS |
| AC-11 | `phpstan.neon.dist` `level: 6`, paths cover `app`, `bootstrap/app.php`, `bootstrap/providers.php`, `config`, `database`, `routes`, `../demo/src`, all 7 `../../packages/*/src`. Guarded by `QaToolchainRegistryCoverageTest` | PASS |
| AC-12 | `composer stan` → `[OK] No errors`. Strictness proven: an untyped method in `app/` produced `Found 1 error` and failed the gate | PASS |
| AC-13 | One `classLike` layer per service (`Service_Demo`) + one per `packages/*` (7); ruleset gives each service layer only `Package_*` targets. Asserted mechanically by `QaToolchainRegistryCoverageTest` | PASS |
| AC-14 | `composer deptrac` → `Violations 0`, exit 0 | PASS |
| AC-15 | Re-proven in run 2 against the **shipped, unmodified** `deptrac.yaml`: a `services/demo` class importing `App\Support\ServiceHost\ServiceRegistry` → exit 1, `DependsOnDisallowedLayer … must not depend on … (HostApp)`; probe removed → exit 0, `Violations 0`. Run 1 additionally proved a real service-to-service violation (`Service_Demo` → `Service_Qaboundary`) with both layers registered. Also `DeptracBoundaryEnforcementTest` | PASS |
| AC-16 | **Now a real pass, not vacuous.** Dropped a test file into `packages/kernel/tests/` → `pest --filter` collected and ran it (`1 passed`); the new `Packages` testsuite glob discovers it with no edit. Generated service packages are discovered too (see AC-26). Fixture removed | PASS |
| AC-17 | Proven at two steps in run 1 (stops at lint; stops at stan) and again in run 2: with my unformatted test file present, `composer check` exited 1 after the lint step only — stan/deptrac/test never ran | PASS |
| AC-18 | `ServicesEnvParsingTest` asserts the loaded `Flexipos\Service\*` providers are exactly `[DemoServiceProvider::class]`; `artisan route:list` with `SERVICES=demo` shows only demo's route plus the two health routes | PASS |
| AC-19 | Real process: `SERVICES=demo,ghost artisan route:list` → exit 1, `Unknown service "ghost" listed in SERVICES: no service package found at …\services\ghost`. Plus 5 malformed-manifest paths in `QaServiceRegistryContractTest` | PASS |
| AC-20 | Real HTTP kernel dispatch with `SERVICES=""` → `/health/live` 200, `/health/ready` 200. Also `ServicesEnvParsingTest` (empty and unset) | PASS |
| AC-21 | Real dispatch with `SERVICES=" demo , demo "` → `{"status":"ok","services":["demo"]}`, `/api/v1/demo/ping` 200. Also `ServicesEnvParsingTest` (route count == 1) | PASS |
| AC-22 | Real dispatch with `SERVICES=demo` → `200 {"status":"ok","services":["demo"]}`. **Run 2 additionally proves the guarantee holds under hostile input**: `Demo`, `demo-`, `demo/../demo` and `demo,Demo,demo/../demo` now all fail fast instead of being reported as booted. New test `QaServiceNameValidationTest` asserts every entry in `/health/ready` has a matching registered provider | PASS |
| AC-23 | Real dispatch with `SERVICES=""` → `200 {"status":"ok","services":[]}` | PASS |
| AC-24 | Generated a package, left it out of `SERVICES`: `/api/v1/<name>/ping` 404, `/health/ready` = `["demo"]`. Also `UnlistedServiceReturns404Test` | PASS |
| AC-25 | `svc:make billing` produced `composer.json`, provider, `PingController`, `config/connection.php`, `routes/api.php`, `database/migrations/.gitkeep`, `src/Consumers/.gitkeep`, `tests/Feature/PingRouteTest.php`. Also `SvcMakeValidationTest` | PASS |
| AC-26 | **Fixed.** `svc:make qaretest` then the configured suite → `PASS …\services\qaretest\tests\Feature\PingRouteTest`, `Tests: 3 passed (7 assertions)`, exit 0. In run 1 the same sequence produced `INFO No tests found.` and exit 0. Pinned by `QaGeneratedServiceTestDiscoveryTest`, now green | PASS |
| AC-27 | md5-fingerprinted all files of `services/host` before and after `svc:make` → identical; then config only, `SERVICES=demo,qaprobe` → new route 200 and `/health/ready` = `["demo","qaprobe"]`. Also `SvcMakeGeneratedServiceReachableTest` | PASS |
| AC-28 | Real CLI: `Bad Name!`, `Billing`, `bill_ing`, `1bill` all exit 1 with `Invalid service name …`, nothing created. **Run 2 also refuses `billing-` and `bill--ing`** (D-12), while `qa-multi-word` is still accepted and yields a valid Composer name and `Flexipos\Service\QaMultiWord\` | PASS |
| AC-29 | Real CLI `svc:make demo` → exit 1, `services/demo already exists. Nothing was created or modified.`; `SvcMakeValidationTest` also proves an existing file's bytes are unchanged | PASS |
| AC-30 | Real CLI `svc:make host` → exit 1, `[host] is a reserved name …`, no files created. `SERVICES=host` also fails fast at boot | PASS |
| AC-31 | Copied `services/demo` aside, deleted it, re-ran `svc:make demo`, `diff -r` against the original → **identical**; original bytes restored. `.env.example` has `SERVICES=demo`; `phpunit.xml` sets it | PASS |
| AC-32 | Real dispatch `GET /api/v1/demo/ping` → `200 {"service":"demo","status":"ok"}`; `svc_demo` connection present with database `pos_demo` | PASS |

**Totals: 32 PASS, 0 FAIL.**

## Re-test of the run-1 defects

### BUG-1 (major) — generated service package's test never runs → **FIXED, verified**

`phpunit.xml`'s `Services` testsuite is now `<directory>../*/tests</directory>` and a new
`Packages` testsuite adds `<directory>../../packages/*/tests</directory>`.

- `svc:make qaretest` → the package's own test runs: `Tests: 3 passed (7 assertions)`, exit 0.
  Run 1 produced `INFO No tests found.` with exit 0.
- `QaGeneratedServiceTestDiscoveryTest` (the test I added to pin this) is green.
- A test dropped into `packages/kernel/tests/` is now discovered as well — this is what turns
  AC-16 from a vacuous pass into a real one.

**Verification that `<exclude>../host/tests</exclude>` hides nothing real.** This was added
beyond the plan text, so I checked it three ways rather than reading it:

1. Ran the suite with `--log-junit` and compared the JUnit log against the filesystem:
   **15 test files on disk → 15 test classes collected**, with every host class
   (`Tests\Unit\ExampleTest` and all 13 `Tests\Feature\*`) present. The exclude only removes
   `../host/tests` from the `Services` suite; the `Unit` and `Feature` suites still collect
   them, so nothing is lost.
2. Checked it cannot over-match a future package whose name merely *starts with* `host`:
   generated `services/hosted`, and its tests were collected and ran (`3 passed`). PHPUnit
   treats `<exclude>` as a path, not a prefix, so `../hosted/tests` is unaffected.
3. Confirmed the stated motivation is real — without the exclude PHPUnit emits a duplicate
   warning per host test file — and that the exclude does not change the pass/fail outcome.

Verdict: the deviation is correct, necessary and safe. The only latent risk is a service
package literally named `host`, which is already impossible (reserved name, AC-30).

### BUG-2 (minor) — `svc:make` accepted names producing an invalid Composer name → **FIXED, verified**

D-12 tightened the pattern to `^[a-z][a-z0-9]*(-[a-z0-9]+)*$`, defined once as
`ServiceRegistry::NAME_PATTERN` and referenced by `SvcMakeCommand::NAME_PATTERN`.

| Input | Result |
|---|---|
| `billing-` | exit 1, `Invalid service name [billing-] …` |
| `bill--ing` | exit 1 |
| `Billing`, `bill_ing`, `1bill`, `Bad Name!` | exit 1 |
| `qa-multi-word` | exit 0 → `"name": "flexipos/service-qa-multi-word"`, `Flexipos\Service\QaMultiWord\` |

Sharing one constant is the right call: `QaServiceNameValidationTest` asserts by reflection
that `SvcMakeCommand::NAME_PATTERN === ServiceRegistry::NAME_PATTERN`, so the two validation
points cannot drift even if someone edits one of them later.

### BUG-3 (major) — `/health/ready` reported names that were not booted services → **FIXED, verified**

Every `SERVICES` entry is now validated in `boot()` before anything else happens. Real
process (`artisan route:list`), all exit 1 with the offending entry named:

| `SERVICES` | Message |
|---|---|
| `Demo` | `Unknown service "Demo" … must be lowercase kebab-case matching /^[a-z][a-z0-9]*(-[a-z0-9]+)*$/.` |
| `demo/../demo` | same, naming `demo/../demo` |
| `demo,Demo` | names **`Demo`** — the offending entry, not the first one |
| `demo-` | same |
| `demo,ghost` | `no service package found at …\services\ghost` (unchanged) |
| `host` | `"host" is the service host application itself …` (unchanged) |

Valid values are unaffected: `demo`, `" demo , demo "` and `""` all behave exactly as before.

**`directoryIsNamedExactly()` verified independently**, because this is the part that only
shows up on a different OS. I generated a package, renamed the directory on disk to `Qacase`,
and asked the host for `SERVICES=qacase`:

```
is_dir("…/services/qacase")  ->  bool(true)      # Windows says yes
artisan route:list           ->  exit 1, Unknown service "qacase" … no service package found
```

So the host now gives the Linux answer on Windows. That closes the run-1 finding that the
same `SERVICES` value booted in XAMPP and would have failed in Docker, and it is a genuine
improvement over what the spec strictly required.

### BUG-4 (major) — suite not isolated from an ambient `SERVICES` variable → **FIXED, verified**

`Tests\TestCase::setUp()` now copies phpunit.xml's declared value from `$_ENV` into
`$_SERVER` before the application is created. Keeping `phpunit.xml` as the single declaration
is the right shape — the test case transports the value rather than restating it.

Full suite run under four hostile ambient values, including the one that previously killed
every test file in `setUp()`:

| ambient `SERVICES` | run 1 | run 2 |
|---|---|---|
| `ghost,demo` | exit 2, **every** test file failed | exit 0, `74 passed (222 assertions)`, 0 `UnknownServiceException` |
| `""` | exit 1, `1 failed, 31 passed` | exit 0, `74 passed` |
| `Demo` | not tested | exit 0, `74 passed` |
| `demo/../demo` | not tested | exit 0, `74 passed` |

No ordering regression from the new `setUp()`: two consecutive runs, `--order-by=random`
with seeds 1 and 7, and `--order-by=reverse` each gave `74 passed (222 assertions)`.

### BUG-5 (minor, latent) — hand-maintained path lists → **mitigated, closed**

- The PHPUnit third of it is genuinely fixed by the globs (above).
- PHPStan and Deptrac cannot glob their `paths`, confirmed by the architect, so those two
  stay literal. `svc:make` now prints a reminder naming the exact edits required, and
  `QaToolchainRegistryCoverageTest` fails the gate if they are forgotten.
- Residual risk, accepted and recorded: between generating a real service and registering it,
  that package is not statically analysed and not boundary-checked. The guard makes that
  window visible instead of silent.

## The judgement call in my own file — I was wrong, `dev` was right

`dev` was correct to stop and ask rather than edit my file, and correct on the substance.

My run-1 test `it reports only genuinely registered service packages from /health/ready`
booted `demo,demo/../demo` and expected the bad entry to be **silently dropped**
(`booted() === ['demo']`). That contradicted three things at once: test 1 in my own file,
which requires that same entry to throw; decision **D-1**, which says a bad `SERVICES` entry
must fail fast and that "nothing is silently skipped"; and the BUG-3 fix I had myself asked
for. Both cannot hold, and silent-drop is the wrong one — it would re-introduce exactly the
typo'd-configuration bug D-1 exists to catch, just relocated from the routing table to the
health endpoint. I had written the assertion against the *symptom* I observed (`/health/ready`
listing three entries for one provider) instead of against the *contract*.

I rewrote the file to the fail-fast contract. The invariant I actually cared about is now
expressed in a way that does not depend on silent-drop:

> `it never reports a service from /health/ready without a matching registered provider` —
> for every name in `booted()`, assert a `Flexipos\Service\<Studly>\…` provider is registered.

That holds under fail-fast, is independent of input shape and OS, and would still have caught
the original BUG-3 symptom. Both previously-skipped tests are now un-skipped and green; the
file has 7 tests, no skips.

## `QaToolchainRegistryCoverageTest` going red on a new package — confirmed intended

Confirmed: that is the backstop working, not a regression. With `services/qaretest` and
`services/hosted` present it failed with:

```
services/hosted/src is not in phpstan.neon.dist paths
services/qaretest/src is not in phpstan.neon.dist paths
services/hosted/src is not in deptrac.yaml paths
no deptrac.yaml layer collects Flexipos\Service\Hosted\QaProbe
…
```

Two things worth noting. First, it is now **narrower** than in run 1: only the PHPStan and
Deptrac assertions fire — `it collects tests from every package that ships them` stays green,
because the phpunit glob covers new packages. That narrowing is itself evidence the BUG-1 fix
landed. Second, this is the intended handshake with the reminder `svc:make` now prints: the
command tells you what to register, and the guard refuses to go green until you do. Deleting
the probe package, as `dev` did, is the correct response for a throwaway; for a real service
the correct response is to add the two entries.

## Test run summary

Commands, from `services/host` with the documented PHP/Composer paths:

| Command | Result |
|---|---|
| `composer check` | **exit 0** — lint `passed` → stan `[OK] No errors` → deptrac `Violations 0` → `Tests: 74 passed (222 assertions)` |
| `composer lint` | exit 0 |
| `composer stan` | exit 0, `[OK] No errors` |
| `composer deptrac` | exit 0, `Violations 0 / Skipped 0 / Errors 0`, `Uncovered 23` |
| `pest` × 2 consecutive, `--order-by=random` seeds 1 and 7, `--order-by=reverse` | `74 passed (222 assertions)` every time |
| `pest` with ambient `SERVICES` = `ghost,demo` / `""` / `Demo` / `demo/../demo` | exit 0, `74 passed` in all four |
| `pest --log-junit` + filesystem comparison | 15 test files on disk → 15 test classes collected |
| `artisan route:list` under 6 invalid `SERVICES` values | exit 1 each, offending entry named |
| `artisan svc:make` under 6 invalid names | exit 1 each, nothing created |

Passed / failed / skipped: **74 / 0 / 0**.

On `Uncovered 23` (22 in run 1): I checked the full uncovered report rather than assume. Every
uncovered token is a framework class (`Illuminate\Support\ServiceProvider`,
`Illuminate\Http\JsonResponse`, `Illuminate\Support\Facades\File`, `Illuminate\Support\Str`, …),
which is expected because no layer is defined for the framework. The +1 is the new
`Illuminate\Support\Str` reference at `SvcMakeCommand.php:108`, i.e. the BUG-5 reminder
message. Not a boundary hole.

## Tests owned by QA

All under `services/host/tests/Feature/`, all Pint-clean, all green.

| File | Tests | Covers |
|---|---|---|
| `QaGeneratedServiceTestDiscoveryTest.php` | 1 | AC-26. Generates a package and requires a filtered child `pest` run to actually execute its test |
| `QaServiceNameValidationTest.php` | 7 | AC-22/AC-28. Fail-fast on invalid `SERVICES` entries incl. case and traversal; `/health/ready` never reports a service without a registered provider; generator refuses `qa-` and `qa--name` but accepts `qa-name-fixture`; reflection check that generator and registry share one pattern constant |
| `QaTestSuiteIsolationTest.php` | 1 | Gate trustworthiness. Child `pest` run with `SERVICES=ghost` in its environment must still pass |
| `QaToolchainRegistryCoverageTest.php` | 7 | AC-9/11/13/16. Every package on disk must be in `phpstan.neon.dist` paths, in `deptrac.yaml` paths + a layer + the ruleset, and collected by a `phpunit.xml` testsuite; no service layer may depend on a non-`Package_*` layer |
| `QaServiceRegistryContractTest.php` | 8 | AC-18..AC-23 negative paths: no `composer.json`, invalid JSON, no `autoload.psr-4`, no `extra.flexipos.provider`, provider not autoloadable — each must fail fast naming the service; plus sorted/deduped parsing and exact health-endpoint shapes |

## Regression risks / notes

- **PHPStan and Deptrac registries remain manual.** This is the one place where a real future
  service can slip through unanalysed and unchecked. Mitigated by the `svc:make` reminder plus
  `QaToolchainRegistryCoverageTest`, but whoever adds the first real service should confirm
  the guard went red and then green again, rather than trusting the reminder was read.
- **`<exclude>../host/tests</exclude>`** is safe today and cannot be tripped by a package
  named `hosted`, but it is a second place (after the `host` reserved name) that assumes the
  host directory is called `host`. Renaming it would need both updated.
- **Test-generated fixture packages** (`svcmakefixture`, `unlistedfixture`,
  `reachablefixture`, `qadiscoveryfixture`, `qabrokenfixture`, `qa-name-fixture`) are created
  under `services/` during a run and removed in `afterEach`. A killed run can leave one
  behind; `QaToolchainRegistryCoverageTest` deliberately skips directories whose name contains
  `fixture` so a stray one cannot cause a confusing failure. Note `qa-name-fixture` also
  matches that skip rule, intentionally.
- **Not verified, out of scope by AC-7/D-3**: PHP 8.3 and a genuinely case-sensitive
  filesystem. BUG-3's fix makes the second one far less likely to bite, and the `Qacase`
  experiment is the closest proxy available on this machine, but it is a proxy.
- **`composer validate --strict`** still reports unbound `*` constraints on the `flexipos/*`
  path requirements. Normal for path repositories; not a defect.

## Worktree cleanliness

`git status --porcelain -uall` against the pre-QA baseline differs only by files QA owns:

```
?? docs/qa/T-001-monorepo-service-host.md
?? services/host/tests/Feature/QaGeneratedServiceTestDiscoveryTest.php
?? services/host/tests/Feature/QaServiceNameValidationTest.php
?? services/host/tests/Feature/QaServiceRegistryContractTest.php
?? services/host/tests/Feature/QaTestSuiteIsolationTest.php
?? services/host/tests/Feature/QaToolchainRegistryCoverageTest.php
?? .claude/agent-memory/qa-engineer/*
```

`services/` contains only `demo` and `host`; all 7 `packages/*` contain only `composer.json`
and `src`; no stray fixture, probe or renamed directory anywhere. `services/demo` was restored
byte-for-byte after the AC-31 regeneration check. No production source, spec, plan or
`CLAUDE.md` was edited by QA. Destructive experiments ran in a scratch copy outside the repo.

`Refference/ERP.md` and `docs/gap-analysis-erp.md` are confirmed by the lead as expected and
outside the T-001 commit.

---

Run 1: FAIL (31/32 ACs; BUG-1 major, BUG-2 minor, BUG-3 major, BUG-4 major, BUG-5 minor-latent) -> Run 2: PASS (32/32 ACs; BUG-1..BUG-4 fixed and re-tested, BUG-5 mitigated and closed, no new defects)
