# QA Report: T-003 — master plus tenant connections

Branch `ai/T-003-tenant-connections` · spec `docs/specs/T-003-tenant-connections.md` (AC-1 … AC-25) ·
plan `docs/plans/T-003-tenant-connections.md` (13 steps) · decisions `docs/decisions/T-003-tenant-connections.md` (D-25 … D-33).

## Verdict: PASS (run 3)

**All 25 acceptance criteria and every non-functional requirement pass.** The gate is green at
**277 passed, 1219 assertions, exit 0**, confirmed on two consecutive runs, with Pint, PHPStan
level 6 and Deptrac clean. Run 1's and run 2's findings are kept below, because the reasoning
behind each fix is the part worth re-reading when FP-203 extends this code.

Run 3 exists because **the reviewer found a defect in my own sweep (BUG-3)**: my two `dump()`
cases passed while the surface leaked. They are rewritten, and the rewrite is described in full
under *Run 3* — including the two further false-green traps I hit while fixing it. One of my
assertions also had to change to accept the reviewer's R-2 fix; I agreed with that ruling and did
not contest it.

Everything `dev` shipped that I could break, I tried to break. The three-level guard design is
sound and the stale-connection defence is real — I reproduced the leak it prevents on an
unguarded connection name and watched the object silently repoint itself at the second tenant's
database. Both defects were gaps in coverage of that design, not flaws in it, and both are now
covered by tests `dev` does not own.

### Run 1 verdict (superseded, kept for context): FAIL

Twenty-four of twenty-five acceptance criteria passed. **AC-15 failed**: there was a second,
working path that built a usable `tenant` connection with no tenant resolved, bypassing all three
guard levels (BUG-2). One **non-functional requirement** also failed: a resolved credential was
visible in `dd()` / `dump()` output (BUG-1).

## Acceptance criteria coverage

