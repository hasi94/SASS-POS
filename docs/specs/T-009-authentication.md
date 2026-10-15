# T-009: Authentication - login against the master, and tenant access

Backlog: FP-207. Decisions: `docs/decisions/T-009-authentication.md` (D-1 to D-24, cited below as "D-n"). Branch `ai/T-009-authentication`.

## Problem / goal

FP-207: "Login against the master, credential validation, tenant access resolution, tenant switching, token issue and refresh, login history, lockout."

T-008 shipped the master schema (`users`, `tenants`, `tenant_users`, ...) and the tenant serving gate, but nothing can yet sign in. This task makes a person with an email and password into a verified identity, tells them which shops they may open, lets them open one (or switch to another), and keeps the session alive and revocable. It must be **provable on the SQLite stand-in** while the MySQL engine is on hold; anything that genuinely needs a real engine is marked **[ENGINE]**.

**Division of labour with the existing gate (do not re-decide).** Authentication resolves *which* tenant and *whether this person may ask for it*. The existing serving gate in `Tenancy::enter()` (`tenant_databases.status`, schema floor) decides whether the tenant may be *served*, and runs on every later request (D-9). This task adds no second copy of it.

## Users affected

- Shop owners, managers, and anyone with a platform login (web admin / back-office).
- Owners of several shops (switch without a second account).
- Platform staff (log in the same way; no bypass, D-18).
- **Not affected:** cashiers and waiters with no login. Employees live in the tenant database and sign in to the till with a PIN (architecture decision 3; FP-208). This task must not create, read or require an employee record, and must not treat a platform user and an employee as the same thing.
- Downstream: FP-208 (adds branch and permission claims), FP-209 (terminal tokens), FP-210 (subscription gating).

## Current behaviour

- No login, token or session code exists. `packages/auth` holds only a `composer.json` and a `.gitkeep`; no JWT library is required anywhere.
- `users` exists with the lockout columns (`failed_attempts`, `locked_until`), `status`, `last_login_at`, `last_login_ip`, `must_change_password` (migration `0001_01_01_000000`). `tenants`, `tenant_users` (with nullable `employee_id`) exist.
- **`refresh_tokens`, `signing_keys`, `login_history` and `security_events` do not exist** although `docs/database.md` section 4.1 designs them (read from `modules/host/database/migrations/`). This task adds them (D-11).
- `Tenancy::enter()` already refuses a tenant whose `tenant_databases.status` is not `active`, with a three-state schema floor. It takes a tenant id and nothing else.
- `users.email` is unique and case-insensitive by connection collation (T-008 D-17). On the SQLite stand-in a plain unique index is case-**sensitive**, so lookup behaviour must be case-insensitive by construction, not by luck of the engine (AC-2).

## User stories

- As a shop owner, I want to sign in once with my email and password, so that I can reach all my shops.
- As an owner of three pharmacies, I want to switch from one to another without signing in again, so that I do not need separate accounts.
- As a shop owner, I want my session to continue without retyping my password all day, and to end when I sign out, so that I stay productive and a lost laptop can be cut off.
- As the platform operator, I want repeated wrong passwords to lock an account for a while, so that guessing is not practical.
- As the platform operator, I want a record of every login attempt and every tenant selection, so that I can investigate abuse.
- As a security reviewer, I want the tenant to come only from a signed token, never from what the browser sends, so that one shop can never reach another's data.
- As a member of a suspended shop, I want to be told the shop is unavailable instead of "wrong password", so that I know to contact my provider.

## Acceptance criteria

Conventions for this section. "Response" means the application's HTTP-level result for the operations `POST /auth/login`, `POST /auth/tenant`, `POST /auth/refresh`, `POST /auth/logout` (paths from database.md section 5; the exact transport is `architect`'s). "Generic refusal" is the single response of D-12: **one status code (401) and one body, byte-identical across causes**. Time is always the injectable clock of D-22; no criterion may sleep. Where a criterion asserts an absence, its positive control is named in the same criterion.

### Login: success

