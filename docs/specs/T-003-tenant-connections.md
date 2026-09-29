# T-003: Convert the service host to master plus tenant connections

Covers backlog row **FP-201** only. The rename of `services/` to `modules/` is FP-202 /
T-004 and is explicitly not part of this task.

## Problem / goal

The product owner decided (`Refference/ARCH.md`, now `docs/architecture.md` v0.4 and
`docs/database.md` v0.4) on **one master database plus one database per tenant**,
replacing the earlier database-per-service design. The service host skeleton built in
T-001 and hardened in T-002 still implements the design being replaced: every generated
service package declares and merges its own named database connection
(`svc_<name>`, pointing at its own schema `pos_<name>`) via
`services/host/stubs/service/connection.config.php.stub`,
`SvcMakeCommand::FILES['connection.config.php.stub']` and
`{{studly}}ServiceProvider::register()`'s `mergeConfigFrom()` call. There is no master
database connection, no concept of "which tenant is this request for," and no code that
could safely refuse to run tenant-scoped work when that question has no answer.

T-003 converts the connection plumbing itself: two named connections (`master`,
`tenant`), a `TenantContext` that makes it impossible to run tenant-scoped code with no
tenant resolved, a resolver that is the only place a `tenant` connection is built, and
removal of the per-module connection model from the generator and from `services/demo`.
This is the foundation every later Phase 0 row (master schema, migrations, provisioning,
authentication, every real module) is built on top of — per the backlog, FP-201 is what
Phase 0 is blocked on.

## Users affected

- Backend developers (`dev`) building every module from Phase 0 onward — every one of
  them will use `master` for platform data and `tenant` for business data, and none of
  them should be able to accidentally query "no tenant in particular."
- `qa`/`reviewer` — this is a security boundary (cross-tenant data leakage), so it is the
  part of the codebase most in need of a suite that actively tries to break it.
- `architect` — every later plan (master schema FP-203, tenant schema FP-204, the
  migration runner FP-205, provisioning FP-206, authentication FP-207) is written against
  the resolver's and `TenantContext`'s contracts this spec fixes.
- Operators — a bug here is a cross-tenant data leak or a leaked credential, not a
  cosmetic defect.

## Current behaviour

- `services/host/config/database.php` is the stock Laravel file: `sqlite`, `mysql`,
  `mariadb`, `pgsql`, `sqlsrv` connection stanzas, no `master` or `tenant` connection, no
  concept of tenancy at all.
- `services/host/stubs/service/connection.config.php.stub` generates
  `config/connection.php` inside every new service package, declaring one MySQL
  connection named `{{connection}}` (`svc_<name>`) pointing at schema `{{schema}}`
  (`pos_<name>`), with its own set of `DB_*_<SCREAMING_NAME>` environment variables.
- `services/host/stubs/service/ServiceProvider.php.stub`'s `register()` calls
  `$this->mergeConfigFrom(__DIR__.'/../config/connection.php', 'database.connections')`
  and declares a public `CONNECTION` constant; `services/demo/src/DemoServiceProvider.php`
  and `services/demo/config/connection.php` are the committed, generated instances of
  both (connection name `svc_demo`, schema `pos_demo`).
