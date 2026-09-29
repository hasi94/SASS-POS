# Plan: T-003 Convert the service host to master plus tenant connections

| | |
|---|---|
| Spec | [docs/specs/T-003-tenant-connections.md](../specs/T-003-tenant-connections.md) (AC-1 … AC-25, decisions D-25 … D-33) |
| Design inputs | [docs/database.md](../database.md) v0.4 §2, §4.4, §5 · [docs/architecture.md](../architecture.md) v0.4 §4, §5, §17 |
| Covers | backlog **FP-201 only**. The `services/` → `modules/` rename is FP-202 / T-004 and is not touched here |
| Branch | `ai/T-003-tenant-connections` (from the T-002 tip, `ad7fb27`) |
| Baseline | `cd services/host && composer check` verified **exit 0** before any change: Pint clean, PHPStan level 6 clean, Deptrac 0 violations, Pest **148 passed (749 assertions)**, 82s warm. Every step below must end with that still true |
| Owner of every step | `dev` |

---

## Approach summary

Two named connections replace the per-service connection model. `master` is a normal,
env-driven stanza in `services/host/config/database.php`. `tenant` is a **placeholder**
stanza carrying a sentinel driver and nothing else — no host, no database, no credentials —
and is rewritten at runtime by exactly one class.

All of the tenancy code lands in **`packages/kernel/src/Tenancy/`**, not in
`services/host/app/`. That is not a style preference: Deptrac's ruleset lets a `Service_*`
layer depend only on `Package_*` layers, so a `TenantContext` living in `App\` would make
every module that used it a boundary violation. `packages/kernel` is also the home
`docs/architecture.md` §4 names, is already a symlinked path package (`vendor/flexipos/kernel`
→ `packages/kernel`, verified), and is already in the PHPStan and Deptrac path lists, so no
registry churn beyond adding its new `tests/` directory.

The guarantee that tenant data cannot be read without a tenant is enforced at **three**
levels, because each one alone has a hole:

1. `config('database.connections.tenant')` never contains a usable database at rest, so
   nothing can connect by accident;
2. a `DB::extend('tenant', …)` resolver refuses to build the connection unless a
   `TenantContext` is present and the config it was handed belongs to *that* tenant;
3. a `beforeExecuting` + `beforeStartingTransaction` callback attached to the built
   connection re-checks the context **on every query**, which is the only thing that stops
   a stale `Connection` object — one already held in a variable, or cached inside
   `DatabaseManager::$connections` — from serving a query after the tenant changed.

Teardown is `DB::purge('tenant')` plus restoring the placeholder stanza, wrapped in a
`Tenancy::run()` whose `finally` always runs. Credentials are referenced, never stored:
the mapping record exposes a `credential_ref` string only, and the password exists as a
`Secret` object until the one line that writes the connection array.

## Alternatives rejected

- **`DB::build()` / `DB::connectUsing('tenant', $config, force: true)`** — the obvious
  Laravel 12 API for a runtime connection, and a **cross-tenant leak**. Verified in
  `vendor/laravel/framework/src/Illuminate/Database/DatabaseManager.php`: `build()` stores
  the full config (password included) in `$dynamicConnectionConfigurations[$name]` (line
  121); `configuration()` prefers that array over `config('database.connections.*')` (line
  218); and `purge()` (line 299) never clears it. There is no public API that does. After
  `purge('tenant')`, `DB::connection('tenant')` would silently rebuild the **previous**
  tenant's connection from a config no code can see or reset. `config()->set(...)` +
  `DB::purge(...)` — what `docs/database.md` §4.4 already specifies — has no such retained
  state. Step 7's test exists specifically to fail if anyone ever switches.
- **A guard *driver* plus `Connection::resolverFor()`** instead of `DB::extend()` — works,
  but `ConnectionFactory::createConnection()` throws `Unsupported driver [...]` before our
  code is reached unless a resolver is also registered, so it is two moving parts for a
  worse error message. `DB::extend()` is keyed by **connection name** and is consulted in
  `makeConnection()` before the factory (line 186), which is exactly the choke point wanted.
- **A model base class alone** (`TenantModel` with `$connection = 'tenant'`) — a correctness
  default, not a boundary: it does nothing about `DB::table()`, raw queries, the query
  builder or a model that forgets to extend it. Shipped *as well* (step 6), never *instead*.
- **Throwing from `leave()` when a tenant transaction is open** — `leave()` is called from a
  `finally`, so throwing there would mask the original exception. It rolls back and logs a
  warning instead; rollback is also what clears the pending records
  `DatabaseTransactionsManager` keys by connection **name** (and which would otherwise let
  tenant A's `afterCommit` callback fire on tenant B's commit).
- **An in-process cache of resolved secrets** (`docs/database.md` §4.4 allows one for the
  process lifetime) — it is the one thing that makes an in-place credential rotation
  invisible, and it is a second copy of a password in process memory. See *Caching*.
- **A `tenant:*` artisan command to exercise the resolver** — no AC needs it, and it would
  be a second production entry point to audit for AC-15. Tests and jobs call the API.
- **Adding `illuminate/*` requirements to `packages/kernel/composer.json`** — correct in
  principle, but a path package's `require` block is recorded in `composer.lock`, so it
  would force a lock rewrite in a task that should not touch dependency resolution. Left as
  debt (see *Follow-ups*).

## Files to change / create

### Create — `packages/kernel/src/Tenancy/` (namespace `Flexipos\Kernel\Tenancy`)

| File | Purpose |
|---|---|
| `TenancyServiceProvider.php` | Binds everything below; registers the `DB::extend('tenant', …)` guard via `$this->callAfterResolving('db', …)` (verified to exist, `ServiceProvider.php:310`) so `/health/live` still never instantiates `db` |
| `TenantContext.php` | **Scoped** singleton (`$app->scoped(...)`) holding the current tenant id and its `TenantDatabase`. `hasTenant(): bool`, `tenantId(): string` and `database(): TenantDatabase` throw `NoTenantResolved` rather than return null (AC-5). Mutators `@internal`, only `Tenancy` calls them |
| `Tenancy.php` | The **only** code that writes `database.connections.tenant` (AC-15). `run(string $id, Closure $cb): mixed`, `enter(string $id): void`, `leave(): void`, `current(): TenantContext` |
| `TenantDatabase.php` | `final readonly` DTO: `tenantId, driver, host, port, database, username, credentialRef, status`. `isActive()`, `STATUS_ACTIVE = 'active'`. No password field at all (AC-8, AC-24) |
| `TenantModel.php` | `abstract class TenantModel extends Model` with `protected $connection = 'tenant'`; the base every FP-204+ business model extends |
| `Contracts/TenantDatabaseRepository.php` | `find(string $tenantId): ?TenantDatabase`. The seam FP-203 replaces (AC-24/AC-25) |
| `InMemoryTenantDatabaseRepository.php` | The T-003 implementation. Built from `config('tenancy.databases')`, or from an explicit array in tests. No migration, no Eloquent model, no query (AC-25) |
| `Secrets/SecretResolver.php` | `resolve(string $reference): Secret` |
| `Secrets/SchemeSecretResolver.php` | Splits `scheme://rest`, dispatches to a registered driver, throws `UnsupportedCredentialReference` for every scheme with no driver (`vault://`, `awssm://`, `azkv://`, `file://`, `enc://` — D-31) |
| `Secrets/EnvSecretResolver.php` | `env://NAME` → `Env::getRepository()->get('NAME')`. Refuses when `app()->environment('production')` (`docs/database.md` §4.4) and when the variable is missing/empty (`CredentialNotAvailable`) |
| `Secrets/Secret.php` | `final readonly` wrapper. `reveal(): string` is the single audited call site; `__toString()`, `__debugInfo()` and `jsonSerialize()` all return `'[redacted]'`; `__serialize()` throws so a `Secret` can never reach a queue payload or a cache entry. **`__debugInfo()` covers `var_dump()` and `print_r()` only — it does NOT make `dd()`/`dump()` safe.** Those use Symfony's VarDumper cloner, which appends the hook's output *beside* the real properties instead of replacing them, so the value prints anyway; a registered caster is what fixes it. See step 12's measured table and requirement 12.6. (An earlier version of this row claimed the opposite and caused BUG-1. Its two source citations were real — the cloner genuinely *does* call `__debugInfo()` — but "it reads the hook" was inferred to mean "it uses the hook instead of the properties", and it does not. A citation supports the line it points at, not the conclusion drawn from it.) |
| `Exceptions/TenancyException.php` | `abstract class TenancyException extends RuntimeException`, following the `UnknownServiceException` convention: the offending tenant id is always in the message, the credential never is |
| `Exceptions/NoTenantResolved.php` | AC-4, AC-5, AC-18 |
| `Exceptions/InvalidTenantIdentifier.php` | AC-11 |
| `Exceptions/TenantDatabaseNotMapped.php` | AC-9 |
| `Exceptions/TenantDatabaseNotActive.php` | AC-10 (message carries the actual status) |
| `Exceptions/TenantAlreadyResolved.php` | nested `enter()` for a *different* tenant |
| `Exceptions/TenantConnectionMismatch.php` | the config/context or connection/context invariant broke (AC-16/AC-17 belt) |
| `Exceptions/TenantConnectionFailed.php` | the sanitised replacement for a driver-level failure. Built with `previous: null` on purpose (AC-12/AC-14) |
| `Exceptions/UnsupportedCredentialReference.php`, `Exceptions/CredentialNotAvailable.php` | secret resolution refusals; carry the reference, never the value |

### Create — `packages/kernel/tests/`

Pest files (no namespace, `uses(Tests\TestCase::class)` in-file, the pattern
`services/demo/tests/Feature/PingRouteTest.php` already uses — Pest refuses a second
binding for a directory the host's `tests/Pest.php` does not cover). Collected with no
`phpunit.xml` edit: the `Packages` suite already globs `../../packages/*/tests`.

`Unit/SecretTest.php` · `Unit/SchemeSecretResolverTest.php` · `Unit/TenantDatabaseTest.php` ·
`Unit/TenantContextTest.php` · `Feature/TenancyResolutionTest.php` ·
`Feature/TenancyTenantSwitchTest.php` · `Feature/TenancyCredentialLeakTest.php` ·
`Feature/TenantConnectionGuardTest.php`

### Change — `services/host`

| File | Change |
|---|---|
| `config/database.php` | Add the `master` stanza (env-driven, `DB_MASTER_*`); add the `tenant` placeholder stanza; change `'default'` fallback from `'sqlite'` to `'master'` (both `.env.example` and `phpunit.xml` set `DB_CONNECTION` explicitly, so nothing moves today). Keep `sqlite`/`mysql`/`mariadb`/`pgsql`/`sqlsrv` untouched — removing stock scaffolding is FP-157 |
| `config/tenancy.php` | **New.** `connection`, `master_connection`, `allowed_drivers`, `databases` (see *Data / API changes*) |
| `config/servicehost.php` | Reword documented constraint 2 only: a provider's `mergeConfigFrom()` is still a no-op under a cached config, but a module no longer contributes a database connection that way. **The literal token `mergeConfigFrom` must stay in the file** — `tests/Feature/QaCacheGuardBranchesTest.php:136` asserts it in both this file and the root README, and that test is `qa`-owned |
| `bootstrap/providers.php` | Append `Flexipos\Kernel\Tenancy\TenancyServiceProvider::class` |
| `phpunit.xml` | Add `DB_MASTER_DRIVER=sqlite`, `DB_MASTER_DATABASE=:memory:`, `TENANCY_ALLOWED_DRIVERS=mysql,mariadb,sqlite`. Do **not** touch `DB_CONNECTION=sqlite` or the testsuite blocks |
| `phpstan.neon.dist` | Add `../../packages/kernel/tests` to `paths` (T-002 AC-16: "any package's `tests/` directory") |
| `deptrac.yaml` | Add `../../packages/kernel/tests` to `paths`. **No layer or ruleset change**: `Package_Kernel` already collects `^Flexipos\\Kernel\\.*` and `HostApp`/`Service_Demo` may already depend on it |
| `.env.example` | Add a commented `DB_MASTER_*` block and a `# TENANT_<ID>_APP_PASSWORD` example for `env://`. Leave `DB_CONNECTION=sqlite` (flipping it is FP-203/FP-217) |
| `app/Console/Commands/SvcMakeCommand.php` | Drop `'connection.config.php.stub' => 'config/connection.php'` from `FILES`; add `config` to `EMPTY_DIRECTORIES`; drop the now-unused `{{connection}}`, `{{schema}}` and `{{screaming}}` replacements |
| `stubs/service/connection.config.php.stub` | **Delete** |
| `stubs/service/composer.json.stub` | Remove `extra.flexipos.connection` and `extra.flexipos.schema` |
| `stubs/service/ServiceProvider.php.stub` | Remove the `CONNECTION` constant and the `mergeConfigFrom()` call; keep `PREFIX`, routes and migrations; add a short docblock stating a module configures no database connection and reaches business data through the shared `tenant` connection |
| `stubs/service/PingRouteTest.php.stub` | Replace the `it('registers the {{connection}} database connection')` case with `it('cannot query the tenant connection until a tenant is resolved')`, asserting `DB::connection('tenant')->select('select 1')` throws `NoTenantResolved` |
| `tests/Feature/SvcMakeValidationTest.php` | `dev`-owned (not `Qa*.php`). Line 91 lists `'config/connection.php'` in the expected generated file set → replace with `'config/.gitkeep'`. Line 111 asserts `$manifest['extra']['flexipos']['connection'] === 'svc_svcmakefixture'` → assert the key is **absent** instead. This is the only place T-001 AC-25's now-superseded clause is encoded in code |
| `tests/Feature/SvcMakeAtomicGenerationTest.php` | `dev`-owned. Line 114 lists `'config/connection.php'` in the expected file set → same replacement |
| `README.md` (repo root) | "Adding a service": drop "a `svc_billing` database connection"; state that a module declares no connection. Reword documented constraint 2 the same way as `config/servicehost.php`, **keeping the literal `mergeConfigFrom`**. Every string `QaDocumentationAccuracyTest` asserts (`phpstan.neon.dist`, `deptrac.yaml`, `Service_Billing`, `ruleset entry`, `QaToolchainRegistryCoverageTest`, and `no file inside \`services/host/\` needs to change for that`) must survive verbatim |

