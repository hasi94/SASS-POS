# T-007: Gate headroom and tenancy hardening

Closes backlog rows **FP-172** (gate headroom against the process timeout), **FP-160** (one construction
choke point for the tenant connection) and **FP-161** (an unforgeable tenant marker). These three gate
FP-203. Decisions D-1 to D-9 are in `docs/decisions/T-007-gate-headroom-and-tenancy-hardening.md`.

**This task is code.** It changes `modules/host/composer.json`, `packages/kernel/src/Tenancy/`
(`Tenancy.php`, `TenancyServiceProvider.php`) and their tests. Every criterion below is checked by
**running something**. Where it needs the diff or history instead, it is marked **(diff)**: `ba` has had no
shell in any session on this project and cannot run `git`, so those are for `qa` or `reviewer`.

## Problem / goal

**FP-172.** Composer kills any script after 300 seconds by default and `modules/host/composer.json` sets no
`process-timeout`. On **2026-10-06** the gate exceeded 420 seconds wall on a cold cache, the test step was
killed, and the run reported **exit 1 with no test failure**: a false red on a clean tree. The re-run
was exit 0 at 156 seconds. Six warm runs that day were 66.95, 74.94, 80.25, 81.47, 84.59 and 89.98 seconds.
A false red teaches people to re-run instead of to read, and on CI every run is cold, so the condition that
fired is the normal one there.

**FP-160.** The per-query tenant guard is attached from two hooks (the connection extension and a
`ConnectionEstablished` listener), a static `WeakMap` (`guardedConnections`) stops it attaching twice, and
when a test fakes the event dispatcher the `build()`/`connectUsing()` route is unguarded (the test-only hole,
which `QaTenantIsolationProofTest.php` currently declines to assert). One choke point removes all three.

**FP-161.** The marker `Tenancy::TENANT_MARKER` is a public constant and its value is the tenant id, which
`TenantContext::tenantId()` publishes. Code inside one tenant's scope can therefore write a stanza that
carries the correct marker and another tenant's database. "Only `Tenancy` writes the stanza" is a convention,
not an invariant.

## Users affected

- Every developer and reviewer running `composer check`; CI, which meets a cold machine every run.
- `architect` and `dev` of FP-203 and every module that touches the tenant connection.
- Shop owners, indirectly: a forged or stale connection reads another shop's data.

## Current behaviour

- `modules/host/composer.json` has no `config.process-timeout`. `check` runs `probes:clean`, `lint`,
  `stan`, `deptrac`, `test` in order. `probes:clean` first is deliberate: it is why the killed run left no
  surviving probes. That held and must keep holding.
- A kill and a genuine failure both exit 1. Nothing in the output separates them except Composer's own
  timeout message.
- `TenancyServiceProvider` attaches level 3 from `registerTenantConnectionGuard` (extension) and from
  `registerTenantConnectionEstablishedGuard` (listener), de-duplicated by the static `$guardedConnections`.
- `Tenancy::TENANT_MARKER` is `'flexipos_tenant'`; the value written is `$database->tenantId`. It is read
  back at three places (the extension, the listener, `configuredTenantId()`). Tests read it at
  `TenancyTenantSwitchTest`, `TenancyResolutionTest`, `TenancyCredentialLeakTest` and
  `QaTenantIsolationProofTest`, and a literal `'flexipos_tenant'` is used in `QaCredentialSurfaceSweepTest`.
- Measured by `ba` on 2026-10-06 by search: neither `docs/database.md` nor `docs/architecture.md` mentions the
  marker, the constant, `ConnectionEstablished` or the guard levels (see Open question 1).

## User stories

- As a developer, I want a killed gate to say it was killed, so that I never re-run a red I should have
  read.
- As a maintainer, I want the timeout stated as a ratio to a dated cold measurement, so that growth of the
  suite cannot silently put it back under the limit.
- As the owner of tenant isolation, I want exactly one place that can produce a usable tenant connection,
  so that the isolation rule is an invariant rather than a convention.
