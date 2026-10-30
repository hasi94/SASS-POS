# T-021: API foundation

Backlog: FP-215, "Envelope, error codes, idempotency, rate limits, OpenAPI, health endpoints"
(dependency FP-201, done). Decisions D-1 to D-22 are in `docs/decisions/T-021-api-foundation.md`.
**No BLOCKING questions** — autonomous/team-mode work; every open design question was decided and logged.

Convention, following T-018 to T-020: every criterion is provable on a deterministic fixture while a
real engine is unreachable, unless marked **[ENGINE]** (needs a real MySQL/MariaDB, gated by
`ENGINE_HOLD`/G-1) or **[REDIS]** (needs real Redis). Tests use the array/database cache and a test-only
route that is registered by the test, never in production.

## Problem / goal

`docs/architecture.md` section 8 fixes the API contract on paper: versioned REST under `/api/v1`, one
response envelope `{ success, data, message, errors[{code, field, detail}], meta{request_id} }`, error
codes namespaced by module and catalogued centrally, idempotency keys on state-changing requests,
rate limits per tenant, per user and per terminal, and OpenAPI generated from code. None of it exists.
Every endpoint shipped so far (T-009, T-010, T-014, T-016, T-017) invents its own body, and four plans
(T-009, T-010 and others) explicitly refused to build the shared machinery and routed it here.
Because the first till (FP-209) and every later module will be written against whatever shape exists,
this task builds the shared machinery once, so later endpoints inherit it instead of copying a shape.

This task builds the machinery and proves it on fixtures and on the two routes that already exist as
pure infrastructure (the demo ping and the health probes). It does **not** rewrite the existing
auth, access, organization or platform endpoint bodies (D-1) and invents no business endpoint.

## Users affected

- Developers of every future module: they get one way to return success, one way to fail, one
  catalogue to register codes in, one opt-in switch for idempotency, one place limits are set.
- The Angular POS/back office and the till: they get a single response parser, stable
  machine-readable error codes to translate, `Retry-After` on throttling, and safe retries.
- Operators and orchestrators: liveness and readiness probes that report real dependency state.
- Integrators and testers: a generated, machine-readable description of the API.
- Not served: no existing endpoint changes its body or status below the rate limit (D-1, D-16); no UI is built.

## Current behaviour

Re-derived from the files:

- Routing: `modules/host/bootstrap/app.php` registers `/up` (framework), and `routes/health.php`
  registers `GET /health/live` and `GET /health/ready` outside every middleware group (so a probe
  never opens a session). Business routes live in package/module `routes/api.php` files under the
  `api` group with an explicit `api/v1/...` prefix (auth, terminal, access, organization, demo).
- `HealthController::live()` returns `{"status":"ok"}`. `ready()` returns `{"status":"ok","modules":[...]}`
  and checks nothing else; its docblock says database and event-bus checks were deliberately deferred.
- Correlation: `App\Http\Middleware\AssignRequestId` is prepended globally. It mints a ULID per request,
  stores it as request attribute `request_id` (read through `Flexipos\Kernel\Http\RequestId`) and echoes
  it as the `X-Request-Id` response header. An inbound `X-Request-Id` is deliberately ignored (T-017).
- Bodies: there is no envelope. Auth refusals are `{"error": "<token>"}` (`AuthRefusals`, 401/403/422/503),
  with the uniform credential refusal byte-identical across causes by design (T-009 D-12); access and
  organization have their own `*Refusals`; `PlanLimitReached` is rendered by `PlatformRefusals`;
  `TenantSchemaBehind` and `RequiredSchemaVersionNotConfigured` render `{"message": "..."}` with 503
  (the first with `Retry-After`) from `bootstrap/app.php`.
- Everything else (unknown route, wrong method, validation failure, unauthenticated, throttled,
  uncaught exception) renders with Laravel's framework default shape, which differs per case and, with
  `APP_DEBUG` on, includes file paths and traces.
- Idempotency: no middleware or store exists. `docs/database.md` already designs the tenant table
  `idempotency_keys` (key VARCHAR(80) PK, request_hash CHAR(64), response_code SMALLINT, response_body
  JSON, created_at; 24-hour retention; "public POST idempotency") but it has no migration.
- Rate limiting: no `RateLimiter::for` definition and no `throttle` middleware on any route.
- OpenAPI: nothing generates or serves an API description.

## User stories

- As a module developer, I want to return success and failure through one envelope helper, so that I
  never hand-write a response body or a status code mapping.
- As a module developer, I want to register my module's error codes in one catalogue, so that the
  frontend can translate them and a typo cannot ship an unknown code.