### Change — `services/demo` (must stay byte-identical to `svc:make demo`, AC-22)

Delete `config/connection.php`; add `config/.gitkeep`; update `composer.json`,
`src/DemoServiceProvider.php` and `tests/Feature/PingRouteTest.php` to the new stubs.
Easiest correct route: delete the directory and re-run `php artisan svc:make demo`.

### Not touched

`app/Support/ServiceHost/*` (the registry never reads the `connection`/`schema` manifest
keys), `routes/*`, `app/Http/Controllers/HealthController.php`, every `Qa*Test.php` under
`services/host/tests/Feature/` (`qa`-owned), `packages/*/composer.json`, `composer.json`,
`composer.lock`, `docs/`.

## Data / API changes

No schema, no migration, no HTTP endpoint. Configuration and one internal contract.

**`config/database.php` — `master`** (mirrors the stock `mysql` stanza, with three
deliberate differences): driver is `env('DB_MASTER_DRIVER', 'mysql')` so the test suite can
point it at sqlite; collation defaults to `utf8mb4_unicode_ci`, not
`utf8mb4_0900_ai_ci`, because XAMPP is MariaDB 10.4 (`docs/database.md` §14) — MySQL
deployments override it; and **no `url` key**, so `ConfigurationUrlParser` is never handed
the empty `DB_URL` the test suite sets.