`pest` = `cd services/host && "E:\xampp\php\php.exe" vendor/bin/pest <file>`.
`gate` = `cd services/host && composer check` (Pint + PHPStan 6 + Deptrac + Pest).

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 master exists, env-built, tenant-independent | `TenantConnectionGuardTest` "ships a master connection built from the environment"; `TenancyTenantSwitchTest` "keeps the master connection identical whether or not a tenant is resolved" | PASS |
| AC-2 master queryable with no tenant | `TenantConnectionGuardTest` "queries the master connection with no tenant resolved at all" | PASS |
| AC-3 tenant declares no usable database at rest | `TenantConnectionGuardTest` "declares no usable tenant database at rest" (asserts the key list is exactly `driver, database, prefix`) | PASS |
| AC-4 no-tenant query fails before any network attempt | `TenantConnectionGuardTest` ×4; **`QaTenantIsolationProofTest` "refuses a fully usable tenant stanza before any network attempt, when no tenant is resolved"** + its negative control "still reaches the driver when a tenant IS resolved" | PASS |
| AC-5 context never answers with null | `TenantContextTest` ×3 (incl. reflection: no public method has a nullable return type) | PASS |
| AC-6 resolver configures from the record and reaches that database | `TenancyResolutionTest` "configures the tenant connection from the mapping record…", "reaches a second, distinguishable tenant database…" | PASS |
| AC-7 context reports the resolved identifier | `TenancyResolutionTest` (same case) | PASS |
| AC-8 credential dereferenced from a reference, never a column | `SchemeSecretResolverTest` ×8, `TenantDatabaseTest` "has no property or constructor parameter that could hold a password", `TenancyResolutionTest` "never puts the credential in the connection configuration it hands out to readers" | PASS |
| AC-9 no mapping → refusal, no partial overwrite | `TenancyResolutionTest` "refuses a tenant with no mapping record at all, naming it, and changes nothing" (`toBe()` against the pre-call stanza) | PASS |
| AC-10 non-active status refused, ≥2 statuses | `TenancyResolutionTest` dataset `suspended` + `provisioning`; `TenantDatabaseTest` dataset of 14 non-active values incl. `ACTIVE`, `Active`, `active ` and `''` | PASS |
| AC-11 empty/whitespace id refused before any lookup | `TenancyResolutionTest` dataset `''`, `'   '`, `"\t\n"` with a repository spy asserting `find()` was never called | PASS |
| AC-12 credential absent from every exception surface | `TenancyCredentialLeakTest` ×6 datasets + "drops the driver exception entirely…", "redacts the credential if a driver message ever quotes it back"; `QaCredentialSurfaceSweepTest` "pins the flattened-trace mechanism…" (with its own control) | PASS |
| AC-13 credential absent from logs | `TenancyCredentialLeakTest` ×6 datasets (MessageLogged) + "logs a redacted warning…"; **`QaCredentialSurfaceSweepTest` "keeps the credential out of a real log record, through a real formatter"** (Monolog `LineFormatter` + `JsonFormatter`) | PASS |
| AC-14 credential absent from rendered debug output | `TenancyCredentialLeakTest` ×6 datasets + "keeps the credential out of rendered debug output when a leaked stanza is rebuilt" (real handler, 810 KB page, and Symfony's fallback renderer) | PASS |
| **AC-15 exactly one construction point, no second working path** | `QaTenantIsolationProofTest` "holds exactly one production write…" (source audit), "refuses a tenant connection built through DatabaseManager::connectUsing with no tenant resolved", "…through DatabaseManager::build for another tenant", "never caches the refused connection under the tenant name", "bricks the tenant connection loudly, rather than quietly, after a refused build()", "keeps the per-query guard attached when the event dispatcher is faked" | **PASS (run 2)** — was FAIL, BUG-2 |
| AC-16 switch serves the second tenant | `TenancyTenantSwitchTest` ×3; `QaTenantIsolationProofTest` "refuses a held connection while another tenant is resolved and serves the first again on the way back", "refuses a tenant connection captured inside a closure across a tenant switch" | PASS |
| AC-17 nothing of the first tenant remains | `TenancyTenantSwitchTest` "leaves nothing of the first tenant in the connection configuration", "keeps no hidden connection configuration that survives a purge"; `QaTenantIsolationProofTest` "keeps nothing of a tenant in the database manager between scopes", "refuses a read-suffixed tenant connection that survives the teardown purge" | PASS |
| AC-18 forget returns to the no-tenant state | `TenancyTenantSwitchTest` "returns to the no-tenant-resolved state…", "refuses to serve a leaked tenant scope after the container scope is reset"; `QaTenantIsolationProofTest` "rolls back a transaction left open across a tenant switch…" | PASS |
| AC-19 no stub produces a per-module connection | **`QaModuleConnectionRemovalTest` "ships no stub that can produce a per-module database connection"** (8 forbidden tokens over the whole stub directory + `SvcMakeCommand::FILES` by reflection + a negative control) | PASS |
| AC-20 generated module declares no connection anywhere | **`QaModuleConnectionRemovalTest` "generates a module that declares no database connection anywhere in its tree"**; `SvcMakeValidationTest` "scaffolds the structure a service package must have" | PASS |
| AC-21 generated test passes out of the box | **`QaModuleConnectionRemovalTest` "runs every test a freshly generated module ships, with no further edits"** (child `pest` by file path → `Tests: 3 passed`); `QaGeneratedServiceTestDiscoveryTest` | PASS |
| AC-22 committed demo == fresh generation | `SvcMakeDemoRegenerationTest`; `QaRegenerationGuardTest` ×2 (drift a stub → red and names the file; CRLF working tree → still green) | PASS |
| AC-23 demo's own test passes, no `svc_demo` dependency | `services/demo/tests/Feature/PingRouteTest` ×3 in the shipped suite; **`QaModuleConnectionRemovalTest` "leaves no connection artefact in the committed demo package"** | PASS |
| AC-24 mapping contract shape | `TenantDatabaseTest` "exposes every field the connection plumbing needs and nothing operational" (exact property list) | PASS |
| AC-25 fake mapping source only, no real tables | `TenantDatabaseTest`; grep proof: `tenant_databases`/`database_servers` appear only in comments, no migration, no Eloquent model, no query | PASS |
| **NFR — "no plaintext credentials … not in debug/error output"** | `QaCredentialSurfaceSweepTest` "keeps the credential out of the dump() helper the application actually installs" (child process, installed handler, three controls) + "registers the redaction in the static default casters every later cloner copies" | **PASS (run 3)** — FAIL in run 1 (BUG-1); passed *falsely* in run 2 (BUG-3); genuinely verified in run 3 |
| NFR — no persistent PDO on `tenant` | `TenancyTenantSwitchTest` "never configures a persistent PDO connection for a tenant" | PASS |
| NFR — fail fast in order (input → mapping → status → connect) | `TenancyResolutionTest` (spy proves step order); code review of `Tenancy::enter()` steps 1-8 | PASS |
| NFR — `composer check` does not regress | gate: all 246 pre-T-003-QA tests still pass | PASS |

### Regression — T-001 and T-002

| Criterion | Result |
|---|---|
| T-001 AC-8/9/12/14/16/17 (`install`/`lint`/`stan`/`deptrac`/`test`/`check`) | PASS — Pint `{"result":"passed"}`, PHPStan `[OK] No errors` at level 6, Deptrac `Violations 0` |
| T-001 AC-18 … AC-24 (boot mechanism, `SERVICES`, health endpoints, 404) | PASS — `ServicesEnvParsingTest`, `HealthEndpointTest`, `UnlistedServiceReturns404Test`, `QaHealthProbeResilienceTest`, `QaServiceRegistryContractTest` all green |
| T-001 AC-25 | **Superseded, not regressed** — see Ruling 1 |
| T-001 AC-26/AC-27 | PASS — `QaGeneratedServiceTestDiscoveryTest`, `SvcMakeGeneratedServiceReachableTest`, and my stronger by-path child run |
| T-001 AC-31/AC-32 (demo committed, ping route 200) | PASS — `services/demo` PingRouteTest, now with no database connection at all |
| T-002 AC-1 … AC-4 (manifest trust) | PASS — `QaManifestTrustAttackTest` (20 cases), `ServiceRegistryManifestTrustTest` |
| T-002 AC-5/AC-6 (atomic generation, retry) | PASS — `SvcMakeAtomicGenerationTest`, `QaAtomicGenerationProofTest`, against the new five-file list |
| T-002 AC-9/AC-10 (config:cache staleness guard) | PASS — `ConfigCacheGuardTest`, `QaCacheGuardBranchesTest`; adding `master`/`tenant` did not interact with it |
| T-002 AC-11/AC-14 (documentation accuracy) | PASS — `QaDocumentationAccuracyTest`, `QaCacheGuardBranchesTest`; every literal token the plan listed survived the doc edits |
| T-002 AC-16/AC-17 (tests analysed by stan/deptrac) | PASS — `QaTestCodeAnalysisCoverageTest`, `QaToolchainRegistryCoverageTest`; `packages/kernel/tests` is registered in both tools |
| T-002 AC-19 / FP-153 (regeneration diff still means something) | PASS — `QaRegenerationGuardTest` drifts a stub in a child run and asserts it goes red and names `routes/api.php`, then restores byte-for-byte |

## Run 3 — my own defect (BUG-3), and the R-2 assertion change

### BUG-3 (critical, mine) — my `dump()` cases passed while the surface leaked

The reviewer's critical finding was in my sweep, not in `dev`'s code. Both run-2 `dump()` cases
built their **own** `VarCloner` — the one object the `dump()` helper never touches:

- `Illuminate\Foundation\Console\CliDumper::register()` constructs **one** cloner and captures it
  inside the `VarDumper::setHandler()` closure;
- `AbstractCloner::__construct()` copies `AbstractCloner::$defaultCasters` **at construction**;
- `FoundationServiceProvider::register()` runs that during *framework* provider registration,
  before anything in `bootstrap/providers.php`.

So a caster registered by our provider reaches every cloner built afterwards — including the one
my test built — and never the live handler's. Reproduced before changing anything: booting the
real application and calling `dump()` printed `-value: "NEEDLE-deadbeef-7f3a"` at all three
shapes. My test was a stand-in guaranteed to agree with us. This is the same family as the
variadic-matcher trap I reported in run 2: a test that measures something adjacent to the real
path and passes for an unrelated reason.

**The rewrite.** `QaCredentialSurfaceSweepTest` now has one case,
*"keeps the credential out of the dump() helper the application actually installs"*, which spawns
a child process, boots the real application, and dumps through the installed handler — reading its
**stdout**, because the handler writes through `ConsoleOutput` and `ob_start()` does not capture
it. It asserts **three controls before** it asserts anything about the credential, and each one
closes a specific way I or the coordinator got a false green:

| Control | Rules out |
|---|---|
| `HANDLER=installed` | the probe measuring no handler at all |
| `CONTROL-VISIBLE` present | an empty capture (the `ob_start()` trap) |
| an unprotected stand-in object's private value comes back | a probe that could not detect a leak even if one existed |

The old shape is kept, honestly renamed to *"registers the redaction in the static default casters
every later cloner copies"* — the narrower claim a hand-built cloner can actually support, and
still worth having, since any cloner built after boot does copy those.

**Two further traps found while fixing it, both of which would have re-created the same false
green:**

1. I first added a `VAR_DUMPER_FORMAT=html` dataset to cover the web surface. It passed — and it
   was green for the wrong reason: with that variable set, `VarDumper::$handler` is **null** after
   bootstrap, so Symfony's lazy fallback builds a cloner at first dump, *after* our provider ran.
   It was measuring the fallback, not the product. Dropped; the `HANDLER=installed` control now
   makes that shape impossible to ship by accident.
2. I then tried registering `Illuminate\Foundation\Http\HtmlDumper` by hand from the CLI child to
   simulate a web request. Also green for the wrong reason: registering it *after* bootstrap
   inverts the very ordering that causes the defect. Measured both ways before discarding it.

The web surface is covered by the CLI case rather than simulated: `HtmlDumper::register()` captures
its cloner in the same line of the same method, from the same provider. **The defect is the
ordering, not the SAPI.** That reasoning is recorded in the test itself so nobody re-adds a
format dataset believing it adds coverage.

### `dev` fixed BUG-3 better than my suggestion, and closed the `var_export` residual with it

Rather than relying on the caster, `dev` moved the plaintext out of the object graph entirely
(`Secrets/CredentialValues.php`): a `WeakMap` keyed by the holder, so `Secret` and
`ConnectionPassword` have **no properties at all**. `(array) $object` is empty, a reflection walk
finds nothing, and no inspector needs to honour any hook. The caster is retained as a second line.

Measured consequence worth recording: **the accepted `var_export()` residual is now gone**, not
merely unreachable. `var_export(new Secret(...))` yields `::__set_state(array())` with no value,
and a stanza containing a `ConnectionPassword` no longer leaks through `var_export()` or
`print_r()`. `reveal()` and `__toString()` still return the real value, so the connector's coercion
is intact. The only remaining surface is deliberate string interpolation of a `ConnectionPassword`,
which is that class's entire purpose. **Ruling 2 below is superseded on that point**: there is no
residual left to accept.

### The R-2 collision — ruling accepted, assertion changed

The reviewer's R-2 fix makes teardown purge **every** cached tenant variant, so nothing
tenant-shaped outlives a scope. Two assertions in
`QaTenantIsolationProofTest` required the opposite, and I agree they were wrong:

> `expect(array_keys(DB::getConnections()))->toContain('tenant::read')` — *"It really did survive
> the purge - which is what makes the refusals below meaningful rather than incidental."*

That was **characterising the unfixed behaviour as a precondition** — asserting the symptom rather
than the contract, which is the same mistake I had already caught and fixed elsewhere in this same
file. An open handle on the previous shop's database outliving its scope is a data-safety problem
in its own right, and the per-query guard should be the second line of defence, not the reason a
stale handle is tolerable. It stops being sufficient at all the day FP-203 adds read replicas.
**No pushback: the fix stands.**

The case is rewritten as *"purges every tenant connection variant on teardown, and still refuses
one held across a switch"*, asserting the stronger pair:

- **no** connection named `tenant` or `tenant::*` remains after `leave()` (the new contract, and a
  strictly stronger claim than the old one);
- asking for one again with no tenant resolved is refused rather than rebuilt;
- an object **held across the switch** is still refused with `TenantConnectionMismatch`.

The last assertion keeps its discriminating power from the held reference — the real "a repository
cached the connection" shape — instead of from the manager failing to clean up. Its negative
control is unchanged and still passes: *"shows what a held connection does without the guard, on
an unguarded connection name"*, where the held object silently repoints itself at the second
database.

### Assertion-shape guard extended

`QaAssertionShapeGuardTest` now lints a fourth matcher, `toHaveProperty()` — whose **second**
argument is a property value, not a message, so `not->toHaveProperty('password', 'hunter2')` has
the same trap. Control extended to four bad shapes and six good ones; sweep still reports **0
offenders** across the suite.

### Gate

`cd services/host && composer check` → **exit 0**, Pint `{"result":"passed"}`, PHPStan level 6
`[OK] No errors`, Deptrac `Violations 0`, Pest **277 passed, 0 failed, 1219 assertions**.
Confirmed on **two consecutive runs** (98 s, 88 s), because an earlier run-3 attempt was polluted
by a concurrent test run and produced 50 meaningless failures — see *Regression risks* below.

### Open item passed back to `dev` (low priority, not mine to fix)

`packages/kernel/tests/Feature/TenancyCredentialLeakTest.php` and `TenancyTenantSwitchTest.php`
cannot be run on their own — they call `tenancyBootFixture()` and friends, declared in
`TenancyResolutionTest.php`, so a single-file run dies on an undefined function while the full
suite is fine. Verified file by file; the other four kernel feature files, **including both of
mine**, run standalone. Mine do because each carries its own fixture deliberately, so a failure in
one can never be a side effect of another file's teardown. The fix (a shared helper file each case
requires) belongs in `dev`-owned files.

