# T-022: Cross-tenant isolation suite

Backlog: FP-216, "Generated attempts across routes, jobs, cache keys and plugin hooks; must pass to
merge" (dependency FP-209, done). Decisions D-1 to D-7 are in
`docs/decisions/T-022-cross-tenant-isolation-suite.md`. **No BLOCKING questions** — autonomous/team-mode
work; every open design question was decided and logged.

Convention, following T-018 to T-021: every criterion is provable on a deterministic fixture, unless
marked **[ENGINE]** (needs a real MySQL/MariaDB server, gated by `ENGINE_HOLD`/G-1). This task is a
QA-stream row: it proves an existing property across what is already built, and ships no new business
endpoint, job or hook. Where the generated sweep this task builds finds a real gap, that gap is a defect
against the AC it violates, handed to `developer`, not silently patched here.

## Problem / goal

`docs/architecture.md` §1's first rule is "No tenant can reach another tenant's database," and §17
restates it as the platform's central security property. Every feature task so far has proven this
property for its own surface by hand — T-010's permission cache, T-014's branch/terminal lookups, T-016's
subscription webhooks, T-017's audit-log scoping, T-021's idempotency replay and rate-limit buckets each
carry their own two-tenant test. Nothing proves it *generically*, across every route, queued job, cache
key and plugin hook the platform has today or gains tomorrow, in one place that runs on every merge. A
single new route, cache key or hook that forgets to key on the tenant has no single test standing between
it and production. This task builds that one place: a generated sweep over the real registries (routes,
the queue job inventory, cache-key construction sites, the plugin hook catalogue) that runs the same
tenant-A-versus-tenant-B attempt against every enumerated surface, gated into `composer check` so a leak
cannot merge.

## Users affected

- Every shop (tenant) on the platform: the property under test is literally "tenant A never sees tenant
  B's data," which is the platform's core promise to every paying customer.
- Future module developers: a route, job, cache key or hook they add is swept automatically, with no new
  test case to remember to write.
- The lead/release process: `composer check` going red on a real cross-tenant leak before merge, not
  after a customer reports one.
- Not served: no end user interacts with this suite directly; it adds no UI and no runtime behaviour.

## Current behaviour

Re-derived from the files (T-022 reconnaissance, 2026-11-06):

- **Routes**: 49 business routes exist under `api/v1` across `packages/auth/routes/api.php` (6, mostly
  master-level login/activation/refresh), `packages/organization/routes/api.php` (22, branch/location/
  terminal/settings reads and writes, all tenant-token-gated), `packages/access/routes/api.php` (20,
  roles/permissions reads and writes, tenant-token-gated), and `modules/demo/routes/api.php` (1, the ping
  example). `modules/host/routes/api.php` adds one more (`GET /api/v1/openapi.json`, master-only, no
  tenant data). Each of T-010/T-014/T-016/T-021 has its own hand-written two-tenant test for its own
  endpoints; none is enumerated generically.
- **Jobs**: the only `Illuminate\Contracts\Queue\ShouldQueue` class in the repository is
  `Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantQueueJob` (T-008). Its payload carries
  `tenantId` explicitly and no credential; `handle()` calls `MigrateTenantHandler`, which is expected to
  resolve the connection for *that* tenant id, not whatever tenant happens to be active in the worker
  process when the job is popped. No business-domain module has shipped a second queued job. The event
  bus (T-018) is a different mechanism — Redis Streams relayed and consumed by artisan commands
  (`RelayOutboxCommand` and consumers reading `ConsumerRegistry`), each already scoped to one tenant's own
  outbox table and already covered by T-018's own tests — not a Laravel queue job, and out of this task's
  "jobs" category (D-5).