**`config/database.php` — `tenant` placeholder**, exactly:

```php
'tenant' => [
    'driver' => 'flexipos-no-tenant',   // sentinel: no real connector answers to this
    'database' => '',
    'prefix' => '',
],
```

`database`/`prefix` are present because `ConnectionFactory::createSingleConnection()` reads
both unconditionally. No `host`, no `username`, no `password` — ever, at rest (AC-3).

**`config/tenancy.php`:**

```php
'connection'        => 'tenant',
'master_connection' => 'master',
'placeholder_driver'=> 'flexipos-no-tenant',
'allowed_drivers'   => explode(',', (string) env('TENANCY_ALLOWED_DRIVERS', 'mysql,mariadb')),
'databases'         => [],   // local development mapping; FP-203 replaces the repository
```

`allowed_drivers` is a whitelist applied to `TenantDatabase::$driver` before a connection is
built, so a mapping row can never smuggle an unexpected driver in. `sqlite` is added by
`phpunit.xml` only.

**The connection array `Tenancy` writes** (the only place a credential appears):

```php
[
    'driver' => $db->driver, 'host' => $db->host, 'port' => $db->port,
    'database' => $db->database, 'username' => $db->username,
    'password' => $secret->reveal(),
    'charset' => 'utf8mb4', 'collation' => …, 'prefix' => '', 'prefix_indexes' => true,
    'strict' => true, 'engine' => null,
    'options' => [],                        // never PDO::ATTR_PERSISTENT (docs/database.md §2.4)
    'flexipos_tenant' => $db->tenantId,     // the invariant the guards check
]
```

`config()->set('database.connections.tenant', $array)` **replaces** the stanza wholesale, so
nothing from a previous tenant can survive as a leftover key (AC-17).

**`TenantDatabaseRepository`** — the FP-203 seam. One method,
`find(string $tenantId): ?TenantDatabase`. `TenantDatabase` maps to `docs/database.md` §4.4
as: `tenantId` ← `tenant_databases.tenant_id`, `database`/`username`/`credentialRef`/`status`
← `database_name`/`app_username`/`app_credential_ref`/`status`, `host`/`port`/`driver` ←
`database_servers.host`/`port`/`engine`. Everything else in those two tables
(`schema_version`, `profile`, `size_bytes`, rotation and migration bookkeeping) is out of
this contract by D-29 and belongs to FP-205/FP-206/FP-210.

## Implementation steps

- [x] **Step 1 — connections and configuration** (AC-1, AC-2, AC-3; D-30).
  `config/database.php` (`master`, `tenant` placeholder, `default` fallback),
  new `config/tenancy.php`, `phpunit.xml` env additions, `.env.example`.
  Prove now: `DB::connection('master')->select('select 1')` works under the test suite and
  `config('database.connections.tenant')` has no host/username/password key.

- [x] **Step 2 — value objects, contract, secrets** (AC-8, AC-11 input validation, AC-24,
  AC-25). `TenantDatabase`, `Contracts/TenantDatabaseRepository`,
  `InMemoryTenantDatabaseRepository`, `Secret`, `SecretResolver`, `SchemeSecretResolver`,
  `EnvSecretResolver`, every exception class. No framework wiring yet; unit-testable in
  isolation.