- As a till developer, I want to retry a state-changing request with the same key and get the original
  result instead of a duplicate record, so that a flaky network never double-posts a financial document.
- As an operator, I want per-tenant, per-user and per-terminal limits, so that one runaway client cannot
  starve a shop or the server.
- As an integrator, I want a generated OpenAPI document that is verified complete, so that the API
  description cannot silently drift from the routes.
- As an orchestrator, I want liveness that never touches a dependency and readiness that reports the
  dependencies, so that a database outage drains traffic without killing healthy processes.
- As a frontend developer, I want every failure to carry `meta.request_id`, so that a support ticket
  can be matched to a server log line.

## Acceptance criteria

### A. Response envelope

- AC-1: Given a handler returns data through the envelope helper, When the response is sent, Then the
  JSON body has exactly the five top-level keys `success`, `data`, `message`, `errors`, `meta`;
  `success` is `true`, `errors` is `[]`, `meta.request_id` is a string, and `data` holds the payload.
- AC-2: Given the helper is called with no payload, When the response is sent, Then `data` is `null`
  (not omitted, not `[]`) and `message` is `null` unless one was supplied.
- AC-3: Given a failure response, When it is sent, Then `success` is `false`, `data` is `null`, `errors`
  has at least one entry, and every entry has exactly the keys `code`, `field`, `detail`; `field` is
  `null` when the error is not about one input.
- AC-4: Given any envelope response, When the body is read, Then `meta.request_id` equals the
  `X-Request-Id` response header of the same response, and equals the value `RequestId::current()`
  returns during that request.
- AC-5: Given the envelope helper is used, When a status is chosen, Then it is the status the catalogue
  holds for the (first) error code; a success uses 200 or 201 (created) as the caller requests (an empty
  success is 200 with `data: null`, never 204), and the envelope never returns `success: true` with a 4xx or 5xx status or the reverse.
- AC-6: Given a request to any `api/*` path raises one of: unknown route (404), wrong method (405),
  validation failure (422), unauthenticated (401), forbidden (403), too many requests (429), or an
  uncaught exception (500), When the framework renders it, Then the body is the failure envelope with
  the matching catalogue code and status (see AC-9 for the code list), never the framework default shape.
- AC-7: Given a 422 validation failure with N invalid fields, When rendered, Then `errors` has one
  entry per invalid field carrying its first message, `code` is the validation code, `field` is the input name
  (dot notation for nested input), and `detail` is the human message.
- AC-8: Given an uncaught exception on an `api/*` path with `APP_DEBUG=false`, When rendered, Then
  status is 500, `errors[0].code` is the server-error code, `detail` is a fixed generic sentence, and
  the body contains no exception class, message, file path, SQL or trace; the real exception is logged
  with the request id.

### B. Error-code catalogue

- AC-9: Given the catalogue, When listed, Then it contains at least these kernel codes with their
  statuses: `kernel.validation_failed` 422, `kernel.unauthenticated` 401, `kernel.forbidden` 403,
  `kernel.not_found` 404, `kernel.method_not_allowed` 405, `kernel.rate_limited` 429,
  `kernel.server_error` 500, `kernel.service_unavailable` 503, `kernel.idempotency_key_required` 400,
  `kernel.idempotency_key_invalid` 400, `kernel.idempotency_key_reused` 422,
  `kernel.idempotency_in_progress` 409.
- AC-10: Given a code string, When it is registered, Then it must match `<module>.<snake_case>`
  (lower-case letters, digits, underscore; exactly one dot) and the prefix must equal the registering
  module's name; any other string is refused at registration with an error naming the offending code.
- AC-11: Given a code is registered twice (same or different module), When the second registration
  happens, Then it is refused; codes are never silently overwritten.
- AC-12: Given the envelope helper is asked to emit a code not in the catalogue, When it runs, Then it
  throws in development/test and, in production, emits `kernel.server_error` and logs the unknown code
  (a typo must not reach a client as an unmapped string).
- AC-13: Given a module is not booted (the `MODULES` environment variable omits it), When the catalogue is read, Then that
  module's codes are absent; booting a module registers its codes through the same single entry point.
- AC-14: Given the catalogue, When a console command (`api:error-codes`) is run, Then it prints every
  code with its module, HTTP status and default message, sorted by code, and the same list is embedded
  in the OpenAPI document (AC-34).
- AC-15: Given a catalogue entry, When read, Then its `message` is a stable English default; the wire
  `errors[].code` is the contract, and no test or client may depend on the English text.

### C. Idempotency