- **Cache keys**: exactly four call sites construct a cache/limiter key anywhere in `packages/*/src` or
  `modules/*/src`: `Flexipos\Kernel\Tenancy\CachingTenantDatabaseRepository` (`tenant:conn:<tenant_id>`,
  a master-side mapping cache, one entry per tenant id); `App\Http\Requests\ApiActor` building the four
  `RateLimiter` bucket names `t:{tenantId}`, `u:{tenantId}:{userId}`, `d:{terminalTenantId}:{terminalId}`,
  `ip:{ip}` consumed by `App\Http\Middleware\ThrottleApi` (T-021), **plus** a fifth bucket from the same
  class, `u:platform:{platformUserId}` (`ApiActor::fromRequest()`), built for a platform-token actor who
  by design carries no tenant at all (its own docblock: "a platform token has no tenant, so it never gets
  a tenant bucket"); `Flexipos\Platform\Support\CachingEntitlementSource` (`platform:entitlements:<tenant_id>`,
  T-016's 300-second entitlement cache); and `Flexipos\Auth\Support\ActivationThrottle` (two keys per call
  site, `terminal-activation:tenant:<tenant_id>` and `terminal-activation:ip:<sha256(ip)>`, T-014's
  activation-guessing limiter). Corrected 2026-11-06 from an original undercount of two (D-8): the original
  reconnaissance missed `CachingEntitlementSource` and `ActivationThrottle` entirely. All five bucket names
  across the four sites embed a tenant id, terminal id or client-IP hash by construction, **except** the
  platform-user bucket, which is deliberately tenant-agnostic by design (a platform token has no tenant to
  embed) and is the one entry the allow-list in AC-13 starts with on day one, not empty. No test enumerates
  cache-key construction sites as a class and asserts this generically; a new site added later is invisible
  to today's suite.
- **Plugin hooks**: `Flexipos\PluginSdk\Gateway\HookPointCatalogue` enumerates every declared hook point
  from booted providers; `HookInvoker` (T-019) runs a registered plugin handler "inside the tenant
  connection's own context" per its own docblock, keyed by `$registration->tenantId`, with per-tenant
  circuit-breaker state (`CircuitBreaker::recordFailure/recordSuccess`) keyed by `(tenantId, pluginId)`.
  T-019's own tests prove the breaker and the manifest cache behave correctly for one tenant at a time;
  none runs two tenants with the same plugin enabled and asserts a hook fired for tenant A never executes
  under, or is visible to, tenant B's connection.