- [x] **Step 3 — `TenantContext` and the connection guard** (AC-4, AC-5, AC-15).
  `TenantContext` as `$app->scoped(...)`. `TenancyServiceProvider` registering, via
  `$this->callAfterResolving('db', …)`, `DB::extend('tenant', function (array $config, string $name) {…})`
  that in order: refuses when `$config['driver']` is the placeholder; refuses when
  `! $context->hasTenant()`; refuses when `($config['flexipos_tenant'] ?? null) !== $context->tenantId()`;
  refuses when the driver is not in `allowed_drivers`; otherwise returns
  `app('db.factory')->make($config, $name)` — use the `'db.factory'` **string**, that is the
  only binding `DatabaseServiceProvider` registers — with the per-query guard attached:

  ```php
  $connection->beforeExecuting(fn () => $guard($config['flexipos_tenant']));
  $connection->beforeStartingTransaction(fn () => $guard($config['flexipos_tenant']));
  ```

  Verified: `Connection::run()` invokes `beforeExecutingCallbacks` before
  `reconnectIfMissingConnection()` (`Connection.php:780-786`), and `createTransaction()`
  invokes `beforeStartingTransaction` (`ManagesTransactions.php:126`). This third level is
  what closes the hole that `DB::extend` alone leaves: the extension only runs when a
  connection is *built*, so a `Connection` already sitting in `DatabaseManager::$connections`
  — or held in someone's variable — would otherwise answer a query with no context check at
  all. Register the provider in `bootstrap/providers.php`.

- [x] **Step 4 — `Tenancy::enter/leave/run`** (AC-6, AC-7, AC-9, AC-10, AC-11, AC-16,
  AC-17, AC-18). Exact order inside `enter()`, and the order is the AC:

  1. `trim($id) === ''` → `InvalidTenantIdentifier`, **before** the repository is touched
     (AC-11);
  2. already inside a different tenant → `TenantAlreadyResolved`; same tenant → increment a
     depth counter and return (re-entrant, no reconfiguration);
  3. `$repository->find($id)` → null → `TenantDatabaseNotMapped` (AC-9);
  4. `! $db->isActive()` → `TenantDatabaseNotActive` naming the actual status (AC-10);
  5. driver not whitelisted → `TenantConnectionMismatch`;
  6. `$secret = $secrets->resolve($db->credentialRef)` (AC-8);
  7. **`leave()`** — purge and restore the placeholder — *then* write the new stanza and set
     the context. Steps 1-6 touch no state, so every refusal above leaves
     `config('database.connections.tenant')` byte-identical to what it was (AC-9/AC-10's
     "no partial overwrite");
  8. force the connection (`DB::connection('tenant')->getPdo()`) inside `try/catch`; on
     failure `leave()`, clear the context and throw `TenantConnectionFailed` (step 5 covers
     why it is sanitised).

  `leave()`: no tenant → no-op; depth > 0 → decrement and return; if `'tenant'` is among
  `DB::getConnections()` and `transactionLevel() > 0` → `rollBack()` and log a
  `warning` naming the tenant id and level (never the config), because rollback is also what
  clears the pending records `DatabaseTransactionsManager` keys by connection **name**; then
  `DB::purge('tenant')` (`purge()` = `disconnect()` + `unset($this->connections[$name])`,
  verified — `disconnect()` alone only nulls the PDO and would silently reconnect via
  `reconnectIfMissingConnection()`); then restore the placeholder stanza; then clear the
  context. `run(string $id, Closure $cb)` = `enter()`, `try { $cb() } finally { leave() }`.

- [x] **Step 5 — credential hygiene** (AC-12, AC-13, AC-14). `#[\SensitiveParameter]` on
  every parameter of our own code that receives a `Secret`, a raw password or the built
  config array — PHP 8.2 replaces such an argument with `SensitiveParameterValue` in
  `getTrace()`, and the framework already does this for its own
  `Connector::createPdoConnection()`. Every exception message is built from a redacted array
  (`password => '[redacted]'`), never from the config. `TenantConnectionFailed` is
  constructed with **`previous: null`** on purpose: that is what drops the
  `Connector::createConnection($dsn, array $config, $options)` frame — whose `$config`
  argument holds the plaintext password, and which `Symfony\…\FlattenException::flattenArgs()`
  copies verbatim into the flattened trace (verified) — out of the exception that escapes to
  the renderer. Laravel's own debug page prints argument *types* only
  (`Renderer/Frame::args()`), but Symfony's fallback `HtmlErrorRenderer::formatArgs()` prints
  `var_export()` of string values, so the mitigation must keep the value out of the trace
  rather than trust the renderer. Nothing logs the config; the resolver logs tenant ids and
  statuses only.