- AC-1: Given an `active` user with the correct password who is an `active` member of exactly one `active` tenant, When they log in, Then the response is 200 with (a) a platform access token, (b) a refresh token, (c) a tenant list of one entry carrying `id`, `code`, `name`, `status`, `selectable=true`, `is_default`, and (d) `must_change_password`. The response contains no `password_hash`, no database name, host, port or credential reference (positive control: the same assertion run against a deliberately polluted response fixture is red).
- AC-2: Given a user stored as `Owner@Example.com`, When they log in with `owner@example.COM` or with the address wrapped in spaces, Then login succeeds (case-insensitive, trimmed; T-008 D-17). The lookup must be case-insensitive on the SQLite stand-in itself, via a stored generated `email_lower` column with its own unique index (D-26), so uniqueness is also case-insensitive on the stand-in. **[ENGINE]** the MySQL collation half stands. That the stand-in now also refuses a case-variant duplicate is a **side effect** that makes T-008's AC-8(a) provable here; it is **not** a re-proof of it, and QA must not claim T-008's criterion. Control for the lookup: a user stored `Owner@Example.com` and a second user `other@example.com` are not confused.
- AC-3: Given a successful login, Then `users.last_login_at` is the clock's now, `last_login_ip` holds the caller's address, and `failed_attempts` is 0 even if it was 4 beforehand.
- AC-4: Given a user with `must_change_password = true`, When they log in correctly, Then login succeeds and the response says `must_change_password: true` (D-16). Enforcement is out of scope. Negative control: a user with the flag false gets `false`.
- AC-5: Given an `active` user with **no** `tenant_users` rows, When they log in, Then the response is 200, the tenant list is empty, `default_tenant_id` is null, a platform token is issued and no tenant token is.

### Login: refusal

- AC-6: Given a wrong password for an existing active user, and separately an email that matches no user, When each logs in, Then both return the **generic refusal**, byte-for-byte identical. Positive control: the correct password for the same user returns 200 in the same test, so the comparison is between a refusal and a success, not between two refusals of nothing.
- AC-7: Given the correct password but `users.status` of `invited`, then `disabled`, then `locked`, and a soft-deleted (`deleted_at` set) active user, When each logs in, Then each returns the generic refusal and no token. Control: flipping the same user back to `active` and undeleted succeeds, so each refusal is caused by the state under test.
- AC-8: Given an email that matches no user, When login is attempted, Then the password hasher is invoked exactly once against a fixed dummy hash, the same number of times as for an existing user with a wrong password. Observed through a counting hasher double bound in the test; a double that counts zero for the unknown case is the negative control (it must turn this red).
- AC-9: Given missing `email`, missing or empty `password`, a non-string value for either, an `email` longer than 191 characters, or a `password` longer than 1024 characters, When login is attempted, Then the response is 422, the hasher is not invoked, no `login_history` row is written, and no user's `failed_attempts` changes (D-19). Control: a 191-character email and a 1024-character password are accepted as input (they fail as wrong credentials, not as validation).
- AC-10: Given a request body containing extra fields such as `tenant_id`, `database`, `host`, When login is attempted, Then they have no effect on the outcome or on any token (the token set and the tenant list are identical to a request without them).

### Tenant list and defaults

- AC-11: Given a user with these memberships, one each of: `tenant_users.status` = `active`, `invited`, `suspended`; a soft-deleted `tenant_users` row; and an active membership in a soft-deleted tenant, When they log in, Then the list contains **only** the tenant of the first (active, undeleted) membership. Control: the same fixture with the other four made valid shows five entries.
- AC-12: Given active memberships in tenants whose `tenants.status` is each of `trial`, `active`, `past_due`, `pending`, `provisioning`, `suspended`, `read_only`, `closed`, `archived`, When the user logs in, Then each is listed with its status and `selectable` is true for exactly `trial`, `active`, `past_due` and false for the other six (D-8). The selectable set is read from one place (configuration or a single constant) and moving it moves the result.
- AC-13: Given `default_tenant_id` rules, Then (a) if a selectable membership has `is_default = true`, it is the default; (b) otherwise if exactly one tenant is selectable, that one; (c) otherwise null. Cases: a default that is not selectable falls through to (b)/(c); two selectable and none default gives null; a single selectable among several listed gives that one.
- AC-14: Given a user whose only tenant is `suspended` (D-7), When they log in, Then login is 200, the list has one entry with `selectable=false` and `status=suspended`, `default_tenant_id` is null, and no tenant token exists in the response.
- AC-15: Given a platform-staff user (`is_platform_staff = true`) with no memberships, When they log in, Then they receive a platform token and an empty list, and When they try to select any tenant, Then it is refused as in AC-40(a) (no bypass, D-18).

