# Plan: T-009 Authentication — login against the master, and tenant access

Spec: `docs/specs/T-009-authentication.md` (53 criteria, commit `20202f0`). Decisions: `docs/decisions/T-009-authentication.md` (D-1 to D-24). Backlog row FP-207. Branch `ai/T-009-authentication`, from the T-008 tip.

Gate baseline this plan is written against: **exit 0, 857 passed, 11 skipped, 4508 assertions**.

`ENGINE_HOLD` is on and is a constant (`Flexipos\Testing\Engine\EngineOnHold::HELD`). **Nothing in this plan needs the MySQL/MariaDB engine.** Every proof below runs on the SQLite stand-in that `modules/host/phpunit.xml` already configures for the `master` connection, or against T-003's configuration-array tenant fixtures. The criteria the spec marks **[ENGINE]** (AC-22, the cross-engine half of AC-50, the collation half of AC-2) stay deferred and are NOT VERIFIED, not skipped.

---

## 0. The three rulings `ba` asked for

### Ruling 1 — JWT library, signing algorithm, and Argon2id availability

**Library: `firebase/php-jwt` ^7.0, required by `packages/auth/composer.json`. Algorithm: ES256 (ECDSA over P-256, SHA-256).**

> **Amended after step 1 — the constraint moves from `^6.10` to `^7.0`.** `dev` installed `v6.11.1` per the original `^6.10` and found that `composer audit` reports **CVE-2025-45769 / GHSA-2x45-7fc3-mxwq ("weak encryption", severity low, affected `<7.0.0`)** against it. There is no 6.x patch release, so `^6.10` is *permanently* inside the affected range — this is not a "wait for the next patch" exposure, it is a constraint that can never go green. `dev` was right not to change a pinned major on its own authority; the ruling is `architect`'s and it is to **move to `^7.0`**.
>
> **Why move rather than accept.** `composer audit` is not part of `composer check`, so the gate is green either way and nothing is blocked today. But the exposure is real in the only way that matters here: the first reviewer who runs an audit, or FP-218 the moment it adds an audit step, names it — and the answer "we knowingly pinned a major that can never clear the advisory" is a worse conversation than a one-line constraint change made now, before any code exists. `v7.2.1` keeps every property Ruling 1 chose the library for: **zero runtime dependencies**, the same `Firebase\JWT` namespace, BSD-3-Clause, no network calls. `php ^8.0` is satisfied by this project's `^8.2`.
>
> **Timing is not negotiable: it lands before step 5.** Step 5 is the first code written against the decode API. Changing the major afterwards would mean rewriting a verifier whose eight refusals are the most carefully controlled tests in the task.
>
> **One thing `dev` must verify, not assume, in the same step.** The 7.x source is not available offline — the local Composer cache holds exactly one `firebase/php-jwt` archive, the 6.x one — so the API claim cannot be checked from here. On the installed 7.x tree, confirm by reading `vendor/firebase/php-jwt/src/JWT.php` and `Key.php` that (i) `JWT::decode($jwt, new Key($publicKey, 'ES256'))` is still the signature, with the algorithm still named by the caller and a mismatched header `alg` still refused by the library — that property is what AC-29 rests on; (ii) `Key`'s constructor is unchanged in **arity and order** — `(mixed $keyMaterial, string $algorithm)`; (iii) `composer.json` still declares no runtime `require` beyond `php` and `ext-json`.
>
> **All three verified on the installed `v7.2.1`, with two clarifications to the wording above.** (ii) was originally written as `string|OpenSSLAsymmetricKey $keyMaterial`; 7.x declares `string|OpenSSLAsymmetricKey|OpenSSLCertificate`. That is a **superset, not a drift** — a widening of what the parameter accepts, with arity, order and the call site `new Key($pub, 'ES256')` all unaffected. `dev` was right to report it rather than treat it as a failed property, and right not to take the fallback; a later reader should not mistake it for a breaking change. (iii) holds: `require` is `php: ^8.0` only, with `sodium` and `phpseclib` as `suggest`. Lock moved `v6.11.1` → `v7.2.1` and `composer audit` went from the one CVE-2025-45769 advisory to "No security vulnerability advisories found". The decode signature, the caller-named algorithm and the library's own refusal of a mismatched header `alg` are intact, so **AC-29's foundation holds** — confirmed empirically as well: alg mismatch, `alg: none`, altered payload and the `exp` boundary all refused, accepted at `exp-1`, `ExpiredException` at exactly `exp`.
>
> **A 7.x behaviour this ruling did not anticipate, and it makes the curve choice load-bearing a second time.** 7.0.0's security fix is a **minimum key-size floor derived from the algorithm name**: ES256 requires 256 bits, and a P-256 (`prime256v1`) key reports **exactly 256** — it passes **on the boundary, with no margin**. Anything smaller raises `DomainException "Provided key is too short"`; RSA's floor is 2048. ES256 was chosen above for signature size and verify speed; it is now *also* the curve that satisfies the library's floor exactly, so **the curve is pinned, not merely preferred**. Binding consequences: `KeyPairGenerator` names `prime256v1` explicitly and never takes a curve from configuration or an environment variable; no test may substitute a smaller curve to go faster, because it would fail inside the library and the failure would read as a verifier bug; and if a future task moves to RS256 it must generate 2048-bit keys or hit the same floor. Step 5 must assert the floor rather than rely on it — a key one size below the floor is refused by the library, which doubles as the control proving the floor is live.
>
> **If any of the three differs, or the network cannot fetch 7.x: stop and report to the lead; do not improvise.** The documented fallback is to stay on `v6.11.1` and record the advisory as an **accepted, named exposure** — a backlog row *"Upgrade `firebase/php-jwt` to ^7.0; 6.x is permanently inside CVE-2025-45769 with no patch release"* and a line in the QA report — never as an unmentioned pin. The one thing that is forbidden is a silent downgrade of this ruling.

Why this library:

- It has **no runtime dependencies** beyond PHP and `ext-json`, so it adds one directory to `vendor/` and no transitive surface. `lcobucci/jwt` pulls `psr/clock` and a larger object graph; `web-token/jwt-framework` pulls a dozen packages. At 94 remaining backlog rows and one developer (`docs/patterns.md` §0), the cheapest correct dependency wins.
- Its decode signature is `JWT::decode($jwt, new Key($publicKey, 'ES256'))` — **the caller names the algorithm, and a header `alg` that differs is refused by the library itself.** That is AC-29's "`alg` = `none`", "`alg` different from the stored key's algorithm" and "payload altered after signing" satisfied by the library's own contract rather than by our code. A hand-rolled verifier would have to re-derive exactly this, and alg-confusion is the classic way to get it wrong.
- MIT licensed, no network calls, no autoloaded globals.

Why ES256 and not RS256 or EdDSA:

- **EdDSA is not available on this machine.** Verified: `extension_loaded('sodium')` is `false` on `E:\xampp\php\php.exe` 8.2.12. EdDSA in `firebase/php-jwt` requires `ext-sodium`. Ruled out on a measured fact, not a preference.
- **ES256 works here.** Verified: an EC `prime256v1` key pair generates, signs and verifies through `openssl_*` on this build (`OpenSSL 3.0.11`).
- RS256 also works, but a 2048-bit RSA signature is 256 bytes against ES256's 64, and every POS request carries the token. ES256 is the smaller token and the faster verify.

**One verified local hazard, and the plan handles it.** `openssl_pkey_new()` **fails on this machine with no arguments**: the OpenSSL error queue reports `configuration file routines::no such file` because no `openssl.cnf` is on the default search path for the CLI SAPI. Passing `['config' => 'E:/xampp/apache/conf/openssl.cnf']` makes both EC and RSA generation succeed. D-23 says tests generate ephemeral key pairs at run time, and **that call would fail on this machine today**. So the plan puts key generation behind one symbol, `Flexipos\Auth\Keys\KeyPairGenerator`, which resolves an OpenSSL configuration in this order and caches the generated pair for the life of the process: the `OPENSSL_CNF` environment variable, then an `openssl.cnf` beside the PHP binary's directory tree, then no `config` key at all (which is what a Linux CI runner needs). No key material is committed; D-23 stands as written.