- `services/demo/composer.json`'s `extra.flexipos` block declares `"connection":
  "svc_demo"` and `"schema": "pos_demo"` alongside `provider` and `prefix`.
- `App\Support\ServiceHost\ServiceRegistry::boot()` never reads the `connection` or
  `schema` manifest keys; it only validates the PSR-4 namespace and the provider class
  (T-002 FP-146). Removing those two keys does not touch registry validation.
- Nothing in the codebase today builds a connection from a tenant identifier, holds a
  "current tenant," or refuses a query for lack of one. `packages/kernel` — the package
  `docs/architecture.md` §4 names as the future home of "TenantContext, connection
  switching, settings, audit, idempotency" — is still the empty structural placeholder
  T-001 created (decision D-10): a stub `composer.json` and an empty `src/`.
- The real master schema (`tenants`, `tenant_databases`, `database_servers`, …,
  `docs/database.md` §4) does not exist; FP-203 has not started.

## User stories

- As a backend developer, I want a `master` connection that is always configured and
  never depends on any tenant being resolved, so that platform-level code (login, plans,
  the mapping itself) always works.
- As a backend developer, I want a `tenant` connection that refuses to run any query
  until a tenant has actually been resolved, so that a missing tenant check is a loud
  failure at the first query, not a silent read from whatever database happened to be
  configured.
- As a backend developer, I want one resolver that is the only place a tenant connection
  gets built, so that there is exactly one piece of code to audit for the cross-tenant
  isolation guarantee, instead of every module being trusted to build it correctly.
- As a backend developer, I want tenant database credentials to be handled only as a
  reference, never as a value that could end up in an exception message, a log line or a
  debug page, so that a stack trace can never leak a tenant's database password.
- As a backend developer running a queued job that serves two tenants in turn, I want the
  second tenant's resolution to completely replace the first's, so that a bug in a worker
  process can never make tenant B's job touch tenant A's data.
- As a backend developer generating a new module with `svc:make`, I want it to declare no
  database connection of its own, so that every module reaches business data only through
  the shared `tenant` connection.
- As `architect`/`reviewer`, I want the mapping the resolver reads to be defined as a
  contract with a fixed shape now, so that FP-203's real master-database-backed
  implementation can replace today's fake one without the resolver changing.

## Acceptance criteria

Numbering starts fresh at AC-1 for T-003.

### Two named connections (`master`, `tenant`)

- **AC-1**: Given the host's database configuration, when inspecting
  `config('database.connections.master')`, then a `master` connection exists, is built
  from environment variables (not from any per-tenant or per-module value), and is
  identical regardless of whether any tenant has been resolved in the process.
- **AC-2**: Given no tenant has been resolved in the current process, when code queries
  the `master` connection, then the query proceeds normally — proving `master`'s
  availability never depends on tenant resolution.
- **AC-3**: Given the host's database configuration as shipped (no tenant resolved yet),
  when inspecting `config('database.connections.tenant')`, then it declares no literal,
  usable database (no hard-coded host/database/credentials pointing at a real schema) —
  proving `tenant` has no fixed database and must be configured at runtime.

### `TenantContext` and the "no tenant resolved" guard

- **AC-4**: Given a fresh process (or test) in which no tenant has been resolved, when any
  code runs a query against the `tenant` connection (directly via
  `DB::connection('tenant')`, or through an Eloquent model that uses it), then it fails
  before attempting to open any network connection, with an error that states plainly
  that no tenant has been resolved for this process — not a driver-level "connection
  refused"/"unknown database" error, and not a silent connection to whatever `DB_HOST`/
  `DB_DATABASE` happens to be set. This is the core security guarantee of the task: it
  must be impossible to run tenant-scoped code with no tenant.
- **AC-5**: Given the same fresh-process state, when application code asks `TenantContext`
  for the currently resolved tenant, then it returns an explicit "no tenant resolved"
  outcome (an exception or an unambiguous absent value — the architect's choice) rather
  than `null`, an empty string, or any other value code could mistake for a valid tenant.

### The resolver — happy path

- **AC-6**: Given a tenant identifier for which the mapping source (see "The mapping
  source contract" below) has a record with status `active`, when the resolver resolves
  it, then: `config('database.connections.tenant')` is populated with that record's host,
  port, database name and username; `TenantContext` reports that tenant as the current
  one; and a query against the `tenant` connection reaches that tenant's database —
  proven with at least two distinct fake tenants (e.g. two separate SQLite files or
  equivalently distinguishable databases) returning identifiably different data.
- **AC-7**: Given the tenant resolved in AC-6, when code asks `TenantContext` for the
  current tenant identifier, then it returns exactly the identifier that was resolved.
- **AC-8**: Given a mapping record whose credential reference resolves to a known,
  distinctive password value, when the resolver builds the `tenant` connection, then the
  password used to build it was obtained by dereferencing that reference through a
  resolution step — never read as a plaintext column already sitting in the mapping
  record itself (the mapping source's own contract only ever exposes a reference string,
  never a raw password, so this is enforced by the contract's shape as well as proven by
  test).

### The resolver — negative cases (the security boundary)

- **AC-9** (no mapping): Given a tenant identifier for which the mapping source has no
  record at all, when the resolver attempts to resolve it, then it refuses with an error
  naming the tenant identifier and stating no mapping exists; `TenantContext` remains (or
  returns to) "no tenant resolved"; and `config('database.connections.tenant')` is left
  exactly as it was before the attempt — no partial overwrite.
- **AC-10** (status forbids access): Given a tenant identifier whose mapping record exists
  but whose status is not `active` — proven for at least two distinct non-active statuses
  from `docs/database.md` §4.4's enumeration (e.g. `suspended` and `provisioning`), when
  the resolver attempts to resolve it, then it refuses with an error naming the tenant
  identifier and its actual status, and leaves state exactly as in AC-9 — no connection is
  configured for it.
- **AC-11** (empty/invalid input): Given the resolver is called with an empty string or a
  whitespace-only tenant identifier, when it processes the call, then it refuses
  immediately with a validation error and never queries the mapping source at all.
- **AC-12** (credentials never leak — exceptions): Given a mapping record whose credential
  reference resolves to a known, distinctive fake password value, when the resolver is
  exercised through every refusal path above (AC-9, AC-10, AC-11) and through a forced
  connection failure (e.g. the resolved host is unreachable), then the known password
  value does not appear anywhere in any exception's message, in its string representation,
  or in the message of any exception earlier in its chain.
- **AC-13** (credentials never leak — logs): Given the same known, distinctive fake
  password value and the same set of runs as AC-12, with the application's log channel
  captured throughout, then the known password value does not appear in any log record
  written during any of those runs.
- **AC-14** (credentials never leak — debug output): Given the same known, distinctive
  fake password value and a forced failure during resolution that would surface through
  the application's normal unhandled-exception rendering (debug mode on), when that
  rendered output is inspected, then the known password value does not appear anywhere in
  it.
- **AC-15** (single point of construction): Given the codebase after T-003, when auditing
  every place that configures `database.connections.tenant` and every place that
  constructs credentials from a reference, then exactly one production code path does so
  — inside the resolver — with no controller, job, module, or other code hand-building or
  bypassing it. `reviewer` verifies this by inspection; `qa` verifies there is no second,
  working path that achieves the same effect.

### Switching tenants within one process

- **AC-16**: Given tenant A is resolved and a query against the `tenant` connection is
  confirmed to reach A's database, when the resolver then resolves tenant B within the
  same process (simulating a queued job handling two tenants in turn, one worker, no
  restart), then a subsequent query against the `tenant` connection reaches B's database,
  not A's, and `TenantContext` reports B, not A.
- **AC-17**: Given the switch in AC-16, when inspecting `config('database.connections.
  tenant')` and the connection actually used for the next query, then nothing from A
  remains: the config array equals exactly what resolving B alone would have produced
  (no leftover keys or values from A), and the query against `tenant` is served by a
  connection actually pointed at B (not a stale, already-open connection object still
  talking to A's database).
- **AC-18**: Given tenant A is resolved, when the process explicitly forgets/resets the
  current tenant (e.g. end of a job, or between two queued jobs sharing a worker) without
  resolving a replacement, then the process returns to the AC-4/AC-5 "no tenant resolved"
  state — a query against `tenant` fails loudly again; it does not keep silently serving
  tenant A.

### Removal of the per-module connection model

- **AC-19**: Given the repository after T-003, when inspecting
  `services/host/stubs/service/`, then no stub generates a `config/connection.php` file
  or any other per-module database connection declaration, and `SvcMakeCommand::FILES`
  no longer includes a connection-config stub entry.
- **AC-20**: Given a module freshly generated via `svc:make <name>` after T-003, when
  inspecting it, then: it contains no `config/connection.php`; its generated service
  provider's `register()` does not call `mergeConfigFrom()` for a database connection and
  declares no `CONNECTION` constant; and its `composer.json`'s `extra.flexipos` block
  declares no `connection` or `schema` key.
- **AC-21**: Given the module generated in AC-20, when running `composer test` with no
  further edits, then its generated test still passes out of the box — T-001 AC-26's
  guarantee ("the template is self-testing") must not regress just because the connection
  file is gone.
- **AC-22**: Given `services/demo` after T-003, when it is regenerated via `svc:make demo`
  using the same move-aside-and-restore pattern as the existing demo-regeneration
  regression test (T-002 FP-153/AC-19) and diffed byte-for-byte against the committed
  tree, then the two are identical, and neither contains `config/connection.php`, a
  `CONNECTION` constant, or a `mergeConfigFrom()` call for a connection.
- **AC-23**: Given `services/demo`'s own committed test (`PingRouteTest` or its
  replacement), when running `composer test`, then it still passes, and it does not
  depend on the removed `svc_demo` connection existing.

### The mapping source contract (for FP-203 to drop in behind)

The real master schema (FP-203) does not exist yet. T-003 defines the contract the
resolver depends on and proves it against a fake/in-memory implementation only.

- **AC-24**: Given the resolver's dependency on a mapping source, when inspecting the
  contract, then one mapping record's shape includes at minimum: a tenant identifier, a
  database host, a database port, a database name, an application username, a credential
  *reference* string (never a plaintext password), and a status value that distinguishes
  `active` from every other status — mirroring the subset of `docs/database.md` §4.4's
  `tenant_databases`/`database_servers` columns the connection plumbing actually needs.
  Operational columns those tables also carry (`schema_version`, `size_bytes`, `profile`,
  migration/provisioning bookkeeping, credential rotation timestamps, …) are not part of
  this contract; they belong to the tasks that use them (FP-205, FP-206, FP-210).
- **AC-25**: Given T-003 ships no real master schema, when the test suite exercises the
  resolver (every AC above that mentions "the mapping source"), then it does so
  exclusively through a fake/in-memory implementation of the AC-24 contract — proven by
  there being no migration, Eloquent model, or query against a real `tenant_databases` or
  `database_servers` table anywhere in T-003's code or tests.

### Regression — what T-001 and T-002 criteria this task could break

- **T-001 AC-25 is superseded, not merely re-proved.** It required `svc:make`'s "at
  minimum" file set to include "a database connection configuration entry named for the
  service." T-003 deliberately removes that file (AC-19/AC-20 above supersede this
  clause). `qa` re-proves AC-25's other elements unchanged (`composer.json`, the service
  provider class, the routes file mounted under its own prefix, the empty
  `database/migrations/` directory, the empty `Consumers/` directory, at least one test
  file) and confirms the connection-config clause no longer applies, by design.