- **Fixtures available to build on**: `Flexipos\Access\Tests\Support\AccessTenantFixture` (two real,
  separately-connected SQLite tenant databases, `boot()`/`switchTo()`/`forget()`/`deleteRoot()`), already
  imported across package boundaries by T-014's and T-016's isolation tests, needs no real engine — but is
  tenant-side only (mapped via `tenancy.databases` config), so it cannot stand in for anything that reads
  the master's own `database_servers`/`tenant_databases` tables. `packages/kernel/tests/Support/
  migrate-tenant-fixture.php` (T-008) provides exactly that: two SQLite tenant databases *plus* real
  master-side mapping/server rows and a faked `TenantMigrationApplier`, needs no real engine either, and is
  the correct fixture for AC-9's deterministic variant because `MigrateTenantQueueJob`'s handler resolves
  its tenant id and connection from the master mapping, which only this fixture provides.
  `Flexipos\Testing\Engine\ThreeTenantFixture` (T-008) is engine-gated (`ENGINE_HOLD`) and built for
  schema-version scenarios; reserved in this task for the one case that is inherently about a real worker
  process against a real engine (AC-9's `[ENGINE]`-tagged real-migration proof).

## User stories

- As a shop owner, I want it to be mechanically impossible for my data to answer another shop's request,
  so that I can trust the platform with my sales, customers and finances.
- As a module developer, I want a new route, cache key or hook I add to be automatically checked for
  cross-tenant leakage, so that I do not have to remember to write that test myself.
- As the lead, I want `composer check` to fail before a cross-tenant leak merges, so that it is caught in
  CI, not in production.

## Acceptance criteria

### A. Route sweep (generated)

- AC-1: Given the live route list, When the sweep collects every `api/v1` route registered by a booted
  module/package, Then it classifies each as tenant-token-gated (behind `RequireTenantToken` or
  `RequireTerminalToken`), master-only (no tenant token, e.g. login/activation/refresh/platform audit/
  `openapi.json`), or unauthenticated-probe, using the route's own registered middleware — never a
  hand-maintained list of route names — so a new route is classified without a code change here.
- AC-2: Given the sweep's classification, When run, Then the count of tenant-token-gated routes it finds
  is asserted to be at least the 42 known today (organization's 22 + access's 20), naming any route it
  expected to find and did not, so a classification regression (a route silently reclassified as
  master-only) is visible as a failing count, not a silently shrinking sweep.
- AC-3: Given one tenant-token-gated route that reads a single resource by id (e.g. `GetBranch`,
  `GetLocation`, `GetTerminal`, an access role/permission read), When the sweep runs its generated
  attempt — seed the resource for tenant A, request the same route and id with tenant B's token — Then
  tenant B's response is a refusal/not-found (never tenant A's record) for every such route found, and
  the reverse (tenant A requesting tenant B's id) is also refused.
- AC-4: Given one tenant-token-gated route that lists a collection (e.g. `ListEffectiveSettings`,
  organization's branch/location/terminal lists, access's role/permission lists), When the sweep seeds a
  distinguishing value in tenant A only and requests the same route with tenant B's token, Then tenant B's
  response contains none of tenant A's distinguishing value, for every such route found.
- AC-5: Given one tenant-token-gated route that writes (create/update/clear), When the sweep performs the
  write as tenant A and then reads the equivalent state as tenant B (via AC-3/AC-4's read, where one
  exists for that resource), Then tenant B observes no effect of tenant A's write.
- AC-6: Given the master-only routes classified in AC-1 (login, activation, refresh, platform audit,
  `openapi.json`), When the sweep runs, Then it asserts each is excluded from AC-3/AC-4/AC-5 by
  construction (no tenant token exists to swap) and does not silently pass by having nothing to check;
  the test names each excluded route and the reason.
- AC-7: Given a planted test-only route registered only inside the sweep's own test file that returns a
  seeded value without checking the caller's tenant, When the sweep runs against it, Then the sweep
  reports the leak (fails), proving the generated attempt can detect a real cross-tenant read; the planted
  route is removed/skipped in the positive run.

### B. Queued job sweep (generated, scoped to real jobs — D-5)

- AC-8: Given the repository, When the sweep enumerates every class implementing
  `Illuminate\Contracts\Queue\ShouldQueue` under `packages/*/src` and `modules/*/src`, Then it finds
  exactly the known job(s) (`MigrateTenantQueueJob` today) and asserts the count, so a second queued job
  added later is automatically picked up and swept with no new test file.
- AC-9: Given `MigrateTenantQueueJob` dispatched for tenant A, When it is popped and run by a worker whose
  process-ambient/active tenant connection is a different tenant B (set up before the job runs, as a
  worker recycled between jobs would have), Then the job's own effect (the migration write) lands only in
  tenant A's database, tenant B's database is untouched, and the job never silently succeeds against B.
  **[ENGINE]** for the real migration-write proof (needs the real migrator); a deterministic variant using
  the kernel's own `packages/kernel/tests/Support/migrate-tenant-fixture.php` (two SQLite tenants mapped
  through real master-side `database_servers`/`tenant_databases` rows, with `TenantMigrationApplier` faked)
  proves the job resolves tenant A's connection name, not the ambient one, without running a real
  migration — `AccessTenantFixture` cannot carry this case: it is tenant-side only (its tenant databases are
  mapped via `tenancy.databases` config, not a master `tenant_databases` table), and `MigrateTenantQueueJob`'s
  handler reads its own tenant id and the tenant-to-database mapping from the master database.
- AC-10: Given the job's serialized payload (as queued, before `handle()` runs), When inspected, Then it
  carries the tenant id and no connection credential, username, password or credential reference — so a
  queue store compromise discloses which tenant, never how to reach any tenant's database.
- AC-11: Given a class is added later that implements `ShouldQueue` but is found by AC-8's scan to carry
  no tenant-identifying property (no `tenantId`-shaped constructor parameter) and is not on a short, named
  allow-list of jobs that are deliberately tenant-agnostic (empty today), When the sweep runs, Then it
  fails, naming the class — so a future job that forgets to carry which tenant it belongs to is caught
  before it ships, not only re-checked by hand.

### C. Cache-key sweep (generated)

- AC-12: Given every `*.php` file under `packages/*/src`, `modules/*/src` and `modules/host/app`, When the
  sweep scans for `Cache::`, an injected `Illuminate\Contracts\Cache\Repository`, or an injected
  `Illuminate\Cache\RateLimiter` used to build a key/bucket name, Then it finds exactly the four known
  construction sites (`CachingTenantDatabaseRepository::keyFor()`, `ApiActor`'s five bucket builders,
  `CachingEntitlementSource::keyFor()`, `ActivationThrottle`'s `tenantKey()`/`addressKey()` pair) and
  asserts the count, so a new site is automatically added to what AC-13 checks.
- AC-13: Given each construction site AC-12 finds, When its key-building expression is inspected, Then it
  either interpolates a tenant id, terminal id or client IP into the key (as `CachingTenantDatabaseRepository`,
  `CachingEntitlementSource` and `ActivationThrottle` do, and as three of `ApiActor`'s five buckets do) or is
  listed on a short, named, in-file allow-list of deliberately tenant-agnostic keys — starting with exactly
  one entry on day one, `ApiActor`'s `u:platform:{platformUserId}` bucket, with its one-line reason ("a
  platform token carries no tenant") already true today, not added later — and any future entry needs its
  own one-line reason; a site that is on neither the tenant-token list nor the allow-list fails the sweep,
  naming the file and line.
- AC-14: Given a planted file (written only by the sweep's own test, under a temp directory, never
  committed) that builds a cache key or `RateLimiter` bucket name with no tenant/terminal/IP token and is
  not on the allow-list, When the sweep scans it, Then it is reported as a hit, proving the scan can fail;
  removing the plant returns the sweep to green.
- AC-15: Given `CachingTenantDatabaseRepository`'s actual runtime behaviour (not just its source text), When
  tenant A and tenant B each resolve their own mapping through `CachingTenantDatabaseRepository::find()`, Then each
  tenant's cache entry is stored and read under its own key only, and `evict()` for tenant A never removes
  or returns tenant B's entry (reusing the two-tenant fixture, no real engine needed).
- AC-16: Given tenant A and tenant B as two authenticated actors, When both send requests up to each
  other's configured limit at the same time, Then tenant A exhausting its bucket (`t:{A}`) never counts
  against or throttles tenant B's bucket (`t:{B}`), confirming T-021 AC-31 generically through the same
  sweep rather than relying only on that task's own test remaining in place.

### D. Plugin hook sweep (generated)

- AC-17: Given `HookPointCatalogue::all()`, When the sweep enumerates every declared hook point from
  booted providers, Then it asserts the count is at least one (today's fixture plugin's declared points)
  and runs the following attempt against every point found, so a hook point added by a future plugin
  module is swept automatically.
- AC-18: Given the same plugin enabled for tenant A only (not tenant B), When a hook point is dispatched
  while tenant B's connection is active, Then `HookInvoker` does not call the plugin's handler at all (it
  is not enabled for B), and no `plugin_execution_logs` row or circuit-breaker state is written under
  tenant B's connection for that plugin.
- AC-19: Given the same plugin enabled for both tenant A and tenant B (two independent enablement rows,
  each tenant's own install), When a hook point is dispatched for tenant A, Then the handler observes
  tenant A's own connection (proven by reading/writing a tenant-distinguishing value through the
  handler during the call) and tenant A's own circuit-breaker and execution-log rows are written; tenant
  B's circuit-breaker state, execution-log rows and connection are untouched by that dispatch.
- AC-20: Given tenant A's plugin handler is made to fail (the fixture plugin's configurable failure mode),
  When the circuit breaker trips for tenant A, Then tenant B's independently-enabled instance of the same
  plugin is unaffected (its breaker is closed, its next dispatch still calls the handler) — the breaker
  key is `(tenantId, pluginId)`, never `pluginId` alone.
- AC-21: Given a planted test-only hook handler (registered only inside the sweep's own test, never
  shipped) that is written to ignore the tenant connection it is handed and read/write a module-level
  static instead, When the sweep runs AC-19's cross-tenant-visibility check against it, Then the sweep
  reports the leak (fails), proving the generated attempt can detect a real cross-tenant hook leak.

### E. Suite integrity and gate

- AC-22: Given the four sweeps (A to D), When placed, Then the three that are deterministic (routes, cache
  keys, plugin hooks) and the deterministic variant of the job sweep live under `modules/host/tests/Feature/`
  named `Qa*CrossTenantSweepTest.php` (one file per category), follow the established `Qa*SourceRulesTest`
  discipline (each file's own helper functions, namespaced to that file, nothing shared by requiring
  another `Qa*` file), and run inside `composer test:fast`; the one `[ENGINE]`-tagged case in AC-9 (the
  real-migration proof, which spawns no child process and so cannot be the `toolchain` group — enforced by
  `ToolchainGroupCoverageTest`'s registry-equals-spawners check) instead lives under
  `modules/host/tests/Engine/`, this repository's established home for `ENGINE_HOLD`-gated tests (e.g.
  `WorkflowSchemaEngineTest.php`, `MigrateTenantEngineTest.php`), and is gated by `ENGINE_HOLD`/G-1 the same
  way every other file there is, not by a `--group`/`--exclude-group` flag.
- AC-23: Given `composer check`, When run with any of AC-3, AC-4, AC-5, AC-9 (the deterministic variant),
  AC-13, AC-16, AC-19 or AC-20 reverted to a leaking implementation (planted via a mutation, same technique
  as the negative controls above), Then the gate fails and the failure message names the offending
  route/job/cache-key site/hook, confirming every criterion here is load-bearing rather than vacuously
  green.
- AC-24: Given every enumeration function this suite adds (route list, `ShouldQueue` scan,
  cache-key-site scan, hook-point catalogue read), When it is first run against the shipped codebase,
  Then the directories/sources it reads are asserted to exist (`is_dir()`/file-exists checks before the
  scan), so an off-by-one in a relative path cannot silently empty a sweep the way it did in T-016
  (`qa-negative-control-every-guard` project memory).
- AC-25: Given this suite is new, When the backlog/spec is read later, Then it is documented as a
  permanent, CI-gate-enforced suite (not a one-off audit): a future route, job, cache key or hook is
  covered by the existing generated sweeps with no new test file required, unless it introduces a wholly
  new *kind* of surface (e.g. a WebSocket channel), which would need its own new sweep category.

## Out of scope

- Fixing any gap the generated sweep discovers; that becomes a QA defect against the AC it violates,
  handed to `developer` via the normal plan/dev/review cycle, not patched silently inside this spec or by
  `ba`.
- A second queued-job sweep case: there is exactly one `ShouldQueue` class today (D-5); when a
  business-domain module ships its first real queued job, AC-8's enumeration picks it up with no spec
  change, and AC-11 fails loudly if it forgets a tenant identifier.
- The event bus's own tenant-scoping (Redis Streams relay/consumers): a different mechanism from a
  Laravel queue job, already covered by T-018's own tests (D-5); re-proving it here would test the same
  property through a different door for no new information.
- Master-only routes' own data model (login, activation, platform audit): they have no tenant to isolate
  against another tenant by construction (AC-6); their own correctness is each one's existing task's
  concern (T-009, T-016).
- Performance/load testing of the rate limiter or the cache; this suite proves isolation, not throughput.
- Building a new shared "N-tenant fixture" package; this task reuses `AccessTenantFixture` for the route,
  cache-key and hook sweeps, `migrate-tenant-fixture.php` for AC-9's deterministic job variant (the one
  case needing a master-side mapping, not tenant-side config), and, for the one engine-gated case,
  `ThreeTenantFixture` as they exist (D-3, corrected by D-10).
- WebSocket, print-agent or any non-HTTP/non-queue transport; none exists yet to sweep.

## Non-functional requirements

- Security: this is the suite whose whole purpose is the platform's core security property (§1, §17); a
  found leak is the highest-severity defect category this repository has.
- Performance: the deterministic sweeps (routes, cache keys, hooks, the deterministic job variant) run
  inside `composer test:fast`'s existing budget; only AC-9's real-migration proof is `[ENGINE]`/slow-group.
- Maintainability: every enumeration is driven by a real registry/source scan, never a hand-maintained
  list of surface names (D-1), so the suite's coverage grows with the codebase automatically.

## Open questions

None blocking. Items for the lead, for visibility only:

1. If a future business module ships its first real queued job, confirm AC-8/AC-11 catch it as designed
   during that module's own QA round, since this task cannot test a job that does not exist yet.
2. If a plugin hook point is ever dispatched for more than one tenant *inside a single request* (a
   cross-tenant orchestration feature), that would need a new AC beyond AC-18/AC-19's one-tenant-per-
   dispatch model; nothing in the codebase does this today.

## Assumptions

- "Generated attempts" means a programmatically-generated test matrix over real registries, not a
  hand-written list of attack scenarios (D-1).
- The two-tenant SQLite fixtures (`AccessTenantFixture` for tenant-side claims, `migrate-tenant-fixture.php`
  for AC-9's master-mapping claim) are an acceptable stand-in for "real" tenant isolation proofs wherever
  the claim is about connection/key separation, not about the real migrator or a real MySQL/MariaDB server;
  only AC-9's `[ENGINE]`-tagged full proof needs the real engine (D-3, D-10).
- No production code changes are expected to ship with this task; every AC is written to pass against
  the codebase as it exists today, because reconnaissance found every current route, the one job, both
  cache-key sites and hook dispatch already resolving the tenant explicitly rather than ambiently (D-6).
- "Jobs" in the backlog row is read as "Laravel queued jobs" specifically, scoped to the one that exists
  (D-5); the event bus's relay/consumer commands are a different, already-tested mechanism.