- [x] **Step 12 — `ConnectionPassword` (architect ruling, added after `dev` measured R-4).**
  `dev` found the residual is narrower and more concrete than R-4 described: the leaking
  frame is the framework's own `call_user_func($this->extensions[$name], $config, $name)`
  inside `DatabaseManager::makeConnection()`, and because that is an internal variadic call
  our `#[\SensitiveParameter]` cannot mark it. **Ruling: take the `Stringable` fix.** Store
  the stanza password as a small value object instead of a plain string; the flattener
  records objects as `['object', get_debug_type($value)]` and discards the value.

  Measured on this machine (PHP 8.2.12, framework v12.69.2), same `call_user_func($closure, $config)`
  shape, needle built at runtime so the source excerpt could not confound the result.

  > **This table was wrong when first written, and it was wrong twice over. Both ways are
  > the lesson.**
  >
  > *First:* its original seven rows were each accurate, but it omitted `dd()` / `dump()`
  > and was read — by me, and then by `dev` — as a complete enumeration. It was not. QA
  > found the defect there. Every row below is now the result of *running* that surface;
  > none is inferred from which magic method it ought to honour. If you add a surface,
  > measure it. Treat the list as open, never exhaustive.
  >
  > *Second, and the more useful one:* the original false claim — "`__debugInfo()` is
  > honoured by Symfony's VarDumper, which is what `dd()`/`dump()` use" — **survived a
  > correction pass aimed directly at it**. I rewrote this section and left the same
  > sentence standing 200 lines earlier in the file table, still carrying two source
  > citations, which made the stale copy read as the more authoritative of the two. A
  > correction has to target the *claim* wherever it appears, not the *section* that
  > prompted it. Grep the whole document for the assertion before calling it fixed.

  | Surface | plain string | `ConnectionPassword` (property + `__debugInfo`) | older `Secret` (value captured in a closure) |
  |---|---|---|---|
  | Symfony fallback HTML page | **LEAKS** | clean | clean |
  | `getTraceAsString()` | clean | clean | clean |
  | `print_r(getTrace())` / `var_dump(getTrace())` | LEAKS | clean | clean |
  | `json_encode()` | — | clean | clean |
  | `var_dump()` of the object | — | clean | clean |
  | `print_r()` of the object | — | clean | clean |
  | **`dd()` / `dump()`, no caster** | LEAKS | **LEAKS** | **LEAKS** |
  | `dd()` / `dump()`, caster registered | LEAKS | clean | clean |
  | `var_export()` of the object | — | **LEAKS** (`var_export` ignores `__debugInfo`) | clean |
  | string interpolation | LEAKS | LEAKS | clean (returns `[redacted]`) |

  **Why the dumpers defeat both classes, measured and then read in the source.**
  `dd()`/`dump()` do not go through `var_dump()`; they use the VarDumper cloner.
  `Caster::castObject()` (`vendor/symfony/var-dumper/Caster/Caster.php:63`) builds its array
  as `(array) $obj` — every property, private included — and then, at line 96, *appends*
  `__debugInfo()`'s entries under `PREFIX_VIRTUAL` keys without ever removing the real ones.
  So the true value prints beside the redacted one. Hiding the value in a closure's captured
  binding instead of a property buys nothing either: the cloner recurses into the closure and
  renders its `use` bindings. Both shapes leak; only a registered caster fixes it, because
  `AbstractCloner::castObject()` assigns `$a = $callback(...)` (line 375) — the caster's
  return value **replaces** the cast array rather than merging into it.

  **Registration entry point — `dev` corrected my suggested signature, confirmed here.**
  Use the **static** `AbstractCloner::addDefaultCasters([...])`, not the instance
  `$cloner->addCasters([...])`. `AbstractCloner::__construct()` (line 233) reads
  `static::$defaultCasters` once, at construction, so an instance-level registration affects
  only the cloner you happen to hold — measured: our instance clean, a freshly constructed
  cloner still leaking, and a fresh cloner is exactly what `dump()`/`dd()` build. It also
  follows that registration must happen at boot, before the first dump, since `VarDumper`
  caches its handler on first use.

  With the caster registered, `ConnectionPassword` is better than a plain string at every
  measured surface and worse at none, which is the claim that justifies the change — but it
  is a claim about the surfaces in this table, not about all surfaces. Requirements:

  1. **A new class, not `Secret`.** `Secret::__toString()` must keep returning `[redacted]`;
     `ConnectionPassword::__toString()` returns the real value — that is its entire purpose.
     Different jobs, different classes. `Tenancy` is the only code that converts one into the
     other (`new ConnectionPassword($secret->reveal())`), so `Secret::reveal()` stays the
     single audited call site.
  2. `final readonly`, `implements \Stringable`, `__debugInfo()` redacts, `__serialize()` /
     `__sleep()` throw, and **no `__set_state()`**. `__debugInfo()` covers `var_dump()` and
     `print_r()` and **nothing else** — it does *not* cover `dd()`/`dump()` (see requirement
     6) and it does not cover `var_export()`, which ignores it, so a stanza holding one must
     never be `var_export`ed. `config:cache` is safe by construction: no tenant is ever
     entered in a config-caching process, so it only ever sees the placeholder.
  3. Keep the `#[\SensitiveParameter]` `dev` already added. It is not redundant — it covers
     our frame, the object covers the framework's.
  4. Keep `TenantConnectionFailed`'s `previous: null`. The object narrows what any one frame
     can print; the sanitised rethrow is what keeps the whole connector chain out of the
     escaping exception. They cover different frames.
  5. AC-17's `toBe()` comparison of the stanza now compares an object — compare a redacted
     projection, or assert the `password` key separately by instance, so the assertion keeps
     its meaning.
  6. **Register a VarDumper caster for both `ConnectionPassword` and `Secret`**, at boot,
     via the **static** `AbstractCloner::addDefaultCasters([Class => fn () => [Caster::PREFIX_VIRTUAL.'value' => '[redacted]']])`.
     Without it, `dd()`/`dump()` print the real value for *both* classes — measured, and
     explained above. The caster's return value replaces the cast array, which is why it
     works where `__debugInfo()` does not. `Secret` needs one too: hiding the value in a
     captured closure binding does not help, because the cloner renders closure bindings.
     A unit test must assert both classes are clean under `dd()`/`dump()`, not only under
     `var_dump()`/`print_r()`.

- [x] **Step 13 — the two regression catchers for step 12.** The coercion dependency is
  real and must be made loud rather than assumed.

  *Verified, not asserted:* `Illuminate\Database` contains **no** `declare(strict_types=1)`
  in any file; `Connector::createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)`
  types none of its parameters (reflection reports type `''`); and `new PDO('sqlite::memory:', null, $stringable)`
  coerces successfully.

  1. **Framework-contract test**, named so a failure reads as *the framework changed the
     contract we depend on*, not *tenancy is broken*: assert by reflection that
     `createPdoConnection`'s `$password` parameter is untyped or string-compatible, and that
     `Connector.php` declares no `strict_types`.
  2. **Behavioural test:** assert `config('database.connections.tenant.password')` is a
     `ConnectionPassword` *and* that the subsequent real query succeeds — the existing
     two-tenant sqlite test already opens real connections, and sqlite still passes
     username/password through `new PDO()` and ignores them, so it exercises the coercion
     boundary even though sqlite needs no credential. Making the instance assertion explicit
     turns incidental coverage into intentional coverage.
  3. **Promote `dev`'s documented residual into an executing test:** the narrow path it
     measured — scope reset plus an external `DB::purge('tenant')`, then a query, with
     `app.debug` true, rendered through the real handler — asserting the password is absent.
     Red before step 12, green after. This is the one that catches a *renderer* regression,
     which is the only direction that could fail silently.

  Why the coercion dependency is acceptable: it fails **closed**, not open. If Laravel ever
  adds `strict_types` or types `$password`, `new PDO(...)` raises a `TypeError` and no tenant
  connection can be opened at all, so the first `composer update` turns the gate red in the
  most visible way available. A dependency whose failure mode is "nothing connects" is a very
  different risk from one whose failure mode is "it quietly leaks again".