**Argon2id: available locally, and there is no CI to check.**

- Verified on `E:\xampp\php\php.exe` 8.2.12: `PASSWORD_ARGON2ID` is defined and `password_hash($p, PASSWORD_ARGON2ID)` returns a real `$argon2id$v=19$m=65536,t=4,p=1$...` digest. `ext-sodium` is absent but **is not needed** — PHP's bundled libargon2 provides `PASSWORD_ARGON2ID` independently.
- **There is no CI.** Verified: no `.github` directory exists in this repository, and `FP-218 | CI pipeline` is still `TODO` in `docs/backlog.md`. The only gate is `composer check` on this machine. The Argon2id requirement therefore becomes an **acceptance item for FP-218** when it lands (the runner image must have Argon2 compiled in; `shivammathur/setup-php` on 8.2 does).
- **How tests hash.** They hash with the real Argon2id driver, at reduced cost parameters, for a measured reason: default Argon2id on this machine costs **0.232 s to hash and 0.222 s to verify**, against **0.0015 s / 0.0014 s** at `memory_cost=1024, time_cost=1, threads=1` — a 150× difference. The spec's lockout criteria alone submit dozens of passwords; at default cost that is tens of seconds added to a gate whose slowest step is budgeted at 106 s cache-cold. So T-009 adds `modules/host/config/hashing.php` with `driver => 'argon2id'` and the Argon parameters read from `ARGON_MEMORY` / `ARGON_TIME` / `ARGON_THREADS`, and `phpunit.xml` sets the cheap triple — exactly the shape the existing `BCRYPT_ROUNDS=4` entry already uses. **The algorithm under test is still Argon2id**; only its work factor moves. A test that asserted a *duration* would be invalid under this and the spec's NFR already forbids one ("wall-clock timing is not asserted").

**Boundary consequence, and it is not small.** `deptrac.yaml` currently declares `Package_Auth: ~` — the auth package may depend on **nothing**, not even the kernel. It cannot use `RunnerClock`, `IdentifierColumn` or `Tenancy` as written. Step 1 widens it to `Package_Auth: [Package_Kernel, Package_Contracts]`, which `docs/patterns.md` §4.1 expressly permits ("a module may use the kernel and another module's published contracts"). **A vendor namespace such as `Firebase\JWT\*` matches no layer and is therefore not reported**, so the library needs no layer, no exemption and no baseline. Widening the ruleset is a new *allowed edge*, which is a reviewable design change — it is **not** an exemption, and AC-51's "no new exemption" is untouched. Step 1 also carves the status symbols back out; see Ruling 2's companion in §"AC-47 is unachievable as worded".

### Ruling 2 — D-9 is CONFIRMED: tenant selection does not probe `tenant_databases` and does not call `Tenancy::enter()`

Confirmed, with one documentation conflict that `ba` should record and one condition.

- `Tenancy::enter()` takes a tenant id and a `TenantConnectionPurpose`. Its serving list is `TenantConnectionPurpose::SERVING_STATUSES`, which is `[TenantDatabase::STATUS_ACTIVE]`, and `TenantStatusPolicy::accepted()` answers `Serving` from that **constant with no constructor argument reaching that branch** — by design, so the serving list cannot be widened by a test or by configuration. That gate runs on every request that enters a tenant. A second copy inside authentication would be a second place for the rule to live, which is the one thing `docs/domain-core.md` §7 names as the root of silent divergence ("a fact stated twice is a fact that will disagree with itself").
- `Refference/ARCH.md` §5 step 4 ("Master DB contains the approved database mapping") is satisfied **one step later**, by the gate, before any module code runs — which is what §5 actually promises. ARCH.md does not say *when* in the exchange the check happens.
- **The conflict `ba` should log.** `docs/database.md` §5's flow for `POST /auth/tenant` lists `master: tenant_databases.status = active AND schema_version >= app_required_schema_version`, `resolve connection, open tenant DB`, and then a read of the tenant's `employees` table. That is a third document disagreeing, and it disagrees for a reason: that flow exists to produce the `employee`, `branch` and `perm_hash` claims, which **D-10 already defers to FP-208**. So D-9 and D-10 are the same deferral seen twice. The honest statement is: *selection opens no tenant database **while the tenant token carries no tenant-local claim**. FP-208, which adds branch and permission claims, necessarily re-opens that question, and when it does the right answer is still to call the existing `Tenancy::enter()` rather than to write a mapping probe.* Please add that sentence to D-9's reason, and raise a backlog row against `docs/database.md` §5 so the flow is annotated rather than left to contradict us.
- **Condition.** D-9 is only safe because a tenant access token is useless on its own: AC-45 to AC-47 require that every tenant-scoped request go through `Tenancy`. Step 12 makes that structural — the middleware that reads the token is the same middleware that calls `enter()`, in one class, so a route cannot be authenticated without being gated.

### Ruling 3 — `login_history.id` and `security_events.id` as `BIGINT AUTO` are CONSISTENT. `docs/database.md` is not amended, and the rule is not bent

There is already a precedent in the master schema, shipped by T-008, with the rationale written out: `modules/host/database/migrations/2026_10_14_000111_create_platform_audit_logs_table.php` uses `$table->id()` for its primary key while using `IdentifierColumn::nullable()` for `actor_id`, `tenant_id`, `subject_id` and `request_id`. Its docblock states the reason in three clauses: *"it is an append-only log written by the platform itself, nothing offline creates a row, and the monotonic key is also the cheapest read order."*

All three hold for `login_history` and `security_events`: both are append-only, both are written only by the master-side application, nothing offline or distributed mints their ids, and both are read in time order with a retention window.

The identifier-column rule is about **references to another row's identity** — a ULID, `CHAR(26)`, `ascii_bin`, so that two ids differing in case are two ids. `login_history.user_id` and `login_history.tenant_id` are such references and **must** use `IdentifierColumn::nullable()`. The table's own surrogate key is not a reference; nothing cites it. `refresh_tokens` is the opposite case and takes `IdentifierColumn::primary()`, because it is a row other things refer to and because its id is minted alongside a secret. `signing_keys` keeps `kid VARCHAR(40)` as its primary key, as designed — a `kid` is a JOSE header value, not an internal identifier, and it is the one id that leaves the system in a token header.

So: no amendment, no exemption, and no new rule. The reviewer should be pointed at `platform_audit_logs` as the precedent rather than at this paragraph.

---

## 1. The correction that changes the design: `tenants.status` has no reader

**Verified.** A grep across `packages/kernel/src`, `modules/host/app` and `modules/host/config` finds **no read of `tenants.status` anywhere**. The only two matches for the string are prose in `PlanMigrationRunHandler`'s docblock explaining why the planner deliberately selects on `tenant_databases.status` instead, and a `'tenants' => $command->tenantIds` array key. The serving gate's `active` is `tenant_databases.status`. `tenants.status` — the commercial fact, the one that says the shop has stopped paying — is read by nobody.

So **authentication is the first and currently only reader of the commercial status**, and T-009 must apply that rule itself. The plan puts it in exactly one place and makes drift expensive:

- One symbol, `Flexipos\Auth\Tenancy\TenantSelectionPolicy`, holds the selectable set (`trial`, `active`, `past_due`) as a **compile-time constant**, in the same shape and for the same reason as `TenantConnectionPurpose::SERVING_STATUSES`: no `config()`, no `env()`, no constructor channel an operator can widen. AC-12's "the selectable set is read from one place and moving it moves the result" is proved by handing dependents a different policy *object* through a signature — the seam `TenantStatusPolicy::withMigratableStatuses()` already establishes as this project's idiom — never by rebinding a constant.
- **Two readers, one authority.** The tenant *list* (AC-12's `selectable` flag) and the tenant *selection* refusal (AC-40(b)) and the tenant-scoped *refresh* re-check (AC-37) are three call sites. All three call `TenantSelectionPolicy::permits()`. None of them restates the list. The drift test is the moved-value proof: a policy object built over `['closed']` must make the list, the selection and the refresh all flip together in one test, and a dependent that kept a copy stays on the old answer and goes red.
- **It cannot drift from FP-206.** FP-206 writes `tenants.status` (provisioning step 12 sets `trial|active`); it does not read it for an access decision. The one future reader that could collide is FP-210 (subscription gating), and the plan's answer is in `packages/auth/src/Tenancy/TenantSelectionPolicy.php`'s docblock: *the selectable set is this class's; a subscription or licence check is a separate predicate ANDed with it, never a second copy of this list.* That sentence is asserted by a test in the same way `TenantStatusPolicy`'s reconciliation is asserted today — if a later task adds a configuration channel to this class, the test that reads its reflection for a `config`/`env` call goes red.
- **FP-195** (report when the commercial status and the database status disagree) is the watcher for the seam itself and stays where it is. T-009 does not implement it; this plan is the second sighting of the same gap and the row should be re-scored upward.

---

## 2. Approach summary

Authentication ships as **seven vertical slices in `packages/auth`** (`docs/patterns.md` §3), each a folder holding its command, its handler and nothing else, with its test mirrored under `packages/auth/tests/Features/<X>/` — the mirror, not the in-folder test, because `modules/host/phpunit.xml` collects `../../packages/*/tests` and **a `*Test.php` under `src/` is collected by nothing**, which is exactly what `VerticalSliceLayoutTest` already enforces.

The slices are `Login`, `SelectTenant`, `RefreshToken`, `Logout`, `IssueTokenPair`, `VerifyAccessToken` and `RecordLoginAttempt`. Shared nouns that are used by more than one slice earn a place in `packages/auth/src/Support/` and `packages/auth/src/Tokens/` — the three-use rule is relaxed to two here because the token pair is produced by three slices on day one.

The HTTP surface is **four thin controllers in the host** (`modules/host/app/Http/Controllers/Auth/`) plus two middleware, mounted at `/api/v1/auth/*` from a route file loaded by a new `Flexipos\Auth\AuthServiceProvider`. The controllers validate, call a handler, and render; they hold no rule. **FP-215 (API foundation) is not built here** — T-009 uses the `architecture.md` §8 envelope shape for these four endpoints only, and builds no error-code catalogue, no idempotency store and no rate limiter.

Four new master migrations plus one expand migration on `users`. The fifth is the answer to the case-insensitive-email problem: a **stored generated `email_lower` column** over `LOWER(email)` with its own unique index, so the *lookup* is an indexed equality on every engine and the stand-in gains the case-insensitive uniqueness that T-008 could only get from a MySQL collation. Verified on SQLite 3.39.2 (the stand-in's version): the generated column populates, the unique index refuses `owner@example.COM` against a stored `Owner@Example.com`, and `ALTER TABLE ... ADD COLUMN ... STORED` is accepted.

Time is the existing `Flexipos\Kernel\Schema\Support\RunnerClock`, bound as a singleton and injected — **not a second clock**. D-22 left the choice open; reusing it means one `now()` in the process, one `DATETIME(3)` rendering (`RunnerClock::PRECISION`), and one `advanceMinutes()` that every boundary criterion (AC-19, AC-29's `exp`, AC-35's `expires_at`) already knows how to drive.

Refusals are built so that the **only** thing distinguishing one cause from another is `login_history.failure_reason`. That is D-12's whole point and it is also this plan's largest masking hazard; §7 treats it as such.

---

## 3. Alternatives rejected

- **Hand-rolled JWT (three base64url segments and `openssl_sign`).** Rejected: alg-confusion and unpadded-base64 bugs are the two classic JWT defects, and AC-29 asks us to refuse eight attack shapes. A reviewed library that refuses them in its own contract is cheaper and safer than 120 lines we own forever.
- **`lcobucci/jwt`.** Rejected: more dependencies and a builder API that would need its own wrapper; no capability we need that `firebase/php-jwt` lacks.
- **RS256.** Rejected: 4× the signature bytes on every POS request for no security gain at this threat level. **EdDSA** rejected on a measured fact: `ext-sodium` is absent on this PHP build.
- **Opaque database-backed access tokens (Sanctum-style), no JWT.** Rejected: `docs/architecture.md` §5 and §17 and `docs/database.md` §4.1 (`signing_keys`, `kid`, `private_key_ref`) all specify asymmetric signed tokens, and a database read per request against the *master* is exactly the coupling the one-database-per-tenant design exists to avoid.
- **Committing a fixture key pair under `tests/`.** Rejected: it reads as a committed private key to every reviewer and every future scanner, and the team rule is absolute. Runtime generation plus a per-process cache costs one keygen per test run.
- **Case-insensitive email by `LOWER(email) = ?` in the query.** Rejected: correct on both engines but it discards the index on MySQL permanently, to work around a stand-in artefact. The generated column is indexed everywhere.
- **Case-insensitive email by lowercasing on write.** Rejected: AC-2 stores `Owner@Example.com` and expects it back; the display form is the user's.
- **A `tenants.status` check inside `Tenancy::enter()`.** Rejected: it would make every tenant request pay a master read of a commercial fact, and it would put the commercial rule inside the serving gate, which is the one thing D-9 and the brief forbid.
- **A `mapping exists` probe at selection.** Rejected, as `ba` proposed — a second copy of the gate's first rule, and it would need a tenant database the engine hold makes unavailable.

---

## 4. Files to change / create

Paths are repository-relative. `[new]` unless marked.

### The auth package — the slices

| Path | What and why |
|---|---|
| `packages/auth/composer.json` *(change)* | add `firebase/php-jwt: ^6.10` to `require`; add the `extra.laravel.providers` entry for `AuthServiceProvider` |
| `packages/auth/src/AuthServiceProvider.php` | binds the clock, the hasher, the key resolver, the policies; loads `routes/api.php`. Registered in `modules/host/bootstrap/providers.php` |
| `packages/auth/routes/api.php` | the four endpoints under `api/v1/auth`, in the `api` middleware group — the same shape `modules/demo/routes/api.php` already uses |
| `packages/auth/src/Features/Login/Login.php` · `LoginHandler.php` | the credential command and the write path: lookup, status, lockout, verify, history, token pair |
| `packages/auth/src/Features/SelectTenant/SelectTenant.php` · `SelectTenantHandler.php` | code → tenant, membership and commercial-status checks, tenant token pair, `last_accessed_at`, history row |
| `packages/auth/src/Features/RefreshToken/RefreshToken.php` · `RefreshTokenHandler.php` | hash, find, replay detection, family revocation, rotation, tenant re-check |
| `packages/auth/src/Features/Logout/Logout.php` · `LogoutHandler.php` | family revocation, idempotent, non-revealing |
| `packages/auth/src/Features/IssueTokenPair/IssueTokenPair.php` · `IssueTokenPairHandler.php` | the one place a JWT is minted and a refresh row written. Three callers; AC-28, AC-31, AC-32 all land here |
| `packages/auth/src/Features/VerifyAccessToken/VerifyAccessToken.php` · `VerifyAccessTokenHandler.php` | the one place a JWT is verified. AC-29's eight refusals |
| `packages/auth/src/Features/RecordLoginAttempt/RecordLoginAttempt.php` · `RecordLoginAttemptHandler.php` | the one writer of `login_history`; AC-23, AC-24, AC-27, AC-40 |

### The auth package — shared support

| Path | What and why |
|---|---|
| `packages/auth/src/Tenancy/TenantSelectionPolicy.php` | **the single authority for `tenants.status`** (§1). Constant set, no configuration channel |
| `packages/auth/src/Tokens/TokenClaims.php` | the exact claim sets of D-10 and AC-28, as two named shapes, so "exactly" is a value and not a literal in three handlers |
| `packages/auth/src/Tokens/SigningKeyring.php` | reads `signing_keys`; `active` signs, `retiring` verifies only, `retired` verifies never. Caches public keys per process, keyed by `kid` **and** status, so AC-29's retired-key case cannot be served from cache |
| `packages/auth/src/Tokens/RefreshTokenValue.php` | 32 CSPRNG bytes → base64url; `hash()` is the SHA-256 hex. The plaintext exists only as a return value and is never a property of a persisted object (AC-32) |
| `packages/auth/src/Keys/KeyPairGenerator.php` | the OpenSSL-config resolution of Ruling 1; used by tests and by a future key-rotation command |
| `packages/auth/src/Support/EmailAddress.php` | trim, length check, and `normalised()` = `mb_strtolower`. The one place an email becomes a lookup key (AC-2, AC-9, AC-23) |
| `packages/auth/src/Support/IpAddress.php` | `inet_pton` / `inet_ntop` across the `VARBINARY(16)` column, both families, one column (AC-27) |
| `packages/auth/src/Support/GenericRefusal.php` | D-12's single refusal: one status, one body, one header set. Constructed in one place so "byte-identical" is structural |
| `packages/auth/src/Exceptions/*.php` | `CredentialRefused`, `TenantNotServable`, `NoTenantAccess`, `NoActiveSigningKey` |

### The host

| Path | What and why |
|---|---|
| `modules/host/app/Http/Controllers/Auth/{Login,SelectTenant,Refresh,Logout}Controller.php` | thin; validate (AC-9, AC-41), call the handler, render |
| `modules/host/app/Http/Middleware/RequirePlatformToken.php` | verifies, requires `token_type=platform` (AC-42) |
| `modules/host/app/Http/Middleware/RequireTenantToken.php` | verifies, requires `token_type=tenant`, **and calls `Tenancy::enter()` with the token's tenant** — one class, so a route cannot be authenticated without being gated (AC-45, AC-46, AC-47) |
| `modules/host/bootstrap/providers.php` *(change)* | add `AuthServiceProvider` |
| `modules/host/config/hashing.php` | Argon2id driver and its work factors (Ruling 1) |
| `modules/host/config/authentication.php` | access TTL 15 min, refresh TTL 14 days, lockout 5 / 15 min, input caps. **Numbers only.** No status list and no algorithm name lives here |
| `modules/host/phpunit.xml` *(change)* | `ARGON_MEMORY=1024`, `ARGON_TIME=1`, `ARGON_THREADS=1` |
| `modules/host/deptrac.yaml` *(change)* | `Package_Auth` gains `Package_Kernel` and `Package_Contracts`; a `Package_Kernel_TenancyStatus` layer is carved out and **not** granted to `Package_Auth` (§9, AC-47) |
| `modules/host/composer.lock` *(change)* | the new dependency |

### Migrations

| Path | What |
|---|---|
| `modules/host/database/migrations/2026_10_15_000201_create_signing_keys_table.php` | `kid` PK, algorithm, public_key, `private_key_ref`, status, created_at, retired_at |
| `…_000202_create_refresh_tokens_table.php` | `IdentifierColumn::primary`, principal_type/id, nullable `tenant_id`, `token_hash CHAR(64) UNIQUE`, `family_id`, expires_at, used_at, revoked_at, ip, user_agent, index (principal_type, principal_id) |
| `…_000203_create_login_history_table.php` | `$table->id()` (Ruling 3), nullable user_id/tenant_id as `IdentifierColumn`, email, principal_type, success, failure_reason, ip, user_agent, device_id, created_at; index (email, created_at), (user_id, created_at) |
| `…_000204_create_security_events_table.php` | `$table->id()`, type, nullable user_id/tenant_id/actor_id, `detail` JSON with `JsonValidity::enforce`, ip, created_at |
| `…_000205_add_email_lower_to_users_table.php` | the stored generated column and its unique index (§2). Phase `expand` |

### Test files (QA-owned unless the slice's own)

`packages/auth/tests/Features/<X>/<X>Test.php` (seven, dev-owned, mirror rule) · `packages/auth/tests/Support/auth-fixture.php` (a required-once plain file, the shape `packages/kernel/tests/Support/master-mapping-fixture.php` established for FP-164) · `modules/host/tests/Feature/Auth*Test.php` (QA: the HTTP-level criteria, AC-45 to AC-47, AC-53) · `modules/host/tests/Feature/QaSecretSinkCaptureTest.php` (QA: AC-26 and AC-32's runtime scans and their positive controls).

---

## 5. Data / API changes

**Schema.** Four new master tables exactly as `docs/database.md` §4.1 lists them, plus `users.email_lower`. Every new table is master-only; `SourceRules`' AC-37 rule forbids naming the `tenant` connection in a master migration, and nothing here does. All phases are `expand`. No tenant-database change at all, which is why this task needs no provisioning and no engine.

**Endpoints** (`api/v1` per `architecture.md` §8; the `api` middleware group already exists and `modules/demo/routes/api.php` shows the idiom):

| Method | Path | Auth | Success | Refusals |
|---|---|---|---|---|
| POST | `/api/v1/auth/login` | none | 200 + platform token, refresh token, tenant list, `default_tenant_id`, `must_change_password` | 422 validation (AC-9); **401 generic refusal** (AC-6, AC-7, AC-18); 503 `NoActiveSigningKey` (AC-31) |
| POST | `/api/v1/auth/tenant` | platform token | 200 + tenant token, tenant refresh token | 422 (AC-41); 401 missing/invalid/expired; 403 `no_access` (AC-40a); 403 `tenant_not_servable` + status (AC-40b); 403 for a tenant token (AC-42) |
| POST | `/api/v1/auth/refresh` | refresh token in the body | 200 + new access token + rotated refresh token | 401 generic refusal (AC-34, AC-35, AC-36); 403 `tenant_not_servable` / `no_access` without consuming the token (AC-37, D-24) |
| POST | `/api/v1/auth/logout` | refresh token in the body | 200, always the same body (AC-49) | — |

**Config keys added:** `authentication.access_ttl_minutes` (15), `authentication.refresh_ttl_days` (14), `authentication.lockout.threshold` (5), `authentication.lockout.minutes` (15), `authentication.limits.email` (191), `authentication.limits.password` (1024). `hashing.driver=argon2id` and its three Argon factors. **Nothing in configuration names a tenant status, an algorithm or a key.**

**Two execution details confirmed as correct, recorded here so a reviewer does not read either as unplanned drift.**

- **Three indexes beyond `docs/database.md` §4.1's named list** — `signing_keys.status`, `refresh_tokens.family_id`, and `security_events` on `(type, created_at)` and `(user_id, created_at)` — each with the read it serves stated in its docblock. **Agreed.** §4.1 names the columns a table must have, not an exhaustive index list, and every one of these backs a query this task actually issues: the active-key lookup on every login, the whole-family revocation of AC-34, and the security-event reads. An index that serves a stated read is not scope creep; an index with no named reader would be. The docblock requirement is what keeps that distinction auditable, and it is met.
- **`config/hashing.php` casts the three Argon factors to `int`,** diverging from the framework's stock file. **Agreed, and the reason is worth keeping:** `env()` does not cast numerics, so `ARGON_MEMORY` arrived from `phpunit.xml` as the string `"1024"` while the default arrived as integer `65536` — and that mismatch was the first red in step 4. The cast is a correctness fix for a real failure, not a style preference, which is exactly when diverging from a stock config file is justified.

**No migration of existing data.** The master has no production rows; `users.email_lower` is a generated column with no backfill. No escalation trigger is met.

---

## 6. Implementation steps

Each step ends green: `composer check` from `modules/host`, or the named narrower command when a step is explicitly mid-flight. Owners are `dev` unless stated. Criteria in brackets.

- [x] **Step 1 — the dependency and the boundary.** `dev`. Add `firebase/php-jwt ^6.10` to `packages/auth/composer.json`; **superseded — see step 4a: the constraint is now `^7.0`, and the negative control in this step is confirmed to prove symbol reuse only, not value copying (§9).** `composer update flexipos/auth firebase/php-jwt` in `modules/host` (**the `--lock` this once read is invalid with a package selection — see step 4a**). Widen `Package_Auth` to `[Package_Kernel, Package_Contracts]` in `deptrac.yaml` and add the carved-out `Package_Kernel_TenancyStatus` layer. Create `AuthServiceProvider` (empty boot) and register it. **Done when:** `composer check` exits 0 at 857 passed and the library is in `vendor/`. **Negative control:** a scratch file in `packages/auth/src/` importing `Flexipos\Kernel\Tenancy\TenantConnectionPurpose` must make `composer deptrac` exit non-zero; delete it before the step closes. *(AC-51, AC-53; sets up AC-47.)*
- [x] **Step 2 — the two stand-in spikes, before anything is built on them.** `dev`. (a) A throwaway migration proving `$table->string('email_lower')->storedAs('LOWER(email)')` inside `Schema::table()` and a unique index over it both apply on the stand-in through Laravel's SQLite grammar — raw SQLite is already verified, the grammar is not. (b) A throwaway test proving an `inet_pton` value round-trips through a Laravel `binary` column on the stand-in for both `203.0.113.7` and `2001:db8::1`. **Both spikes are deleted at the end of the step**; what survives is a one-line note in each of steps 3 and 7. **Known artefact to design around:** SQLite's SQL `length()` and `typeof()` on a packed address report `1` and `text`, because they truncate at the first NUL — **AC-27 must be asserted on the PHP value after `inet_ntop`, never on `length(ip)` in SQL.** If (a) fails, the fallback is the `LOWER(email) = ?` lookup of §3's rejected list, and `architect` must be told, not improvised around.
- [x] **Step 3 — the five migrations.** `dev`. Write the four tables and the `email_lower` column. **Stated constraint from step 2(a)'s spike — YES, this belongs in the plan.** SQLite refuses `ALTER TABLE … ADD COLUMN … STORED` with *"cannot add a STORED column"* as soon as the table holds **one row**, and accepts it again after a `DELETE`; measured three ways. MySQL has no such restriction, so this is a stand-in-only hazard and **not** a reason to change the design. Two consequences, both binding: (i) migration `…000205` is safe as written because the stand-in migrates into a fresh, empty database, and that ordering is now a **load-bearing property of the stand-in, not an accident** — any future change that seeds `users` before the expand phase breaks it; (ii) **no test may seed `users` and then run the expand migration.** Tests that need both must migrate first and seed second. QA must record the row-count dependency as the reason, so a later failure reads as "the stand-in's engine refuses the ALTER", not as "the generated column is broken". If a future task genuinely needs seed-then-expand on the stand-in, the fallback is the table-rebuild shape (create, copy, swap), not the rejected `LOWER(email) = ?` lookup. **Red first, structurally:** the schema assertions of AC-50 are written in the same commit and are red before the migrations exist, because the tables cannot be described. **Negative control — corrected after execution; the plan named the wrong instrument.** A scratch sixth migration with a `timestamp` column and a bare `decimal` must make **`MigrationTreeDisciplineTest`** red, not `MigrationSourceRulesTest`. Measured: the scratch migration left `MigrationSourceRulesTest` **green at 20 passed / 75 assertions**, while `MigrationTreeDisciplineTest` went **red at 2 failed / 6 passed / 15 assertions**, naming `AC-35(a)` and `AC-35(c)` and the exact master table list. The control is sound; only this sentence was wrong. The cause is in the files themselves: `MigrationSourceRulesTest` scans **fixtures only**, and its own header says the shipped-tree sweep "arrives with the master tree in step 3 … `MigrationTreeDisciplineTest`". `dev`'s call to fix the plan rather than the control is right.

> **Which file is authoritative for the shipped-tree sweep — binding on steps 13 and 14.** For any claim of the form *"the migrations actually shipped in `modules/host/database/migrations/` obey the source rules"*, the instrument is **`MigrationTreeDisciplineTest`**. `MigrationSourceRulesTest` proves the *rule engine* works, against fixtures, and a green run of it says nothing about the shipped tree. Steps 13 and 14 must cite the first, never the second, and the QA report must not present a `MigrationSourceRulesTest` pass as evidence about real migrations.
>
> **This is the second time on this task that a named instrument did not redden where the plan's text said it would** — the first was the Deptrac string-FQCN claim in §9. The pattern is the same error both times: naming an instrument from its name rather than from what it actually collects. The standing rule for the rest of T-009: **before any step cites an instrument as its control, open the file and confirm what it scans.** A control that cannot be shown red is not a control, and a control pointed at the wrong file is worse than none, because it reports success.

**Done when:** `MasterSchemaBuildsTest`, `QaMasterStandInShapeTest`, `MigrationTreeDisciplineTest`, `MigrationSourceRulesTest`, `QaDatetimePrecisionSourceTest` and `QaSchemaSignatureInstrumentTest` all pass **with no new exemption**. *(AC-50, AC-2's stand-in half.)*
- [x] **Step 4a — the JWT major bump (NEW, added after step 1; must close before step 5).** `dev`. Change `packages/auth/composer.json` to `firebase/php-jwt ^7.0` and run **`composer update flexipos/auth firebase/php-jwt`** in `modules/host`. **Corrected after execution:** the form originally written here, `composer update firebase/php-jwt --lock`, is refused by Composer — *"You cannot simultaneously update only a selection of packages and regenerate the lock file metadata."* `--lock` cannot be combined with a package selection, so it is dropped; and the path package `flexipos/auth` must be named too, because the changed constraint lives in **its** `composer.json`, not the host's. This is the same two-package form step 1 used, and it is the correct idiom whenever a constraint inside `packages/*` moves. The `--lock` flag has no place in either command. Verify the three API properties named in Ruling 1's amendment by reading the installed `src/JWT.php`, `src/Key.php` and the package's `composer.json` — the decode signature, the `Key` constructor, and the absence of runtime dependencies. **Done when:** `composer check` exits 0 **with zero delta against whatever the gate stood at when this step began** — not against the 857/11/4508 plan baseline, because step 3 lands first and moves it. No code uses the library yet, so any delta here is a red flag and not progress. **Measured: 866/11/4598 before and after, zero delta, as required.** And `composer audit` no longer reports CVE-2025-45769 — measured: one advisory before, "No security vulnerability advisories found" after. Record both results. **If 7.x cannot be fetched or any API property differs: stop, report to the lead, and take the documented fallback — never a silent re-pin.** *(No AC; it is a dependency hygiene step that protects AC-29's foundation.)*
- [x] **Step 4 — hashing, the clock, and configuration.** `dev`. `config/hashing.php`, `config/authentication.php`, the `phpunit.xml` Argon entries, and the `RunnerClock` singleton binding. **Negative control:** a test asserting that `Hash::make()` returns an `$argon2id$` prefix — and that the same assertion against the stock bcrypt driver is red. *(D-22, NFR security.)*
- [x] **Step 5 — `IssueTokenPair` and `VerifyAccessToken`.** `dev`. The keyring, `TokenClaims`, `RefreshTokenValue`, `KeyPairGenerator`. **Red first:** AC-29's eight refusals are written before the verifier, each with its valid-token control in the same test, and the *first* implementation attempt must be the one that fails them — a verifier stubbed to `return true` is forbidden by the "a guard is never stubbed permissively" rule, so the red phase is "the verifier does not exist and the call throws", not "the verifier allows everything". **Negative control for AC-28:** a token minted in the test with one extra claim must fail the set-equality comparison. **Mask to break:** the library refuses `alg: none` on its own, so a test that only exercises `none` would pass against *our* code doing nothing — AC-29's `kid`-of-a-`retired`-key and `token_type`-wrong cases are **ours**, and each must be shown red against a deliberately permissive keyring before the real one lands. *(AC-28, AC-29, AC-31, AC-32.)*
- [ ] **Step 6 — `TenantSelectionPolicy`.** `dev`. The constant, the three call sites' contract, and the moved-value proof: one test that builds a policy over a different set and watches the list, the selection and the refresh all follow. **Negative control:** a second policy object left unused must *not* change the result — this is what distinguishes "the dependents read the policy" from "the test moved something nobody reads". *(AC-12, AC-14, AC-40b, AC-37.)*
- [ ] **Step 7 — `RecordLoginAttempt`.** `dev`. The one writer of `login_history` and of `security_events`. Written **before** `Login`, so that every later refusal has somewhere to record its reason and AC-24's "persisted in spite of the error" is a property of where the write sits (outside any transaction, before the response is rendered) rather than a retrofit. **Positive control for AC-24:** a handler decorated to throw after the attempt is recorded still leaves the row. *(AC-23, AC-24, AC-25, AC-27.)*
- [ ] **Step 8 — `Login`: the success path and validation.** `dev`. Lookup via `email_lower`, status, hash verify, `last_login_at` / `last_login_ip` / `failed_attempts = 0`, the tenant list and `default_tenant_id` rules, the token pair. **Signing-key resolution happens first, before the lookup** — that is what makes AC-31's "no refresh row, `last_login_at` not updated, not counted toward lockout" structurally true instead of a cleanup path. **Negative control for AC-1:** the no-leak assertion run against a deliberately polluted response fixture must be red. *(AC-1, AC-3, AC-4, AC-5, AC-9, AC-10, AC-11, AC-12, AC-13, AC-14, AC-15, AC-31.)*
- [ ] **Step 9 — `Login`: refusal, the dummy hash, lockout.** `dev`. The generic refusal, the counting-hasher double, the fixed dummy Argon2id digest, the atomic `failed_attempts` increment (a single `UPDATE … SET failed_attempts = failed_attempts + 1`, never read-modify-write — AC-22's [ENGINE] half rests on the shape, and the shape is reviewable in the diff). **Negative controls, both named by the spec:** a hasher double that counts zero for the unknown-email case must turn AC-8 red; AC-16's four-failure case is AC-17's control. **AC-19 needs both sides of the boundary in one dataset** — at `T-1s` refused, at exactly `T` accepted, after `T` accepted. *(AC-6, AC-7, AC-8, AC-16 to AC-21, AC-22 by reading.)*
- [ ] **Step 10 — `SelectTenant`.** `dev`. Code lookup through the master, membership, policy, claims, `last_accessed_at`, history. **The proof that no tenant database is touched:** the master connection's query log contains no `tenant_databases` access **and** `config('database.connections.tenant')` is still the placeholder stanza afterwards — T-003's existing test is the instrument for the second half and must pass unmodified (AC-52). *(AC-39 to AC-44.)*
- [ ] **Step 11 — `RefreshToken` and `Logout`.** `dev`. Rotation inside a family, replay → whole-family revocation + `token_reuse`, user-status revocation (D-13), tenant refusal **without** consuming (D-24), the ignored-field rule, logout idempotence. **Control required inside AC-34's own test:** the newest token of the family works immediately before the replay and is refused after it. *(AC-30, AC-33 to AC-38, AC-49.)*
- [ ] **Step 12 — the HTTP surface and the tenant middleware.** `dev`. Routes, four controllers, the two middleware. `RequireTenantToken` verifies and calls `Tenancy::enter()` in one class. **Negative control for AC-46:** a handler spy that must be called exactly once by a valid token and never by a platform token, a missing token, or an expired one. *(AC-42, AC-45, AC-46, AC-48.)*
- [ ] **Step 13 — QA's cross-cutting proofs.** `qa`. The AC-26 sentinel-capture harness over the three sinks, **with its mandatory positive control** (a sink deliberately containing the sentinel is reported as found); AC-32's runtime column scan over every master table with the same harness; AC-47's behavioural half against T-003's `suspended`-mapping fixture, asserting `TenantDatabaseNotActive` and a never-called handler. **No proof in this step may be a textual search.** *(AC-26, AC-32, AC-45 to AC-47.)*
- [ ] **Step 14 — the gate, the report, and the deferrals.** `qa`, then `dev` for any fix. Full `composer check`, exit 0, with the new counts recorded against the 857/11/4508 baseline. **The per-step progression measured so far, for the report to reconcile against:** step 1 and step 2 857/11/4508 (zero delta, correct for an empty provider and a boundary rule) · step 3 **866/11/4598** (+9 tests, +90 assertions, fully attributed — 85 from the new file and 5 from `MigrationSourceRulesTest` classifying one more path per migration file, measured 69→74) · step 4a **866/11/4598** (zero delta, as that step requires) · step 4 **872/11/4629** (+6, +31, from the new file and nothing else). Every later step must attribute its delta the same way; an unattributed increase is a finding, not a pass. The QA report names the control for every criterion whose own text did not, and lists AC-22, the cross-engine half of AC-50 and the collation half of AC-2 as **NOT VERIFIED — engine hold**, never as passed.

---

## 7. Test strategy

**Unit (inside a slice, dev-owned).** `TokenClaims` set equality. `RefreshTokenValue` length and hash. `EmailAddress` trim/normalise/limits. `IpAddress` both families. `TenantSelectionPolicy` over three different policy objects. `GenericRefusal` identity across constructions.

**Slice tests (dev-owned, mirrored under `packages/auth/tests/Features/`).** One per slice, against the stand-in master migrated by the fixture helper, with the clock fixed. These carry the bulk of AC-1 to AC-44.

**Integration / HTTP (QA-owned, `modules/host/tests/Feature/`).** The four endpoints end to end; the two middleware; AC-45 to AC-47 against T-003's two configuration-array fixture tenants; the AC-26 / AC-32 sink harness; AC-53's gate.

**Which tests prove which ACs** is recorded in the QA report as a table; the spec already names the control for every red-first criterion, and §6 above names the control for every step that adds one.

### Proof grading (every claim in the QA report carries one)

- **Independently proven** — runs on the stand-in and would fail if the behaviour were absent: AC-1 to AC-21, AC-23 to AC-44, AC-49 to AC-53.
- **Corroborating** — true here, but the engine is the real subject: AC-2's lookup (the collation half is the engine's), AC-27 (the column is `VARBINARY(16)` only on the engine), AC-50's column list (`information_schema` is the engine's).
- **Covered by reading** — AC-22's atomic-update shape, and AC-48, which is a statement of accepted behaviour rather than a test.

### The mask family, all four shapes, named where they bite here

1. **An earlier gate absorbs it.** Validation (422) sits in front of every credential rule. A refusal test whose input is also invalid proves nothing. *Remedy — change the input:* AC-9's own control already requires a 191-character email and a 1024-character password to be *accepted as input* and to fail as credentials. Every refusal test uses inputs inside the limits.
2. **A key or constraint absorbs it.** `UNIQUE(refresh_tokens.token_hash)` will refuse a duplicated token even if rotation is broken, and the unique index on `email_lower` will refuse a duplicate even if the lookup is wrong. *Remedy — pin something only the mechanism produces:* rotation is proved by `used_at` on the presented row **and** a new row in the same `family_id`, not by the absence of an error.
3. **A sibling guard refuses with the same exception class.** This is the big one, and D-12 *designs* it: unknown email, wrong password, locked, not-active and soft-deleted all return one byte-identical 401. A test asserting the 401 proves only that *something* refused. *Remedy — pin something only that mechanism produces:* **every refusal test asserts `login_history.failure_reason`**, which is the one place the cause survives, plus the state the cause should have changed (`failed_attempts`, `locked_until`). AC-7's control — flipping the same user back to `active` and watching it succeed — is required for each state separately, not once for the group.
4. **The read that feeds a statement duplicates its own terms.** `SelectTenant` reads the membership and the tenant status, and then updates `tenant_users.last_accessed_at`. If that update re-filters on the same status terms, a broken policy read is invisible: the update simply writes nothing and the refusal path happens to be right for the wrong reason. The same shape appears in the refresh re-check. *Remedy — change the input, which is the stronger of the two here:* the update is written unconditionally on the row the read returned, and the proof is a case where the policy says *selectable* and the row is nonetheless stale, so only the read can decide the outcome. A test that pinned the SQL would be the weaker remedy and is not used.

### Two standing rules this task must not break

- **No proof that is a textual search.** Three were evaded on T-008. AC-26 and AC-32 say so in terms and this plan honours it with runtime capture plus a positive control. AC-47's text-search half is **replaced** by a deptrac rule (§9) — executable, with the negative control in step 1.
- **A printed instruction must be runnable.** T-009 prints no operator instruction. If step 14 adds one (a key-rotation hint, say), the test must *run the command it names*, not assert its text.

---

## 8. If the owner reverses the suspended-tenant reading

`ba` chose **refuse**, which is the safest reading, and the plan is built for it. The three documents genuinely disagree, and one detail sharpens the question rather than settling it: `docs/database.md` §8's Suspend row sets `tenants.status = suspended` **and** `tenant_databases.status = read_only`, and §4.4 says a `read_only` mapping forces the connection read-only and rejects writes at the repository layer rather than returning 503. So the *database* half of the design already contemplates a suspended shop that can read. It is the *access* half (§4.4's 503 list, §5's three selectable statuses, `architecture.md` §5's "refused at resolution") that refuses.

**Cost of the reversal, stated precisely, in two parts.**

*Inside T-009 it is cheap.* One constant gains two values: `TenantSelectionPolicy::SELECTABLE` becomes `trial, active, past_due, suspended, read_only`. Because AC-12 already forces every reader through that one symbol, and step 6's moved-value proof pins that, **no handler changes.** Three criteria change their expected values — AC-12's selectable set (two of nine statuses flip), AC-13's default-tenant cases (a suspended-only user now gets a default), AC-14 (login still 200, but the entry becomes `selectable=true` and a tenant token is issued). D-7 and D-8 are rewritten by `ba`. Estimate: one constant, three test datasets, two decision entries. **Under a day, and reversible again.**

*Outside T-009 it buys nothing yet, and this is the part the owner needs to hear.* A tenant token for a suspended shop would be issued and would then be **refused on the very first request**, because the serving gate accepts exactly `tenant_databases.status = active` (`TenantConnectionPurpose::SERVING_STATUSES`), and that list is deliberately not widenable — not by configuration, not by a test, not by a constructor. Delivering "a suspended shop can read and export" therefore requires a **read-only serving mode in the kernel**: `read_only` added to the serving list, a connection that refuses writes, and a write-rejection layer. That is kernel work of its own and should be its own backlog row; it must not be smuggled into T-009.

**So the answerable form of the question is:** *does a shop that has stopped paying keep read and export access?* If yes, T-009 flips a constant today and a new backlog row delivers the capability. If no, nothing changes and `docs/database.md` §8's Suspend row needs its last sentence corrected.

---

## 9. Judgement on the four findings from the spec round

1. **Token claims — `ba`'s shorter set is CONFIRMED.** `branch_ids`, `employee` beyond the `tenant_users.employee_id` passthrough, and `perm_hash`/`perm_version` all come from the tenant's `employees` and `roles` tables, which do not exist until FP-208. A claim we cannot populate is a claim we must not mint, because FP-208 would then have to change a claim's *meaning* rather than add one, and every issued token would be wrong in the window. D-10 is right. **Two follow-ups, neither for T-009:** `docs/database.md` §5 and `docs/architecture.md` §5 both print the longer claim set and should carry an "added by FP-208" annotation; and FP-208's row should name the claims it owes.
2. **Case-insensitive email — the problem is CONFIRMED, the remedy is upgraded.** A plain SQLite unique index is case-sensitive and the lookup cannot rely on the connection collation; `ba` is right about the divergence. But a lookup-only fix leaves the stand-in without the *uniqueness* half and costs the MySQL index. The ruling is the stored generated `email_lower` column with its own unique index — verified working on SQLite 3.39.2, consistent with T-008's own stored-sentinel convention, and it incidentally makes T-008's AC-8(a) provable on the stand-in. **That is a side effect, not a re-proof**: AC-2's `[ENGINE]` marker stands as written and QA must not claim T-008's criterion.
3. **Per-account lockout — YES, a backlog row now, and `ba` is right to accept the exposure for T-009.** Per-account locking does let a stranger lock a known address on purpose; D-5's refusal to extend a live lock is the mitigation that keeps it from being indefinite, and that is enough for a product with no public login page yet. But "needed before public launch" has to live somewhere a release checklist reads. **Recommendation to the lead** (I do not own `docs/backlog.md`): a row *"Per-IP and per-credential throttling, and CAPTCHA, on the login endpoint — blocks public launch; without it per-account lockout is a denial-of-service against a known email address. Depends on FP-207."* Spec open question 6 can then be closed as decided rather than left for the owner.
4. **The four missing tables — CONFIRMED, and there is a fifth change.** `refresh_tokens`, `signing_keys`, `login_history` and `security_events` are absent from `modules/host/database/migrations/`; all four go through T-008's machinery, `IdentifierColumn` for every ULID reference, `JsonValidity::enforce` for `security_events.detail`, phase `expand`, and no new exemption. The fifth is `users.email_lower` (finding 2). The stored-sentinel convention `ba` names applies to a nullable column inside a unique key — **none of these four has one**, so no sentinel column is needed; the convention is honoured by not inventing a use for it.

### And one more: AC-47 is unachievable as worded

AC-47 asks for the "move the value" proof — *"change the policy's migratable list and authentication behaves identically"*. **That instrument does not exist for the serving list.** `TenantStatusPolicy::accepted()` answers `Serving` from the constant `TenantConnectionPurpose::SERVING_STATUSES`, and its own docblock states that no constructor argument reaches that branch *on purpose*: "the list that decides whether a request is served is not injectable at all. Only the migrating list moves." Moving the *migrating* list proves nothing about serving, and the criterion's second sentence then falls back to "no status list appears in the authentication code", which is exactly the textual search T-008 refused.

**Proposed rewrite for `ba`** (behaviour unchanged, instrument replaced):

> AC-47: Given a valid tenant token for a tenant whose mapping status is not `active` (fixture `suspended`), Then the request is refused by the **existing** gate — the refusal is `Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotActive`, which authentication never constructs — and no handler runs (spy; control: the `active` fixture calls it once). That authentication holds no copy of the tenant-database status list is proved by the **boundary check**: `deptrac.yaml` grants `Package_Auth` the kernel but **not** the `Package_Kernel_TenancyStatus` layer (`TenantConnectionPurpose`, `TenantDatabase`, `TenantStatusPolicy`), so any reference from the auth package to a tenant-database status symbol is a violation with no exemption available. Negative control: a scratch file in `packages/auth/src/` importing one of the three makes `composer deptrac` exit non-zero.

#### Correction (post-implementation, after `ba`'s challenge and two measurements)

An earlier version of this paragraph claimed the boundary check is "stronger than the original" because "a second literal copy written in double quotes — the evasion that defeated T-008's scan — is still reported, because the collector is the type graph and not the characters". **That claim was wrong, and `ba` was right to challenge it.** It has been measured twice independently — by `dev` in `packages/auth/src/`, and by `architect` through a separate Deptrac configuration whose probe files lived outside the repository, against `dev`'s final three-layer ruleset. Both runs agree:

| Shape in a `Flexipos\Auth\*` class | `composer deptrac` |
|---|---|
| A real type reference — `use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;`, a type hint, a constant read | **reported**, naming `Package_Kernel_TenancyStatus` |
| The same FQCN as a string — `"Flexipos\\Kernel\\Tenancy\\TenantConnectionPurpose"`, the single-quoted form, a `[FQCN, 'method']` string callable, `new $className` from a string variable | **not reported**, zero violations |
| A class holding `['active', 'read_only']` as bare words, with an `in_array()` over them | **not reported**, zero violations |

Every collector in `deptrac.yaml` is `type: classLike`, and Deptrac's collector is the resolved *type* graph: it reads `use` statements, type hints, instantiations, `::class`, `extends`/`implements`, attributes and catch types. **It does not resolve class names out of string literals in any position.** So the string-FQCN shape is invisible too — which means AC-47's wording "a double-quoted `use`-equivalent reference is still reported" is false on its own terms, and `architect` has asked the lead to relay that to `ba`. The plan does not edit the spec.

**What the boundary check therefore does and does not prove.** It is strictly stronger than a grep for **symbol reuse**: a reference to one of the three types is reported however it is spelled, aliased, imported or re-exported, with no exemption available. It is **completely blind to value copying**: a hand-written list of status words in the auth package is not a Deptrac dependency at all, and value copying is precisely the evasion the T-008 scan existed to catch. The rule must therefore always be described as *"authentication holds no reference to the tenant-database status types"*, and **never** as *"authentication holds no copy of the serving list"*.

**Where the copied-list residual is actually closed.** `dev`'s reading is correct and this plan adopts it: the residual is not closable by any static instrument available here, so it is closed behaviourally, in two places, and **step 13 must be written against these two and must not lean on the boundary check for this**:

- **Step 13** — the refusal for a non-`active` mapping is `Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotActive`, a type authentication never constructs, plus a handler spy that is never called, with the `active` fixture as the control that calls it once. A private copy of the list in the auth package cannot produce *that* exception from *that* owner, so a copy that had drifted would show up as a wrong refusal type or a wrong spy count.
- **Step 6** — the moved-value shape for `TenantSelectionPolicy`: build the policy over a different set and watch the list, the selection and the refresh all follow, with an unused second policy object as the negative control. That is the instrument that proves a list is *read from one place* rather than duplicated, and it is the only shape in this task that does so.

AC-47's "Evidence and its limit" clause as `ba` finally wrote it is honest about this; it is only this plan's prose that overstated it, and the overstatement is retracted above.

#### Confirmation of three deviations `dev` declared in this area

All three are **CONFIRMED** as better than the plan's text, and the plan is amended to match:

- **(a) The negative-lookahead collector.** `^Flexipos\\Kernel\\(?!Tenancy\\(TenantConnectionPurpose|TenantDatabase|TenantStatusPolicy)$).*` is correct and necessary. A plain `^Flexipos\\Kernel\\.*` leaves the three symbols matched by two layers at once, which is why the kernel's own internals turned into 70 violations. The layers must be disjoint. A `bool` / `must_not` collector would be cleaner YAML, but it breaks `QaToolchainRegistryCoverageTest`, which reads only top-level `classLike` values — and an existing instrument is not bent to make a configuration file prettier. The regex carries the comment explaining why, which it does.
- **(b) The grants.** `Package_Kernel_TenancyStatus` granted to `HostApp`, `HostTests` and `Module_Demo`, and both directions between it and `Package_Kernel`, is right. The carve-out is about **who may read the status types**, and the answer is "everyone who could before, except the auth package". Withholding it from the kernel itself or from the host would be a different, unintended restriction, and the bidirectional edge is what keeps the migration planner and the connection factory legal.
- **(c) No `extra.laravel.providers` in `packages/auth/composer.json`.** Correct, and it matches `TenancyServiceProvider`: registration belongs in `bootstrap/providers.php` so the host decides what boots, which is the whole premise of a module host driven by `MODULES`. Package auto-discovery would take that decision away from the host.

---

## 10. Risks & rollback

| Risk | Likelihood | Handling |
|---|---|---|
| `composer update` needs the network; the composer cache has no `firebase/php-jwt` entry (checked: the directory exists and is empty) | medium | If the install cannot complete, **stop and report to the lead**. Do not hand-roll a JWT silently — `dev` switching design unannounced is the one thing the protocol forbids. `lcobucci/jwt` 13d5e84 *is* in the local cache as a fallback, at the cost of two extra packages |
| Laravel's SQLite grammar rejects the generated column through `Schema::table()` (raw SQLite accepts it; the grammar is unproven) | low | Step 2 spikes it before anything depends on it. Fallback is the rejected `LOWER(email) = ?` lookup, with the index cost accepted and a backlog row; `architect` is told |
| Argon2id at default cost inflates the gate past its 106 s budget | low | Step 4's measured work factors. If a reviewer objects to reduced factors, the answer is the measurement: 0.232 s against 0.0015 s, and the algorithm is unchanged |
| The generic refusal is not byte-identical because a framework middleware adds a varying header | medium | `GenericRefusal` constructs the whole response in one place; AC-6 compares the serialised response, headers included, between two causes **in one test** |
| `firebase/php-jwt` 6.x is permanently inside CVE-2025-45769 (no 6.x patch); `^7.0` needs the network and an unverified decode API | medium | Step 4a bumps and verifies **before** step 5 writes against the API. `composer audit` result recorded both sides of the bump. If the fetch or the verification fails, stay on `v6.11.1` with a backlog row and a QA line — accepted and named, never silent |
| The boundary check is mistaken for proof that no status *list* is copied into authentication | high | §9's correction states the limit explicitly and names the two behavioural instruments that do close it (step 13's `TenantDatabaseNotActive` + never-called spy, step 6's moved-value shape). The vocabulary rule is fixed: "no reference to the status types", never "no copy of the list" |
| A later test seeds `users` before the `email_lower` expand migration and hits SQLite's "cannot add a STORED column" | medium | Step 3 states the constraint and the migrate-then-seed ordering. MySQL is unaffected, so the hazard is stand-in-only and the fallback is a table rebuild, not a design change |
| `Package_Auth: ~` widening is read as boundary erosion | medium | It is a new allowed edge, not an exemption, and `docs/patterns.md` §4.1 grants it explicitly. The carve-out in the same change is the evidence that it was widened deliberately and narrowly |
| A `jti` claim with no store implies revocation we do not have (AC-48 already accepts the window) | low | AC-48 states the accepted behaviour; `jti` is a correlation id here and the plan says so in `TokenClaims`' docblock, so nobody later mistakes it for a revocation list |
| Scope creep into FP-215 (envelope, error catalogue, rate limits) | medium | The four endpoints use the §8 envelope shape and nothing else. Any further API machinery is refused and routed to FP-215 |

**Rollback.** The branch is additive: five migrations, one package, one provider entry, one route file, two middleware, three configuration files, one deptrac widening. Nothing existing is deleted and no T-008 migration is edited. Reverting the branch returns the gate to 857/11/4508. No data exists to migrate and no external system is touched, so no escalation trigger is met.

---

## 11. Questions for the human

**One**, and it is the owner's, not the lead's: **does a shop whose tenant is `suspended` (or `read_only`) keep read and export access?** §8 states the cost of each answer. `ba` has decided *refuse* and logged it as D-8; work proceeds on that reading and is not blocked. The three documents that disagree are `docs/database.md` §8 (Suspend row), `docs/database.md` §5 with §4.4, and `docs/architecture.md` §5.

The other two owner questions `ba` raised (an absolute refresh-family cap, D-21; the 5-failures / 15-minutes figures, D-5) need no human: both are conventional, both are in configuration, both are reversible, and finding 3 above closes question 6 with a backlog row instead.