- As a module author, I want a forged or stale tenant connection refused on every construction route, so
  that I cannot leak another shop's rows by accident.

## Acceptance criteria

Terms. **Cold run**: a full `composer check` after every analysis cache the gate uses has been removed
(PHPStan result cache, the deptrac cache file named in the `deptrac` script, the Pest and PHPUnit caches);
`qa` records which paths were removed. **Slowest process** (S): the longest wall time of any single process
the gate spawns, measured cold. **Sentinel**: a fixed line of output, exact text chosen in the plan, that
the gate's own code prints when it ends a step because the time limit was reached.

### A. FP-172: headroom, and a kill that is visible

- **AC-1** (measurement, dated): Given the cache removed as defined, When `qa` runs `composer check` to
  completion on the development machine, Then the QA report records the date, the exact command, the cache
  paths removed, the wall seconds of each process the gate spawns, and the exit status. This record is the
  only source of S.
- **AC-2** (headroom, a ratio): Given S from AC-1 and no `COMPOSER_PROCESS_TIMEOUT` set, When `qa` reads the
  limit in force (`composer config process-timeout` run in `modules/host`, plus any per-step limit the
  plan introduces), Then the limit for every process the gate spawns is at least **2 times S** (D-1). QA
  states the computed ratio. The same check against `composer.json` at the T-006 tip gives a ratio below 1
  (default 300 s against a cold run that exceeded it); `qa` runs it there too and reports the number
  **(diff)**.
- **AC-3** (bounded): Given the final `composer.json`, When `qa` searches the `check`, `test`, `test:fast`
  and `test:slow` scripts and `config`, Then none sets the timeout to 0 or calls
  `Composer\Config::disableProcessTimeout` (D-2). `dev` keeps that call, which already exists.