## Run 2 — re-test of both fixes

`dev` fixed BUG-1 with a VarDumper caster and BUG-2 with a `ConnectionEstablished` listener, and
added three things beyond the suggestions. All four of my run-1 pinning tests now pass unedited,
and I checked the three additions rather than accepting them. Six new QA cases were added for
this run (four in `QaTenantIsolationProofTest`, two in the new `QaAssertionShapeGuardTest`).

### Gate

`cd services/host && composer check` → **exit 0**, Pint `{"result":"passed"}`, PHPStan level 6
`[OK] No errors`, Deptrac `Violations 0`, Pest **275 passed, 0 failed, 1207 assertions**, 114 s.
275 = the 269 the coordinator verified + my 6 new cases. No test was skipped.

### BUG-1 — verified fixed

- The four combinations that leaked now come back clean: `Secret` and `ConnectionPassword`, each
  through `CliDumper` and `HtmlDumper`, plus nested inside an array (the `dd(config(...))` shape).
  A `Secret` built after boot now dumps as `Secret { value: "[redacted]" }` — the private
  `-revealer` closure and its captured `use` binding are gone from the output entirely.
- The correction to my suggestion is right and worth recording: `addCasters()` is an **instance**
  method, and `Caster::castObject()` reads the cloner's own `$casters`, which a freshly
  constructed `VarCloner` copies from the static defaults — so `AbstractCloner::addDefaultCasters()`
  is the entry point that reaches a cloner nobody configured, which is the only kind `dump()` and
  `dd()` ever build. I verified this the way it matters: constructing a **brand-new `VarCloner`**
  after boot and dumping through it, which is the live path.