### Lockout

- AC-16: Given an active user with `failed_attempts = 0`, When four consecutive wrong passwords are submitted, Then the user is not locked (`locked_until` null), `failed_attempts` is 4, and the fifth login with the **correct** password succeeds and resets the counter to 0. This is the negative control for AC-17.
- AC-17: Given the same user, When five consecutive wrong passwords are submitted, Then after the fifth, `locked_until` equals clock-now plus 15 minutes, `failed_attempts` is 0 (D-5), `users.status` is still `active`, and exactly one `security_events` row of type `lockout` exists for the user with the `user_id` and the caller's address.
- AC-18: Given a locked user, When they log in with the **correct** password before `locked_until`, Then they get the generic refusal (indistinguishable from a wrong password, AC-6), `failed_attempts` stays 0 and `locked_until` is unchanged (not extended), and a `login_history` failure row with reason `locked` is written.
- AC-19: Given `locked_until` = T, When the clock is T minus one second, Then the correct password is refused; When the clock is exactly T, Then it is accepted; When it is later than T, Then it is accepted. (Both sides of the boundary are mandatory; a `>` written for `>=` is only visible at exactly T.)
- AC-20: Given a lock that has expired, When five consecutive wrong passwords follow, Then the first four do not lock and the fifth does: the count starts afresh.
- AC-21: Given user A is locked, When user B (different email) logs in correctly, Then B succeeds. Wrong passwords for an unknown email change no `users` row and create none.
- AC-22 **[ENGINE]**: Given N simultaneous wrong passwords for one user, Then `failed_attempts` increments atomically (no lost updates, lock applied once). Cannot be proved on one SQLite connection; the implementation must use an atomic update rather than read-modify-write, shown by (diff) review, and the concurrent proof waits for the engine.

### Login history

- AC-23: Given any login attempt that passes validation, Then exactly one `login_history` row is written, with `principal_type=user`, `email` (trimmed, as submitted), `ip`, `user_agent`, `created_at`, `success`, and `user_id` set when the email matched a user and null when it did not. `failure_reason` is null on success and one of `unknown_user`, `bad_password`, `locked`, `not_active` on failure (tenant-selection reasons: AC-40). `tenant_id` is null for logins.
- AC-24: Given a failed login (error response), Then its history row **is persisted** in spite of the error. Control: the same request wrapped so that it throws after the response is decided still leaves the row.
- AC-25: Given the fifth wrong password, Then both its `bad_password` row and the `security_events` lockout row exist (AC-17).
- AC-26: No password, refresh token or access token appears in `login_history`, `security_events`, the application log, or any exception message produced by these operations. Admissible evidence is **runtime**: capture those three sinks during a run that submits a known sentinel password and returns a known refresh token, and assert the sentinels are absent. **Positive control (mandatory):** the same capture harness, fed a sink that deliberately contains the sentinel, reports it found. This criterion is not to be proved by searching source text.
- AC-27: Given callers at `203.0.113.7` and at `2001:db8::1`, When each logs in, Then `login_history.ip` and `users.last_login_ip` round-trip each address unchanged (one column holds both families). Retention pruning is not implemented here (D-17); QA should not look for it.

### Tokens: what they are