- **AC-4** (a kill of the test step is not a pass, and is not silent; per-step form, D-10): Given
  `composer test` (the gate's test step, run on its own) with `COMPOSER_PROCESS_TIMEOUT=2` and the suite
  still running at 2 seconds, When the limit is reached, Then the exit status is non-zero **and** the
  output contains the sentinel, naming the step and the limit in force and stating that no test result was
  produced. The whole-gate form is AC-45. The original wording ("the gate run with a 2-second limit") was
  unachievable: at that limit Composer kills `lint` first and the test step never starts, so no sentinel can
  appear and the criterion would fail for a reason unrelated to the mechanism.
- **AC-5** (negative control, a failure is not a kill): Given a deliberately failing test (planted with the
  existing probe mechanism and removed afterward) and the normal limit, When the gate runs, Then the exit
  status is non-zero, the output names the failing test, and the output does **not** contain the sentinel.
- **AC-6** (a pass is not a kill): Given a clean tree and the normal limit, When the gate runs to completion,
  Then the exit status is 0 and the sentinel is absent.
- **AC-7** (what the new check can fail): AC-4 (per-step form) is red against the T-006 tip, whose output
  on a kill is Composer's own message with no sentinel. `qa` runs AC-4 there and reports it **(diff)**.
- **AC-8** (no wreckage after a kill): Given a run ended as in AC-4, When `composer check` is run again
  with the normal limit, Then it exits 0, so no probe or generated package survived. `probes:clean` is still
  the first step of `check`.
- **AC-9** (margin reported on every completed run): Given a gate that ran to completion, When it ends,
  Then the last line states elapsed seconds, the limit in force and their ratio (D-4).
- **AC-10** (margin warning that can fire): Given `composer test` run on its own (per-step form, D-10)
  with `COMPOSER_PROCESS_TIMEOUT` set so the step takes more than half but less than all of it, When the gate completes, Then the output carries a low-headroom
  warning and the exit status is 0. Given a normal limit and a normal run, the warning is absent.
- **AC-11** (a number carries its date or is a ratio, FP-171): Given every place the change writes a
  duration or a limit (the `scripts-descriptions` text, any comment, the QA report), When read, Then each is
  a ratio, or carries the date measured and the command that re-measures it. This one is by reading.
- **AC-45** (whole-gate form of AC-4, gated on AC-1; D-10): Given S per step from AC-1 and a
  `COMPOSER_PROCESS_TIMEOUT` chosen **between the longest of `lint`, `stan` and `deptrac` and the cold wall
  time of the test step** (so only the test step can reach it), When `composer check` runs, Then `lint`,
  `stan` and `deptrac` complete, the test step is ended, the exit status is non-zero and the sentinel
  appears. `qa` states the limit chosen and the AC-1 numbers it was derived from. It cannot be run before
  AC-1 exists; until then it is pending, not failed.
- **AC-12** (other scripts untouched): `test:fast` still excludes the `toolchain` group, `test:slow` still
  runs only it, `lint`, `stan` and `deptrac` are unchanged, and `check` still runs its five steps in the
  same order.

### B. FP-161: the marker cannot be forged

Forger capabilities (D-6): **F1** knows the published source, including every constant. **F2** runs inside
the scope of tenant A, can call every public method (`Tenancy::current()`, `TenantContext`, `config()`,
`DB`) and can read the current connection stanza. **F3** reflects into the live scoped object or rebuilds the
container: out of scope, recorded as the residual (AC-22).

- **AC-13** (forgery refused, F1; red against today's code): Given tenant A resolved and a stanza written
  with `config()->set('database.connections.tenant', ...)` that points at tenant B's database and carries
  the marker value an F1 forger can compute from the published source and `TenantContext::tenantId()`, When
  a connection is requested and a query run, Then it throws a `TenancyException` subclass and no row of
  tenant B is returned. Today this succeeds, so the test is red first.
- **AC-14** (forgery refused, F2; the case the backlog's own remedy does not cover): Given the same setup
  but the forger first **reads** the current stanza and copies **every** value in it, including any
  marker or token Tenancy put there, changing only the database, host or username, When a connection is
  requested and a query run, Then it throws and no row of tenant B is returned. See Open question 2: a
  random token stored only in the stanza passes AC-13 and fails here.
- **AC-15** (every route): AC-13 and AC-14 are run against each route in AC-23's list, not only
  `DB::connection('tenant')`.
- **AC-16** (the marker is not obtainable from the public surface): Given tenant A in scope, When `qa`
  inspects `TenantContext`, `Tenancy::current()`, every exception message a refusal path raises, and the
  log lines the guard writes, Then the value used to authenticate the stanza appears in none of them.
  (The stanza in `config()` is covered by AC-14, not by this one.)
- **AC-17** (legitimate path untouched): Given tenant A then tenant B entered through `Tenancy::run()`, When
  each runs a query, Then each reads only its own database, and the credential-leak and credential-surface
  tests pass with no change to what they assert.
- **AC-18** (a stale authenticator is refused): Given a connection object built in tenant A's scope and held
  across a switch to B, When it is queried, Then it throws `TenantConnectionMismatch` (the existing level-3
  behaviour, retained).
- **AC-19** (marker rotates per scope): Given the same tenant entered twice in separate scopes, When the
  value that authenticates the stanza is compared across the two scopes by a test that is allowed to
  reflect, Then it differs, so a value captured in one scope is refused in the next. **(Applies if the
  chosen design uses a per-scope value; the plan states whether it does. If not, the plan must say what
  replaces this and `qa` must test that instead.)**
- **AC-20** (the public constant is gone or inert): When `qa` searches `packages/` and `modules/` for
  `TENANT_MARKER`, Then there is no public constant whose value alone authenticates a stanza. Search
  result alone is not accepted: AC-13 must also pass.
- **AC-21** (refusal wording, review R-5): Given a `tenant` connection built with no valid authenticator
  while a tenant **is** resolved, When it is refused, Then the exception is `TenantConnectionMismatch`, not
  `NoTenantResolved`. Given no tenant resolved, it is `NoTenantResolved`. Both are asserted.
- **AC-22** (residual written down): The comment beside the mechanism states that F3 is not defended and
  names AC-13 and AC-14 as the proof of F1 and F2. By reading.

### C. FP-160: one choke point; three things disappear

Routes (every one is a row in a table-driven test): `DB::connection('tenant')`; an Eloquent model on the
tenant connection; `DB::build(...)` with the tenant name; `DB::connectUsing(...)` with the tenant name; the
connection factory's `make()` called directly with a tenant stanza; reconnect after `purge`; a
read/write-split stanza (`tenant::read` and `tenant::write` cache keys).

- **AC-23** (one choke point): Given each route above with **no** tenant resolved, When the connection is
  requested or a query is run, Then it throws `NoTenantResolved`. Given each route with a tenant resolved and
  a stanza Tenancy did not write, Then it throws a `TenancyException` subclass. Given each route with a
  stanza Tenancy wrote, Then the query succeeds and returns only that tenant's rows. Every route passes the
  one mechanism; `qa` names it in the report.
- **AC-24** (the doubled attachment disappears): Given a connection built by the ordinary route, When `qa`
  counts the registered `beforeExecuting` and `beforeStartingTransaction` callbacks (reflection is
  acceptable), Then there is exactly **one** of each carrying the tenant guard. Today there is one only
  because a map hides the second; the test must pass with no map present (AC-25).
- **AC-25** (the de-duplication map disappears): Given `TenancyServiceProvider`, When
  `new ReflectionProperty(TenancyServiceProvider::class, 'guardedConnections')` is constructed, Then it
  throws `ReflectionException`; and a search of `packages/kernel/src/Tenancy/` finds no `WeakMap` that holds
  a `Connection`, and no `static` property that holds one.
- **AC-26** (positive control for AC-25, nothing is retained): Given twenty-five tenant switches each
  querying, When a `WeakReference` taken to a connection of an earlier switch is checked after
  `gc_collect_cycles()`, Then it is released. This replaces the reflection test on the map, which pins
  the thing being removed.
- **AC-27** (the test-only hole disappears; red against today's code): Given `Event::fake()` is active and
  tenant A resolved, When a connection is built through `DB::build()` or `DB::connectUsing()` and queried
  after a switch to tenant B, Then it throws `TenantConnectionMismatch`. Today that route is unguarded under
  `Event::fake()`, so this is red first. The test that declines to assert it (in
  `QaTenantIsolationProofTest.php`) is replaced by this one.
- **AC-28** (no dependency on the event): Given `Event::fake()` active, When AC-23's table is run, Then
  every row gives the same result as with a real dispatcher. A search of `packages/kernel/src/Tenancy/`
  finds no reference to `ConnectionEstablished`.
- **AC-29** (handles left live, review R-2): Given a read/write-split tenant stanza, When the scope ends,
  Then `DB::getConnections()` holds no key beginning with the tenant connection name. Retained from T-003
  and still covered by a test that fails if the purge of suffixed names is removed.
- **AC-30** (credential never in a refusal): Given each refusal in AC-23 with a distinctive fake password in
  the stanza, When the exception is cast to string and `getTraceAsString()` is read, Then the password
  appears in neither. The credential-surface sweep passes unchanged in what it asserts.
- **AC-31** (open transaction on scope exit): Given an open tenant transaction when the scope ends, Then it
  is rolled back, a warning is logged and `leave()` does not throw. Retained.
- **AC-32** (hook count, as a mutation) **(diff)**: Given the new choke point disabled in a scratch copy
  (the decoration removed or the guard attach commented out), When AC-23, AC-27 and AC-28 run, Then each
  fails. A guard test that stays green with the guard removed is the defect this task exists to stop.

### D. Regression, review findings and process

- **AC-33**: The full `composer check` ends exit 0 with the sentinel absent, run cold by `qa`, and the QA
  report records the AC-9 line.
- **AC-34**: Every test in `packages/kernel/tests/` that passed at the T-006 tip passes now, except the
  tests named in AC-41.
- **AC-35** (every closed review finding has a criterion, the lesson of T-006 AC-78): For each review
  round, `qa` lists every finding the reviewer reports closed and next to each the AC or test name that
  verifies it. A finding with none is itself a defect against this spec.
- **AC-36**: The backlog rows FP-160, FP-161 and FP-172 are not marked done by `dev` or `qa`. The lead does
  it after the reviewer approves.
- **AC-37** (the vocabulary sweep stays green): New comments in source and tests do not quote the pre-T-005
  vocabulary, since source and `composer.json` are not exempt. The gate run in AC-33 covers this.
- **AC-38** (docs): If the plan changes anything `docs/database.md` or `docs/architecture.md` states (Open
  question 1), the plan says so by section, `architect` makes the edit, and the document's version line and
  `CLAUDE.md`'s citation of it are the lead's to check. If nothing changes, the plan says "no document
  affected" and `qa` repeats the search from "Current behaviour".

### E. Likely-wrong criteria (standing section)

These test the ways a code task most often passes for the wrong reason.

- **AC-39** (a test that passes for a reason unrelated to the change): Given AC-4, AC-13, AC-14 and AC-27,
  each test must name, in a comment or assertion message, **which refusal** it expects (exception class and
  stage). `qa` confirms each fails with the **intended** exception rather than an unrelated one (a missing
  SQLite file, a bad driver, a typo in the stanza). A control run with a legitimate stanza and the
  identical set-up must succeed, proving the failure comes from the forgery.
- **AC-40** (a guard with no negative control): Every new guard assertion (AC-5, AC-13, AC-14, AC-23,
  AC-27) has a paired positive control that passes with the guard on; and AC-32 shows the guard test fails
  with the guard off. A guard whose first run is green is deferred, not shipped.
- **AC-41** (a criterion satisfiable by editing the test rather than the code) **(diff)**: The diff of
  `packages/kernel/tests/` and `modules/host/tests/` touches only: the reflection test on
  `guardedConnections`, the `Event::fake()` test that declines to assert, tests that read
  `Tenancy::TENANT_MARKER` or the literal `'flexipos_tenant'`, and new tests. Any other edit or deletion
  is justified in the QA report line by line. Assertions loosened (`toBe` to `toContain`, a deleted
  `expect`) are reported even when listed.
- **AC-42** (a measurement with no date): Every duration or limit in the QA report and in
  `composer.json` has a date or is a ratio (AC-11). `qa` greps the changed files for digits followed by
  `s`, `sec` or `second` and accounts for each.
- **AC-43** (a number that was never re-measured): The S used in AC-2 comes from a run made **after** the
  final code change to the gate, not from the 2026-10-06 incident figures, which are the motivation and not
  the evidence.
- **AC-44** (the timeout setting is not itself the fix for the wrong problem): AC-4 uses a limit forced
  below the test step's duration, so it proves the sentinel regardless of how generous the real limit is. A
  generous limit alone, with no sentinel, fails AC-4 and AC-7.

## Out of scope

- CI workflow files, job-level timeouts and cache restoration (D-5).
- Defending against F3 (reflection into the live scoped object, rebuilding the container).
- FP-163 (raw handles and lazily evaluated results across a tenant switch).
- Making the suite faster; FP-172 is headroom and visibility, not speed.
- Read-replica routing (FP-203); the read/write stanza appears here only as a construction route.
- Any change to the tenant database schema, the master schema or a migration.
- Editing `docs/backlog.md`, `docs/database.md`, `docs/architecture.md`, `CLAUDE.md`, `Refference/`.

## Non-functional requirements

- **Security**: no credential value in any new exception, log line, trace or dump (AC-30). The new choke
  point receives the connection stanza as an argument and must keep it out of `getTrace()` as the current
  extension does with `#[SensitiveParameter]`.
- **Performance**: the guard adds no query and no network round trip. The per-query check stays a context
  comparison. The gate's own added step (AC-9) costs a negligible share of S; `qa` records its wall time.
- **Reproducibility**: every measurement is a re-runnable command with its date beside it.

## Open questions

1. **Does closing FP-160 or FP-161 change what `docs/database.md` or `docs/architecture.md` states?** `ba`
   searched both on 2026-10-06 and found no mention of the marker, `ConnectionEstablished`, the connection
   extension or the guard levels; but `docs/database.md` section 4.4 describes the stanza and the
   `config()->set()` plus `purge()` teardown, and a design that changes the stanza's shape or the teardown
   would touch it. **This is the architect's call and possibly the owner's; `ba` has not assumed either
   way.** Not blocking the start of work. **BLOCKING for any edit to either document.** [architect / owner]
2. **RESOLVED (architect, plan 29c45ac): AC-14 stands unchanged.** Mechanism: a per-`enter()` token plus a
   private digest of the whole canonical stanza, so a copied token cannot keep the digest valid. A sentinel
   object was also refuted (a copy carries the same instance). Kept below for the record.
   **The backlog's FP-161 remedy may not be enough.** It says: generate a random token per scope, hold it
   on the scoped object, compare that. But the token must be written into the stanza for the connection
   to carry it, and `config()` is readable by any code in scope, so a forger of capability F2 copies the
   token and changes only the database. AC-14 requires that to be refused. Satisfying it needs the stanza's
   content (host, port, database, username) to be bound to a value only the scoped object can produce, not
   merely a token that sits beside it. `ba` states the requirement and leaves the mechanism to `architect`;
   if the architect finds AC-14 unattainable, that is a change to the requirement and goes to the lead
   before `dev` starts. [architect, BLOCKING for FP-161's plan]
3. **RESOLVED by measurement (architect): my premise was false.** Composer is late, never early (a 2 s
   limit let the child run about 3.0 s), and child output written before the kill survives. The sentinel is
   printable; AC-4 and AC-7 change only in scope (per-step, D-10). Kept below for the record.
   **Can the gate print its own sentinel on a kill?** If Composer aborts the script on its timeout before
   any project code runs, the sentinel in AC-4 needs a step that owns its own timer (as
   `tools/clean-probes.php` is a project step today). `ba` does not know which is feasible. If neither is,
   AC-4 and AC-7 need rewording by `ba` before `dev` starts. [architect, BLOCKING for FP-172's plan]
4. **Is a distinct exit status for a kill wanted?** D-3 says no; the reviewer may prefer it. [reviewer,
   not blocking]

## Assumptions

- Composer's `process-timeout` applies per spawned process, so S is the slowest single process and not the
  sum. Now measured by the architect (`check` is five independent processes), no longer an assumption.
- `COMPOSER_PROCESS_TIMEOUT` overrides the configured value, which is what lets AC-4 and AC-10 force a kill
  without waiting minutes.
- SQLite file databases in `packages/kernel/tests/Support/tenancy-fixture.php` remain the fixture for
  tenant databases; no MySQL is needed to prove AC-13 to AC-31.
- Docker is stopped on the development machine; nothing in this task needs it.
- The existing suite was green at the T-006 tip (`cef6a17`); AC-34 compares against it.
- Brief figures re-checked by `ba` against the backlog: the backlog's earlier warm range (79 to 137 s,
  measured 2026-10-02) and the brief's six runs of 2026-10-06 (66.95 to 89.98 s) are different samples, not
  a contradiction, and neither is used as evidence by this spec (AC-43).
- **Brief corrected on one point**: the brief says FP-161's remedy makes forging need "the live scoped
  object". Read against the code, the marker's value is the tenant id itself, so today it is not even a
  secret constant, and the backlog's token remedy leaves F2 open (Open question 2).