- **T-001 AC-8, AC-9, AC-12, AC-14, AC-16, AC-17** (`composer install`/`lint`/`stan`/
  `deptrac`/`test`/`check` all exit 0): re-prove on the changed stubs, the changed
  `services/demo`, the new `TenantContext`/resolver code and its tests, and the changed
  `config/database.php`.
- **T-001 AC-18 through AC-24** (service host boot mechanism: `SERVICES` parsing,
  fail-fast on an unknown service, empty `SERVICES`, dedupe/whitespace, `/health/ready`
  and `/health/live` bodies, unlisted-service 404): unaffected by this task's changes —
  `ServiceRegistry::boot()` never reads the `connection`/`schema` manifest keys being
  removed — but `qa` re-proves them because the demo manifest and `services/demo` itself
  change.
- **T-001 AC-27** (adding a generated service to `SERVICES` and booting it requires no
  edit inside `services/host/`): re-prove with the new, connection-less template.
- **T-001 AC-31/AC-32** (`services/demo` committed and included by default; its `/api/v1/
  demo/ping`-equivalent route returns HTTP 200): re-prove — the ping route must still
  work with no database connection at all, since the demo package no longer declares one.
- **T-002 AC-1 through AC-4** (registry manifest trust: wrong-kind-of-class, namespace
  mismatch, provider-outside-namespace, trailing newline): re-prove unchanged — this
  task's manifest changes (removing `connection`/`schema` keys) do not touch the
  `provider`/namespace keys these criteria validate.