- AC-28: Given a login, Then the platform access token is a signed JWT whose header names a `kid` present in `signing_keys` with status `active`, and whose claims are **exactly** `sub` (user id), `token_type=platform`, `iat`, `exp = iat + 15 minutes`, `jti`, plus issuer/audience if `architect` adds them. Verification uses the public key from `signing_keys`. Admissible: decode and compare the claim-name set for equality with the allowed set (not "contains"), so an extra `database` claim fails. Positive control: a token minted with an extra claim in the test is rejected by the same comparison.
- AC-29: Given verification of an access token, Then it is refused in each of these cases, each with a valid token as control: payload altered after signing; signed by a key not in `signing_keys`; header `alg` = `none`; header `alg` different from the stored key's algorithm; unknown `kid`; `kid` of a `retired` key; `exp` reached (valid at exp minus 1 second, refused at exactly `exp`); `token_type` wrong for the operation (a platform token presented where a tenant token is required, and the reverse). A `retiring` key still verifies but is never used to sign.
- AC-30: Given a user who has logged in (platform family F0) and then selects tenant A, Then the tenant-scoped refresh token belongs to a **new** `family_id` F1 with `tenant_id` = A; F0 is neither used nor revoked and its refresh token still works. Selecting B makes F2. Revoking F1 (logout) leaves F0 and F2 usable.
- AC-31: Given exactly one `active` key, Then every newly issued token is signed by it. Given **no** active key, Then login fails closed with a 5xx-class refusal, no refresh-token row is written and `last_login_at` is not updated. (Operational failure, not a credential failure; it is not counted toward lockout.)
- AC-32: Given a refresh token issued to the client, Then `refresh_tokens.token_hash` is the SHA-256 hex (64 characters) of it, the plaintext value appears in no column of any master table (runtime scan with the positive control of AC-26: the scan finds the *hash* of the value when pointed at it), `principal_type=user`, `principal_id` is the user, `expires_at` is issue time plus 14 days, `used_at` and `revoked_at` are null, and the value has at least 256 bits of randomness (length, not a statistical test).

### Refresh

