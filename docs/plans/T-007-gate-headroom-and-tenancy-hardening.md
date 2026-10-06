# Plan: T-007 Gate headroom and tenancy hardening

Spec: `docs/specs/T-007-gate-headroom-and-tenancy-hardening.md` (AC-1 to AC-44) · Decisions:
`docs/decisions/T-007-gate-headroom-and-tenancy-hardening.md` (D-1 to D-9) · Covers **FP-172**,
**FP-160** and **FP-161**, which gate FP-203 · Branch `ai/T-007-gate-headroom-and-tenancy-hardening`
from the T-006 tip (`cef6a17`), because `main` still lacks T-005 and T-006.

Everything numbered below was measured on this working tree on **2026-10-06** by `architect`, with the
command beside it, and is marked **MEASURED**. Where a measurement contradicts the brief, the spec or
the backlog, the contradiction is named rather than quietly corrected. Nothing here was run through
`composer check`: per the brief I ran single-purpose probes in the scratchpad and read the installed
framework source. Framework version in the lock file: **laravel/framework v12.69.2**, PHP 8.2.12.

---

## Approach summary

Four code steps, one per concern, each independently green and each its own commit.

**FP-172 goes first** because every later step pays for it: a developer agent runs the gate per step,
the first of those runs is cold, and the condition that produced the false red on 2026-10-06 is still
in force (`composer config process-timeout` → **300**, MEASURED). The fix is a project-owned timer:
`modules/host/tools/gate-step.php` wraps the Pest invocation of the `test` script, computes its own
budget from the limit actually in force, kills the child before Composer can, prints a fixed sentinel
line, and exits **3**. Composer's limit is raised at the same time so the project's timer is the
binding one rather than merely a smaller ceiling, and every completed run ends with one line stating
elapsed seconds, the limit and the ratio.

**FP-160 goes second**: `db.factory` is decorated, and the connection extension, the
`ConnectionEstablished` listener, the static `WeakMap` and the test-only hole all disappear with it.
MEASURED in the installed framework: every construction route in the spec funnels into
`ConnectionFactory::make()`, so one decoration covers all of them with no event dependency.

**FP-161 goes third and depends on the second**: the stanza authenticator is checked in exactly one
place, and that place has to exist before the invariant is written, or the check gets written twice
and then moved — the T-006 failure mode the lead named. The authenticator is **not** a secret value;
it is a per-`enter()` random token bound, through a private record on a scoped authority object, to a
digest of the whole stanza. Copying the stanza and changing the database breaks the binding, which is
what AC-14 asks for and what neither the backlog's remedy nor the brief's sentinel hypothesis
delivers (R1, with the measurement).

**No document is affected** (R4). `docs/database.md` §4.4 and `docs/architecture.md` stay byte-identical.

## Alternatives rejected

- **`pcntl_alarm()` for the step timer.** Rejected, MEASURED: `php -m` on this machine lists no
  `pcntl` (Windows), and `max_execution_time` does not apply to CLI.
- **A dependency-free `proc_open()` wrapper**, matching `tools/clean-probes.php`'s no-dependency rule.
  Rejected: MEASURED, `Symfony\Component\Process` kills the whole process tree on Windows
  (`taskkill /F /T /PID`, Process.php:1526), which a hand-rolled `proc_open` does not, and Pest spawns
  children of its own. `symfony/process` v7.4.19 is a **production** dependency of the framework
  (`require` of laravel/framework), so this adds no dependency.
- **One runner process for the whole gate** (`check` becomes a single PHP entry point with per-step
  budgets). Rejected: Composer's limit would then apply to the sum instead of per step, and the runner
  would have to re-implement Composer's script resolution or duplicate five command strings — a second
  authority for each command (FP-181).