- **T-002 AC-5/AC-6** (atomic `svc:make` generation, retry after a failed generation
  succeeds): re-prove against the new, shorter file list.
- **T-002 AC-9/AC-10** (the `config:cache`/`SERVICES` staleness guard): re-prove — adding
  `master`/`tenant` to `config/database.php` must not interact with this guard, which
  only compares `SERVICES`.
- **T-002 AC-16/AC-17** (test directories included in `stan`/`deptrac`): re-prove with
  the new `TenantContext`/resolver source and test files included, at level 6 and
  boundary-clean.
- **T-002's FP-153 regeneration-diff test** (`SvcMakeDemoRegenerationTest` or its
  successor): must be updated by `dev` to reflect the new template and re-pass; `qa`
  confirms it is not accidentally green by no longer testing anything (i.e. it still
  fails if a stub drifts from the committed `services/demo`).

## Out of scope

- **FP-202** — the `services/` → `modules/` rename. Nothing in this task renames a
  directory, a namespace, or `svc:make`/`SERVICES` vocabulary.
- **FP-203** — the real master schema (`tenants`, `users`, `tenant_users`, `plans`,
  `subscriptions`, `licences`, feature flags, platform settings, platform audit, and the
  real `tenant_databases`/`database_servers` mapping tables). T-003 defines the contract
  those tables must satisfy (AC-24) and proves the resolver against a fake implementation
  of it (AC-25) only.