- AC-16: Given a route is marked idempotent (opt-in per route, D-5) and a request arrives with no
  `Idempotency-Key` header, When processed, Then it is refused with 400 `kernel.idempotency_key_required`
  and the handler does not run.
- AC-17: Given a key that is empty, longer than 80 characters, or contains characters outside
  `A-Za-z0-9_-:.`, When processed, Then it is refused with 400 `kernel.idempotency_key_invalid`
  and the handler does not run.
- AC-18: Given a valid new key, When the handler returns a 2xx, Then one row is stored in the tenant
  database `idempotency_keys` (key, request hash, status, body, created_at) and the response is
  returned unchanged.
- AC-19: Given the same key, same actor, same method, path and body, When it is sent again within 24
  hours, Then the handler is not run a second time, the stored status and body are returned, and the
  response carries `Idempotent-Replayed: true`; the replay body's `meta.request_id` is the replay's
  own id, not the original's.
- AC-20: Given the same key with a different body, path, method or actor, When sent, Then it is refused
  with 422 `kernel.idempotency_key_reused`, the handler does not run and the stored response is not
  disclosed (a second user cannot read the first user's stored body by guessing a key).
- AC-21: Given the handler returns 4xx, 5xx or throws, When the request ends, Then nothing is stored
  and the same key can be retried and will run the handler again (only 2xx is replayable, D-6).
- AC-22: Given two requests with the same new key are in flight at once, When both arrive, Then exactly
  one runs the handler and the other receives 409 `kernel.idempotency_in_progress` with `Retry-After`;
  never two executions. **[ENGINE]** for the real-concurrency proof; a deterministic test proves the
  in-progress refusal by pre-seeding an in-flight claim.
- AC-23: Given a stored key older than 24 hours, When a request arrives with it, Then it is treated as a
  new key, and the console command `idempotency:prune` deletes rows older than 24 hours and reports how
  many it deleted; it does not touch younger rows.
- AC-24: Given the idempotent middleware on a route with no resolved tenant (a master/platform route),
  When a request arrives, Then it is refused as a configuration error with 500 `kernel.server_error`
  (logged) rather than storing keys in the wrong database; the store is tenant-only in this task (D-7).
- AC-25: Given two different tenants send the same key, When both run, Then each executes once and
  neither sees the other's row (one table per tenant database).
- AC-26: Given the `idempotency_keys` migration, When the per-tenant migration runner runs, Then every
  tenant database gains the table with the documented columns, the migration is expand-only and
  re-runnable without error, and `tenant:schema-status` counts it.
- AC-27: Given an idempotent route is also throttled, When a replay arrives, Then the replay still
  counts against the rate limits (a replay is a request).

### D. Rate limits

- AC-28: Given an authenticated tenant request, When it is processed, Then it is counted against three
  independent buckets: per tenant, per user (employee), and, when the token is a terminal token, per
  terminal; exceeding any one yields the 429 refusal.
- AC-29: Given a bucket is exhausted, When the next request arrives, Then the status is 429, the body
  is the failure envelope with `kernel.rate_limited`, and the response has `Retry-After` (whole
  seconds, at least 1); the handler does not run.
- AC-30: Given any response on a limited route, When sent, Then it carries `X-RateLimit-Limit` and
  `X-RateLimit-Remaining` for the most constrained bucket.
- AC-31: Given tenant A has exhausted its tenant bucket, When tenant B sends a request, Then B is not
  affected; likewise for users within one tenant and terminals within one tenant.
- AC-32: Given an unauthenticated request (login, refresh, activation, probes excluded), When processed,
  Then it is counted per client IP, with its own configured limit (D-9); an authenticated request is
  never also counted against the IP bucket.
- AC-33: Given the limits are read from configuration (`config/api.php`, overridable by environment),
  When a limit is changed, Then no code change is needed; a limit of 0 or a non-integer is refused at
  boot with a clear message rather than silently disabling throttling. Defaults are in D-9.
- AC-33a: Given `/health/*` and `/up`, When requested, Then they are never throttled and never touch
  the limiter's store (a limiter store outage cannot fail a liveness probe).
- AC-33b: Given the limiter's cache store is unreachable, When a limited request arrives, Then the
  request is allowed (fail open, D-10), the failure is logged at warning level once per request, and no
  500 results. **[REDIS]** for the real-outage proof; a throwing fake store proves it deterministically.

### E. OpenAPI

- AC-34: Given the console command `api:openapi`, When run, Then it writes one valid OpenAPI 3.1 JSON
  document containing: `info` (title, version = the API version `v1`), `servers`, one path item per
  route whose URI starts with `api/v1`, the envelope as reusable component schemas
  (`SuccessEnvelope`, `ErrorEnvelope`, `ErrorItem`), the error-code catalogue (AC-14) and the bearer
  security scheme.
- AC-35: Given every `api/v1` route registered by booted modules, When the document is generated, Then
  each appears exactly once with its HTTP method, a unique `operationId`, a tag equal to its module,
  its security requirement (none for public routes, bearer for routes behind a token middleware), and
  the standard error responses (401/403 only where authenticated, 422 where input is read, 429 always).
- AC-36: Given a route is marked idempotent, When documented, Then it declares the required
  `Idempotency-Key` header parameter and the 400/409/422 idempotency responses.
- AC-37: Given a completeness test, When a route exists under `api/v1` but is missing from the document
  (or the reverse), Then the test fails and its message lists the route; this test runs in `composer
  check`, so drift cannot merge.
- AC-38: Given the document is generated twice with no change, When compared, Then the output is
  byte-identical (stable ordering), so a committed copy produces meaningful diffs.
- AC-39: Given `API_DOCS_ENABLED` is true, When `GET /api/v1/openapi.json` is requested, Then it returns
  the document (not wrapped in the envelope, `Content-Type: application/json`); when false or unset
  (the default, D-11), the path answers 404 with the failure envelope, so production does not publish
  its API map.
- AC-40: Given a route with no per-route request/response schema declared, When documented, Then it is
  still listed; declaring detailed body schemas per endpoint is incremental, not required by this task
  (D-12). Operations whose handlers still return a legacy body (auth, terminal, access, organization,
  platform; D-1) carry `x-flexipos-legacy-body: true` and are not described as returning the envelope
  until FP-369 migrates them. The OpenAPI tag is the first URI segment after `api/v1` (so `terminal`
  for the terminal routes declared by the auth package); the docs route is tagged `kernel`.
- AC-40a: Given the catalogue, When listed, Then it also holds `kernel.bad_request` 400 and
  `kernel.payload_too_large` 413; other 4xx statuses render as 400 and other 5xx statuses except 503
  render as 500.

### F. Health endpoints

- AC-41: Given the process is running, When `GET /health/live` is requested, Then it answers 200 with a
  body containing `"status":"ok"`, opens no database, cache or session connection (proven with the
  database unreachable), and is outside every middleware group except the request-id middleware.
- AC-42: Given the master database is reachable, When `GET /health/ready` is requested, Then it answers
  200 with `status: "ok"`, the existing `modules` list, and a `checks` object with an entry per
  dependency each reading `ok`.
- AC-43: Given the master database is unreachable, When `/health/ready` is requested, Then it answers
  503 with `status: "unavailable"`, `checks.master_database` reading `failed`, and the probe itself
  returns promptly (bounded by a configured timeout, default 2 seconds), with no exception detail in
  the body.
- AC-44: Given Redis is configured as the cache/queue driver and is unreachable, When `/health/ready` is
  requested, Then `checks.redis` reads `failed` and the status is 503; given Redis is not configured
  (the XAMPP/local mode), Then `checks.redis` reads `skipped` and does not affect the status (D-13).
  **[REDIS]** for the real-outage proof.
- AC-45: Given the health bodies, When read, Then they contain no hostnames, credentials, versions or
  tenant identifiers, only fixed words (`ok`, `failed`, `skipped`, `unavailable`) and module names.
- AC-46: Given the tenant-schema-behind condition of T-003 for some tenants, When `/health/ready` is
  requested, Then readiness is not affected: a single lagging tenant must not drain the whole host
  (D-14); that condition remains reported by `tenant:schema-status`.
- AC-47: Given the existing readiness tests (`HealthEndpointTest`, `QaHealthProbeResilienceTest`,
  `UnlistedModuleReturns404Test`, `ModuleMakeGeneratedModuleReachableTest`,
  `ModuleRegistryManifestTrustTest`, `ModulesEnvParsingTest`; nine exact-JSON assertions), When their
  expected JSON gains the `checks` object, Then they pass: the `status` and `modules` keys keep their
  names and values and the routes stay outside the `web` group.

### G. Non-regression

- AC-48: Given the existing endpoints that return their own bodies (`AuthRefusals`, `AccessRefusals`,
  `OrganizationRefusals`, `PlatformRefusals`, the two 503 renders in `bootstrap/app.php`), When their
  existing tests run, Then every one still passes with unchanged status codes and bodies (D-1); the
  new generic renderer applies only to failures no existing renderer already handles.
- AC-49: Given any non-`api/*` request (web, console), When it fails, Then it renders as it does today;
  the envelope renderer is scoped to paths beginning `api/`.
- AC-50: Given the demo `api/v1/demo/ping` route, When requested, Then it returns the success envelope
  (it is the in-repo example of correct usage). Every test and generator stub that pins the old ping body
  is updated to the new one: the ping's own test, `ModuleMakeGeneratedModuleReachableTest`,
  `ModuleRegistryManifestTrustTest`, `QaAtomicGenerationProofTest`, `QaManifestTrustAttackTest`, and the
  two `module:make` stubs (`ModuleMakeDemoRegenerationTest` requires the stubs to follow the demo).
- AC-51: Given `QaActivationCodeSecrecyTest`, When the envelope replaces the framework 404 (whose
  `message` echoed the request path and so an activation code in it), Then the test is strengthened to
  assert the response body contains no copy of the code; the envelope's 404 never echoes the path.
- AC-52: Given the limiter now applies to every existing `api/*` route (AC-28 to AC-32), When an existing
  endpoint is called below its limit, Then its status and body are unchanged and it additionally carries
  `X-RateLimit-*` headers; `TerminalActivationHttpTest`'s byte-identical header comparison ignores
  `x-ratelimit-remaining` (as it already ignores `x-request-id`).
- AC-53: Given a terminal token, When limited, Then it is counted against the tenant and terminal buckets
  only (no user bucket); given a platform token, Then only a user bucket applies.
- AC-54: Given the idempotency request hash, When computed, Then it covers method, path, actor and body
  and excludes the query string; given a claim left in flight (null stored status) for more than 120
  seconds, Then a new request with that key may take it over.

## Out of scope

- Rewriting the bodies of existing auth, access, organization, platform, terminal endpoints into the
  envelope (D-1); follow-up row proposed: FP-369 (below), owned by lead.
- Offline-document client ids as idempotency keys and the `sync/*` group (belong to the sync task).
- Idempotency for master/platform routes (D-7) and for GET, which is naturally idempotent.
- Request-body and response-body schemas for every existing endpoint in the OpenAPI document (D-12).
- Pagination, filtering and sorting conventions (a `meta` extension for the first list endpoint to define).
- Frontend client, error-message translations, generated TypeScript client.
- API versions other than `v1`, deprecation headers, content negotiation.
- Metrics, tracing, alert rules, and the readiness inclusion of event bus lag (FP-2xx observability).
- Per-plan or per-licence rate-limit tiers; billing-related quotas.
- Authentication, authorization, CORS, or any new credential.
- Route-group restructuring into `platform/*`, `tenant/*`, `pos/*` (section 8 names them; no task here moves routes).

## Non-functional requirements

- Security: no internal detail (class, path, SQL, hostname) in any envelope or health body; idempotency
  replay never crosses actors or tenants (AC-20, AC-25); the OpenAPI route is off by default (AC-39); the
  inbound `X-Request-Id` remains ignored (T-017). Idempotency stored bodies may contain business data
  and live in the tenant database, covered by its existing retention and backup rules.
- Performance: the envelope adds no database access; the limiter adds at most one cache round trip per
  bucket; the idempotency store adds one indexed primary-key read and one insert on idempotent routes only;
  `/health/live` does no I/O. Readiness is bounded by the configured timeout (AC-43).
- Operability: every refusal produced by this task is logged with the request id; `meta.request_id` is
  how a support ticket finds the log line.
- Compatibility: expand-only migration (AC-26); old and new code can run side by side during a rollout.

## Open questions

None blocking. Items for the lead: (1) propose a backlog row FP-369, "Migrate existing endpoint bodies
(auth, access, organization, platform, terminal) to the envelope", dependent on FP-215, to be done
together with the first frontend consumer (FP-209) since it changes the wire contract; (2) FP-315's
setting-size cap needs a refusal code and can use the catalogue once this lands.

## Assumptions

- The envelope is applied to `api/*` paths only; "everywhere" in architecture.md section 8 is read as
  "every API endpoint going forward", reached for existing ones by FP-369 (D-1).
- `X-Request-Id` and `meta.request_id` stay server-minted ULIDs (T-017); this task adds no client-supplied
  correlation id.
- Redis is not required to run the suite; the array/database cache stands in for it and a Redis-only
  assertion is marked **[REDIS]**.
- The test-only idempotent route is registered inside tests and never exists in production; no
  production state-changing route is made idempotent by this task, because no financial record endpoint
  exists yet (the sale endpoint is a later task and opts in by one line).
- Default limits and retention numbers are in the decisions file and are configuration, not contract.