- The caster redacts the *dump*, not the object: `Secret::reveal()` and
  `(string) ConnectionPassword` still return the real value, so the connector's coercion is
  untouched. Checked, because a caster that broke `__toString()` would have failed closed and
  looked like a tenancy bug.
- Both classes are `final`, so an exact-class caster cannot be dodged by a subclass. Checked by
  reflection rather than assumed.
- `dev` proved the registration load-bearing by removing only it and watching all four
  combinations re-leak while the other five sweep cases stayed green. I did not need to repeat
  that: the four cases live in a `qa`-owned file inside the gate, so the proof is standing rather
  than one-off.
- The stale claims that made run 1's defect possible were corrected at the source, not papered
  over: `Secret`'s class docblock now says outright that holding the value in a closure makes the
  `dd()` surface *worse*, and `ConnectionPassword::__debugInfo()` is now annotated "Not sufficient
  for dd()/dump()". That is the honest version.

### BUG-2 — verified fixed, and the three additions checked

| `dev`'s addition | Checked how | Result |
|---|---|---|
| Kept the extension **as well as** the listener, arguing that faking the dispatcher would otherwise silently disable a security guard | `QaTenantIsolationProofTest` "keeps the per-query guard attached when the event dispatcher is faked" — resolve a tenant under `Event::fake()`, hold the connection, switch tenants, query | **Reasoning holds.** `Event::fake()` swaps the `events` binding and `DatabaseManager` resolves it from the container on every dispatch, so the listener genuinely stops firing — measured. The extension is what keeps the ordinary route guarded: the held connection is still refused with `TenantConnectionMismatch`, with no dispatcher involved. Removing the extension in favour of the listener alone would have made `Event::fake()` disable level 3 wholesale. |
| Relies on the refusal happening before the connection is cached under that name | "never caches the refused connection under the tenant name" — both routes, asserting `DB::getConnections()` afterwards and that the next `DB::connection('tenant')` still refuses | **Confirmed.** `connectUsing()` dispatches `ConnectionEstablished` *before* `$this->connections[$name] = $connection`, so throwing in the listener stops the install. Run 1's measured behaviour — the unguarded object serving every later `DB::connection('tenant')` in the process — no longer happens on either route. |
| De-duplicates the double attachment with a `WeakMap` keyed by object identity | "does not accumulate guarded connections across many tenant switches" — 25 switches, measured relatively against a pre-loop baseline, then a held connection dropped and collected | **Confirmed.** 25 switches added far fewer than 25 entries (measured 7 with GC running normally), and dropping the last reference to a held, purged connection took the map straight back down — from 10 entries to 0 in the probe. The guard closures capture `$app`, `$name` and `$tenantId`, never the connection, so nothing keeps a key alive. Measured relatively, never against an absolute, because the map is static and outlives individual tests by design. |

### One consequence of the BUG-2 fix, pinned rather than left to be discovered

`DatabaseManager::build()` writes `$dynamicConnectionConfigurations[$name]` **before** delegating
to `connectUsing()`, `purge()` never clears it, and `configuration()` prefers it over
`config('database.connections.*')`. So a *refused* `DB::build(['name' => 'tenant', …])` still
leaves the tenant name pointing at the caller's array for the rest of the process, and legitimate
tenancy then refuses too:

```
TenantConnectionMismatch: The [tenant] connection configuration belongs to tenant [unknown]
but tenant [qa-iso-a] is resolved. Flexipos\Kernel\Tenancy\Tenancy is the only code allowed
to write that configuration.
```

This is **fail-closed, loud, and names its own cause** — reaching for the forbidden API bricks the
tenant connection for the process instead of half-working, and no row from either tenant comes
back. I consider that the right outcome rather than a defect, so it is pinned by
`QaTenantIsolationProofTest` "bricks the tenant connection loudly, rather than quietly, after a
refused build()", which goes red if anyone later makes it permissive. `connectUsing()` on its own
does not retain anything; only `build()` does.

