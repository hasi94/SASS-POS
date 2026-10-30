# Plan: T-021 API foundation

Spec: `docs/specs/T-021-api-foundation.md` (AC-1 to AC-50, plus AC-33a and AC-33b). Decisions: `docs/decisions/T-021-api-foundation.md` (D-1 to D-15).
Backlog: FP-215. Branch: `ai/T-021-api-foundation`, stacked on T-020, which is still in its round-1 fix cycle.
Gate directory: `modules/host`. Confirmed: `services/` no longer exists, and `composer check` is defined in `modules/host/composer.json`.

## 0. Rulings

Each ruling settles a design question the spec left to the architect. The ones `ba` must see are marked **-> ba** and are listed again in section 10.

### Ruling 1: where the code lives (no new package, no new deptrac layer)

| Concern | Home | Why |
|---|---|---|
| Error catalogue, envelope helper, idempotency store, prune slice, error-codes slice | `packages/kernel/src/Api/` (namespace `Flexipos\Kernel\Api\`) | The kernel's `composer.json` already describes it as holding "idempotency". Every package and `Module_Demo` may depend on `Package_Kernel`, so a module can use the envelope and declare codes. `Package_Contracts` cannot hold them: it depends on nothing, and the catalogue needs the container. |
| Exception renderer, rate limiter, idempotency middleware, actor reader, OpenAPI generator, readiness checks | `modules/host/app/` (`HostApp` layer) | Each of these reads host symbols: the `RequireTenantToken`, `RequireTerminalToken` and `RequirePlatformToken` attribute constants, the routing table, or `ModuleRegistry`. |

- `deptrac.yaml` is **not edited**. The new kernel code reaches only Illuminate (an uncovered vendor) and `Flexipos\Kernel\*`: `Tenancy`, `TenantFleet`, `RunnerClock`, `RequestId`.
- The host already holds `HostApp -> Package_Kernel`, and `Module_Demo -> Package_Kernel` is already allowed.
- `phpstan.neon.dist` is **not edited**, because `packages/kernel/src` and `packages/kernel/tests` are already analysed.
- `composer.json` is **not edited**, and no dependency is added (see section 2).

### Ruling 2: the catalogue registers by declaration (AC-9 to AC-13)

The pattern is the `DeclaresAuditActions` / `DeclaresPermissions` one, moved into the kernel.

- `Flexipos\Kernel\Api\DeclaresErrorCodes` is implemented by a service provider. Its method `errorCodes(): array<string, list<ErrorCode>>` is keyed by module name, the same shape as `DeclaresAuditActions::auditActions()`.
- `ErrorCatalogue` is a singleton. It reads `$app->getProviders(DeclaresErrorCodes::class)`.
  - **It builds lazily and memoises against the list of provider classes.** A module booted later, as `ModuleRegistry::bootAll()` does inside tests, is picked up on the next read (AC-13).
  - **It validates while building.** The validation is "refused at registration" (AC-10, AC-11), and it throws `ErrorCatalogueRefused` with the offending code in the message in these cases:
    - the code does not match `^[a-z0-9_]+\.[a-z0-9_]+$`;
    - the prefix is not the declaring key;
    - the module key itself is not `^[a-z0-9_]+$`;
    - the code was already registered, by the same module or another;
    - the status is outside 400 to 599;
    - the message is empty.
- `Flexipos\Kernel\Api\ApiServiceProvider` (new) declares module `kernel`, using the constants of `KernelErrorCodes`.
- **The kernel codes:**
  - the 12 that AC-9 lists;
  - two more, `kernel.bad_request` (400) and `kernel.payload_too_large` (413). Ruling 4 says why.
- **AC-12.** `ErrorCatalogue::resolve(string $code)` returns the entry, or:
  - outside `production`, throws `UnknownErrorCode`;
  - in `production`, logs `error` with the unknown code and the request id, then returns the `kernel.server_error` entry.
- **AC-13 needs no change to `modules/demo`.** The test registers an anonymous provider that implements `DeclaresErrorCodes`. The kernel tests have no PSR-4 autoload, so an anonymous class is the only fixture shape that works there.

### Ruling 3: the envelope helper (AC-1 to AC-5, AC-15)

`Flexipos\Kernel\Api\ApiResponse` is a set of static methods. It resolves the catalogue and the request id from `Container::getInstance()`, so module code calls it without any injection.

| Method | Status | Body |
|---|---|---|
| `success(mixed $data = null, ?string $message = null, int $status = 200)` | 200 or 201 only. Any other value throws `InvalidArgumentException` (AC-5) | `{success:true, data, message, errors:[], meta:{request_id}}` |
| `created(mixed $data = null, ?string $message = null)` | 201 | the same |
| `failure(string $code, ?string $detail = null, ?string $field = null, array $headers = [])` | the catalogue's status for `$code` | `{success:false, data:null, message:<catalogue message>, errors:[{code, field, detail}], meta}` |
| `failures(list<ApiError> $errors, array $headers = [])` | the catalogue's status for the first code | one error item per entry |

- **Key order is fixed and asserted with `array_keys`:** `success`, `data`, `message`, `errors`, `meta`.
- An error item has exactly `code`, `field`, `detail`. `detail` defaults to the catalogue message.
- **"204-equivalent" (AC-5) is `success()` with no data**, which answers 200 with `data: null`. A real 204 cannot carry the envelope. **-> ba informational.**
- **`meta.request_id` is `RequestId::current()`.**
  - Every HTTP request carries it, because `AssignRequestId` is prepended globally.
  - Exceptions are rendered inside that middleware's `$next` by the routing pipeline, so the header and the body agree (AC-4).
  - Outside HTTP the value is null. That is never on the wire.
- **`failure()` and `failures()` log one `info` line, `API request refused.`** The context is `codes`, `status` and `request_id`, which is the operability non-functional requirement.
  - **The line never includes the path, the query or the detail text.** A path can carry an activation code; see `QaActivationCodeSecrecyTest`.

### Ruling 4: the generic `api/*` renderer (AC-6 to AC-8, AC-48, AC-49)

**Placement.** One `$exceptions->render(function (Throwable $e, Request $request) {...})` is registered **last** in `bootstrap/app.php`.

- Laravel tries render callbacks in registration order, and the first non-null answer wins. So the existing `PlanLimitReached`, `TenantSchemaBehind` and `RequiredSchemaVersionNotConfigured` renders keep priority, which is AC-48 by construction.
- `AuthRefusals`, `AccessRefusals` and `OrganizationRefusals` never reach the handler at all: they are returned by middleware and controllers.

**The callback returns null, so the framework renders as today, when:**
- the path is not `api/*` (AC-49, D-15); or
- the exception is an `HttpResponseException`, which already carries a response.

**Otherwise it calls `App\Http\Responses\ApiFailureRenderer::render($e)`.** The exception has already passed through `prepareException`, so `ModelNotFound` arrives as a 404 and `AuthorizationException` as a 403.

| Exception | Code | Headers kept |
|---|---|---|
| `ValidationException` | `kernel.validation_failed`, one error item per field. `field` is the dot-notation key and `detail` is that field's first message (AC-7, **-> ba**: "one entry per failing rule-set per field" is read as one per field) | none |
| `AuthenticationException` | `kernel.unauthenticated` | none |
| `HttpExceptionInterface` 401 / 403 / 404 / 405 / 413 / 422 / 429 / 503 | `unauthenticated` / `forbidden` / `not_found` / `method_not_allowed` / `payload_too_large` / `validation_failed` / `rate_limited` / `service_unavailable` | `$e->getHeaders()`, which includes `Allow` and `Retry-After` |
| any other 4xx `HttpException` | `kernel.bad_request`. The status becomes 400, so that it equals the catalogue status (AC-5) | the exception's headers |
| any other exception, or a 5xx other than 503 | `kernel.server_error` with status 500. `detail` is the fixed catalogue sentence and is never the exception's message (AC-8) | none |

**The renderer never includes debug detail, even with `APP_DEBUG=true`.** The log has it.

**AC-8 logging.** Laravel already reports non-HTTP exceptions. `bootstrap/app.php` adds `$exceptions->context(fn () => array_filter(['request_id' => app(RequestId::class)->current()]))`, so every reported exception carries the id. No second log line is written.

### Ruling 5: rate-limiter placement (AC-27 to AC-33b) — resolves ba's risk 1

**The problem.** Identity exists only after the token middleware runs, and those middleware are route-level, not in a group.

**The resolution: a group middleware ordered after the token middleware by Laravel's middleware priority list.**

1. `App\Http\Middleware\ThrottleApi` is appended to the `api` group: `$middleware->api(append: [ThrottleApi::class])`.
   - Every `api/v1/*` route therefore gets it, including the existing routes and the demo route.
   - `/health/*` and `/up` are in no group, so they never touch it (AC-33a).
2. In `bootstrap/app.php`, by `appendToPriorityList`:
   - `RequirePlatformToken`, `RequireTerminalToken` and `RequireTenantToken`, each after `SubstituteBindings`;
   - then `ThrottleApi` after the array of those three;
   - then `RequireIdempotencyKey` after `ThrottleApi`.
3. Laravel's `SortedMiddleware` (measured in vendor 12.69.2) then moves a route's token middleware ahead of `ThrottleApi`.
   - The access routes resolve to `[SubstituteBindings, RequireTenantToken, ThrottleApi, RequireTenantPermission:x, ...]`.
   - `SubstituteBindings` still runs first, so the existing order is unchanged.
   - `RequireTenantPermission` is not in the map and keeps its relative place.
4. **`gatherMiddleware()` is untouched.** Group names are not expanded there. So these tests are unaffected:
   - `HealthEndpointTest`, which expects `[]`;
   - the access, organization and terminal surface sweeps, which read the declared middleware.

**The buckets, read by `App\Http\Requests\ApiActor::from($request)` from the attributes the token middleware set:**

| Token | Buckets | Limit key |
|---|---|---|
| tenant (`RequireTenantToken::TENANT_ID`, `USER_ID`) | `t:{tenant}` and `u:{tenant}:{sub}` | `tenant` and `user` |
| terminal (`RequireTerminalToken::TENANT_ID`, `TERMINAL_ID`) | `t:{tenant}` and `d:{tenant}:{terminal}` | `tenant` and `terminal`. A terminal token has no user, so it gets no user bucket. **-> ba informational** |
| platform (`RequirePlatformToken::USER_ID`, with no tenant attribute) | `u:platform:{sub}` | `user` |
| none | `ip:{$request->ip()}` | `unauthenticated` |

An authenticated request never touches the IP bucket (AC-32).

**The algorithm** uses `Illuminate\Cache\RateLimiter`, resolved from the container, with a fixed 60-second window:
1. For each bucket, `tooManyAttempts(key, limit)`.
2. If any bucket is exhausted, answer 429 through `ApiResponse::failure('kernel.rate_limited')`:
   - `Retry-After` is `max(1, availableIn)`, the largest over the exhausted buckets;
   - `X-RateLimit-Limit` is that bucket's limit, and `X-RateLimit-Remaining` is 0;
   - the handler does not run, and the refused request is not counted (Laravel's own semantics).
3. Otherwise `hit()` every bucket and call `$next`. Then set `X-RateLimit-Limit` and `X-RateLimit-Remaining` from the bucket with the smallest remaining count (AC-30).
   - A thrown handler is converted into a response by the routing pipeline before it returns here, so 4xx and 5xx responses get the headers too.

**Fail open (AC-33b, D-10).**
- Every limiter call sits inside one `try`/`catch (Throwable)`.
- On failure: one `warning` line, `Rate limiter unavailable; request allowed.`, with the request id and the exception class. The handler then runs, without the headers.

**Limits (AC-33).**
- `App\Support\Api\ApiLimits::fromConfig(array)` accepts an `int` of at least 1, or a digit string with no leading zero. Anything else throws `InvalidApiConfiguration`, naming the key and the value.
- `AppServiceProvider::boot()` builds it as a singleton, so a bad value fails at boot.
- The defaults are class constants holding D-9's numbers: tenant 1200, user 120, terminal 300, unauthenticated 30. `config/api.php` reads `env(..., ApiLimits::DEFAULT_*)`.

**Stated limitation (risk 4).** A request refused by the token middleware itself, with a bad or expired token, is never counted, because the limiter runs after it. The credential endpoints keep their IP bucket, and a refused token never reaches tenant data.

### Ruling 6: idempotency (AC-16 to AC-27) — the in-flight claim is `response_code IS NULL`

**The table is exactly `docs/database.md` §6.21:**
- `key` VARCHAR(80) primary key;
- `request_hash` CHAR(64);
- `response_code` SMALLINT UNSIGNED **NULL**;
- `response_body` JSON NULL;
- `created_at` DATETIME(3) plus an index on `created_at`.

The document does not state nullability. Nullable `response_code` is how "claimed, handler still running" is represented. No column is added. **-> ba informational.**

**The middleware, `App\Http\Middleware\RequireIdempotencyKey`.** Opt-in per route, written `->middleware(RequireIdempotencyKey::class)`. No alias is added, because the other middleware are named by class.

1. If `Tenancy::current()->hasTenant()` is false: log `error`, then answer 500 `kernel.server_error` (AC-24).
2. If the `Idempotency-Key` header is absent: 400 `kernel.idempotency_key_required`. If it is present but does not match `^[A-Za-z0-9_\-:.]{1,80}$` (empty counts): 400 `kernel.idempotency_key_invalid` (AC-16, AC-17).
3. Compute the hash with `IdempotencyFingerprint::of(method, path, actor, rawBody)`.
   - It is the sha256 hex of a JSON array, in this order: the upper-cased method, `$request->path()`, the actor (`user:{sub}` or `terminal:{id}`, from `ApiActor`) and `$request->getContent()`.
   - **The query string is excluded,** because AC-20 lists only method, path, body and actor. **-> ba informational.**
4. Call `IdempotencyStore::claim($key, $hash)`. It inserts `{key, hash, null, null, now}`. If the insert raises `UniqueConstraintViolationException`, it reads the row:
   - if `created_at` is older than the retention (24 h): delete that row, matching on key and the old `created_at`, and claim again once, so the key is treated as new (AC-23);
   - else if the hash differs: **reused**, whatever the row's state (AC-20), and the stored body is never read into the response;
   - else if `response_code` is null and the claim is younger than `api.idempotency.claim_timeout_seconds` (120): **in progress**, answered 409 with `Retry-After: api.idempotency.in_progress_retry_after` (1) (AC-22);
   - else if `response_code` is null and the claim is stale: the process holding it died. Take the claim over and run the handler;
   - else: **replay**.
5. **On replay:**
   - answer with the stored status and the decoded stored body;
   - if the body has a `meta` array, set `meta.request_id` to the current request id (AC-19);
   - add the header `Idempotent-Replayed: true`;
   - the handler does not run.
6. **On a new claim,** call `$next`:
   - a 2xx whose body decodes as JSON: `complete($key, $hash, status, body)`, which updates the row where the key, the hash and a null `response_code` all match (AC-18);
   - any other outcome: `release($key, $hash)` deletes the in-flight row (AC-21, D-6). That covers a non-2xx, a non-JSON 2xx (also logged at `warning`) and a throw (released in `catch`, then rethrown).
7. **Each refusal goes through `ApiResponse::failure`,** so it is logged with the request id.

**Clock.** `created_at` comes from `RunnerClock`, in `RunnerClock::PRECISION`, so AC-23 can move time in a test.

**Atomicity is stated, not hidden (risk 3).** The claim commits on its own, outside the handler's transaction, because a concurrent request must be able to see it. If the process dies after the handler commits and before `complete()`, the stale-claim takeover can run the handler a second time. That is why `docs/database.md`'s financial unique keys remain the second line of defence.

**Ordering.** The middleware is in the priority list after `ThrottleApi`, so a replay is counted (AC-27). It runs inside the token middleware, so the tenant connection is entered.

**Configuration.**
- `api.idempotency.retention_hours` (24);
- `claim_timeout_seconds` (120);
- `in_progress_retry_after` (1).

**Pruning (AC-23).** Slice `Flexipos\Kernel\Api\Features\PruneIdempotencyKeys`, command `idempotency:prune {--tenant=*}`.
- It has the same sweep shape as `audit:archive`: the fleet or the named tenants, through `Tenancy::run()`. One tenant's failure is logged and the loop continues; the exit code is 1 if any tenant failed.
- It deletes rows with `created_at` older than the retention, and prints `{tenant}  {n} key(s) deleted`.
- It is scheduled `hourly()->withoutOverlapping()` in `ApiServiceProvider::boot()` inside `$app->booted()`, exactly as `AccessServiceProvider` does. It inherits FP-319.

### Ruling 7: health (AC-41 to AC-47) — resolves the readiness-body risk

**Liveness is unchanged.**

**Readiness** answers `{"status":"ok"|"unavailable","modules":[...],"checks":{"master_database":"ok"|"failed","redis":"ok"|"failed"|"skipped"}}`. The status is 200 or 503, and the keys appear in that order.

**`App\Support\Health\ReadinessProbe`** runs two checks:

**`MasterDatabaseCheck`:**
- It builds a probe connection, `database.connections.health_probe_master`, cloned from `database.connections.master`.
- For `mysql` or `mariadb` it adds `PDO::ATTR_TIMEOUT => api.health.timeout_seconds` (2), which bounds the connect (AC-43).
- It runs `select 1` and returns `failed` on any `Throwable`. It logs a `warning` with the exception class only, then calls `DB::purge()` on the probe connection in a `finally`.
- **It probes the connection named `master`,** never `database.default`.

**`RedisCheck`:**
- It reads the redis connection names actually in use:
  - `cache.stores.{cache.default}` when its driver is `redis`;
  - `queue.connections.{queue.default}` when its driver is `redis`;
  - the event bus's redis connection when `eventbus.driver` is `redis`.
- With none of them in use, it returns `skipped` (D-13).
- Otherwise it pings each one through a cloned connection with `timeout` and `read_timeout` set to the configured value, and returns `failed` if any ping throws.

Both checks are bound by interface (`ReadinessCheck`), so tests swap in a throwing fake. A real Redis outage is **[REDIS]**.

**AC-45.** The body holds only the fixed words and the module names, and the controller builds it from enums.

**AC-46.** No tenant is consulted. A test binds a `TenantFleet` and a `Tenancy` that throw if touched.

**AC-47 cannot hold literally, -> ba CONFLICT 1.** Nine `assertExactJson(['status' => 'ok', 'modules' => [...]])` assertions in six files pin the readiness body without `checks`. AC-42 requires `checks`, so those assertions gain `'checks' => ['master_database' => 'ok', 'redis' => 'skipped']`. No assertion is weakened. Section 9 lists them.

**Why those six files still pass.** `QaHealthProbeResilienceTest` breaks `database.default`, not `master`, so its readiness answer stays 200 with `master_database: ok`. Its title stays true.

### Ruling 8: OpenAPI (AC-34 to AC-40)

**The generator is `App\Support\OpenApi\OpenApiDocument::build(): array`.** It is pure, reading `Router::getRoutes()` and `ErrorCatalogue::all()`.

**The rules:**
- **Domain:** every route whose URI starts with `api/v1/`. Each non-`HEAD` method is one operation.
- **The path** is `'/'.uri`, with `{x?}` written as `{x}`. A path parameter is declared `in: path`, `required: true`, `type: string`.
- **`operationId`** is the lower-case method, then `_`, then the URI with every run of non-alphanumerics turned into `_`. For example, `post_api_v1_auth_login`. It is unique by construction, because (method, URI) is unique.
- **The tag** is the first segment after `api/v1/`. A URI with only one segment after `api/v1/`, which today is the docs route, is tagged `kernel`. **-> ba informational:** the terminal routes are tagged `terminal`, although the auth package declares them.
- **Security:** `[{bearerAuth: []}]` when `gatherMiddleware()` contains any of the three token classes, otherwise `[]`.
- **Responses:**
  - `200` is a reference to `SuccessEnvelope` (AC-40);
  - `401` and `403` only when the route is authenticated;
  - `422` for `POST`, `PUT` and `PATCH`, or when the path has a parameter;
  - `429` always;
  - for an idempotent route, `400`, `409` and `422`, plus the required header parameter `Idempotency-Key` (AC-36);
  - every error response references `ErrorEnvelope`.
- **Legacy bodies, -> ba CONFLICT 5.** An operation whose tag is in `OpenApiDocument::LEGACY_BODY_TAGS = ['access', 'auth', 'organization', 'terminal']` carries `x-flexipos-legacy-body: true`. The description says the body predates the envelope (FP-369). The document is therefore honest that D-1 left those bodies alone.
- **Top level:**
  - `openapi: "3.1.0"`;
  - `info: {title: "FlexiPOS API", version: "v1"}`;
  - `servers: [{url: "/"}]`, which is relative, so nothing depends on `APP_URL`;
  - `components.schemas`: `SuccessEnvelope`, `ErrorEnvelope` and `ErrorItem`. `ErrorItem.code` has an `enum` of every catalogue code;
  - `components.securitySchemes.bearerAuth`: `http`, `bearer`, `JWT`;
  - `x-error-codes`: a sorted list of `{code, module, status, message}` (AC-14 and AC-34).
- **Stability (AC-38):**
  - paths are sorted with `ksort`, and methods follow the fixed order `get`, `post`, `put`, `patch`, `delete`;
  - the tags list is sorted;
  - encoding uses `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`, with a trailing newline;
  - there is no timestamp;
  - an empty map is encoded as `(object) []`.

**The command `api:openapi {--output=}`** is `App\Console\Commands\OpenApiCommand`. It writes the file, or prints to standard output when no path is given.

**The route `GET api/v1/openapi.json`, named `api.openapi`,** lives in a new `modules/host/routes/api.php`.
- The file is `require`d from the `then:` callback beside `health.php`.
- It uses `Route::middleware('api')->prefix('api/v1')`, the package convention.
- Its controller is the invokable `App\Http\Controllers\OpenApiController`.
- **When `api.docs_enabled` is false,** the controller throws `NotFoundHttpException`, which renders as the 404 envelope (AC-39, D-11).
- **When it is true,** the controller returns `response()->json($doc)` without the envelope.
- The route is always registered, so route caching and the toggle stay independent.

**AC-37: a committed copy is the drift gate.**
- `docs/api/openapi.v1.json` is generated with modules `demo` booted, and committed.
- `OpenApiCompletenessTest` boots the host with `demo`, generates the document and compares it with the committed file, normalising CRLF to LF.
  - **On failure** it lists every `METHOD /path` that is missing from either side, then says how to regenerate.
  - **Control:** a scratch `api/v1/qa-drift` route registered by the test is reported as missing.
- Without a committed copy, comparing the generator with the routing table would be tautological.

### Ruling 9: the demo ping (AC-50) — resolves ba's risk 2

`PingController::index()` returns `ApiResponse::success(['module' => 'demo', 'status' => 'ok'])`.

**`ModuleMakeDemoRegenerationTest` requires `modules/demo` to equal what `module:make` generates,** so both stubs change in step with the demo files:
- `stubs/module/PingController.php.stub`;
- `stubs/module/PingRouteTest.php.stub`.

**Four more tests pin the ping body at the top level.** Each is edited from `assertJson(['module' => ..., 'status' => 'ok'])` to `assertJson(['success' => true, 'data' => ['module' => ..., 'status' => 'ok']])`:
- `ModuleMakeGeneratedModuleReachableTest:61`;
- `ModuleRegistryManifestTrustTest:335`;
- `QaAtomicGenerationProofTest:125`;
- `QaManifestTrustAttackTest:494`.

**-> ba CONFLICT 2:** AC-50 names only "its existing test".

## 1. Approach summary

- **The machinery** is built once:
  - the kernel holds the catalogue, the envelope and the idempotency store;
  - the host holds one generic `api/*` failure renderer, registered last so every existing body stays byte-identical;
  - one group-level limiter, ordered after the token middleware by Laravel's priority list;
  - one opt-in idempotency middleware.
- **One expand-only tenant migration** creates the documented `idempotency_keys` table. The in-flight claim is a null `response_code`.
- **Readiness** gains `checks` for the master database and Redis, bounded by a timeout. Liveness is untouched.
- **OpenAPI** is generated from the routing table, with a committed copy compared in the gate.
- **The demo ping becomes the in-repo example of the envelope.**
- **No business endpoint and no existing refusal body changes.**
- Existing tests that pinned framework or legacy shapes are edited only where a criterion requires it, and each edit is listed in section 9.

## 2. Alternatives rejected

- **A new `packages/api` package.** It would mean a fifth set of `composer`, deptrac and PHPStan registrations for code that the kernel's own manifest already claims.
- **The limiter inside each token middleware.** That puts throttling into authentication classes, and the routes without a token would need a second mechanism anyway.
- **The limiter declared on each route.** That edits every existing route file, and a forgotten route would go unthrottled.
- **Laravel's `ThrottleRequests` with a named multi-limit.** Its headers reflect the last limit, not the most constrained one (AC-30). It cannot fail open (AC-33b) and does not validate limits (AC-33).
- **An OpenAPI validator dependency** (`cebe/php-openapi` or similar). That is a new dependency for one structural check. Instead, `OpenApiDocumentTest` asserts the 3.1 required fields, unique `operationId`s and that every `$ref` resolves.
- **A `status` column for in-flight keys.** It changes the documented table, and a nullable `response_code` carries the same state.
- **Checking `database.default` for readiness.** Production's master connection is named `master`, and checking the default would also flip the semantics of `QaHealthProbeResilienceTest`.

## 3. Files to change / create

### `packages/kernel` (new files)
- `src/Api/ErrorCode.php`: a readonly value with `code`, `status` and `message`.
- `src/Api/DeclaresErrorCodes.php`: the interface from Ruling 2.
- `src/Api/ErrorCatalogue.php`: lazy, memoised, validating. Methods `resolve()`, `has()`, `all()` (sorted by code) and `moduleOf()`.
- `src/Api/ErrorCatalogueRefused.php` and `src/Api/UnknownErrorCode.php`: `LogicException` subclasses.
- `src/Api/KernelErrorCodes.php`: 14 constants and `declarations(): list<ErrorCode>`.
- `src/Api/ApiError.php`: a value with `code`, `field` and `detail`.
- `src/Api/ApiResponse.php`: Ruling 3.
- `src/Api/ApiServiceProvider.php`:
  - implements `DeclaresErrorCodes`;
  - binds the `ErrorCatalogue` singleton;
  - registers the two commands;
  - schedules the prune.
- `src/Api/Idempotency/IdempotencyKey.php`: the format rule (AC-17).
- `src/Api/Idempotency/IdempotencyFingerprint.php`: the hash.
- `src/Api/Idempotency/IdempotencyStore.php`: `claim()`, `complete()`, `release()`, on `config('tenancy.connection')`.
- `src/Api/Idempotency/IdempotencyClaim.php`: an outcome enum (`Claimed`, `Replay`, `InProgress`, `Reused`) plus the replay payload.
- `src/Api/Features/PruneIdempotencyKeys/`:
  - `PruneIdempotencyKeys.php`;
  - `PruneIdempotencyKeysHandler.php`;
  - `PruneIdempotencyKeysResult.php`;
  - `PruneIdempotencyKeysCommand.php`.
- `src/Api/Features/ListErrorCodes/`:
  - `ListErrorCodes.php`;
  - `ListErrorCodesHandler.php`, which returns sorted rows;
  - `ListErrorCodesCommand.php` (`api:error-codes`).

`VerticalSliceLayoutTest` requires a mirrored test folder for each of the two slices.

### `modules/host` (new files)
- `app/Http/Responses/ApiFailureRenderer.php`: Ruling 4.
- `app/Http/Requests/ApiActor.php`: Ruling 5's table. Used twice, by the limiter and by idempotency.
- `app/Http/Middleware/ThrottleApi.php` and `app/Http/Middleware/RequireIdempotencyKey.php`.
- `app/Support/Api/ApiLimits.php` and `app/Support/Api/InvalidApiConfiguration.php`.
- `app/Support/Health/ReadinessCheck.php` (interface), `CheckStatus.php` (enum: `ok`, `failed`, `skipped`), `MasterDatabaseCheck.php`, `RedisCheck.php` and `ReadinessProbe.php`.
- `app/Support/OpenApi/OpenApiDocument.php`.
- `app/Console/Commands/OpenApiCommand.php`.
- `app/Http/Controllers/OpenApiController.php`.
- `routes/api.php`.
- `config/api.php`, with these keys:
  - `limits.{tenant,user,terminal,unauthenticated}`;
  - `idempotency.{retention_hours,claim_timeout_seconds,in_progress_retry_after}`;
  - `docs_enabled`;
  - `health.timeout_seconds`.
- `database/migrations-tenant/2026_10_30_001001_create_idempotency_keys_table.php`.

### `docs` (new file)
- `docs/api/openapi.v1.json`: generated, not hand-written.

### Edited
Section 9 lists every edited file with its cause.

## 4. Data / API changes

**Migration (tenant tree, `@phase expand`).**
- It creates `idempotency_keys`:
  - `$table->string('key', 80)->primary()`;
  - `$table->char('request_hash', 64)`;
  - `$table->unsignedSmallInteger('response_code')->nullable()`;
  - `$table->json('response_body')->nullable()`;
  - `$table->dateTime('created_at', 3)`;
  - `$table->index('created_at')`;
  - then `JsonValidity::enforce('idempotency_keys', 'response_body')`.
- There is no `down()` and no foreign key. `key` is not a ULID, so `IdentifierColumn` does not apply.
- **The tree grows from 30 files to 31,** and from 28 tables to 29.
- **`APP_REQUIRED_SCHEMA_VERSION` goes from 30 to 31** in `.env.example`.
- **Republish the catalogue:** `php artisan schema:publish --write-fixture`. `tenant:schema-status` then counts version 31 (AC-26).
- **Rollout:** the change is expand-only. Old code never reads the table, and new code reads it only on opted-in routes, of which there are none in production (spec, Assumptions).

**HTTP.**
- **New route:** `GET /api/v1/openapi.json`, returning 404 unless `API_DOCS_ENABLED=true`.
- **New response headers:**
  - `X-RateLimit-Limit` and `X-RateLimit-Remaining` on every `api/*` response;
  - `Retry-After` on 429 and 409;
  - `Idempotent-Replayed: true` on a replay.
- **New request header:** `Idempotency-Key`, on opted-in routes only.
- **Readiness body:** gains `checks`, and answers 503 with `status: "unavailable"` when a dependency fails.
- **Bodies:** `api/*` failures that previously used the framework's shape now use the envelope. The demo ping body is now the envelope.

**Commands:** `api:error-codes`, `api:openapi {--output=}` and `idempotency:prune {--tenant=*}`.

**Configuration (`.env.example` gains these, commented, with the defaults):**
- `API_DOCS_ENABLED=false`;
- `API_RATE_LIMIT_TENANT`, `API_RATE_LIMIT_USER`, `API_RATE_LIMIT_TERMINAL`, `API_RATE_LIMIT_UNAUTHENTICATED`;
- `API_IDEMPOTENCY_RETENTION_HOURS`;
- `API_IDEMPOTENCY_CLAIM_TIMEOUT`;
- `HEALTH_CHECK_TIMEOUT`.

**`phpunit.xml` gains four `<env>` lines,** setting the `API_RATE_LIMIT_*` values to `100000`.
- **Why:** many existing tests make dozens of calls from one address or token inside a single test. A test that is about something else must not meet a 429.
- **The production defaults are still asserted** on the `ApiLimits` constants.
- **The limiter tests set their own small limits** through `config()->set()`.

## 5. Implementation steps

Run every command from `modules/host`. Section 7 has the full commands. **Red-list rule:** after each step, run the step's own tests plus every test file section 9 names for that step. Stop on any red file that section 9 does not predict.

- [ ] **Step 1: workspace and baseline.**
  - **`vendor/` is absent in this worktree** (measured). Run `composer install`, then `php artisan config:clear`.
  - Record a baseline. Run these files alone, because they are in FP-316's rotating cluster, so a red one in the full run is not automatically this task's:
    - `QaHealthProbeResilienceTest`;
    - `ModulesEnvParsingTest`;
    - `QaModuleRegistryContractTest`;
    - `QaModuleNameValidationTest`.
  - Note the T-020 round-1 fix commits on the parent branch. Do not merge them; that is for the lead.

- [ ] **Step 2: the catalogue and the envelope.** Covers AC-1 to AC-5, AC-9 to AC-13 and AC-15.
  - Create every `src/Api/*.php` file in section 3 except the `Idempotency/` and `Features/` ones.
  - Register `Flexipos\Kernel\Api\ApiServiceProvider::class` in `bootstrap/providers.php`, after `SchemaServiceProvider`.
  - **Test `packages/kernel/tests/Api/ErrorCatalogueTest.php`** (with `uses(TestCase::class)`). It covers:
    - AC-9: each of the 12 codes with its status;
    - AC-10: a malformed code is refused, with one case per rule (two dots, upper case, a hyphen, no dot, a wrong prefix), and the message names the code;
    - AC-11: a duplicate is refused, within one module and across two;
    - AC-12: an unknown code throws in testing, and in production degrades to `kernel.server_error` and logs;
    - AC-13: an anonymous provider is absent before `app()->register()` and present after;
    - `all()` is sorted.
  - **Test `packages/kernel/tests/Api/ApiResponseTest.php`.** It covers:
    - AC-1: exactly five keys, in order;
    - AC-2: `data` null with `message` null;
    - AC-3: the error item has exactly `code`, `field`, `detail`, and `field` is null when absent;
    - AC-4: `meta.request_id` equals `RequestId::current()`, using a fixed `RequestId`;
    - AC-5: a success with status 400 or 500 throws, `created()` answers 201, and `failure()` takes the catalogue's status;
    - one `info` log line on failure, with no detail in it.
  - **Gate slice:** `composer stan` and `composer deptrac`.

- [ ] **Step 3: configuration and limits.** Covers AC-33.
  - Create `config/api.php`, `ApiLimits` and `InvalidApiConfiguration`. Wire `AppServiceProvider::boot()`.
  - Edit `.env.example` and `phpunit.xml` as in section 4.
  - **Test `tests/Feature/ApiLimitsConfigurationTest.php`:**
    - the constants equal D-9's numbers;
    - `config/api.php` reads them;
    - `0`, `-1`, `1.5`, `"abc"`, `""` and `"07"` are each refused with the key named;
    - re-running `AppServiceProvider::boot()` with a bad value throws.

- [ ] **Step 4: the generic renderer.** Covers AC-4, AC-6, AC-7, AC-8, AC-48 and AC-49.
  - Create `ApiFailureRenderer`.
  - In `bootstrap/app.php`, register the callback last and add `$exceptions->context(...)`.
  - **Test `tests/Feature/ApiFailureRenderingTest.php`.** It uses test-only routes under `api/v1/qa-render/*`, registered in the test.
    - AC-6: each of 404, 405, 422, 401, 403, 429 and 500 gives the envelope, the catalogue code and the status. The 405 keeps `Allow` and the 429 keeps `Retry-After`.
    - AC-7: two invalid fields, one of them nested `a.b`, give two items with dot notation.
    - AC-8: with `app.debug` false and also true, a 500 from `throw new RuntimeException('SELECT secret FROM x at C:\path')` contains none of: the message, `RuntimeException`, `C:\`, `SELECT`, `trace`. The log captures the exception with `request_id`.
    - AC-4: the body's request id equals the `X-Request-Id` header.
    - AC-49: a failing `web` route and an unknown non-API path render as before, not as the envelope.
    - AC-48 sentinel: with a registered route that throws `TenantSchemaBehind`, the body is still `{"message": ...}`.
  - **Edit `QaActivationCodeSecrecyTest` lines 101 to 113** (section 9).
  - **Run the AC-48 regression set unchanged:**
    - `HttpAuthEndpointsTest`, `AuthCredentialsAreNeverReadFromTheUrlTest`, `TenantTokenMiddlewareTest`, `TenantSchemaBehindRenderingTest`, `QaTenantSelectionClaimsTest`;
    - `PlanLimitHttpTest`, `OrganizationBranchHttpTest`, `OrganizationTerminalHttpTest`, `TerminalRevocationHttpTest`, `TerminalActivationHttpTest`;
    - `QaSuspendedMappingGateTest`, `QaAuthHttpSurfaceTest`, `UnlistedModuleReturns404Test`, `QaAccessHttpSurfaceTest`.

- [ ] **Step 5: the demo ping.** Covers AC-50 and Ruling 9.
  - Edit `PingController`, `PingRouteTest` and both stubs, character-identical apart from the placeholders.
  - Edit the four tests in Ruling 9.
  - **Run:**
    - `ModuleMakeDemoRegenerationTest`, `ModuleMakeGeneratedModuleReachableTest`, `QaAtomicGenerationProofTest`;
    - `ModuleRegistryManifestTrustTest`, `QaManifestTrustAttackTest`;
    - `modules/demo/tests`.
  - `PingRouteTest` asserts:
    - the five keys;
    - `data` equals `{module: demo, status: ok}`;
    - `meta.request_id` equals the header.

- [ ] **Step 6: `api:error-codes`.** Covers AC-14.
  - Create the `ListErrorCodes` slice.
  - **Test `packages/kernel/tests/Api/Features/ListErrorCodes/ListErrorCodesTest.php`:**
    - the command output lists every code with its module, status and message, sorted;
    - a declared fixture module's code appears;
    - the handler's rows equal `ErrorCatalogue::all()`.

- [ ] **Step 7: the rate limiter.** Covers AC-28 to AC-33b and Ruling 5.
  - Create `ApiActor` and `ThrottleApi`.
  - In `bootstrap/app.php`: the `api(append: ...)` call and the priority appends. Add `RequireIdempotencyKey` to the priority list in step 10, when its class exists.
  - **Edit `TerminalActivationHttpTest::activationWire()`** (section 9).
  - **Test `tests/Feature/ApiRateLimitTest.php`.** Small limits set by config, the organization HTTP fixture (`orgHttpBoot`, `orgHttpWorld`, `orgHttpToken`, `orgHttpActivated`), and test routes behind `RequireTenantToken` and `RequireTerminalToken`.
    - **AC-28:** exhausting each of the tenant, user and terminal buckets alone gives 429. The handler spy count does not rise.
    - **AC-29:** the envelope `kernel.rate_limited`, and `Retry-After` is an integer of at least 1.
    - **AC-30:** both headers on a 200 and on a handler-thrown 500, and they report the tightest bucket. Set user to 2 and tenant to 100: `Limit` is 2 and `Remaining` is 1 after the first call.
    - **AC-31:**
      - tenant A exhausted, tenant B still 200;
      - two users of one tenant;
      - two terminals of one tenant.
    - **AC-32:** the unauthenticated demo ping is limited per IP. Two IPs are independent, set through `withServerVariables(['REMOTE_ADDR' => ...])`. An authenticated request with the IP bucket at 0 remaining still passes.
    - **AC-33a:** a `RateLimiter` over a throwing cache store, with a call counter. `/health/live`, `/health/ready` and `/up` give 200, and the counter stays at 0.
    - **AC-33b:** the same throwing limiter on the demo ping gives 200, exactly one `warning` line, and no rate headers.
    - **Ordering:** `Router::gatherRouteMiddleware()` on a real access route and on a test route places `RequireTenantToken` before `ThrottleApi`, and `SubstituteBindings` first.
  - **Run:** `TerminalActivationHttpTest`, `QaActivationCodeSecrecyTest`, `HttpAuthEndpointsTest`, `QaAccessGuardIsolationHttpTest`, `OrganizationSettingsHttpTest`, `QaPinSinkCaptureTest`.

- [ ] **Step 8: the migration.** Covers AC-26.
  - Create the migration and republish the catalogue.
  - Change `.env.example` from 30 to 31.
  - Make the test edits in section 9: `Helpers.php`, `MigrationTreeDisciplineTest`, `QaAccessCloseOutSweepsTest`, `QaAccessSchemaShapeTest`.
  - **Test `packages/kernel/tests/Api/IdempotencyKeysSchemaTest.php`** on a SQLite stand-in, the T-020 `WorkflowTenantSchemaTest` shape:
    - the sorted column list exactly, and the nullability of `response_code` and `response_body`;
    - the primary key on `key`, and the `created_at` index;
    - a second `migrate` runs nothing;
    - `SourceRules::inspect()` reports nothing;
    - there is no `down`;
    - the catalogue's top entry is this migration, with phase `expand` and version 31.
  - **Run:**
    - `SchemaCataloguePublishTest`, `FixtureMigrationSetsTest`, `TenantBaselineBuildsTest`;
    - `QaDatetimePrecisionSourceTest`, `MigrationSourceRulesTest`, `QaSchemaSignatureInstrumentTest`;
    - the kernel `PlanMigrationRun` tests.

- [ ] **Step 9: the store.** Groundwork for AC-18 to AC-23 and AC-25.
  - Create `IdempotencyKey`, `IdempotencyFingerprint`, `IdempotencyStore` and `IdempotencyClaim`.
  - **Test `packages/kernel/tests/Api/Idempotency/IdempotencyStoreTest.php`.** It runs in a fixture tenant entered through `tenancy-fixture.php` / `AccessTenantFixture`, with the clock set by `RunnerClock`. It covers:
    - claim, complete, then claim again gives a replay;
    - a different hash gives `Reused`, even while the row is in flight;
    - an in-flight row gives `InProgress`;
    - a stale in-flight row is taken over;
    - an expired completed row is re-claimed;
    - `release` deletes only a null row;
    - `complete` with the wrong hash updates nothing;
    - the key format: 80 characters pass and 81 fail, as do the empty string, a space and `é`.

- [ ] **Step 10: the middleware.** Covers AC-16 to AC-22, AC-24, AC-25 and AC-27.
  - Create `RequireIdempotencyKey` and add it to the priority list.
  - **Test `tests/Feature/IdempotencyHttpTest.php`.** It registers test-only routes `POST api/v1/qa-idem/*` behind `RequireTenantToken` and `RequireIdempotencyKey`. The handler spy counts calls and returns `ApiResponse::created([...])`, or a 422, or throws, according to a header.
    - AC-16: no key gives 400 and the spy at 0.
    - AC-17: an empty key, an 81-character key and a `key with space` each give 400 `invalid` and the spy at 0.
    - AC-18: one row holds the hash, 201 and the body, and the response is unchanged.
    - AC-19: a replay gives the same status and the same `data`, `Idempotent-Replayed: true`, the spy at 1, and a new `meta.request_id` equal to the replay's `X-Request-Id`.
    - AC-20: a different body, a different path (a second route), a different actor (a second employee's token) and a different method each give 422 `reused`. None of those response bodies contains the first body's marker.
    - AC-21: a 422 from the handler, and then a throw, each store nothing. A retry runs the handler again.
    - AC-22: a row pre-seeded in flight with the matching hash (`IdempotencyFingerprint::of`) gives 409 with `Retry-After` and the spy at 0. The real concurrent proof is **[ENGINE]**, NOT VERIFIED under the engine hold.
    - AC-24: a test route with `RequireIdempotencyKey` and no tenant middleware gives 500 `kernel.server_error`, an `error` log line, and no row anywhere.
    - AC-25: tenant A's database and tenant B's database run once each with the same key, and each table holds one row. The two databases must be really separate: follow `QaOrganizationTenantIsolationTest`'s two-fixture-database setup, because `orgHttpWorld()` maps every shop to `tenant-a`.
    - AC-27: user limit 2, then a request and a replay, then a third request gives 429.

- [ ] **Step 11: `idempotency:prune`.** Covers AC-23.
  - Create the slice, register the command and the schedule.
  - **Test `packages/kernel/tests/Api/Features/PruneIdempotencyKeys/PruneIdempotencyKeysTest.php`:**
    - rows aged 25 h and 23 h, deleted and kept;
    - the report count;
    - `--tenant` narrows the run;
    - a failing tenant logs, the loop continues, and the command exits 1;
    - the schedule entry exists, the `AccessServiceProvider` precedent;
    - an over-age key on HTTP is treated as new: the spy rises to 2. That one belongs in `IdempotencyHttpTest`.

- [ ] **Step 12: readiness.** Covers AC-41 to AC-47.
  - Create the health support classes, and edit `HealthController::ready()`. `live()` is unchanged.
  - Make the nine `assertExactJson` edits (section 9).
  - **Test `tests/Feature/HealthReadinessTest.php`:**
    - AC-42: 200, `status: ok`, the `modules` list, and both checks;
    - AC-43: `database.connections.master` pointed at a dead SQLite path gives 503, `unavailable` and `master_database: failed`, with wall time under `timeout + 1` seconds, and the body contains neither `qa-no-such-directory` nor `SQLSTATE`;
    - AC-44: `cache.default` on a `redis` store with a bound fake `RedisCheck` that throws gives `redis: failed` and 503; the default configuration gives `skipped` and 200;
    - AC-45: every scalar in the body, with master both up and down, is in `{ok, failed, skipped, unavailable}` or the module names;
    - AC-46: a throwing `TenantFleet` and `Tenancy` binding still gives 200;
    - AC-41: `/health/live` with a throwing cache store bound still gives 200 with `{"status":"ok"}`.
  - **Run:**
    - `HealthEndpointTest`, `QaHealthProbeResilienceTest` (AC-47), `UnlistedModuleReturns404Test`;
    - `ModuleMakeGeneratedModuleReachableTest`, `ModuleRegistryManifestTrustTest`, `ModulesEnvParsingTest`;
    - `QaModuleNameValidationTest`, `QaModuleRegistryContractTest`, `QaAtomicGenerationProofTest`;
    - `modules/demo/tests`.

- [ ] **Step 13: OpenAPI.** Covers AC-34 to AC-40.
  - Create `OpenApiDocument`, `OpenApiCommand`, `OpenApiController` and `routes/api.php`, and add the `require` to the `then:` callback.
  - Generate and commit `docs/api/openapi.v1.json`:

    ```
    php artisan api:openapi --output=../../docs/api/openapi.v1.json
    ```

    Run it with modules `demo`, and `API_DOCS_ENABLED` irrelevant.
  - **Test `tests/Feature/OpenApiDocumentTest.php`:**
    - AC-34 and AC-35: the structure (`openapi 3.1.0`, `info.version v1`, `servers`, the three schemas, `bearerAuth`, `x-error-codes` equal to the catalogue);
    - unique `operationId`s;
    - every `$ref` resolves;
    - the tags;
    - `security` empty on `POST /api/v1/auth/login` and bearer on an access route;
    - 401 and 403 only when authenticated, 422 by the rule, 429 everywhere;
    - AC-36: a test idempotent route gains the header parameter and 400, 409 and 422;
    - AC-38: two builds are byte-identical, and so are two command runs;
    - AC-39: disabled gives the 404 envelope; enabled gives raw JSON with `application/json`, `openapi: 3.1.0` and no `success` key;
    - AC-40: a route with no declarations is listed with `SuccessEnvelope`;
    - the legacy flag on the `auth` routes and not on `demo`.
  - **Test `tests/Feature/OpenApiCompletenessTest.php` (AC-37),** with the control from Ruling 8.
  - **Run:** `RouteCachingTest`, `QaAuditNoHttpSurfaceTest`, `QaPluginNoHttpSurfaceTest`, `QaEventbusNoHttpSurfaceTest`, `QaWorkflowNoHttpSurfaceTest`, `HttpAuthEndpointsTest`. The new URI holds none of their words.

- [ ] **Step 14: documents.**
  - `docs/database.md` §6.21 `idempotency_keys`: an "As built (T-021)" note:
    - a null `response_code` means in flight;
    - the index on `created_at`;
    - the hash covers method, path, actor and body.
  - `docs/architecture.md` §8: an "As built (T-021)" paragraph naming:
    - the envelope helper;
    - the catalogue;
    - the opt-in middleware;
    - the limiter's buckets;
    - `api:openapi`;
    - the legacy bodies until FP-369.
  - Describe, never quote, the old module vocabulary (memory: the gate sweeps the working tree).

- [ ] **Step 15: the full gate.**
  - Run `composer check` in `modules/host` and report real numbers.
  - The expected failures are the FP-316 cluster of 12, against the baseline from step 1.
  - Report the gate-step margin: FP-328 measured the ratio at 1.46 before this task, and this task adds roughly 120 tests.

## 6. Test strategy

**Kernel, deterministic:**
- `ErrorCatalogueTest` (AC-9 to AC-13);
- `ApiResponseTest` (AC-1 to AC-5);
- `ListErrorCodesTest` (AC-14);
- `IdempotencyKeysSchemaTest` (AC-26);
- `IdempotencyStoreTest` (the store half of AC-18 to AC-23);
- `PruneIdempotencyKeysTest` (AC-23).

**Host feature, HTTP through the real kernel:**
- `ApiLimitsConfigurationTest` (AC-33);
- `ApiFailureRenderingTest` (AC-4, AC-6 to AC-8, AC-48 sentinel, AC-49);
- `ApiRateLimitTest` (AC-27 to AC-33b);
- `IdempotencyHttpTest` (AC-16 to AC-25, AC-27);
- `HealthReadinessTest` (AC-41 to AC-46);
- `OpenApiDocumentTest` (AC-34 to AC-36, AC-38 to AC-40);
- `OpenApiCompletenessTest` (AC-37).

**Edited existing tests:**
- `PingRouteTest` (AC-50);
- the six readiness-body files (AC-47);
- the AC-48 regression set in step 4, which must pass unedited.

**Mutations `qa` should apply:**
1. Register the generic renderer **first**: the AC-48 sentinel and `HttpAuthEndpointsTest` must go red.
2. Drop the priority appends: the ordering test and AC-28 must go red, because the actor reads empty and everything lands in the IP bucket.
3. Count the IP bucket for authenticated requests: AC-32 goes red.
4. Remove the hash comparison (replay on any match): AC-20 goes red.
5. Store a 4xx: AC-21 goes red.
6. Skip the `meta.request_id` rewrite: AC-19 goes red.
7. Make the limiter fail closed: AC-33b goes red.
8. Check `database.default` instead of `master`: `QaHealthProbeResilienceTest` and `HealthReadinessTest` disagree.
9. Remove `ksort` in OpenAPI: AC-38 goes red.
10. Delete one route from the committed document: AC-37 goes red, naming the route.
11. Let a duplicate code overwrite: AC-11 goes red.
12. Echo the exception message in the 500: AC-8 goes red.

**NOT VERIFIED here:**
- **[ENGINE]:** AC-22's real concurrency.
- **[REDIS]:** the real outage halves of AC-33b and AC-44.
- The timeout bound of AC-43 against a real MySQL host that never answers.

## 7. Verification commands (Git Bash)

```
cd /d/Flexiata/NEW-FLEXIPOS/.claude/worktrees/build-56f213/modules/host
PHP=/e/xampp/php/php.exe; COMPOSER="$PHP /c/ProgramData/ComposerSetup/bin/composer.phar"
$COMPOSER install                                   # step 1 only: vendor/ is absent in this worktree
$PHP artisan config:clear
$PHP vendor/bin/pest tests/Feature/ApiRateLimitTest.php          # one file
$PHP vendor/bin/pest ../../packages/kernel/tests/Api             # a directory
$PHP artisan schema:publish --write-fixture                       # step 8
$PHP artisan api:openapi --output=../../docs/api/openapi.v1.json  # step 13 (with the demo module booted)
$COMPOSER stan ; $COMPOSER deptrac ; $COMPOSER lint
$COMPOSER test:fast                                 # inner loop only, never the gate
$COMPOSER check                                     # the gate (step 15)
```

## 8. Risks & rollback

| # | Risk | Likelihood | Mitigation |
|---|---|---|---|
| 1 | **The middleware priority list is subtle.** A future reorder silently moves the limiter ahead of authentication | medium | The ordering test in step 7. Mutation 2 proves it bites |
| 2 | **Existing tests pin more shapes than section 9 found** | medium | The red-list rule. The greps used are recorded in section 9's note |
| 3 | **Idempotency atomicity.** A crash between the handler's commit and `complete()`, followed by the stale-claim takeover, can re-run a handler | low (no production route opts in yet) | The claim timeout is configurable. The financial unique keys in `docs/database.md` stay the second line. Stated for the first financial endpoint |
| 4 | **Bad-token floods are not counted** (the limiter runs after the token check) | low | It is documented. Proposed follow-up row (section 10) |
| 5 | **Gate time.** FP-328's ratio was 1.46 before this task | medium | Report the margin in step 15. No new test spawns a child process, so none is added to the toolchain group |
| 6 | **The FP-316 cluster includes two files this task edits** | high (known) | Run them alone, against the step-1 baseline |
| 7 | **The parent T-020 is still in its fix round** | medium | No file overlap expected (its R-1 and R-2 changes are in `packages/workflow`). The lead absorbs it before merge |
| 8 | **The vocabulary sweep.** A quoted old-vocabulary word in new code, JSON or documents turns the gate red | low | Section 10 tells `dev` |
| 9 | **The readiness probe clones the master stanza** | low | It uses its own connection name, purged in `finally`. It never writes `connections.tenant`, so the tenancy choke point is untouched |

**Rollback.** Revert the branch. The migration is expand-only and no code outside this task reads the table, so leaving the table in a tenant database is harmless.

## 9. Pre-existing files legitimately edited

Each edit is listed here so that `qa` reports it as intended, not as a regression. **No assertion is weakened**, except where the cell says why it is replaced.

**Production code and configuration:**

| Step | File | Edit and cause |
|---|---|---|
| 2 | `modules/host/bootstrap/providers.php` | adds `ApiServiceProvider` |
| 3 | `modules/host/app/Providers/AppServiceProvider.php` | builds `ApiLimits` at boot |
| 3 | `modules/host/.env.example` | the new commented keys |
| 3 | `modules/host/phpunit.xml` | the four `API_RATE_LIMIT_*` lines (section 4) |
| 4, 7, 10, 13 | `modules/host/bootstrap/app.php` | the renderer (last), `context()`, `api(append:)`, the priority appends, `require routes/api.php` |
| 5 | `modules/demo/src/Http/Controllers/PingController.php`, `modules/host/stubs/module/PingController.php.stub`, `modules/host/stubs/module/PingRouteTest.php.stub` | AC-50 and Ruling 9 |
| 8 | `modules/host/database/catalogue/tenant_schema_versions.json` | republished |
| 8 | `modules/host/.env.example` | `APP_REQUIRED_SCHEMA_VERSION=31` |
| 12 | `modules/host/app/Http/Controllers/HealthController.php` | `ready()` uses `ReadinessProbe`. The docblock's "deferred" note is replaced |
| 14 | `docs/database.md`, `docs/architecture.md` | the "As built" notes |

**Tests:**

| Step | File | Edit and cause |
|---|---|---|
| 4 | `modules/host/tests/Feature/QaActivationCodeSecrecyTest.php` lines 101 to 113 | The framework 404 echoed the path; the envelope does not (AC-6). Replace the `json('message')` pin with two assertions: `errors.0.code === 'kernel.not_found'`, and the body does not contain `$live`. **This is a strengthening:** the measured finding in its comment becomes "no".|
| 5 | `modules/demo/tests/Feature/PingRouteTest.php` | AC-50 |
| 5 | `ModuleMakeGeneratedModuleReachableTest.php:61`, `ModuleRegistryManifestTrustTest.php:335`, `QaAtomicGenerationProofTest.php:125`, `QaManifestTrustAttackTest.php:494` (all under `modules/host/tests/Feature/`) | The ping body moves under `data` (Ruling 9) |
| 7 | `modules/host/tests/Feature/TerminalActivationHttpTest.php` `activationWire()` | Also `unset($headers['x-ratelimit-remaining'])`. **The precedent is the `x-request-id` unset already in that function (T-017).** The remaining count changes per request, not per refusal cause. `x-ratelimit-limit` stays compared |
| 8 | `modules/host/tests/Helpers.php` | `tenantTreeTables()` gains `idempotency_keys` |
| 8 | `modules/host/tests/Feature/MigrationTreeDisciplineTest.php` | `toHaveCount(28)` becomes 29 |
| 8 | `modules/host/tests/Feature/QaAccessCloseOutSweepsTest.php`, `QaAccessSchemaShapeTest.php` | Exclude `$t021 = ['idempotency_keys']`, the T-017, T-019 and T-020 precedent |
| 12 | `HealthEndpointTest.php` lines 23, 34, 73; `QaHealthProbeResilienceTest.php` lines 91, 102; `UnlistedModuleReturns404Test.php:32`; `ModuleMakeGeneratedModuleReachableTest.php:65`; `ModuleRegistryManifestTrustTest.php:337`; `ModulesEnvParsingTest.php:103` | Add `'checks' => ['master_database' => 'ok', 'redis' => 'skipped']` to each `assertExactJson` (AC-42 against AC-47, Ruling 7) |

**Count:** 10 production or configuration files (`.env.example` counted once), 2 documents, and 15 test files (`Helpers.php` included).

**Expected unchanged,** so a red one is investigated, not patched:
- **The AC-48 regression set** (step 4).
- **The surface sweeps:** `QaAccessHttpSurfaceTest`, `QaOrganizationHttpSurfaceTest`, `TerminalSessionHttpTest`, `QaAccessGuardIsolationHttpTest`. `gatherMiddleware()` does not expand groups.
- **`HttpAuthEndpointsTest`'s exact `api/v1/auth` route list.** The new route is not under `auth`.
- **`RouteCachingTest`.** The new route is an invokable controller.
- **The secret and PIN sink tests.** The new headers carry numbers only, and the refusal log line carries no path and no detail.
- **`VerticalSliceLayoutTest`.** The two new slices have their mirrored tests.

**How the lists above were found** (`modules/host/tests`, `modules/demo/tests`, `packages/*/tests`):
- greps for `assertExactJson(['status'`, `assertNotFound` / `toBe(404)` / `toBe(405)` / `toBe(500)` / `json('message')`, `'module' =>` on the ping, `headers->all()`, `getRoutes()`, `gatherMiddleware`;
- greps of `app/` and `packages/*/src` for `validate(` and `ValidationException`. There are none: no existing endpoint uses framework validation, so no existing test pins a framework 422 body.

## 10. Messages

### `architect` → lead (relay to `ba` where marked)

1. **-> ba CONFLICT 1 (AC-47 against AC-42).** Adding `checks` breaks nine `assertExactJson` readiness assertions in six files: `HealthEndpointTest`, `QaHealthProbeResilienceTest`, `UnlistedModuleReturns404Test`, `ModuleMakeGeneratedModuleReachableTest`, `ModuleRegistryManifestTrustTest`, `ModulesEnvParsingTest`. The plan adds `checks` to their expected JSON. AC-47 should read "pass after their exact-JSON expectations gain `checks`".
2. **-> ba CONFLICT 2 (AC-50 is understated).** Four more tests and two `module:make` stubs pin the ping body. `ModuleMakeDemoRegenerationTest` forces the stubs to change with the demo.
3. **-> ba CONFLICT 3 (AC-6 against an existing QA test).** `QaActivationCodeSecrecyTest` pins the framework 404's `message`, which echoes the path (and so the activation code). The envelope removes the echo. The test is strengthened to "no copy of the code", and it is a security improvement.
4. **-> ba CONFLICT 4 (D-1 "no existing endpoint changes behaviour" against AC-28, AC-30 and AC-32).** The limiter necessarily applies to every existing `api/*` route: new headers, and a possible 429. `TerminalActivationHttpTest`'s byte-identical header comparison must drop `x-ratelimit-remaining` (the T-017 `x-request-id` precedent). D-1 should be read as "no existing body or status changes below the limit".
5. **-> ba CONFLICT 5 (AC-40 against D-1).** The document would describe the legacy endpoints as returning the envelope, which is false until FP-369. The plan marks them `x-flexipos-legacy-body: true`, by tag.
6. **-> ba informational, no criterion changed:**
   - AC-13 names the environment variable by its pre-rename word. The code reads `MODULES`. Quoting the old word in code trips `QaVocabularyRenameSweepTest`.
   - AC-7 is read as one error item per field, carrying the first message.
   - AC-5's "204-equivalent" is 200 with `data: null`.
   - Two extra kernel codes: `kernel.bad_request` 400 and `kernel.payload_too_large` 413. Other 4xx statuses collapse to 400, and other 5xx statuses except 503 to 500.
   - A terminal token gets no user bucket. A platform token gets only a user bucket.
   - The idempotency hash excludes the query string.
   - A null `response_code` is the in-flight claim, with a stale takeover after 120 s.
   - The OpenAPI tag is the first URI segment after `api/v1` (so `terminal` for routes the auth package declares), and the docs route is tagged `kernel`.
   - `phpunit.xml` raises the default limits for the suite. The defaults are asserted separately.
7. **Follow-up rows proposed:**
   - count bad-token refusals in a pre-authentication bucket (risk 4);
   - an engine test for AC-22's concurrency;
   - a real-Redis readiness and limiter outage test;
   - promote the tenant-connection reader into the kernel (the idempotency store holds the seventh private copy).
8. **Pre-existing state:**
   - FP-316's cluster includes `QaHealthProbeResilienceTest` and `ModulesEnvParsingTest`, both edited here;
   - T-020's round-1 fixes are pending on the parent branch;
   - FP-328's headroom is 1.46;
   - `vendor/` is absent in this worktree.

### `architect` → `dev`

- **Register the generic renderer last,** and never let it touch a non-`api/*` path or an `HttpResponseException`.
- **Keep the refusal log line free of the path, the query and the detail text.**
- **The limiter fails open; nothing else here does.**
- **Never quote the old module vocabulary** in code, JSON or documents.
- **Stop on any red file that section 9 does not name.**

## Questions for the human

None that block the work. Five spec conflicts (section 10, items 1 to 5) are routed to `ba` through the lead. The plan takes the resolution stated with each one.