- [x] **Step 6 — `TenantModel`.** `abstract class TenantModel extends Model` with
  `protected $connection = 'tenant'`. Deliberately does **not** override `setConnection()`
  (Eloquent calls it internally); the boundary is step 3's guard, this is the default that
  stops a module's model quietly landing on `master`.

- [x] **Step 7 — kernel tests** (proves AC-4 … AC-18) and the two registry lines.
  Add `../../packages/kernel/tests` to `phpstan.neon.dist` and `deptrac.yaml`. Fixture: two
  sqlite files under `storage/app/tenancy-fixture/`, each with a `marker` table holding
  `tenant-a` / `tenant-b`, and an `InMemoryTenantDatabaseRepository` bound with matching
  records whose `credentialRef` is `env://TENANT_A_APP_PASSWORD` set to a distinctive value.
  See *Test strategy* for what each file proves.

- [x] **Step 8 — strip the per-module connection model** (AC-19, AC-21).
  `SvcMakeCommand` (`FILES`, `EMPTY_DIRECTORIES`, `replacements`), delete
  `stubs/service/connection.config.php.stub`, edit the `composer.json`, `ServiceProvider`
  and `PingRouteTest` stubs, and update the two `dev`-owned expected-file-set assertions
  (`SvcMakeValidationTest.php:91` and `:111`, `SvcMakeAtomicGenerationTest.php:114`). Then
  `php artisan svc:make probe && composer test && rm -rf ../probe` before moving on: AC-21
  is the thing most likely to break here.

- [x] **Step 9 — regenerate `services/demo`** (AC-20, AC-22, AC-23). Delete
  `services/demo` and run `php artisan svc:make demo`. `SvcMakeDemoRegenerationTest` and
  `QaTestCodeAnalysisCoverageTest`'s "stub and committed copy are identical" case then pass
  without either being edited — neither test hard-codes the old file list; the first
  compares the tree to the generator and the second `strtr`s whatever placeholders the stub
  contains. If `composer install` complains about the demo path package, run it again after
  the regeneration.

- [x] **Step 10 — documentation** (keeps T-002 AC-11/AC-14 green). Root `README.md` and
  `config/servicehost.php` as described in *Files to change*. **Keep the literal
  `mergeConfigFrom`, `config:cache`, `route:cache`, `--classmap-authoritative`, `guarded`
  and `documented only` tokens in both files** — `QaCacheGuardBranchesTest` asserts all six
  in both, and it is `qa`-owned.

- [x] **Step 11 — the gate.** `cd services/host && composer check` must exit 0 with at
  least the baseline 148 tests still passing. Fix Pint first (`composer lint:fix`), then
  PHPStan level 6, then Deptrac, then Pest — in that order, because the earlier tools are
  the fast ones.

## Test strategy

### Unit (`packages/kernel/tests/Unit/`)

- `SecretTest` — `reveal()` returns the value; `__toString()`, `__debugInfo()`, `json_encode()`,
  `var_dump()` and `print_r()` all return/contain `[redacted]` and never the value;
  `serialize()` throws; and — the case the first version of this plan missed — **`dd()`/`dump()`
  are clean too**, which only holds once step 12.6's caster is registered. Assert the dumper
  surfaces by capturing a real dump, not by asserting `__debugInfo()`'s return value: the
  point of the defect QA found is that the two are not the same question.
- `SchemeSecretResolverTest` — `env://NAME` resolves; `vault://`, `awssm://`, `azkv://`,
  `file://`, `enc://` and a scheme-less string all throw `UnsupportedCredentialReference`
  naming the reference; a missing/empty variable throws `CredentialNotAvailable`; `env://`
  throws when the environment is `production`. **AC-8.**
- `TenantDatabaseTest` — `isActive()` is true only for `'active'`; `suspended`,
  `provisioning`, `read_only`, `archived`, `failed` and an unrecognised string are all
  non-active (deny-by-default, D-26); the DTO has no password-shaped property. **AC-10, AC-24.**
- `TenantContextTest` — fresh context: `hasTenant()` false, `tenantId()` and `database()`
  throw `NoTenantResolved`, and there is no accessor that can return null. **AC-5.**

### Integration (`packages/kernel/tests/Feature/`)

- `TenantConnectionGuardTest` — with no tenant resolved,
  `DB::connection('tenant')->select('select 1')` throws `NoTenantResolved`; so does an
  Eloquent model extending `TenantModel`; and no PDO is attempted (assert the sqlite fixture
  files are untouched / no `ConnectionEstablished` event for `tenant` was dispatched).
  **AC-4.**