### My own assertion shapes — swept, and the sweep made permanent

The flaw I reported in run 1 (`->not->toContain($secret, 'CliDumper')` passes when *either*
argument is absent, because `toContain()` takes variadic needles, not a message) is **gone from my
files**. Every negated matcher across all four `qa`-owned test files takes exactly one argument;
the only two-argument negations are `not->toBe('', $message)`, where the second parameter really
is a message.

A grep is a one-off, so it is now a test: **`services/host/tests/Feature/QaAssertionShapeGuardTest.php`**
lints every test file in the repository for a negated `toContain()` / `toHaveKey()` / `toThrow()`
with more than one argument — the three matchers whose extra argument is a *value*, not a message.
It strips string literals and nested parentheses before counting commas, so
`not->toContain(implode(', ', $x))` and `not->toContain('a, b')` are correctly read as
single-argument calls. It carries its own negative control (three bad shapes it must flag, five
good ones it must not) and excludes only itself, by exact basename, because it holds the bad
shapes on purpose. Current result: **0 offenders across 30+ test files**, `dev`'s and mine.

### Minor, non-blocking — two stale test names in a `dev`-owned file

`packages/kernel/tests/Unit/SecretTest.php` still carries two case names and comments whose
premise BUG-1 disproved. The assertions are fine and still worth having; only the stated rationale
overstates what they buy:

- *"holds the value in no property at all, whatever a dumper chooses to honour"* — the comment
  says no property is reachable "by a future dumper". VarDumper reaches the value anyway, through
  the closure's `use` binding; that is exactly what BUG-1 was.
- *"redacts the value under var_dump, which is what dd() and dump() build on"* — `dd()` and
  `dump()` build on VarDumper, not `var_dump()`. That sentence is the belief that produced the
  defect, and it is still in the suite.

`dev` corrected both production docblocks properly, so this is only the test file lagging. Not
worth a fix round on its own; worth folding into whatever `dev` next touches there.

## Test run summary

- Commands:
  - `cd services/host && "E:\xampp\php\php.exe" "C:\ProgramData\ComposerSetup\bin\composer.phar" check`
  - `cd services/host && "E:\xampp\php\php.exe" vendor/bin/pest <path>` for targeted runs
- Baseline before my tests (verified independently): **246 passed, 1068 assertions, exit 0, 79 s**.
- Run 1, after adding 23 QA tests: **265 passed, 4 failed, 1184 assertions, 144 s**. The 4
  failures were the defect-pinning tests for BUG-1 and BUG-2.
- Run 2, after `dev`'s fixes and 6 more QA cases: 275 passed, 0 failed, 1207 assertions, exit 0,
  114 s. **That green was partly false** — see BUG-3.
- **Run 3, after rewriting the `dump()` cases and the R-2 assertion: 277 passed, 0 failed,
  1219 assertions, exit 0**, on two consecutive runs (98 s, 88 s). Pint `{"result":"passed"}`,
  PHPStan level 6 `[OK] No errors`, Deptrac `Violations 0` — my test files are covered by both
  and clean.
- **Every one of the 246 pre-existing tests still passes** (277 − 31 new = 246).
- Skipped: none.
- Gate duration measured at 100 s, 106 s, 114 s and 144 s across four runs, up from the 79 s
  baseline. Plan R-9 asks for this to be reported against **FP-155** rather than fixed by trimming
  tests; the top of that range is at the ~150 s threshold R-9 names.

New QA files (all `qa`-owned):
`packages/kernel/tests/Feature/QaTenantIsolationProofTest.php` (15 cases, self-contained fixture),
`packages/kernel/tests/Feature/QaCredentialSurfaceSweepTest.php` (7 cases, self-contained fixture),
`services/host/tests/Feature/QaModuleConnectionRemovalTest.php` (5 cases),
`services/host/tests/Feature/QaAssertionShapeGuardTest.php` (2 cases),
plus one line added to `qaFixturePackageNames()` in `QaToolchainRegistryCoverageTest.php` so the
`qatenancyfixture` package generated mid-run does not turn the registry backstop red.

## Defects

Both were found in run 1 and are **CLOSED in run 2** — see *Run 2* above for how each fix was
verified. The findings are kept in full because the mechanisms behind them are load-bearing for
FP-203 and easy to reintroduce.

### BUG-1 (major) — **CLOSED** | AC-13/AC-14 non-functional requirement | credential visible in `dd()` / `dump()`

- Failing tests:
  `packages/kernel/tests/Feature/QaCredentialSurfaceSweepTest.php`
  → "keeps the credential out of dump() and dd() output for the resolved tenant stanza"
  → "keeps the credential out of dump() and dd() output for a Secret and a ConnectionPassword"
- Expected: the spec's non-functional requirement — "No plaintext credentials, ever, anywhere
  observable. Not in an exception message, not in a log line, **not in debug/error output**".
  Both classes document the guarantee explicitly: `ConnectionPassword::__debugInfo()` is
  annotated *"Honoured by var_dump() and by Symfony's VarDumper, which is what dd() and dump()
  use"*, and `Secret`'s class docblock states *"there must simply be no property holding the
  credential"*. Plan step 12's table records `var_dump()/print_r() of the object` as **clean**.