- AC-33: Given a valid unused platform refresh token, When it is presented to refresh, Then the response carries a new access token and a new refresh token in the **same `family_id`**, the presented row has `used_at` set, and the new row expires 14 days after the clock's now.
- AC-34: Given a refresh token already used, When it is presented again (replay), Then the response is the generic refusal, **every** row in its family gets `revoked_at`, and a `security_events` row of type `token_reuse` is written. Control, in the same test: the *newest* token of that family worked immediately before the replay and is refused after it.
- AC-35: Given a refresh token that is expired (valid at `expires_at` minus one second, refused at exactly `expires_at`), revoked, unknown, empty, or malformed, When presented, Then the generic refusal and no token is issued; nothing is written other than a failure record of the attempt if `architect` chooses to log one.
- AC-36: Given the user's `status` has become anything but `active`, or the user is soft-deleted, When their refresh token is presented, Then it is refused and its family is revoked (D-13; contrast D-24, where a refused tenant leaves the token intact). Control: the same token worked before the status change. A **locked** user (D-5) with a live session is *not* cut off by lockout; lockout governs login only.
- AC-37: Given a **tenant-scoped** refresh token (`refresh_tokens.tenant_id` set), When presented, Then the response is a tenant access token for that same tenant (never another), after re-checking that the membership is still `active` and the tenant's status is selectable. If either fails, the refusal names `tenant_not_servable` or `no_access` as in AC-40, **the refresh token is not consumed or revoked** (D-24), and no token is issued. Control: reinstating the tenant makes the same token work.
- AC-38: Given any refresh request that also carries a `tenant`, `tenant_id`, `database` or `scope` field, Then the field is ignored: the issued token's tenant is the stored row's (or none for a platform token). A refresh token cannot be used as an access token and an access token cannot be used as a refresh token (each refused by the other's operation).

### Tenant selection and switching

- AC-39: Given a valid platform access token and the `code` of a tenant where the user has an `active` membership and `tenants.status` is selectable, When they select it, Then the response is 200 with a tenant access token (`token_type=tenant`, claims exactly `sub`, `tenant` = the tenant's ULID **id** not its code, `employee` only when `tenant_users.employee_id` is non-null, `iat`, `exp = iat + 15 min`, `jti`) and a tenant-scoped refresh token; `tenant_users.last_accessed_at` is now; one `login_history` row has `tenant_id` set and `success=true` (D-15). A membership with `employee_id` null yields a token with **no** `employee` claim; positive control: one with it set yields the claim. The tenant database is not opened and `tenant_databases` is not read (D-9; observable by the tenant connection staying at the placeholder stanza and a query log with no `tenant_databases` access).
- AC-40: Given a selection that must be refused, Then: (a) unknown code, a tenant the user has no membership in, a membership that is `invited`, `suspended` or soft-deleted, and a tenant that exists but is soft-deleted all give **one identical** response (403, body `no_access`), so existence of other people's shops is not disclosed; (b) an `active` membership in a tenant whose status is not selectable gives 403 with reason `tenant_not_servable` and the tenant's status. Each refusal writes a `login_history` failure row with `tenant_id` set when the tenant is known to exist, and issues no token. Control: the valid case of AC-39 in the same test.
- AC-41: Given an empty, whitespace-only, missing or non-string `code`, Then 422; no history row.
- AC-42: Given a **tenant** access token, an expired platform token, or no token, When selection is attempted, Then it is refused (401 for missing/invalid/expired, 403 for a tenant token) (D-4). A client with an expired platform token must refresh it first.
- AC-43: Given a request body to select that also carries `tenant_id`, `database`, `host`, `driver`, Then those are ignored: the token's tenant is the one found by `code` through the master, whatever else was sent. Test: send code A with a `tenant_id` of B and assert the token names A. (ARCH.md section 5: a client tenant identifier is a request, not a fact.)
- AC-44: Given a user who holds a tenant-A token, When they select tenant B, Then a **new** tenant-B token is issued, the A token is unchanged and still verifies until its own `exp`, and the B token names only B. No token ever carries two tenants.

### Using a tenant token on a later request

(Proved against T-003's two SQLite fixture tenants; the engine is not needed.)

- AC-45: Given a request authenticated with a tenant access token for tenant A whose body, query string and headers name tenant B (and a database name), When it is handled, Then the tenant resolved through `Tenancy` is A only, B is never entered, and the request-supplied values are not read for tenancy. Control: the same request without the extra values resolves A.
- AC-46: Given a platform token on a tenant-scoped route, or no token, or an invalid/expired token, Then the request is refused (403 / 401 / 401) and no module handler runs (observable by a handler spy that is never called; control: the valid token calls it once).
- AC-47: Given a valid tenant token for a tenant whose mapping status is not `active` (fixture `suspended`), Then the request is refused by the **existing** gate, the refusal being `Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotActive`, which authentication never constructs, and no handler runs (handler spy; control: the `active` fixture calls it once). That authentication holds no copy of the tenant-database status list is proved by the **boundary check**: `deptrac.yaml` grants `Package_Auth` the kernel but **not** the `Package_Kernel_TenancyStatus` layer (`TenantConnectionPurpose`, `TenantDatabase`, `TenantStatusPolicy`), so any reference from the auth package to one of those symbols is a violation with no exemption available. Negative control: a scratch file in `packages/auth/src/` importing one of the three makes `composer deptrac` exit non-zero. **Evidence and its limit (state this in the QA report):** the evidence is the type graph, an executable result, not a text search. It proves auth *references* none of the three status symbols (a double-quoted `use`-equivalent reference is still reported). It does **not** by itself detect a bare string-literal list such as `['active']` that names no symbol; that residual is covered by the behavioural half (auth issues a tenant token for a `suspended` mapping and the kernel, not auth, refuses it) and by diff review. The criterion is graded accordingly, not as proof of "no copy" in the absolute.
- AC-48: Given the access token window (D-2), a user disabled after the token was issued still passes verification until `exp`. Stated as accepted behaviour so QA does not file it as a defect.

### Logout

- AC-49: Given a valid refresh token, When logout is requested with it, Then its whole family gets `revoked_at` and a following refresh is refused (AC-35). Given an unknown, expired or already-revoked token, Then logout returns the **same** success response as a valid one and changes nothing, so it discloses nothing. Control: another user's family is untouched.

### Schema and structure

- AC-50: Given the master migration set, When migrated on the stand-in, Then `refresh_tokens`, `signing_keys`, `login_history`, `security_events` exist with every column database.md section 4.1 lists, including `UNIQUE(refresh_tokens.token_hash)` and the indexes named there; the suite of T-008's schema checks (naming, datetime precision, identifier columns, the signature instrument) still passes without a new exemption. `login_history.id` and `security_events.id` are `BIGINT AUTO` as designed; ruled consistent (D-28): the identifier rule governs references to another row's identity, not a log's own surrogate key (precedent: `platform_audit_logs`). `login_history.user_id`/`tenant_id` use the ULID identifier column. `users` also gains `email_lower` (stored generated, unique; D-26). **[ENGINE]**: the cross-engine comparison of this schema is FP-194 and stays deferred.
- AC-51 (branch): Each operation (login, refresh, select tenant, logout, verify token) is its own folder of feature code with its own test under the auth package, per `docs/patterns.md` section 3. Evidence is the directory listing in the diff plus `composer deptrac` exiting 0 with **no new exemption**; this is an executable result, not a text search.
- AC-52 (branch): Nothing in authentication writes `database.connections.tenant`; the existing T-003 test that only `Tenancy` writes the stanza passes unmodified. No test file existing before this branch is edited to make it pass (diff).
- AC-53 (branch): `composer check` (lint, static analysis, boundary rules, tests) exits 0 on the branch. Every criterion above marked as red-first has its negative control named in its own text; for the rest, QA names the control in the test report.

## Out of scope

Listed in D-17: MFA (`mfa_factors`), password reset and change, invitation acceptance, impersonation, terminal tokens and the activation code, service tokens, per-IP rate limiting and CAPTCHA, retention pruning of `login_history`, subscription and licence checks (FP-210), `tenant_domains` host-based resolution, branch and permission claims in tokens (FP-208), employee or PIN login, an absolute session-lifetime cap (D-21), the front-end login screen.

## Non-functional requirements

- **Security.** Passwords verified against Argon2id hashes only; no plaintext or token in any log or history (AC-26). Refresh tokens stored hashed, single use, family revocation on replay. Private signing keys are never in the database or repository (`private_key_ref` only, D-23). Tokens never travel in URLs. Unknown-email timing is not distinguishable by hasher work (AC-8); wall-clock timing is not asserted.
- **Determinism.** Time is injectable (D-22); randomness for token values is the platform CSPRNG.
- **Performance.** No figure was given and none is invented. Login is dominated by one Argon2id verification; the access-token path adds no master query beyond what verification of the key needs (`architect` may cache public keys; a cache must not let a retired key verify past its retirement).
- **Observability.** Failure reasons are in `login_history`, not in responses.

## Open questions

None are BLOCKING. Items marked **-> architect** are technical or contradictory points that `ba` cannot rule on and has no channel to ask; they travel via the lead.

Questions 1 to 3 and 6 are answered and moved into decisions D-25 to D-29 (library/algorithm, D-9 confirmation, BIGINT ids, throttling filed as FP-199).

4. Owner, non-blocking: should a `suspended` or `read_only` tenant be selectable so members can read and export (database.md section 8, Suspend row says yes)? Current decision D-8 is no.
5. Owner, non-blocking: should a refresh family have an absolute lifetime cap (D-21)?
6. (Closed.) Lockout stays 5 failures / 15 minutes (D-5). Per-IP throttling is filed as FP-199, marked as blocking public launch (D-29).

## Assumptions

- Figures **taken from the brief** and not re-derived: that T-008's D-17 makes email case-insensitive; that the serving gate refuses a tenant that is not `active`; that the version floor has three states; the FP-207 sentence. **Re-derived from files and found different:** the gate's `active` test is on `tenant_databases.status`, not `tenants.status`; authentication must therefore apply the `tenants.status` rule itself (D-8). The four missing tables were checked in the migrations folder.
- Claim names follow database.md section 5 (D-1, D-10).
- Access 15 minutes, refresh 14 days, 5 failures, 15 minutes lock, 1024-character password cap: conventional, configurable, not owner-specified.
- The response shape and status codes (200/401/403/422) are conventional; `architect` may adjust the transport as long as the generic refusal stays one indistinguishable response.
- The `tenant_users.employee_id` is not verified against the tenant database (the nightly consistency check owns that).
- Soft-deleted rows (`deleted_at`) count as absent everywhere.
- Library `firebase/php-jwt`, algorithm ES256, Argon2id with reduced work factor in tests, and ephemeral test keys behind a resolver (D-25). Measurements (sodium absent, `openssl_pkey_new()` failing without a config path, Argon2id timings, no CI directory) are `architect`'s and the lead's; `ba` re-derived none of them.
- Everything marked **[ENGINE]** is deferred, not skipped: AC-22, the cross-engine half of AC-50, and the MySQL collation half of AC-2.