- `TenancyResolutionTest` — happy path for tenant A: the config gains A's host/port/database/
  username, `TenantContext` reports A, and `marker` reads `tenant-a` (**AC-6, AC-7**).
  Refusals: unmapped id → `TenantDatabaseNotMapped` (**AC-9**); `suspended` and
  `provisioning` → `TenantDatabaseNotActive` naming the status (**AC-10**); `''` and `'   '`
  → `InvalidTenantIdentifier` with a repository spy proving `find()` was never called
  (**AC-11**). After every refusal, `config('database.connections.tenant')` equals the
  placeholder array captured before the call, compared with `toBe()` (**AC-9/AC-10** "no
  partial overwrite").
- `TenancyTenantSwitchTest` — the single most important file in the task:
  1. `enter('A')`, read `tenant-a`, capture `$connA = DB::connection('tenant')` and
     `$pdoA = $connA->getPdo()`;
  2. `leave()`, `enter('B')`: `marker` reads `tenant-b`, `TenantContext` reports B,
     `DB::connection('tenant') !== $connA`, `DB::connection('tenant')->getPdo() !== $pdoA`,
     and `$connA->getRawPdo()` is `null` — A's PDO was actually closed, not merely dropped
     (**AC-16**);
  3. `config('database.connections.tenant')` equals, with `toBe()`, the array produced by
     entering B in a fresh application — no leftover A key or value (**AC-17**);
  4. **the stale-object proof:** `$connA->select('select value from marker')` throws
     `TenantConnectionMismatch` instead of returning data. This is the one assertion that
     covers a repository or service that cached `DB::connection('tenant')` across a tenant
     switch, and the only one that fails if step 3's `beforeExecuting` guard is ever removed;
  5. `leave()` with no replacement → `DB::connection('tenant')` throws `NoTenantResolved`
     again and the stanza is back to the placeholder (**AC-18**);
  6. **the `DB::build()` regression guard:** after a full A → leave → query cycle,
     `DB::connection('tenant')` must still throw. Carries a comment naming
     `DatabaseManager::$dynamicConnectionConfigurations` so the next person understands why
     `connectUsing()` is forbidden;
  7. the queue shape: two `Tenancy::run()` calls for A then B inside one process, plus one
     run for A followed by `app()->forgetScopedInstances()` (what
     `QueueServiceProvider`'s `$resetScope` does between jobs, verified) — after which a
     query against `tenant` must throw rather than serve A;
  8. `config('database.connections.tenant.options')` never contains
     `PDO::ATTR_PERSISTENT` (`docs/database.md` §2 rule 4).
- `TenancyCredentialLeakTest` — one distinctive password (`'s3cret-DO-NOT-LEAK-7f3a'`) for
  every run. Across AC-9, AC-10, AC-11 and a forced connection failure (a tenant record
  whose sqlite `database` path cannot be opened): the value appears in **no** exception
  message, no `(string) $e`, no message anywhere in `getPrevious()` chain, and
  `$e->getPrevious()` is null for `TenantConnectionFailed` (**AC-12**); in no record
  captured from the log channel via `Log::spy()`/a captured handler (**AC-13**); and in
  nothing rendered by `app(ExceptionHandler::class)->render($request, $e)` with
  `config(['app.debug' => true])` (**AC-14**).

### Regression — proven by the existing suite, no edits

`composer check` re-proves T-001 AC-8/9/12/14/16/17, AC-18 … AC-24, AC-27, AC-31, AC-32 and
T-002 AC-1 … AC-6, AC-9 … AC-13, AC-16 … AC-19 as-is. Three existing tests do the heavy
lifting for T-003's removals and must pass **unedited**: `SvcMakeDemoRegenerationTest`
(AC-22), `QaTestCodeAnalysisCoverageTest`'s stub/committed-copy case (AC-23), and
`QaToolchainRegistryCoverageTest` (the new `packages/kernel/tests` registration).

**One criterion is superseded, not regressed.** T-001 AC-25 required the generator to write
"a database connection configuration entry named for the service". FP-201 deletes that file
by design and the spec's AC-19/AC-20 supersede the clause. It is encoded in code in exactly
three places, all `dev`-owned and all listed above: `SvcMakeValidationTest.php:91` and `:111`
and `SvcMakeAtomicGenerationTest.php:114`. `dev` updates those three assertions and says so
explicitly in the hand-off to `qa`, so a deliberate supersession is never mistaken for a
weakened test.

### AC → test map

| AC | Proven by |
|---|---|
| 1, 2, 3 | Step 1 assertions + `TenantConnectionGuardTest` |
| 4 | `TenantConnectionGuardTest`, and the generated module stub's own new test case |
| 5 | `TenantContextTest` |
| 6, 7 | `TenancyResolutionTest` |
| 8 | `SchemeSecretResolverTest`, `TenantDatabaseTest` (no password field), `SecretTest` |
| 9, 10, 11 | `TenancyResolutionTest` |
| 12, 13, 14 | `TenancyCredentialLeakTest` |
| 15 | `reviewer` by inspection; `TenancyTenantSwitchTest` step 6 proves no second working path |
| 16, 17, 18 | `TenancyTenantSwitchTest` |
| 19, 20, 21 | Step 8's throwaway `svc:make probe` run; `SvcMakeValidationTest` |
| 22, 23 | `SvcMakeDemoRegenerationTest`, `QaTestCodeAnalysisCoverageTest`, `services/demo`'s own `PingRouteTest` |
| 24, 25 | `TenantDatabaseTest`; grep proof that no migration or Eloquent model names `tenant_databases`/`database_servers` |

## Caching

**T-003 caches nothing** (spec D-32). The design is fixed here so FP-203 drops a decorator
in behind `TenantDatabaseRepository` without touching `Tenancy`:

- **What is cached:** the `TenantDatabase` DTO — host, port, database, username,
  `credential_ref`, status. **Never the resolved credential.** That single property is what
  makes the whole thing safe: an in-place rotation (new secret version written at the same
  reference) is picked up on the very next connection with no invalidation at all, because
  the secret is dereferenced on every build.
- **Keyed by:** `tenancy:mapping:{tenant_id}` in the platform cache store, TTL 300s.
- **Invalidated by:** the TTL, plus an explicit `forget()` on every write to
  `tenant_databases` (FP-203) — that is the only change that can move a host, a username or
  a credential reference. FP-212's `tenant.mapping.changed` event makes the eviction
  multi-node.
- **Deliberately not done:** an in-process secret cache, even though `docs/database.md` §4.4
  permits one. It is the one thing that would make a rotation invisible, and it is a second
  copy of a password in process memory for a `getenv()`/file read.

**Two defects in my own `docs/database.md` §4.4 that this plan corrects** — they need a doc
edit in a follow-up, since `docs/database.md` is out of T-003's file scope:

1. The key I wrote, `tenant:conn:<tenant_id>:<credential_version>`, is **unusable as a
   lookup key**: nothing knows `credential_version` until after the row is read. The version
   belongs in the cached value, not the key.
2. §4.4's "resolves the secret (cached in memory for the process lifetime)" contradicts its
   own rotation story two bullets later. The narrowing above is deliberate.

## Risks & rollback

| # | Risk | Mitigation |
|---|---|---|
| R-1 | **A stale `Connection` object serves the wrong tenant.** `DB::purge()` drops the manager's reference, but any code holding `DB::connection('tenant')` in a property keeps a live object that will happily reconnect via `reconnectIfMissingConnection()` | Step 3's `beforeExecuting` + `beforeStartingTransaction` guard re-checks the context on every query on that object. `TenancyTenantSwitchTest` step 4 is the proof. **If `dev` is tempted to drop this guard for performance, escalate to `architect` — it is the load-bearing part of the task** |
| R-2 | **Someone "simplifies" the resolver to `DB::build()`/`connectUsing()`.** `$dynamicConnectionConfigurations` is never cleared by `purge()` and takes priority over `config()`, so the previous tenant's config — password included — silently survives a purge with no API to remove it | `TenancyTenantSwitchTest` step 6 fails if it happens; the *Alternatives rejected* entry and an inline comment on `Tenancy` say why |
| R-3 | A queued job or console loop forgets `leave()` | `run()` is the documented API and its `finally` always fires; `TenantContext` is `scoped`, so the queue worker's `forgetScopedInstances()` drops it between jobs and the guard then refuses the leaked config (proven in `TenancyTenantSwitchTest` step 7) |
| R-4 | A credential reaches a debug page or a log | Step 5: sanitised exception with `previous: null`, `#[\SensitiveParameter]`, `Secret` redaction, and the config stanza existing only inside `run()` — so `config:show database`, `artisan about` and any `dd(config('database'))` outside a tenant scope show the placeholder. **Closed by steps 12-13**, after `dev` measured the residual precisely: the one leaking frame is the framework's own `call_user_func($this->extensions[$name], $config, $name)`, which `#[\SensitiveParameter]` cannot reach, so the password becomes a `Stringable` the flattener records as `object(ClassName)` with no value. **Then reopened by QA and closed again:** `dd()`/`dump()` leaked for both that class and `Secret`, because the cloner appends `__debugInfo()` beside the real properties rather than replacing them — fixed by step 12.6's caster. Remaining, accepted: `var_export()` of the stanza and direct string interpolation of the password — both are our own code's to avoid, neither occurs in the framework's rendering path |
| R-13 | **A leak surface nobody enumerated.** R-4 was declared closed twice on tables that each looked complete and each omitted a surface | The lesson `dev` and QA reached independently, and the one to carry forward: enumerate by *running* each surface, never by reasoning about which magic method it honours. Step 12's table now says so in the file, and its rows are measurements. Treat it as open. Any new place a credential could be rendered gets a test before it gets an argument |
| R-14 | **A corrected claim surviving elsewhere in the same document.** The false "`__debugInfo()` covers `dd()`/`dump()`" sentence — the one that caused BUG-1 — outlived a rewrite aimed straight at it, because the rewrite fixed the section that prompted it and not the claim itself. The stale copy sat in the file table with two source citations, so it read as the *more* authoritative of the two contradictory statements, and it is the one a skimmer reaches first | Fixed at both sites, and the rule is written into step 12's standing note: correct the **claim** wherever it appears, not the section. Grep the whole document for the assertion before calling it fixed. For a security claim specifically, prefer one statement plus cross-references over the same sentence restated in several places — restatements are what drift |
| R-11 | The `Stringable` coercion stops working on a framework upgrade (`strict_types`, or a `string` type hint on `$password`) | It fails **closed**: `new PDO(...)` raises a `TypeError` and no tenant connection opens at all, so the existing two-tenant sqlite tests go red on the first `composer update`. Step 13's reflection test names the exact framework contract so the failure is diagnosable in one read rather than looking like a tenancy bug |
| R-12 | A renderer change (Symfony or PHP deciding to render `Stringable` args by value) silently restores the leak | Step 13.3: the narrow path `dev` measured is promoted from a code comment into an executing, debug-mode, rendered-output assertion. This is the only regression direction that could fail silently, so it is the one that gets a dedicated test |
| R-5 | The Symfony/Laravel renderer internals I relied on change on a framework upgrade | AC-14's test renders through the real handler, so an upgrade that starts leaking fails the gate |
| R-6 | `QaCacheGuardBranchesTest` / `QaDocumentationAccuracyTest` go red on the doc edits | Step 10 lists every literal token that must survive. `qa` owns those tests; `dev` must not edit them — if a token genuinely cannot stay, message `qa` |
| R-7 | Adding `master` changes what `default` means for sessions, cache, queue and `artisan migrate` (`config/session.php`, `cache.php`, `queue.php` all fall back to the default connection) | `.env.example` and `phpunit.xml` keep `DB_CONNECTION=sqlite`, so nothing moves in this task. The stock `users`/`cache`/`jobs` migrations stay where they are; relocating them to a master migration tree is FP-203 |
| R-8 | sqlite in `allowed_drivers` leaks into a real deployment | It is not in the default; only `phpunit.xml` adds it, via `TENANCY_ALLOWED_DRIVERS` |
| R-9 | Gate time (82s warm at the T-003 baseline, FP-155 flags growth as multiplicative) | Kernel tests are unit-heavy and sqlite-backed; no MySQL, no `RefreshDatabase`. If the gate crosses ~150s, report it to the lead against FP-155 rather than trimming tests. **Outcome: 109s after QA's additions** — a 33% rise for a materially larger suite, below the threshold, reported against FP-155 and explicitly *not* addressed by trimming tests. The credential-leak tests are the last ones that should ever be cut for speed |
| R-10 | `packages/kernel` uses `Illuminate\*` without declaring it | Pre-existing across every `packages/*` stub; declaring it would rewrite `composer.lock` inside this task. Left as debt (*Follow-ups*) |

**Rollback:** every change is additive except the deleted stub, `services/demo/config/connection.php`
and the edited stubs/config. `git checkout -- services packages docs` on the branch restores
T-002 exactly; nothing in T-003 creates or migrates data, and no dependency changes, so there
is nothing to undo outside the working tree.

## Follow-ups for the lead (not in T-003)

1. **FP-158** — `docs/database.md` §4.4: fix the cache key and the secret-cache
   contradiction above (architect-owned edit; a small doc row, not a code task).
2. New backlog row: declare `illuminate/*` requirements in `packages/*/composer.json`
   (needs a deliberate `composer.lock` update; sits naturally next to FP-155/FP-156).
3. FP-203 must add: the real `TenantDatabaseRepository` against `tenant_databases` +
   `database_servers`, the caching decorator described above, and a
   `TenantConnectionFailed` → re-read-and-retry-once path for a rotation that changed the
   username or the reference.
4. FP-207 owns the HTTP middleware that calls `Tenancy::run()` from a verified token, and
   the tenant-code → tenant-id translation (D-25).

## Questions for the human

None.