- **Wrapping `lint`, `stan` and `deptrac` too.** Rejected for this task: AC-12 requires those three
  scripts unchanged. The residual (a kill of one of them still shows only Composer's message) is
  written down in R7 as a follow-up candidate, not silently accepted.
- **Raising the timeout only, with no sentinel.** Rejected by AC-44 and by the incident itself: the
  false red was *unreadable*, not merely early.
- **A sentinel object in the stanza, held in a private `WeakMap`** (the brief's hypothesis). Rejected
  with a measurement: it **accepts** the AC-14 forgery. See R1.
- **A random token per scope, compared instead of the constant** (the backlog's FP-161 remedy).
  Rejected for the same measured reason, and because a per-container-scope token would not rotate
  between two `enter()` calls in one process, failing AC-19.
- **An HMAC over the stanza with a per-scope key held on the scoped object.** Equivalent security to
  the chosen design, rejected as the more complex of two equals: it needs key material and key
  lifetime rules, and it cannot revoke a disclosed digest, whereas dropping a record can.

---

## Rulings the lead asked for

### R1. Q2 — AC-14 stands as written. The authenticator binds to the stanza's content, not to a secret value

**The requirement does not change. The mechanism does.** Both candidate mechanisms on the table fail
AC-14, and they fail it for the same reason: they try to make the authenticator's *value*
unobtainable, when the thing AC-14 forbids is a *valid authenticator beside a changed database*.

MEASURED, `scratchpad/m6-authenticator.php` run on PHP 8.2.12 (both designs implemented side by side,
same stanza, same forger):

| Attempt | Sentinel object in a private `WeakMap` (the brief's hypothesis) | Per-`enter()` token bound to a whole-stanza digest (chosen) |
|---|---|---|
| Legitimate stanza | verifies as `tenant-a` | verifies as `tenant-a` |
| **AC-14**: copy every value, change `database` | **verifies as `tenant-a`** — accepted | `NULL` — refused |
| AC-14 variant: inject a `read` split pointing elsewhere | not covered by the mechanism | `NULL` — refused |
| **AC-13**: forger mints its own authenticator | `NULL` — refused | `NULL` — refused |
| Stanza retained after the scope ended | `NULL` once the entry is unset | `NULL` — refused |
| Same tenant entered twice: value differs (AC-19) | no (identity is stable) | yes |

So the brief's hypothesis is refuted on the one criterion it was proposed for. The reason is not a PHP
subtlety: the authority of a sentinel object is its **identity**, and an array copy carries the same
instance. `config()` is readable in scope, the forger copies the array, the sentinel comes along.

**Correction to my own hypothesis as stated in the brief.** I expected the sentinel to fail on
revocation — "the retained dynamic configuration keeps the instance alive, so the `WeakMap` entry can
never be cleared". MEASURED: that is wrong. `unset($map[$sentinel])` removes the entry regardless of
who holds the instance, so a sentinel design *can* revoke. It fails on content binding instead.

**And the two hazards the brief told me to test it against are not where it dies, so they are recorded
as measured non-issues rather than left implied:**

- *`DB::build()` retains configuration a `purge()` cannot clear.* Confirmed on the installed version,
  MEASURED: `DatabaseManager::configuration()` reads
  `$this->dynamicConnectionConfigurations[$name] ?? Arr::get($connections, $name)` (DatabaseManager.php:218)
  and `purge()` only unsets `$this->connections[$name]` (:299). So a retained stanza is immortal and is
  *preferred* over `config()`. Both designs survive it, because both record the tenant id beside the
  authenticator and the per-query guard still compares against the live context. The chosen design is
  strictly better here: the record is dropped on scope exit, so a rebuild from retained configuration
  is refused by the authenticator itself and not only by the tenant-id comparison.
- *A dumper merges `__debugInfo()` beside real properties.* Irrelevant to either design, and
  deliberately so: under the chosen design **the authenticator's disclosure is harmless**. A token
  authenticates exactly one stanza — the legitimate one — so printing it, logging it or dumping it
  gains a forger nothing. That is why AC-16 is cheap to satisfy and why `config:show` may keep printing
  the stanza.

**The mechanism.** A new scoped `TenantConnectionAuthority`:

- `authorise(array $stanza, string $tenantId): array` — drops any previous record, mints
  `bin2hex(random_bytes(16))`, returns the stanza with that token under one key, and stores
  `[$token => ['tenant' => $tenantId, 'digest' => digest($stanza)]]` in a **private** property.
- `verify(array $stanza): ?string` — the token must be a key of that private array **and**
  `hash_equals()` must match a digest recomputed from the stanza it was just handed. Returns the
  recorded tenant id, or `null`.
- `revoke(): void` — empties the records. Called from `Tenancy::resetTenantConnection()`.
- `digest()` is canonical over the **whole stanza except the authenticator key**: `ksort`, recursive
  into arrays, objects encoded as `class#spl_object_id` and **never stringified** (so the credential's
  plaintext is never copied into a digest input and `ConnectionPassword::__serialize()`'s refusal is
  never triggered), then `hash('sha256', …)`.

Why whole-stanza rather than a whitelist of redirecting keys: MEASURED, `ConfigurationUrlParser`
expands a `url` key into host/database **before** the factory sees the stanza
(`DatabaseManager::configuration()` → `parseConfiguration()`), and `ConnectionFactory::make()` branches
to a read/write connection when `isset($config['read'])`. A whitelist would have to track every key the
framework can be redirected by; the whole stanza needs no list and goes stale for nobody.

What closes which forger (D-6): **F1** cannot mint a token that is in the private array. **F2** can copy
the token but not keep the digest valid while changing anything that decides which database is opened.
**F3** (reflection into the live scoped object) is not defended, by decision, and is written beside the
mechanism as AC-22 requires.

### R2. Q3 — the sentinel is printable. Composer does not kill before project code runs; the step owns its own timer

Feasible on Windows and, by construction, on POSIX. MEASURED end to end in the scratchpad with the real
`composer.phar` and the real `symfony/process`:

| Probe | Result |
|---|---|
| Composer kills a spawned script at the limit and prints its own message | yes; exit status **1**, indistinguishable from a test failure (confirms D-3's premise) |
| Child output written before the kill survives and is visible | **yes** — ticks 0 to 5 appeared before the kill; the pipe is streamed, so a sentinel printed by project code is readable |
| Detection latency with `process-timeout: 2` | the child ran ~3.0 s before Composer killed it: Composer is **late**, never early |
| Composer exports the limit to the child's environment | **no** — only `COMPOSER_BINARY` and `COMPOSER_DEV_MODE`. The wrapper must resolve the limit itself |
| `COMPOSER_PROCESS_TIMEOUT` is visible to the child when set on the command line | yes (ordinary environment inheritance) |
| Project timer inside `COMPOSER_PROCESS_TIMEOUT=2`: budget 1 s | sentinel printed at **elapsed 1.52 s**, wrapper exit 3, total wall 1.74 s standalone / inside Composer the step ended at 1.52 s, well before Composer's own limit |
| A composite script (`check`'s shape) after a step exits 3 | the following step does **not** run, and **exit code 3 propagates verbatim** to the shell |

So the shape the lead expected is the shape that works, with one measured addition: the exit code is
free (R3). The wrapper's budget must be below the limit in force, with a reserve that covers its own
boot plus Symfony's timeout-detection latency (MEASURED ≈ 0.6 s at a 0.25 s output cadence):

```
reserve = max(2, ceil(limit * 0.02))
budget  = max(1, limit - reserve)
```

A ratio with a floor, not an absolute (FP-171). At `limit = 2` the budget is 1 s, which is the case
MEASURED above; at `limit = 900` it is 882 s.

**One measured problem with AC-4 as worded, for `ba` — not blocking, no spec edit needed, and the
criterion is satisfiable today.** AC-4 says "the gate run with `COMPOSER_PROCESS_TIMEOUT=2`". With a 2 s
limit, Composer kills **`lint`** first — `check` runs `probes:clean`, `lint`, `stan`, `deptrac`, `test`,
each its own process, each with the same limit — and the test step never starts, so no sentinel is
printed and AC-4 fails for a reason that has nothing to do with the sentinel. Two recipes are therefore
given to `qa` in the test strategy: the per-step form (`COMPOSER_PROCESS_TIMEOUT=2 composer test`,
seconds, deterministic) and the whole-gate form (a limit derived from qa's own AC-1 numbers so that only
the test step exceeds it). AC-4's intent is met by both; the per-step form is the one that always works.

### R3. Q4 — the kill gets a distinct exit code **as well as** the sentinel, because the code turns out to be free

MEASURED: Composer propagates a script's exit status verbatim through a composite script
(`"composite": ["@first", "@killed", "@after"]` returned **3** to the shell and skipped `@after`). So
"an exit code would force a wrapper around every invocation" — the cost D-3 weighed — does not apply
here; the wrapper already exists, and the code costs one `exit(3)`.

Decision: **exit 3 on a kill by the project's own timer**, and the sentinel line stays the primary
discriminator exactly as D-3 requires. This does not contradict D-3, which requires the status to be
non-zero and permits it to equal a failure's status rather than requiring it. Ambiguity residual: if
Pest itself ever exits 3, the wrapper passes that 3 through **without** the sentinel, so the pair
(status, sentinel) is unambiguous and the sentinel alone is sufficient. Reviewer may overrule the code;
removing it is a one-line revert and the sentinel-based criteria stay green.

### R4. Q1 — no document is affected. `docs/database.md` §4.4 and `docs/architecture.md` are untouched

MEASURED, repeating `ba`'s search with the mechanism names this plan introduces: neither document
mentions the marker, the constant, `ConnectionEstablished`, the connection extension, the guard levels,
`beforeExecuting` or a choke point. The single overlap is **`docs/database.md` §4.4, line 262**, which
states that the resolver "calls `config()->set('database.connections.tenant', …)` + `DB::purge('tenant')`".

That sentence stays true, word for word:

- the only write to `database.connections.tenant` is still one `config()->set()` inside `Tenancy`, with
  the whole stanza replaced wholesale — the authority **returns** an array and never touches `config()`,
  which is a hard constraint of this design because `QaTenantIsolationProofTest`'s "exactly one
  production write" sweep asserts exactly one matching production file and names `Tenancy.php`;
- the teardown is still placeholder-`set()` plus `purge()`, unchanged by FP-160 and FP-161;
- the stanza gains one key, and §4.4 enumerates no stanza keys — the `…` in that sentence covers it.

So per AC-38 the plan says: **no document affected**, and `qa` repeats the search from the spec's
"Current behaviour" section as its evidence.

*Observation for the lead, not an edit and not mine to make:* §4.4 names the class
`TenantConnectionResolver`, which does not exist — the code calls it `Tenancy`. Pre-existing drift of the
FP-186 family, untouched by this task, worth a backlog row rather than a silent fix inside T-007.

### R5. Ordering is load-bearing: FP-172, then FP-160, then FP-161

- **FP-172 first** for a practical reason that has already cost this project a day: the limit that
  produced the false red is still 300 s (MEASURED), `dev` must run the gate at every step, and the first
  of those runs is cold. Fixing the gate before using it four times is the cheapest ordering available.
- **FP-160 before FP-161.** The invariant depends on the choke point. If the authenticator landed first,
  its check would have to be written into the connection extension **and** the `ConnectionEstablished`
  listener, and then moved when the choke point replaced both — two copies of a security check, briefly,
  and then a migration of one. That is precisely the shape the lead flagged from T-006: a guard written
  under one invariant becoming wrong when a later change moves which thing is authoritative.
- **A deliberate consequence, and the proof that the two rows are not one row:** AC-13 and AC-14 stay
  **red after FP-160 lands** and go green only in the FP-161 step. FP-160 consolidates *where* the check
  happens; it does not change *what* is checked, so an F1 forger who computes the tenant id still passes.
  `dev` must paste both results (red after step 3, green after step 4) into the step-4 commit message.

### R6. The new invariant is stronger than today's, and it breaks three existing assumptions. Named here, not discovered by `dev`

Today the stanza can be edited after `enter()` and the guard only checks one key of it. After FP-161
**the stanza is immutable once authorised: any post-hoc mutation of any key is a refusal.** That is the
point of the content binding, and it has consequences that must be in the plan rather than in a QA bug
report:

1. **`QaTenantIsolationProofTest.php`, the control at line 228** ("still reaches the driver when a tenant
   IS resolved") mutates `database.connections.tenant.database` to an unopenable path inside a scope and
   asserts the refusal is **not** a `TenancyException`, proving the guard let it through to the driver.
   Under the new invariant that mutation is itself the refusal, so the control must reach the driver
   another way: point the fixture **mapping** at the unopenable path *before* entering, then assert the
   refusal is `TenantConnectionFailed` and is **not** `TenantConnectionMismatch`/`NoTenantResolved` — the
   discrimination the control exists for ("the authenticator did not stop it, the driver did") survives
   intact. This edit is not in AC-41's allowed list and is pre-justified here so `qa` can report it as
   required rather than as a surprise.
2. **`packages/kernel/tests/Support/tenancy-fixture.php`, `tenancyComparableStanza()`** must substitute
   the authenticator by type, exactly as it already substitutes `password` by type and for the same
   stated reason: a per-`enter()` token makes two stanzas for the same tenant unequal under `toBe()`, and
   `TenancyTenantSwitchTest:84-100` compares them. AC-19 is asserted separately against the raw stanza, so
   nothing is lost. Also not in AC-41's list; pre-justified here.
3. **`QaCredentialSurfaceSweepTest.php`, lines 378-381** asserts that `config:show` printed the real
   stanza by looking for `flexipos_tenant` **and the tenant id `qa-cred-a`**. The tenant id is no longer in
   the stanza, so this positive control goes red whatever the key is called. It must prove the stanza was
   printed by a value that is still there and still not a secret — the username `qa_cred_app` — keeping
   the `ConnectionPassword::class` and no-credential assertions exactly as they are. Allowed by AC-41 (it
   reads the literal), and the reason for the edit is the missing tenant id, not the key name.

### R7. Residuals, written down because the sentence above is only true with them

Each belongs in the comment AC-22 requires, or in the QA report, as marked.

- **F3 is not defended** (D-6): reflection into the live scoped authority, or rebuilding the container,
  mints a valid authenticator. AC-13 and AC-14 are the proof of F1 and F2 and are named in the comment.
- **The mapping source is trusted.** `TenantDatabaseRepository` is built from `config('tenancy.databases')`;
  code that can rewrite that key before the singleton resolves can map any tenant id to any database. Not
  a forgery of the stanza and not in this task's threat model — FP-203 replaces the in-memory repository
  with the master-database reader — but it is the real floor under the isolation guarantee and belongs in
  the comment.
- **A genuine stanza can be rebuilt under a different connection name** (`DB::build(['name' => 'other', …])`
  with the stanza as-is). It reaches only the forger's own tenant, it is guarded per query like any other,
  and it escapes only the name-based teardown. FP-163 territory; comment only.
- **A third party registering a name-keyed extension for the tenant connection would bypass the choke
  point** (MEASURED: `DatabaseManager::makeConnection()` consults `$this->extensions[$name]` *before*
  the factory, DatabaseManager.php:195). Closed cheaply by a standing test asserting no extension is
  registered under the tenant connection name — included in step 3.
- **`spl_object_id` reuse** in the digest's object encoding requires the original object to be freed;
  `config()` holds a strong reference to the `ConnectionPassword` for the life of the record, so an id
  cannot be recycled while the record is live. Comment only.
- **A kill of `lint`, `stan` or `deptrac` still shows only Composer's message**, because AC-12 requires
  those scripts unchanged. The wrapper is written as a generic step runner, so applying it to them later
  is a one-line change per script. QA report, and a backlog candidate for the lead.
- **On POSIX, `Process::stop()` signals the direct child only** (Windows uses `taskkill /F /T`, MEASURED),
  so a Pest grandchild could briefly outlive a kill. `probes:clean` first in `check` is what makes that
  harmless, and AC-8 is the test.

---

## Measured surface — what the code does today

MEASURED by reading the installed framework and the current sources.

| Fact | Where |
|---|---|
| Every construction route funnels into `ConnectionFactory::make()`: `connection()` → `makeConnection()` → `$this->factory->make()`; `connectUsing()` → `$this->factory->make()`; `build()` → `connectUsing()` | DatabaseManager.php:93, 117, 147, 185, 203 |
| The factory is **injected into the manager at construction** (`__construct($app, ConnectionFactory $factory)`), so the decoration must be registered before `db` is resolved, and the decorator must be a subclass of `ConnectionFactory` | DatabaseManager.php:75 |
| A name-keyed extension is consulted **before** the factory | DatabaseManager.php:195 |
| `configuration()` prefers `$dynamicConnectionConfigurations[$name]` over `config()`, and `purge()` never clears it | DatabaseManager.php:218, 299 |
| `ConfigurationUrlParser::parseConfiguration()` returns the stanza unchanged when there is no `url` key, and pulls/expands `url` when there is | Support/ConfigurationUrlParser.php |
| `ConnectionFactory::make()` adds `prefix` and `name` **inside** `make()`, after an override's check would have run, and branches to read/write on `isset($config['read'])` | Connectors/ConnectionFactory.php:42, 60 |
| The per-query hooks live in `Connection::$beforeExecutingCallbacks` and `Connection::$beforeStartingTransaction` | Connection.php:197, 204 |
| `'db.factory'` is a plain singleton closure, so `extend()` composes | DatabaseServiceProvider.php |
| **The decoration really reaches the manager on this application**: extending `db.factory` and then booting the host, `DatabaseManager`'s injected `$factory` is the decorator (`decoration landed: true`) | `scratchpad/m7-factory-extend.php`, real `bootstrap/app.php`, MEASURED today |
| The marker is written once and read at three places; tests read it at five | `Tenancy.php:51,327,364`; `TenancyServiceProvider.php:143,242`; `TenancyResolutionTest:56`, `TenancyTenantSwitchTest:103`, `TenancyCredentialLeakTest:275`, `QaTenantIsolationProofTest:198`, `QaCredentialSurfaceSweepTest:241,381` |
| `composer config process-timeout` in `modules/host` | **300** (no key in the manifest; Composer's default) |
| Analysis caches the cold definition must remove | `modules/host/storage/framework/cache/phpstan/` (directory) and `storage/framework/cache/.deptrac.cache` (named in the `deptrac` script). No PHPUnit result cache is configured and no `.phpunit.cache` exists; `bootstrap/cache/config.php` is cleared by the `test` script itself |
| `tools/` is inside the PHPStan paths (level 6) and the Pint targets, and is **not** in the deptrac paths | `phpstan.neon.dist`, the `lint` script, `deptrac.yaml` |
| `ToolchainGroupCoverageTest` asserts `test` and `check` carry no `--group`/`--exclude-group`, and that `test:fast`/`test:slow` do | ToolchainGroupCoverageTest.php:44-68 |
| A new test file that spawns a child process **must** be listed in `toolchainGroupFiles()`, or the registry sweep fails | `modules/host/tests/Helpers.php:56`, ToolchainGroupCoverageTest layer 2 |
| `QaTenantIsolationProofTest` asserts **exactly one** production file writes `database.connections.*`, and names `Tenancy.php` | QaTenantIsolationProofTest.php:463 |
| `QaTenantIsolationProofTest` reflects on `TenancyServiceProvider::$guardedConnections`, so removing the map turns that test into a `ReflectionException` | QaTenantIsolationProofTest.php:665 |

## Files to change / create

Owner in brackets. `dev` owns production sources and the non-`Qa*` tests; `qa` owns `Qa*` test files,
fixtures and `docs/qa/` — the repository's existing convention, kept because step 3 and step 4 would
otherwise be blocked by red tests `dev` may not touch.

**FP-172**

- `modules/host/tools/gate-step.php` — **new** [dev]. The project's own timer: resolves the limit in
  force, kills the child at its budget, prints the sentinel, prints the margin line last, exits 3 on a
  kill. Contract in step 1.
- `modules/host/composer.json` — [dev]. `config.process-timeout`; `extra.flexipos.gate` (the one
  authority for the cold measurement, its date and the headroom factor); the Pest entry of `scripts.test`
  wrapped; `scripts-descriptions.test` extended to point at that authority. `check`, `lint`, `stan`,
  `deptrac`, `test:fast`, `test:slow` and `dev` are untouched (AC-3, AC-12).
- `modules/host/tests/Feature/GateStepTimerTest.php` — **new** [dev]. Wrapper behaviour, four spawned
  children plus the manifest assertions.
- `modules/host/tests/Helpers.php` — [dev], one line: register the file above in `toolchainGroupFiles()`.
  Mechanically required; report it under AC-41.

**FP-160**

- `packages/kernel/src/Tenancy/TenantConnectionFactory.php` — **new** [dev]. The choke point.
- `packages/kernel/src/Tenancy/TenancyServiceProvider.php` — [dev]. Register the authority and the
  decoration; delete the extension, the listener, `$guardedConnections`, `guardEveryQuery` (moved) and
  the now-unused imports; rewrite the class docblock's level story.
- `packages/kernel/tests/Feature/TenantConnectionChokePointTest.php` — **new** [dev].
- `packages/kernel/tests/Unit/TenancyFrameworkContractTest.php` — [dev]. One new case: the framework
  contract the choke point depends on, in the file's existing "if this fails, the framework changed"
  idiom.

**FP-161**

- `packages/kernel/src/Tenancy/TenantConnectionAuthority.php` — **new** [dev]. Mint, verify, revoke,
  canonical digest, and the AC-22 residual comment.
- `packages/kernel/src/Tenancy/Tenancy.php` — [dev]. Authorise the stanza on write, revoke on reset,
  delete `TENANT_MARKER`, replace `configuredTenantId()` with a context read, update the docblock.
- `packages/kernel/src/Tenancy/Exceptions/TenantConnectionMismatch.php` — [dev]. `forConfiguration()`
  becomes `forUnauthorisedConfiguration(string $tenantId)`.
- `packages/kernel/tests/Feature/TenantStanzaForgeryTest.php` — **new** [dev].
- `packages/kernel/tests/Feature/TenancyResolutionTest.php`, `TenancyTenantSwitchTest.php`,
  `TenancyCredentialLeakTest.php` — [dev]. The three marker-reading assertions (AC-41 allowed).

**qa-owned**

- `packages/kernel/tests/Support/tenancy-fixture.php` — [qa]. `tenancyComparableStanza()` tolerance (R6.2).
- `packages/kernel/tests/Feature/QaTenantIsolationProofTest.php` — [qa]. The retention control (AC-26),
  the AC-27 replacement, the rewritten driver-reach control (R6.1), the marker-reading stanza at line 198,
  the AC-23 table and AC-30.
- `packages/kernel/tests/Feature/QaCredentialSurfaceSweepTest.php` — [qa]. Lines 237-241 and 378-381 (R6.3).
- `docs/qa/T-007-gate-headroom-and-tenancy-hardening.md` — **new** [qa].

**Not touched:** `docs/database.md`, `docs/architecture.md`, `CLAUDE.md`, `docs/backlog.md`,
`Refference/`, `composer.lock`, any migration, any schema, any CI file (D-5).

## Data / API changes

No schema, no migration, no endpoint, no master or tenant table.

- **The tenant connection stanza** loses the key holding the tenant id and gains one key holding a
  per-`enter()` token: `flexipos_tenant_authenticator`, a private constant of
  `TenantConnectionAuthority`. No other class names that key; the factory asks the authority whether a
  stanza carries one. The tenant id is no longer in the stanza at all — the authority's private record
  holds it. `docs/database.md` §4.4 enumerates no stanza keys, so nothing published changes (R4).
- **`Tenancy::TENANT_MARKER` is deleted** (AC-20). Nothing outside `packages/kernel` referenced it
  (MEASURED).
- **`TenantConnectionMismatch::forConfiguration(string, ?string)` → `forUnauthorisedConfiguration(string)`**.
  The forger-supplied value is no longer echoed into a message (there is no trustworthy configured tenant
  to name), and the sentence `Tenancy is the only code allowed to write that configuration` is kept
  verbatim so the existing assertion keeps its meaning.
- **`Tenancy`'s constructor gains `TenantConnectionAuthority`**, bound `scoped` beside `TenantContext`
  and `Tenancy` itself. Nothing outside the kernel constructs `Tenancy` (MEASURED).
- **Configuration**: `modules/host/composer.json` gains `config.process-timeout` and
  `extra.flexipos.gate` with `cold-slowest-step-seconds`, `measured-on`, `measured-by`, `remeasure-with`
  and `headroom-factor`. MEASURED: nothing reads the host manifest's `extra` (the module registry reads
  only module manifests, and the host's own `extra` is asserted by no test), so the key is free to add.
  It is the **single authority** for that number (FP-181), the wrapper and the ratio test both read it,
  and it carries its own date (FP-171, AC-11, AC-42).

---

## Implementation steps

Owner in brackets. **One commit per step** — on this project a developer agent lost all uncommitted work
three times before that became the rule. Every step states its gate expectation; a step marked
**RED FIRST** must show the new test failing *before* the production change and the failure must be
pasted into the commit message, because a guard whose first run is green is deferred, not shipped (AC-40).

- [ ] **Step 0 — Baseline, nothing changed [dev].** In `modules/host`: `composer config process-timeout`
      (expect **300**, MEASURED by `architect` today); `composer check` warm, recording the exit status
      and Pest's `Tests:`/`Assertions:` line for AC-34; then time the five steps individually
      (`composer probes:clean`, `composer lint`, `composer stan`, `composer deptrac`, `composer test`)
      to give `qa` a warm per-step profile and the window it needs for AC-4's whole-gate recipe. Keep
      the numbers in the scratchpad, out of the repository, and hand them to `qa`. Note for `qa`:
      Composer's own boot was MEASURED at ≈1.9 s, so five separate invocations cost ≈8 s more than one
      `check`. *Gate: green. No commit.*

- [ ] **Step 1 — FP-172: the gate's own timer and a sentinel on a kill [dev].**
      *Covers AC-2, AC-3, AC-4 (**RED FIRST**), AC-5 (wrapper half), AC-6, AC-9, AC-10, AC-11, AC-12,
      AC-42, AC-44.*
      Write `GateStepTimerTest.php` first and run it: with no `tools/gate-step.php` on disk the kill
      case cannot produce the sentinel, which is AC-7's statement about the T-006 tip made executable.
      Then build it:

      `modules/host/tools/gate-step.php`, invoked as `php tools/gate-step.php <label> -- <command…>`:
      - `declare(strict_types=1)`, requires `../vendor/autoload.php` and nothing else, no framework boot;
      - limit resolution, in order, with the chosen source named in the output so nobody has to guess:
        `COMPOSER_PROCESS_TIMEOUT` when it is a positive integer → `config.process-timeout` from the
        `composer.json` beside it → `300` (Composer's documented default);
      - `reserve = max(2, ceil(limit * 0.02))`, `budget = max(1, limit - reserve)` (R2, with the
        measured justification in the file header);
      - runs `[PHP_BINARY, ...$command]` from the host directory with `Process` timeout `budget`,
        streaming stdout and stderr through unchanged so Pest's output is unaltered;
      - on `ProcessTimedOutException`: `stop(1.0, 9)`, then **one** line and `exit(3)`:
        `FLEXIPOS-GATE-TIMEOUT step=<label> at=<ISO-8601> elapsed=<n>s budget=<n>s limit=<n>s source=<env|manifest|default> — the gate ended this step on its own timer before the process limit was reached; NO TEST RESULT WAS PRODUCED.`
      - on completion: when `limit / elapsed < headroom-factor`, print
        `FLEXIPOS-GATE-LOW-HEADROOM step=<label> ratio=<n> below the required <n>x (D-1); raise config.process-timeout — see extra.flexipos.gate in modules/host/composer.json.`
        then **always last** (AC-9):
        `FLEXIPOS-GATE-MARGIN step=<label> at=<ISO-8601> elapsed=<n>s limit=<n>s ratio=<n>`
        and exit with the child's own status;
      - the sentinel token appears on no other path, which is what makes AC-5 and AC-6 true by
        construction rather than by assertion;
      - every duration in the file header is a ratio or carries its date and the command that
        re-measures it (AC-11).

      `modules/host/composer.json`:
      - `scripts.test` becomes `["@php artisan config:clear --ansi", "@php tools/gate-step.php test -- vendor/bin/pest"]`.
        `config:clear` stays its own sub-second process, outside the measured step;
      - `config.process-timeout`: provisional **900**, chosen by the rule
        `limit ≥ (headroom-factor × cold S) / 0.98`, rounded up to a 300 s multiple, minimum 600 — the
        0.98 is the budget reserve, so the **budget** clears 2 × S and not merely the limit. `dev` sets
        `extra.flexipos.gate.cold-slowest-step-seconds` from its own cold measurement of the slowest
        step; `qa` re-measures after the final code change and raises the limit if the ratio test fails
        (AC-43). The incident's figures are motivation, never evidence;
      - `extra.flexipos.gate`: `cold-slowest-step-seconds`, `measured-on`, `measured-by`,
        `remeasure-with` (the exact command), `headroom-factor: 2`;
      - `scripts-descriptions.test`: say that the step owns its own timer, that a kill prints
        `FLEXIPOS-GATE-TIMEOUT` and exits 3, and that the limit's justification lives in
        `extra.flexipos.gate` — one authority, every other mention pointing at it (FP-181).

      `GateStepTimerTest.php` (new file, **must** be added to `toolchainGroupFiles()` in the same commit —
      it spawns children, and the registry sweep fails otherwise): a child outlasting the budget →
      sentinel present, exit 3, the line naming the step, the limit and "no test result"; a fast child
      exiting 0 → margin line last, no sentinel, exit 0; a child exiting 1 → margin line, no sentinel,
      exit 1; a child slower than half the limit but inside the budget → low-headroom warning then the
      margin line last, exit 0; and the manifest assertions: `config.process-timeout` is a positive
      integer `≥ headroom-factor × cold-slowest-step-seconds`, no `check`/`test`/`test:fast`/`test:slow`
      entry and no `config` key disables the timeout, `extra.flexipos.gate.measured-on` parses as a date,
      `check` still lists its five steps in order, `test:fast`/`test:slow` still carry their group flags.
      Each spawned case forces its own tiny limit through the child's environment, so the whole file runs
      in seconds; the slow, whole-gate forms of AC-4 to AC-8 are `qa`'s.
      *Gate: green.* Commit `T-007: FP-172 give the test step its own timer and a sentinel on a kill`.

- [ ] **Step 2 — Two test edits that must land before the mechanisms move [qa].**
      *Covers AC-26; unblocks steps 3 and 4.*
      Both are green at the T-006 tip **and** after the whole change, which is why they can land first —
      and both are deliberately not red-first criteria: AC-26 is the positive control that replaces a test
      pinning the thing being removed (D-9).
      1. `QaTenantIsolationProofTest.php:665` — replace the `ReflectionProperty(TenancyServiceProvider::class,
         'guardedConnections')` test with AC-26's retention control: twenty-five switches, each querying, a
         `WeakReference` to a connection from an earlier switch released after `gc_collect_cycles()`.
         Without this edit, step 3's removal of the map turns a `Qa*` file into a `ReflectionException` and
         `dev` would have to edit a file it does not own to get through the hook.
      2. `packages/kernel/tests/Support/tenancy-fixture.php` — `tenancyComparableStanza()` substitutes the
         authenticator key by type when it is present, exactly as it already does for `password` and for
         the same reason (R6.2). Written so it is a no-op today (the key does not exist yet), so this
         commit is green before and after step 4.
      *Gate: green, counts = baseline.* Commit `T-007: FP-160 replace the map-pinning test with a retention control`.
      If `qa` is unavailable, `dev` makes both edits inside step 3 and reports them line by line under
      AC-41 — but message `qa` first; this is `qa`'s file.

- [ ] **Step 3 — FP-160: one construction choke point [dev].**
      *Covers AC-21 (both refusal classes), AC-23 (dev half), AC-24, AC-25, AC-27 (**RED FIRST**), AC-28,
      AC-29 and AC-31 (retained), plus the two installation invariants.*
      The marker's semantics are **unchanged** in this step — the stanza still carries the tenant id — so
      the commit is one concern, and AC-13/AC-14 stay red at the end of it by design (R5).

      `TenantConnectionFactory` (new): `final class TenantConnectionFactory extends ConnectionFactory`,
      constructed with the application and **the factory it replaces**, overriding
      `make(#[SensitiveParameter] array $config, $name = null)`:
      - delegate straight to the inner factory for any connection that is neither the tenant connection
        name nor a stanza carrying the tenancy key — the second half of that test is what stops
        `make($legitimateStanza, null)` producing an unguarded tenant connection, and it costs one array
        key lookup (R7). In this step "the tenancy key" is still the marker; in step 4 the factory asks
        the authority `carries($stanza)` instead, so the key name lives in exactly one class;
      - placeholder driver, or no tenant resolved → `NoTenantResolved::forConnection($name)`;
      - a stanza the authenticator does not vouch for, while a tenant **is** resolved →
        `TenantConnectionMismatch` (AC-21). In this step that is still "the marker is absent or names
        another tenant"; step 4 swaps the condition and nothing else;
      - the driver whitelist, moved verbatim from the extension;
      - `return self::guardEveryQuery($this->inner->make($config, $name), …)` — `guardEveryQuery` moves
        here from the provider, with the `WeakMap` de-duplication deleted, because one choke point means
        one attachment (AC-24, AC-25);
      - `#[SensitiveParameter]` on `$config`, as the extension has today (the stanza holds the resolved
        credential and this closure raises the refusals).
      Delegating to the inner factory rather than calling `parent::make()` keeps any other extender of
      `db.factory` composed instead of silently discarded; extending `ConnectionFactory` is still required,
      because `DatabaseManager::__construct` type-hints it (MEASURED).

      `TenancyServiceProvider`: `$this->app->extend('db.factory', …)` in `register()`, **before** anything
      can resolve `db`; delete `registerTenantConnectionGuard`, `registerTenantConnectionEstablishedGuard`,
      `$guardedConnections`, `guardEveryQuery`, the `callAfterResolving('db')` hook and the `WeakMap` and
      `ConnectionEstablished` imports (AC-25, AC-28); rewrite the class docblock so the level story names
      the factory decoration as level 2 and says what was removed and why.

      `TenantConnectionChokePointTest.php` (new): the seven routes of the spec's list as a table —
      `DB::connection('tenant')`, an Eloquent model on the tenant connection, `DB::build()` with the tenant
      name, `DB::connectUsing()`, `app('db.factory')->make($stanza, 'tenant')`, reconnect after `purge`, and
      a read/write-split stanza — each run with no tenant, with a stanza Tenancy did not write, and with a
      legitimate stanza (AC-23); the hook count via reflection on `beforeExecutingCallbacks` and
      `beforeStartingTransaction`, exactly one of each (AC-24); `ReflectionProperty(…, 'guardedConnections')`
      throws `ReflectionException`, plus a source search of `packages/kernel/src/Tenancy/` finding no
      `WeakMap` holding a `Connection` and no `ConnectionEstablished` reference (AC-25, AC-28);
      **AC-27 red first** — `Event::fake()` with a connection built through `DB::build()`/`connectUsing()`
      and queried after a switch throws `TenantConnectionMismatch`; the AC-23 table repeated under
      `Event::fake()` with identical results (AC-28); and the two installation invariants — the resolved
      `DatabaseManager`'s injected factory **is** ours, and no name-keyed extension is registered under the
      tenant connection name (R7). Each guard row has the paired positive control AC-40 requires.

      `TenancyFrameworkContractTest.php`: one case in the file's existing idiom — `DatabaseManager` still
      takes a `ConnectionFactory` by constructor injection, `makeConnection()` and `connectUsing()` still
      call `$this->factory->make()`, and `configuration()` still prefers `dynamicConnectionConfigurations`.
      If the framework moves any of those, the choke point claim is false and this names it in one read
      instead of leaving a silent hole.
      *Gate: green. AC-13 and AC-14 are expected to be **red** here — record that in the commit message.*
      Commit `T-007: FP-160 one construction choke point for the tenant connection`.

- [ ] **Step 4 — FP-161: an authenticator bound to the stanza [dev].**
      *Covers AC-13 (**RED FIRST**), AC-14 (**RED FIRST**), AC-15, AC-16, AC-17, AC-18, AC-19, AC-20,
      AC-21, AC-22, AC-30, AC-39.*
      Write `TenantStanzaForgeryTest.php` first; AC-13 and AC-14 must fail against the step-3 tip with the
      **intended** exception absent and a tenant-B row actually returned, and that output goes in the
      commit message. Then:

      `TenantConnectionAuthority` (new, `scoped`): `authorise()`, `verify()`, `revoke()` and the private
      canonical `digest()` exactly as specified in R1. Hard constraints: it never calls `config()` (the
      single-write sweep), it stores only two strings per record and never the stanza (no credential object
      retained past the scope, AC-26/AC-30), it never logs or interpolates a token, and the comment beside
      it states the F3 and mapping-source residuals and names AC-13/AC-14 as the proof of F1 and F2 (AC-22).

      `Tenancy`: `writeTenantConnection()` builds the stanza without the tenant id and writes
      `$this->authority->authorise($stanza, $database->tenantId)` through the one existing
      `config()->set()`; `resetTenantConnection()` calls `revoke()` **after** the purge loop and the
      placeholder write, so the rollback of an open transaction still runs against a live connection
      (AC-31); `configuredTenantId()` becomes a context read with the existing `'unknown'` fallback — the
      log line keeps saying `tenant-a`, which `TenancyCredentialLeakTest` asserts; `TENANT_MARKER` deleted;
      docblock updated to describe the authenticator and to keep the `set()`-plus-`purge()` paragraph
      §4.4 depends on.

      `TenantConnectionFactory`: the one condition changes — `verify()` returning `null` is the refusal,
      and the tenant id for the per-query guard comes from the authority's record rather than from the
      stanza.

      `TenantConnectionMismatch`: `forConfiguration()` → `forUnauthorisedConfiguration(string $tenantId)`,
      keeping the "only code allowed to write that configuration" sentence and carrying no forger value
      and no credential (AC-30).

      `TenantStanzaForgeryTest.php` (new): AC-13 (F1 — the published source plus `TenantContext::tenantId()`,
      no valid authenticator); AC-14 (F2 — copy **every** value including the token and change `database`,
      then as separate rows `host`, `username`, and an injected `read` split); both run against every route
      in step 3's table (AC-15); AC-16's surface search over `TenantContext`, `Tenancy::current()`, every
      refusal message and every log record the guard writes; AC-19 — the same tenant entered in two scopes
      produces different tokens and the first scope's stanza is refused in the second; AC-21's two classes;
      AC-39's control — the identical set-up with the stanza untouched succeeds and returns tenant A's row,
      and every forgery test names the exception class and stage it expects.

      The three AC-41-allowed edits: `TenancyResolutionTest:56` and `TenancyTenantSwitchTest:103` stop
      asserting the tenant id in the stanza and assert instead that the authority vouches for it and that
      the value is not the tenant id; `TenancyCredentialLeakTest:275` becomes the F1 forgery with the new
      message. **AC-19 is answered "yes, per `enter()`"**, so the spec's conditional clause applies as
      written and needs no substitute.
      *Gate: green.* Commit `T-007: FP-161 bind the tenant stanza to an authenticator Tenancy alone can mint`.

- [ ] **Step 5 — The proof suite and the measurements [qa].**
      *Covers AC-1, AC-2, AC-4 to AC-8 (whole-gate forms), AC-10, AC-23, AC-27, AC-28, AC-30, AC-32,
      AC-33, AC-34, AC-35, AC-37, AC-38, AC-39 to AC-44.*
      `QaTenantIsolationProofTest.php`: the AC-23 table in qa's own words; the AC-27 replacement (the
      declining paragraph at line 627 becomes an assertion, its comment rewritten — the two assertions it
      already makes stay green throughout); the rewritten driver-reach control (R6.1); the line-198 stanza;
      AC-30's credential-free refusal for every row. `QaCredentialSurfaceSweepTest.php`: R6.3.
      AC-32's mutation runs in a scratch copy — the decoration removed, then the guard attach commented
      out — with AC-23, AC-27 and AC-28 failing in both. The cold measurements, the two AC-4 recipes, the
      AC-8 re-run and the AC-33 cold `composer check` per the test strategy below. The report lists every
      edited test file line by line (AC-41), accounts for every digit followed by `s`/`sec`/`second` in the
      changed files (AC-42), and repeats the §4.4 search (AC-38, R4).
      *Gate: green, cold, sentinel absent, with the AC-9 line recorded.*
      Commit `T-007: QA proof suite and the cold gate measurement`.

- [ ] **Step 6 — Hand-off [lead].** `docs/backlog.md` rows FP-160, FP-161 and FP-172 are the lead's to mark
      after the reviewer approves (AC-36). Nothing in this task edits the backlog, `CLAUDE.md`,
      `docs/database.md` or `docs/architecture.md`.

---

## Test strategy

**Unit**
- `TenancyFrameworkContractTest` — the four framework facts the choke point rests on, in the existing
  "if this fails the framework changed, not us" idiom.
- `GateStepTimerTest`'s manifest cases — the limit is finite, positive, and at least the headroom factor
  times the dated cold measurement; no script disables the timeout; the five steps and the two group flags
  are intact. This is how AC-2, AC-3, AC-11 and AC-42 become **standing assertions** instead of prose that
  decays (FP-171's own remedy).

**Feature / integration**
- `GateStepTimerTest`'s four spawned cases — kill, pass, failure, low headroom. Seconds, deterministic,
  no whole-gate run. The file joins the `toolchain` group.
- `TenantConnectionChokePointTest` — seven routes × three states, twice (real dispatcher and `Event::fake()`),
  plus hook counts, absence proofs and the two installation invariants.
- `TenantStanzaForgeryTest` — F1 and F2 against every route, the surface search, token rotation, both
  refusal classes, and the legitimate control.
- `QaTenantIsolationProofTest` / `QaCredentialSurfaceSweepTest` — qa's independent table, the retention
  control, the credential surfaces.

**Measurement recipes for `qa` (every one re-runnable, every number dated)**
1. **Cold definition (AC-1).** Delete `modules/host/storage/framework/cache/phpstan/` and
   `modules/host/storage/framework/cache/.deptrac.cache`; record the paths removed and confirm no
   PHPUnit result cache exists. Then run the five steps in `check`'s order, each timed
   (`date +%s%N` in Git Bash, `Measure-Command` in PowerShell), and record each wall time, the exit
   status and the date. Note Composer's ≈1.9 s boot per invocation (MEASURED). S is the largest of the
   five. Then write S into `extra.flexipos.gate.cold-slowest-step-seconds` with today's date, and re-run
   the ratio assertion (AC-2, AC-43).
2. **AC-2's "(diff)" number at the T-006 tip.** `git stash` or a scratch worktree at `cef6a17`, then
   `composer config process-timeout` → **300** (MEASURED by `architect` today), against a cold step that
   exceeded it on 2026-10-06 → a ratio below 1. Report the number, not the reasoning.
3. **AC-4, per-step form (always works).** `COMPOSER_PROCESS_TIMEOUT=2 composer test` in `modules/host`:
   budget 1 s, the sentinel appears at ≈1.5 s, exit 3. MEASURED in the scratchpad with the real
   `composer.phar` before this plan was written.
4. **AC-4, whole-gate form (the criterion's literal wording).** Choose
   `COMPOSER_PROCESS_TIMEOUT = ceil(1.2 × the slowest non-test step)` from recipe 1, and check it is below
   0.8 × the test step's time. If no such window exists on the machine, record that and use recipe 3 —
   with the measured reason (R2: at a 2 s limit Composer kills `lint` and the test step never starts).
5. **AC-8.** Force the kill mid-suite — about half the test step's measured time — so probe-planting tests
   have run, then `composer check` with the normal limit and expect exit 0 with no surviving probe.
6. **AC-10.** `COMPOSER_PROCESS_TIMEOUT ≈ 1.5 × the test step's measured time`: the step completes at
   ≈67 % of the limit, inside the 98 % budget, so the low-headroom warning fires and the status is 0. With
   the normal limit the warning must be absent.
7. **AC-33, AC-43.** One cold whole-`composer check` after the final code change: exit 0, sentinel absent,
   AC-9's line pasted into the report.

**Which tests prove which criteria**

| Criterion | Proof |
|---|---|
| AC-1, AC-2 (number), AC-33, AC-43 | qa's cold runs, recipes 1 and 7 |
| AC-2, AC-3, AC-11, AC-12, AC-42 (standing) | `GateStepTimerTest` manifest cases |
| AC-4, AC-7, AC-44 | `GateStepTimerTest` kill case (fast) + recipes 3 and 4 (whole gate) |
| AC-5, AC-6 | `GateStepTimerTest` failure and pass cases; sentinel absent by construction |
| AC-8 | recipe 5 |
| AC-9, AC-10 | `GateStepTimerTest` margin and low-headroom cases + recipe 6 |
| AC-13, AC-14, AC-15, AC-16, AC-19, AC-21, AC-39 | `TenantStanzaForgeryTest` |
| AC-17, AC-18, AC-30, AC-31 | `TenancyResolutionTest`, `TenancyTenantSwitchTest`, `TenancyCredentialLeakTest`, `QaCredentialSurfaceSweepTest`, qa's table |
| AC-20, AC-22 | source search plus AC-13 passing; the comment, by reading |
| AC-23, AC-24, AC-25, AC-27, AC-28 | `TenantConnectionChokePointTest` + qa's independent table |
| AC-26 | step 2's retention control |
| AC-29 | the retained T-003 purge test, unchanged |
| AC-32 | qa's mutation runs |
| AC-34, AC-35, AC-37, AC-38, AC-41 | the QA report against step 0's baseline |
| AC-36 | the lead, after approval |

**Criteria red at the T-006 tip, by step** — AC-4 in step 1, AC-27 in step 3, AC-13 and AC-14 in step 4,
and AC-13/AC-14 are **still red at the end of step 3**, which is the evidence that FP-160 did not
accidentally close FP-161.

## Risks & rollback

| Risk | Mitigation / detection | Rollback |
|---|---|---|
| Something resolves `db` before `TenancyServiceProvider::register()`, so the manager keeps the undecorated factory and the choke point is absent | Measured not to happen on today's boot (`decoration landed: true`, and that probe extended *later* than a provider would), and the installation invariant test in step 3 asserts it on every run — it fails loudly rather than leaking quietly | bind earlier in `register()`; worst case re-register `db` itself |
| A framework upgrade adds a key between `configuration()` and the factory, or renames `make()` | Fails **closed**: every tenant connection is refused and the whole tenancy suite goes red on the first `composer update`. `TenancyFrameworkContractTest`'s new case names the cause in one read | widen the digest's excluded keys, or pin the framework |
| A third party registers a name-keyed extension for the tenant connection and bypasses the factory | The standing test in step 3 | remove the extension; the test names it |
| `process-timeout: 900` lets a genuinely hung step hang for 15 minutes | The wrapper's budget is the binding limit for the test step, and the margin line reports the ratio on every run | lower the limit; the ratio test tells you how far |
| Pest's own exit code 3 read as a kill | The sentinel is the discriminator (D-3); a child's 3 arrives with the margin line, not the sentinel | drop the distinct code (one line) |
| A POSIX kill leaves a Pest grandchild running | `probes:clean` first in `check`, proved by AC-8 | none needed; stated as a residual |
| The per-`enter()` token makes a stanza comparison in some future test non-deterministic | `tenancyComparableStanza()` substitutes it by type, the precedent the credential already set | the helper is one function |
| Colour or formatting of Pest's output changes because the wrapper sits between Composer and Pest | `dev` eyeballs one run; if colour is lost, add `--colors=always` to the wrapped command | one argument |
| Steps land out of order and the authenticator check is written twice | R5; the step order is a criterion of this plan, not a preference | revert step 4 alone: the choke point stands without it |

Each step is one commit, so `git revert` of step 4 restores the marker while keeping the choke point,
`git revert` of step 3 restores both hooks, and step 1 is independent of both.

## Questions for the human

None. Two things for the lead's queue rather than the human's:

1. `docs/database.md` §4.4 names `TenantConnectionResolver`, a class that does not exist — the code calls
   it `Tenancy`. Pre-existing drift, untouched by this task (R4), worth a backlog row.
2. AC-4's wording ("the gate run with `COMPOSER_PROCESS_TIMEOUT=2`") is unachievable in the whole-gate
   form at a 2 s limit, because Composer kills `lint` first and the test step never starts (MEASURED, R2).
   The criterion is satisfiable as written by the per-step run and by the derived-limit whole-gate run, both
   specified above, so `ba` need not change the spec — but if `ba` would rather the words matched the
   recipe, "the gate's test step" is the phrase.