- **FP-204/FP-205** — the tenant migration tree, the per-tenant migration runner, schema
  version tracking and the "refuse to serve a tenant whose schema is older than the code
  expects" gate. `schema_version` is explicitly excluded from the AC-24 contract for this
  reason.
- **FP-206** — tenant provisioning (creating the database, the MySQL user, running
  migrations, seeding defaults).
- **FP-207** — authentication and the login flow: validating credentials, resolving which
  tenants a user may access, translating a human-readable tenant code or a JWT claim into
  the identifier the resolver accepts (decision D-25), issuing tokens, and any
  per-HTTP-request middleware that calls the resolver automatically. T-003's resolver is
  exercised directly (by a job, a console command, or a test), not via request middleware.
- **FP-210** — plan/subscription enforcement and the finer-grained handling
  `docs/database.md` §4.4 describes for `read_only`/`archived` tenants (served read-only
  rather than refused outright) and for mapping a refusal to an HTTP 503 with
  `Retry-After`. T-003's resolver has exactly two outcomes — connect, or refuse — per
  decision D-26.
- **FP-212** — the event bus, the outbox, and the `tenant.mapping.changed` cache
  invalidation `docs/database.md` §4.4 describes. T-003 does not cache resolved mappings
  (decision D-32).
- Credential-reference schemes other than `env://` (`vault://`, `awssm://`, `azkv://`,
  `file://`, `enc://`) — decision D-31. Whichever task first needs a real secret store
  implements the matching driver behind the same contract.
- Redis-backed caching of the resolved mapping, and any performance optimisation of the
  resolver — nothing in this task is expected to run at production tenant volume yet.
- Any change to `services/host/app/Support/ServiceHost/ServiceRegistry.php`'s manifest
  validation (namespace, provider) beyond the manifest no longer carrying `connection`/
  `schema` keys — FP-146/FP-149 are T-002's, already closed, and are not reopened here.

## Non-functional requirements