- Actual: `dump()` and `dd()` do not use `var_dump()`; they use Symfony's `VarCloner`, and
  `Caster::castObject()` casts the object with `(array) $obj` **and then** merges `__debugInfo()`
  under separate *virtual* keys. The real property is rendered alongside the redacted one.
  Measured (`CliDumper`, needle `s3cret-DO-NOT-LEAK-7f3a`):

  ```
  Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword {#1759
    -value: "s3cret-DO-NOT-LEAK-7f3a"      <-- the real private property
    value: "[redacted]"                     <-- __debugInfo()
  }
  ```

  `Secret` is worse, and its closure-instead-of-a-property design does not help, because
  VarDumper renders a closure's captured `use` bindings:

  ```
  Flexipos\Kernel\Tenancy\Secrets\Secret {#4069
    -revealer: Closure(): string {#4071
      use: { $value: "s3cret-DO-NOT-LEAK-7f3a" }
    }
    value: "[redacted]"
  }
  ```

  All four surfaces leak: `Secret` and `ConnectionPassword`, each via `CliDumper` and
  `HtmlDumper`. `dump(config('database.connections.tenant'))` inside a tenant scope therefore
  prints the tenant's database password — and `dd()` is the first thing anyone reaches for when
  debugging a tenancy problem, with output that routinely ends up in a ticket or a chat thread.
- What does **not** leak (verified in the same sweep, so the scope of the defect is exact):
  PHP's own `var_dump()`, `print_r()`, `json_encode()`, Monolog's `LineFormatter` and
  `JsonFormatter`, `artisan config:show database.connections.tenant`, `artisan about`,
  `serialize()` (throws, fail-closed), and both exception renderers.
- Suspected location: `packages/kernel/src/Tenancy/Secrets/Secret.php`,
  `packages/kernel/src/Tenancy/Secrets/ConnectionPassword.php` — and
  `packages/kernel/src/Tenancy/TenancyServiceProvider.php` as the place a fix would be wired.
- Suggested fix (dev's call): register VarDumper casters for both classes, e.g. in
  `TenancyServiceProvider::register()`:
  `AbstractCloner::addCasters([Secret::class => $redact, ConnectionPassword::class => $redact]);`
  where `$redact` returns `['value' => TenancyException::REDACTED]`. A caster's return value
  replaces the cast array, so the private property and the closure binding both disappear.
  This is independent of how the value is stored — note that the "keep it out of a property"
  approach does **not** fix it, as `Secret` demonstrates. The docblocks on both classes and the
  step 12 table in the plan need correcting to match whatever ships.

### BUG-2 (major) — **CLOSED** | AC-15 | `DB::build()` / `DB::connectUsing()` bypass all three guard levels

- Failing tests:
  `packages/kernel/tests/Feature/QaTenantIsolationProofTest.php`
  → "refuses a tenant connection built through DatabaseManager::connectUsing with no tenant resolved"
  → "refuses a tenant connection built through DatabaseManager::build for another tenant"
- Expected: AC-15 — *"qa verifies there is no second, working path that achieves the same
  effect."* The plan already names `DB::build()`/`connectUsing()` as forbidden (Alternatives
  rejected; risk R-2) and ships a regression test for it.
- Actual: the existing regression test (`TenancyTenantSwitchTest` "keeps no hidden connection
  configuration that survives a purge") only proves that **`Tenancy` itself** does not use the
  API. Nothing stops anyone else, and the guard does not cover them, because
  `DatabaseManager::connectUsing()` calls `$this->factory->make($config, $name)` **directly**
  instead of going through `makeConnection()` — so the name-keyed extension that is the entire
  level-2 guard is never consulted, and level 3 is never attached. Verified on this branch
  (`vendor/laravel/framework/src/Illuminate/Database/DatabaseManager.php`, `connectUsing()` and
  `build()`):

  | probe | result |
  |---|---|
  | `DB::connectUsing('tenant', <A's config>, true)->scalar(...)` with **no tenant resolved** | served `tenant-a`'s row |
  | the subsequent `DB::connection('tenant')->scalar(...)` | served `tenant-a` — the unguarded object is now cached under the `tenant` name for the rest of the process |
  | `DB::connection('tenant')->table('marker')->value(...)` (builder/Eloquent path) | served `tenant-a` |
  | inside `enter('tenant-a')`: `DB::build(['name' => 'tenant', …B's config])->scalar(...)` | served **`tenant-b`** while `TenantContext::tenantId()` still reported `tenant-a` |
  | after an explicit `DB::purge('tenant')` | guard returns — `NoTenantResolved` |

  The last row of that table is the cross-tenant leak the whole task exists to prevent, reached
  through a public framework API, with `TenantContext` reporting the wrong tenant throughout and
  no exception raised anywhere.
- Severity rationale: **major, not critical.** No production code does this today (the source
  audit in the same file confirms exactly one writer of `database.connections.tenant`), and
  reaching another tenant's data this way needs that tenant's credentials. But AC-15's promise is
  precisely "one piece of code to audit", and that promise does not currently hold: a module
  author following ordinary Laravel documentation for a runtime connection lands on `DB::build()`,
  which is the one API the design forbids and the one the guard cannot see.
- Suspected location: `packages/kernel/src/Tenancy/TenancyServiceProvider.php` —
  `registerTenantConnectionGuard()` hooks `$manager->extend($connectionName, …)` only.
- Suggested fix (dev's call): `connectUsing()` *does* dispatch
  `Illuminate\Database\Events\ConnectionEstablished`. Listening for it and, when
  `$event->connectionName` is the tenant connection, attaching the existing level-3 guard to
  `$event->connection` — refusing outright when the connection's config carries no
  `Tenancy::TENANT_MARKER` — closes every construction route with no change to the current
  design. Level 2 stays where it is; this only makes level 3 unconditional.

## Two rulings

### 1. T-001 AC-25 is a faithful supersession, not a weakened test

`dev` changed three assertions, said so explicitly, and every one of them is a swap rather than a
deletion:

- `SvcMakeValidationTest.php:91` — `'config/connection.php'` → `'config/.gitkeep'` inside the
  required-file loop. Still `expect(File::exists(...))->toBeTrue()`; the list did not get shorter,
  and the `config/` directory is still required to exist.
- `SvcMakeValidationTest.php:111` — the positive `->toBe('svc_svcmakefixture')` became **two**
  negative assertions, `not->toHaveKey('connection')` **and** `not->toHaveKey('schema')`. That is
  stronger than what it replaced: it pins the removal instead of merely stopping checking, and
  `schema` was never asserted at all before.
- `SvcMakeAtomicGenerationTest.php:114` — the same swap in `atomicHostFingerprint()`, with the
  file-header comment corrected from "six rendered files and five .gitkeep markers" to "five
  rendered files and six .gitkeep markers", which matches `SvcMakeCommand::FILES` and
  `EMPTY_DIRECTORIES` as shipped.

Independently re-proved rather than taken on trust: `QaModuleConnectionRemovalTest` re-checks
every other element T-001 AC-25 required (`composer.json`, the provider class, the routes file
under its own prefix, an empty `database/migrations/`, an empty `Consumers/`, at least one test
file) and additionally sweeps **every file in the stub directory and every file in a freshly
generated package** for eight tokens of the removed model — with a negative control proving the
sweep detects a token when one is present. Coverage of this criterion is higher after T-003 than
before it.

### 2. The accepted `var_export()` residual is acceptable — but the residual *list* was not complete

On the residual itself: **acceptable, and better supported than the plan claimed.** The rule "a
stanza holding a `ConnectionPassword` is never exported" reads as merely stated, but the only
path in the framework that `var_export()`s configuration is structurally unable to see a tenant
stanza. `ConfigCacheCommand::getFreshConfiguration()` boots a **second `Application`** and caches
*that* one's configuration, so the caching process only ever sees the placeholder however deep
inside a tenant scope the calling process is. Measured: `artisan config:cache` invoked from
inside a resolved tenant scope exits 0 and writes

```php
'tenant' => array ( 'driver' => 'flexipos-no-tenant', 'database' => '', 'prefix' => '' ),
```

with neither the credential nor the string `ConnectionPassword` anywhere in the file. Together
with the deliberate absence of `__set_state()` — which makes an accidental export impossible to
load back — and with the object being better than the plain string it replaced at every surface
measured and worse at none, I would not spend a round changing it.

Two qualifications:

1. That safety rests on a **framework implementation detail**, and nothing in this repository
   asserted it. It does now: `QaCredentialSurfaceSweepTest` "caches configuration from a fresh
   application, so var_export never sees a tenant stanza" goes red the day `config:cache` starts
   serialising the live configuration.
2. **The list of residuals was incomplete, and that is the real finding here.** It named
   `var_export()` and direct string interpolation; it missed `dd()`/`dump()` (BUG-1), which is a
   far more likely surface than either. Note this is *not* a consequence of following the plan
   literally on storage: the alternative `dev` flagged — keeping the value out of a reachable
   property — would not have prevented BUG-1, because `Secret` already does exactly that and
   leaks through the closure's `use` bindings. `dev` was right to flag the trade-off rather than
   change it silently, and right not to change it; the gap is in the enumeration of surfaces, not
   in the storage decision. The lesson worth carrying into FP-203: enumerate leak surfaces by
   *running* them, not by reasoning about which magic methods each one honours.

## The two "false reds" `dev` restructured

Both were harness faults, both were restructured rather than relaxed, and both still fail for the
right reason:

1. **`ConnectionPasswordTest` "is recorded by a flattened trace as an object with no value"** —
   the original routed the subject through a helper closure, and PHP 8.2's `print_r()` renders a
   closure's captured `[static]` variables, so the helper reported its own captured needle. The
   fix calls the thrower directly. The test **kept its control half** (`expect($asString)->toContain($needle)`),
   so it goes red both if `ConnectionPassword` stops hiding the value and if the mechanism stops
   working at all. Verified independently: my own
   `QaCredentialSurfaceSweepTest` "pins the flattened-trace mechanism the whole credential design
   rests on" re-derives the same result through the **real `HtmlErrorRenderer`** with a
   runtime-generated needle (so it cannot appear in the rendered source excerpt) and its own
   string-vs-object control. It agrees: the plain string reaches the page, the object does not.
2. **`TenancyTenantSwitchTest` "refuses a query on a connection object held across a tenant
   switch"** — the restructure added one `disconnect()` in a `finally`; no assertion changed.

   > **Correction (review M-3).** This paragraph originally justified that `disconnect()` by
   > stating that `Connection::createTransaction()` reconnects *before* the
   > `beforeStartingTransaction` hooks run, so a refused transaction reopened a handle on the
   > sqlite fixture and Windows then failed the directory cleanup. **That is false, in the safe
   > direction**, and I had inherited it rather than measured it. `beginTransaction()` runs the
   > `beforeStartingTransaction` callbacks *first* and only then calls `createTransaction()`,
   > which is where `reconnectIfMissingConnection()` lives
   > (`Concerns/ManagesTransactions.php:126-130` and `:148-151`) — so our guard throws before any
   > reconnect can happen. Verified twice rather than re-read: after a refused transaction on a
   > stale connection, `getRawPdo()` is `null` and `transactionLevel()` is `0`; and `leave()`
   > already nulls a held object's PDO by itself, because `purge()` calls `disconnect()` on that
   > same instance. `dev` has since deleted the defensive call it justified, having measured the
   > same thing. Left visible instead of quietly edited out, because this report is the durable
   > record of what was checked and a reader cannot otherwise tell a measured fact from an
   > inherited one.

   It still asserts
   `toThrow(TenantConnectionMismatch::class, 'built for tenant [tenant-a]')` — wording only
   `forStaleConnection()` produces — and the transaction variant alongside it. Reproduced
   independently in `QaTenantIsolationProofTest` (held object, and a connection captured in a
   closure), with the same refusals.

I also added the control that makes those refusals *evidence*:
`QaTenantIsolationProofTest` "shows what a held connection does without the guard, on an unguarded
connection name" performs exactly the same moves on a connection name the tenancy extension does
not cover, and the held object **silently repoints itself at the second database and returns its
row**. That is the leak; the per-query guard is what converts it into a refusal. No production
code had to be touched to show it.

## Regression risks / notes

- **`artisan config:cache` swaps the global container instance and never restores it.**
  `Application::__construct()` calls `Container::setInstance($this)`, and
  `ConfigCacheCommand::getFreshConfiguration()` builds a second `Application` without putting the
  first one back — so after `Artisan::call('config:cache')`, `app()` and every facade point at the
  throwaway application. Consequence for tenancy: a tenant scope entered before such a call cannot
  be left afterwards, because `app(Tenancy::class)` resolves a fresh, empty instance and the real
  application keeps an open PDO on the tenant database. Nothing in FlexiPOS runs `config:cache` at
  runtime, so this is **not** a product defect — but FP-207/FP-206 should not start doing so
  inside a tenant scope. It is documented in the test that has to work around it.
- **`tenant::read` / `tenant::write` survive the teardown purge.** `DatabaseManager` caches a
  read/write-suffixed connection under that exact key, and `DB::purge('tenant')` unsets only
  `tenant`, so such an object outlives the tenant scope *inside the manager itself*, not merely in
  someone's property. The level-3 guard does catch it (`NoTenantResolved` with no tenant,
  `TenantConnectionMismatch` under another one) — verified and now pinned by
  `QaTenantIsolationProofTest`. Worth remembering when FP-203 adds real read replicas: teardown
  purges one key, only the per-query guard covers the rest.
- **`Event::fake()` re-opens the `build()`/`connectUsing()` route inside that test.** Only the
  `ConnectionEstablished` listener covers those, and a faked dispatcher does not run listeners, so
  a test that fakes events and then calls `DB::build(['name' => 'tenant', …])` gets data back.
  Not a product concern — production always has a real dispatcher, and the divergence runs in the
  **safe** direction (production refuses what the faked test allows) — but a module author could
  write a test that passes against code that fails in production. The ordinary route stays guarded
  either way, because the extension is still attached; that is exactly why keeping both
  attachment points was the right call. Deliberately not asserted either way in the suite, so a
  future improvement that closes it does not have to fight a test.
- **A refused `DB::build(['name' => 'tenant', …])` bricks the tenant connection for the process.**
  Fail-closed and loud, by design; pinned. See *Run 2* for the mechanism and the exact message.
- **Two agents running the suite at once corrupts it, loudly but confusingly.** One run-3 attempt
  reported **50 failures** — `PDOException`, `FileNotFoundException` and `UnexpectedValueException`
  across every test that generates, moves or regenerates a package under `services/`. None were
  real: `dev` was running the suite and editing production files at the same time, and those tests
  collide on `services/demo` and on shared fixture names. The signature to recognise is mass
  failures in the generator tests plus a child run's own `Tests:` line bleeding into the output.
  Confirm the tree is stable (`find … -newermt`) before trusting a red gate during a fix round.
- **Gate duration.** 79 s baseline → 88 s / 98 s / 100 s / 106 s / 114 s / 144 s across six clean
  runs with the QA tests added. Per plan R-9 this goes to the lead against **FP-155**, not to
  trimming tests.
- **Trimming the identifier.** `Tenancy::enter()` does `trim($tenantId)`, so `'  tenant-a  '`
  resolves `tenant-a`. Conventional, and no AC or decision covers it; flagged for FP-207, which
  owns the code→identifier translation (D-25), rather than encoding a guess here.
- **`InMemoryTenantDatabaseRepository::fromConfig()` defaults `status` to `''`.** A malformed
  mapping row is therefore refused by the AC-10 gate rather than defaulting to anything —
  deny-by-default holds through the config parser too. Confirmed by `TenantDatabaseTest`'s `''`
  dataset row.
- **Worktree.** Clean after both runs. `git status` shows only `dev`'s T-003 changes plus the five
  files `qa` owns. No fixture packages, no fixture directories under `storage/app/`, no
  `bootstrap/cache/config.php`, no stub left drifted. All new files are LF (checked with
  `grep -qU $'\r'`), per FP-156.
- **One `qa`-owned fixture needed a Windows-specific teardown.** `File::deleteDirectory()` opens a
  `FilesystemIterator` and then `rmdir()`s; on Windows the handle can outlive the unset, leaving an
  empty directory. Both kernel fixtures now delete twice. Noted because the same shape will bite
  FP-206's provisioning tests, which create far more per-tenant state than these do.

---

Run 1: FAIL — 2 major defects (BUG-1 credential in `dd()`/`dump()`, BUG-2 `DB::build()`/`connectUsing()` bypasses the tenant guard). 24/25 AC pass; AC-15 and one non-functional requirement fail.
Run 2: FAIL -> PASS — both defects closed and independently verified, plus `dev`'s three additions checked (extension kept alongside the listener, refusal before caching, `WeakMap` de-duplication). 25/25 AC and every non-functional requirement pass. Gate exit 0, 275 passed, 1207 assertions. 1 minor non-blocking note (two stale test names in `dev`-owned `SecretTest.php`).
Run 3a: PASS, reviewer APPROVED. M-3 closed — corrected a disproved claim about `createTransaction()` reconnecting before the `beforeStartingTransaction` hooks, which I had inherited rather than measured; re-verified against the framework source and by measurement, and left visible as a marked correction rather than edited out. No code change, gate unchanged.
Run 3: PASS (run 2's green was partly false) — reviewer found BUG-3 in my own sweep: both `dump()` cases measured a hand-built cloner, not the installed handler, and passed while the surface leaked. Rewritten as a child-process probe of the real handler with three controls; two further false-green shapes found and discarded while fixing it. Accepted the R-2 ruling and rewrote the assertion that had pinned the unfixed behaviour as a precondition. `toHaveProperty()` added to the assertion-shape guard. `dev`'s `WeakMap` storage also closed the `var_export()` residual, superseding Ruling 2 on that point. Gate exit 0, 277 passed, 1219 assertions, confirmed twice.