- **No plaintext credentials, ever, anywhere observable.** Not in an exception message,
  not in a log line, not in debug/error output, not in the mapping source's own data
  (which stores only a reference) — per `docs/architecture.md` §17 ("Tenant credentials
  referenced as secrets, never stored in plain text; the resolver is the only code that
  builds a tenant connection") and proven by AC-8, AC-12, AC-13, AC-14.
- **No persistent/pooled PDO connections on the `tenant` connection.** Per
  `docs/database.md` §2 point 4: a pooled connection is bound to one tenant and cannot be
  safely reused once another tenant is resolved in the same process, so persistence is a
  direct route to the leak AC-16/AC-17 forbid.
- **Fail fast, in order: validate input, then check the mapping, then check status,
  before ever attempting to open a network connection to a tenant database.** An empty
  identifier (AC-11) or a missing/inactive mapping (AC-9/AC-10) must never reach the point
  of attempting a PDO connection.
- Configuration files remain OS-agnostic (no Windows-specific absolute paths), consistent
  with T-001's and T-002's existing non-functional requirements.
- No fix or addition in this task may cause `composer check` to regress from its current
  exit-0 state once T-003's own new/changed files are included.

## Assumptions

- **D-25**: the resolver's canonical input is the tenant mapping's own opaque identifier,
  not a human-readable tenant code; translating a code (or a JWT claim) into that
  identifier is FP-207's job.
- **D-26**: only mapping status `active` permits a connection in T-003; every other status
  is refused outright (no read-only special case yet — that is FP-210/FP-215).
- **D-27**: `TenantContext` is scoped to the running process with an explicit resolve/
  forget API; there is no automatic per-HTTP-request reset in this task (FP-207's job).
- **D-28**: an explicit forget/reset operation exists, independent of resolving a new
  tenant, so a queue worker can return to "no tenant resolved" between jobs.
- **D-29**: the mapping-source contract carries only the fields the connection plumbing
  needs (identifier, host, port, database name, username, credential reference, status),
  not every column `docs/database.md` §4.4 eventually gives `tenant_databases`/
  `database_servers`.
- **D-30**: `master` and `tenant` are added to `services/host/config/database.php` now,
  even though `master` has no real schema to query until FP-203 — the same
  "structural-ahead-of-content" pattern T-001 used for `packages/*` (D-10).
  **D-31**: only the `env://` credential-reference scheme is implemented/faked in T-003;
  the others are deferred to whichever task first needs a real secret store.
- **D-32**: no caching of resolved mappings is added in T-003; that waits for the event
  bus (FP-212) to exist so a cache can be correctly invalidated.
- **D-33**: this spec does not mandate where `TenantContext`, the resolver, or the
  mapping-source contract physically live in the current (pre-rename) repository layout
  (`packages/kernel` vs. `services/host/app/Support/...` vs. elsewhere) — that is the
  architect's technical design choice, informed by but not bound to `docs/architecture.md`
  §4's post-rename target layout.
- All ambiguous business rules encountered while writing this spec were resolved by `ba`
  and logged in `docs/decisions/T-003-tenant-connections.md` (D-25 onward) per the team
  protocol, rather than left as blocking questions.

## Open questions

None blocking. Non-blocking items left for `architect` to fix once in `docs/plans/` (pure
mechanism, not business rules — see decisions D-27, D-28, D-33):

1. Where `TenantContext`, the resolver, and the mapping-source contract live in the
   current pre-rename layout. [NOT BLOCKING]
2. The exact mechanism that makes querying the `tenant` connection with no tenant
   resolved fail before any network attempt (a custom connector, a guard on the
   connection resolver, a macro, etc.) — AC-4 fixes only the observable behaviour.
   [NOT BLOCKING]
3. The exact mechanism for ensuring a second tenant's resolution leaves nothing from the
   first (e.g. `DB::purge('tenant')` plus `config()->set(...)`, per the pattern
   `docs/database.md` §4.4 already describes for the eventual real resolver) — AC-16/
   AC-17 fix only the observable behaviour. [NOT BLOCKING]
4. The exact class/exception names used throughout (e.g. what the "no tenant resolved"
   and "tenant not found"/"tenant not active" errors are called) — left to `architect`'s
   plan so `dev` and `qa` agree on one vocabulary, following the existing
   `UnknownServiceException` convention. [NOT BLOCKING]
