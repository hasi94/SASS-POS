# Plan: T-014 Branches, warehouses, terminals

Spec: `docs/specs/T-014-branches-warehouses-terminals.md`. The spec header is the authority for the criterion range (AC-1 to AC-62). Decisions: `docs/decisions/T-014-branches-warehouses-terminals.md` (D-1 to D-30). Backlog row FP-209, which carries the three branch keys T-010 left opaque, T-010 D-39's PIN-reset prerequisite and FP-309. Branch `ai/T-014-branches-warehouses-terminals`, cut from the T-011 tip.

**Gate baseline: not measured by `architect`.** This worktree has no `modules/host/vendor`, so no test can run here. T-011's final figure was exit 0, 1590 passed, 11 skipped. That figure is a reference, not this branch's baseline. Step 0 takes the baseline.

**Path note for the lead.** `CLAUDE.md` says `services/host`. The tree is `modules/host` plus `packages/*`, and every path below uses the real one. Every command in `CLAUDE.md`'s table runs from `modules/host`.

`ENGINE_HOLD` is on. Section 11 is the authority for the `[ENGINE]` set.

---

## 0. Rulings

Each ruling takes the safest reversible option. The ones marked **-> ba** amend or contradict the spec or decisions, and the lead routes them. The rest are design choices the spec left open.

### Ruling 1: one contract migration carries every key, not four files (-> ba: AC-4 wording, NFR "two runs")

I read `PlanMigrationRunHandler::assertContractStepsUnblocked()`. It refuses any plan that applies contract step V while a non-provisioning tenant is below V - 1. With four consecutive contract files at V to V+3, a fleet at the last expand version E needs five runs (`--to=V`, `--to=V+1`, `--to=V+2`, `--to=V+3`, then the top). The spec's NFR and AC-4 promise two. **So all the keys ship in one contract-phase file.** It runs every pre-check before it alters anything, then adds each key. AC-4's "the four foreign-key ones declare `contract`" should read "the foreign-key migration declares `contract`". Nothing else in AC-3 to AC-7 changes, because each key is still added, pre-checked and refused independently. The tree then holds two contract steps: T-011's `settings.updated_by` and this one. `ShippedTenantTreeTest` already handles more than one (D-16, T-011 W-1b). A production fleet at T-011's top needs exactly two runs.

### Ruling 2: the two circular keys ship in that same contract file (-> ba: informational)

`branches.default_location_id -> locations.id` and `terminals.current_device_id -> devices.id` each point at a table created later. MySQL refuses a key onto a table that does not exist yet. The other option is to create both tables in one file and alter the first in the same file. That would break the one-table-per-file identity that `MigrationTreeDisciplineTest` derives its file count from. Adding a key to a table created in an *earlier* file is, by `SourceRules` AC-34, a contract step. **So the expand files create both columns unguarded, and the contract file adds both keys, each with its own pre-check.** The contract file holds six keys in total. Between the two runs the two columns are unguarded. This is harmless: `APP_REQUIRED_SCHEMA_VERSION` is the top, so `Tenancy::enter()` refuses to serve a tenant below it (`TenantSchemaBehind`), and no application write can reach those columns before the key exists.

### Ruling 3: the activation alphabet has 31 characters, not 32 (-> ba: D-17, AC-26)

D-17 excludes `0`, `O`, `1`, `I` and `L` from 36 alphanumerics, which leaves **31**. No 32-character alphabet meets that exclusion. The alphabet is `23456789ABCDEFGHJKMNPQRSTUVWXYZ`. Two groups of four give 31^8, about 2^39.6, which is "40 bits" for D-18's arithmetic. AC-26 should say "31-character".

### Ruling 4: `activated_by` comes from the audit row that issued the code in force (-> ba: AC-29, D-20, AC-1)

AC-29 wants `activated_by` to be "the employee who issued the code in force". AC-1 forbids every column it does not list, and `terminals` has no issuer column. The issuing event already writes exactly one `audit_logs` row in the same transaction (AC-20), with the actor: `terminal_created` or `activation_code_issued`, subject `terminal`. So activation reads the actor of the newest such row for that terminal. The read is deterministic and adds no column. **Cleaner alternative for `ba`:** add `terminals.activation_issued_by` (nullable, FK `employees`), which is an additive expand change. If `ba` picks it, only the read moves.

### Ruling 5: a refresh while the terminal is `inactive` refuses and keeps the family (-> ba: AC-36, last clause)

AC-36 says any refresh while the terminal is not `active` revokes the family. AC-37 says pausing leaves the families intact and resuming makes the same token work again. A paused till refreshes every 15 minutes. If it did so during a pause, AC-36 would revoke its family, and after resuming it could never refresh again. The code is consumed and the terminal is active, so re-activation is impossible, and the only remedy would be a new terminal. **Ruling:** while the terminal is `inactive`, a refresh is refused with the generic refusal and consumes nothing, which is T-009 D-24's treatment of a suspended tenant. A `revoked` terminal, a missing row or a `revoked` credential refuses *and* revokes the family, as AC-36 says.

### Ruling 6: the fingerprint must be 64 lower-case hex characters (-> ba: AC-29, AC-32)

AC-32 stores the fingerprint "exactly", and D-16 compares it against other terminals' devices. If mixed case were accepted, `AB…` and `ab…` would be two devices and D-16 could be bypassed. Folding the case would break "exactly". So an upper-case fingerprint gets the uniform refusal. The till is first-party, and SHA-256 hex is lower-case by convention.

### Ruling 7: the tenant part is `tenants.code`, verified in the master schema (no master table added)

Measured: `modules/host/database/migrations/2026_10_14_000105_create_tenants_table.php` has `code VARCHAR(30) unique`. `docs/database.md` §2 calls it "immutable, human-visible, used in activation codes". **The tenant part is `tenants.code` verbatim.**

- **Issuing.** Issuing reads the code through a new auth contract (section 4). If the stored code does not match `^[A-Z0-9](?:[A-Z0-9-]{0,28}[A-Z0-9])?$`, issuing stops with a server error that is logged at error level and writes nothing.
- **Parsing.** The parser upper-cases the input and splits from the right: the last nine characters are `XXXX-XXXX`, the separator is `-`, and the remainder is the tenant part. Tenant codes may therefore contain hyphens.
- **Lookup.** `tenants where code = ? and deleted_at is null`.
- **Recorded for FP-206:** tenant codes must be stored upper-case (-> lead).

### Ruling 8: refusal vocabulary and HTTP shapes (-> ba: AC-21, AC-46, AC-52 read together)

AC-52 says "`validation` with reason `unknown_branch`", while AC-21 and AC-46 name `unknown_branch` as a refusal of its own. Both are satisfied by one shape:

- `unknown_branch` and `unknown_terminal` return **422 `{"error":"validation","reason":"unknown_branch"}`**.
- `branch_inactive`, `has_active_terminals`, `last_branch`, `location_in_use`, `terminal_not_pending`, `terminal_not_active`, `terminal_revoked` and `scope_not_supported` return **403 `{"error":"<code>"}`**. This follows T-010's convention, where `last_owner` and `role_in_use` are 403.
- `not_found` returns **404 `{"error":"not_found"}`**, which is new; T-010 has none.

In the access package, `Flexipos\Contracts\Access\RefusalReason` gains `branch_inactive` and `unknown_branch`. Its closed set goes from 15 to 17, and `RefusalReasonTest` moves with it. The organization package has its own closed enum (section 3).

### Ruling 9: settings registry semantics (-> ba: D-23, AC-46, AC-48)

Nothing anywhere writes `settings_registry` (measured: no writer in `packages/*/src` or `modules/host/app`). T-014 ships **no setting keys and no declaration mechanism**, which is out of scope. Every settings test registers fixture keys.

- `type` is a closed set: {`string`, `integer`, `number`, `boolean`, `json`}.
- `validation` is a JSON object with the optional keys {`min`, `max`, `max_length`, `in`}.
- A registry row with an unknown `type` or `validation` key **fails closed**: the write is refused `validation` and an error-level log line names the key, never the value.
- `is_set` (AC-47, AC-48) means an override row is in force at some layer above the default.

### Ruling 10: smaller field rules the spec left open

- **(a)** `branches.business_type` is null or one of {`retail`, `restaurant`, `both`}, mirroring `tenants.business_type`. A closed set is the narrower choice, and widening it later is free. **-> ba**
- **(b)** The branch's `branch_store` location takes the branch's code and name. If a location already holds that code, creating the branch is refused `validation` and nothing is written. **-> ba**
- **(c)** `manager_employee_id` must name a live employee (not soft-deleted).
- **(d)** Column lengths:

  | Column | Type |
  |---|---|
  | `name` | VARCHAR(100) |
  | `code` | VARCHAR(10) |
  | `timezone` | VARCHAR(64) |
  | `currency` | CHAR(3) |
  | `phone` | VARCHAR(30) |
  | `email` | VARCHAR(191) |
  | `tax_registration_no` | VARCHAR(50) |
  | `business_type` | VARCHAR(30) |
  | `type` and `status` | VARCHAR(30) |
  | `devices.platform` | VARCHAR(50) |
  | `devices.browser` | VARCHAR(100) |
  | `devices.os` | VARCHAR(100) |
  | `devices.screen` | VARCHAR(30) |

  An over-length device field gets the uniform refusal at activation. Any other over-length field gets `validation`.
- **(e)** `locations.created_by`, `locations.updated_by` and `terminal_credentials.revoked_by` get a key onto `employees`, like `branches.created_by` and `devices.revoked_by`, where the spec states the key. This adds constraints, not columns, so D-10 is untouched.
- **(f)** Pausing an `inactive` terminal, resuming an `active` one, and deactivating or reactivating a location twice are each refused `validation` (AC-14 and AC-37 by symmetry).
- **(g)** Page sizes are clamped, as in T-010 AC-29.

### Ruling 11: the revoke response when the master step fails (AC-39)

The response is HTTP 200 with `{"terminal_id":…, "status":"revoked", "sessions_revoked": false}`, and `true` on full success. The tenant state did change, and the field is the statement AC-39 asks for. A repeated revoke on a `revoked` terminal makes no tenant change and writes no audit row. It retries the master step and returns `sessions_revoked: true` when that succeeds.

### Ruling 12: activation lockout details (AC-31, D-18)

- Counters are anchored to the first failure.
- Keys: `terminal-activation:tenant:<tenants.id>` and `terminal-activation:ip:<sha256(ip)>`.
- An unknown tenant part increments only the address counter (AC-31).
- A refusal *while locked* neither counts nor postpones the unlock. This mirrors the PIN lockout rule in `config/access.php`.
- Success does not reset the counters.
- Time comes from `RunnerClock`, not Laravel's `RateLimiter`. `RateLimiter` and `ArrayStore` read Carbon's clock, which an injected `RunnerClock` cannot move.
- **Operational failures are not the uniform refusal and are not counted.** No signing key, or the master write failing at mint, returns 503 as in T-009. AC-30's list holds only business failures.

### Ruling 13: the PIN rule, exactly (D-8, AC-54 to AC-59)

The order inside `SetPinHandler` is:

1. No actor: `actor_required`, logged at error level, nothing read.
2. Target lookup (missing target: `validation`).
3. If the actor is the target, skip to 6.
4. If the target holds no role with `roles.is_system = true` (soft-deleted roles excluded, as the resolver excludes them), skip to 6.
5. For every **allowing** contribution of the target (`PermHash::contributionsFor()`, which ignores status, so a suspended target counts), drop the ones a target **deny** fully covers. A deny at null covers every scope of that key; a deny at X covers the allow at X only. Every remaining (key, scope) must pass `Escalation::requireHeld($actor, $key, $scope)`. A null scope asks `holdsEverywhere()`, which is AC-59's "a null-scope grant covers every branch". Any failure: `no_escalation`.
6. `PinPolicy::accept()` (weak PIN: `validation`).
7. Write.

An allow at null with a deny at X still requires the actor to hold the key everywhere. That is the conservative reading. Steps 1 to 5 sit before the policy, which is D-8's "authorization first".

### Ruling 14: the stand-in rebuild caveats, and the engine caveat they imply

On SQLite, Laravel 12.69's `SQLiteGrammar::compileAlter()` adds a key by rebuilding the table: create `__temp__`, copy, drop, rename, recreate indexes. I read the source.

- **Generated columns.** The rebuild re-reads each generated column's expression from `sqlite_master` with `SQLiteProcessor`'s regex. It preserves `storedAs`, the primary key (composite included), the indexes and the existing keys. It **never preserves CHECK constraints**. That is harmless here, because `JsonValidity::enforce()` adds none on SQLite.
- **Proved and unproved.** T-010 step 3b and T-011 AC-15 proved the rebuild on `settings` (one `COALESCE` sentinel). **Not yet proved:**
  - `employee_roles`, `employee_permissions` and `number_sequences` (the same sentinel);
  - `employee_branches` (a `CASE WHEN … END` stored column **and** a composite primary key);
  - the rebuild of a **parent** table (`branches`, `terminals`), whose children's keys must survive the drop and rename.

  **Step 1 measures all of these before anything depends on them.**
- **No transaction.** The grammar suspends `foreign_keys` around the rebuild, never runs `foreign_key_check`, and no SQLite migration is transactional (T-011 Ruling 7). So **the pre-check is the only data guard on the stand-in.** A file that fails halfway leaves earlier keys in place. That is why every key is guarded by "add only if absent" (section 4), so a retry is safe on both engines.
- **SQLite transaction trap.** `PRAGMA foreign_keys` is a no-op inside an open transaction. Run inside a test transaction, `drop table "branches"` would run with keys on, and its implicit delete would be refused by the children. The migration must never run inside a transaction on SQLite. The migrator never wraps it; tests must not either.
- **Engine caveat ([ENGINE], AC-6).** MySQL 8 and MariaDB refuse `ON DELETE SET NULL`, `SET DEFAULT` and `ON UPDATE CASCADE` on a foreign key whose column is the **base column of a stored generated column**. `branch_id` is the base of `branch_id_key` in `employee_roles`, `employee_permissions` and `number_sequences`. D-4's restrict is the only legal choice there, so the contract file declares **no** referential action. AC-8's source test asserts the absence of `onDelete`, `cascadeOnDelete`, `nullOnDelete` and `restrictOnDelete`, so nobody "helpfully" adds SET NULL later. On an engine, adding the key copies the table under a write lock. These are small configuration tables, and like T-011 nothing claims the shop must be offline.

### Ruling 15: no settings cache and no resolver memo

This follows T-010 Ruling 5. AC-49 is satisfied structurally. Its two controls (a cache keyed without the tenant, and a cache not invalidated on write) are test doubles shown to give the wrong answer, so the criterion stays falsifiable.

---

## 1. Approach summary

1. **A new path package, `packages/organization`** (namespace `Flexipos\Organization`, the architecture's "Organization" module). It holds the branch, location, terminal, device and settings slices. Like `packages/access` (T-010 §2), it is a package rather than a `modules/*` module, because every later module reads branches through a contract.
2. **Cross-package questions go through `packages/contracts` only**, so no new edge between packages is allowed:
   - access asks organization whether a branch exists and is active;
   - auth asks organization to activate a terminal and whether it is still active;
   - organization asks auth to revoke a terminal's master sessions and for the tenant code;
   - organization writes audit rows, `access_log` rows and checks a manager through access-published contracts. That keeps the single writers of `audit_logs` and `access_log`, and organization never queries an access table (T-010 AC-11's stated limit).
3. **Tenant migrations.** Five expand files, one table each, then one contract file with six keys (Rulings 1 and 2). All live in `modules/host/database/migrations-tenant/`, sorted after T-011's file.
4. **Terminal tokens** are a third token type in `TokenClaims`, minted by `IssueTokenPairHandler` with `principal_type = terminal`.
   - **Activation** is an auth slice. It writes the tenant side, then the master refresh row, then commits the tenant transaction.
   - **Every terminal request** checks the tenant database (`RequireTerminalToken`).
   - **Revocation** is tenant first, then master. It is retryable and idempotent.
5. **The PIN rule** is a reorder of `SetPinHandler` plus one new `Escalation` method (Ruling 13).
6. **Time** is `RunnerClock`. Hashing is the framework hasher. **No new dependency.**

## 2. Alternatives rejected

- **Four contract files.** Five runs against a behind fleet (Ruling 1).
- **One file creating `branches` and `locations` together.** It breaks the one-table-per-file identity `MigrationTreeDisciplineTest` derives from, and buys nothing over Ruling 2.
- **Putting the branch, terminal and settings tables in `packages/access`.** Simpler wiring, but access becomes the whole Identity *and* Organization module, against `docs/architecture.md` §3.
- **Organization querying `employees`, `audit_logs` writers or the master `tenants` table directly.** These are table names in strings, which deptrac cannot see (memory: deptrac proves symbol reuse only), and they would break T-010 AC-11's stated boundary. Contracts are used instead.
- **Minting the master token before the tenant transaction.** A refused activation would then have written a refresh row, which AC-30 forbids.
- **A unique "active fingerprint" generated column on `devices`.** It would close the concurrent same-fingerprint race, but AC-1 forbids the column. Recorded as risk 6.
- **Laravel `RateLimiter`.** Its clock is not `RunnerClock` (Ruling 12).
- **HMAC instead of plain SHA-256 for the code.** D-17 says SHA-256. Recorded as risk 7.

---

## 3. Files to change or create

`dev` writes every file. Files named `Qa*` are `qa`-accountable: `qa` adopts or rewrites them and runs step 17's mutations. The list is indicative for `Support/` classes. A class that becomes the single home of a rule two slices share is the pattern working (T-010 §3).

### `packages/contracts/src` (new symbols)

| Path | What |
|---|---|
| `Organization/BranchDirectory.php` + `Organization/BranchState.php` | `state(string $branchId): BranchState` (`Unknown`, `Active`, `Inactive`). The id is compared exactly; the caller folds it first. Consumer: access |
| `Organization/TerminalAuthority.php` | `activeBranchOf(string $terminalId): ?string`. Non-null only when the terminal row exists, is not soft-deleted, its `status` is `active` and its credential's `status` is `active`. Also `recordSeen(string $terminalId): void`. Consumers: `RequireTerminalToken`, terminal refresh |
| `Organization/TerminalActivation.php` + `ActivationAttempt.php`, `ActivatedTerminal.php`, `TerminalActivationRefused.php` | `activate(ActivationAttempt $attempt, callable $issueInsideTransaction): mixed`. The callable runs **inside** the tenant transaction after every tenant write, and its return value is returned (section 4). The refusal carries a closed reason class for logging only |
| `Auth/TerminalSessions.php` | `tenantPartFor(string $tenantId): string` (Ruling 7) and `revokeAll(string $tenantId, string $terminalId): int`. Implemented in auth, master side |
| `Access/EmployeeDirectory.php` | `isLive(string $employeeId): bool`. Consumer: locations (`manager_employee_id`) |
| `Access/TerminalLoginLog.php` | `record(string $terminalId, string $branchId, ?string $ip): void`, which writes one `access_log` row of type `terminal_login` |
| `Audit/AuditTrail.php` | `record(string $module, ?string $actorId, string $action, string $subjectType, string $subjectId, ?array $before, ?array $after, ?string $branchId = null): void`. Implemented by access's `AuditWriter`, the one writer (T-010 D-20) |
| `Access/RefusalReason.php` *(change)* | gains `BranchInactive = 'branch_inactive'` and `UnknownBranch = 'unknown_branch'` (Ruling 8) |

### `packages/organization` (new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/organization`, psr-4 `Flexipos\Organization\`, requires `flexipos/contracts` |
| `src/OrganizationServiceProvider.php` | implements `DeclaresPermissions`; binds `BranchDirectory`, `TerminalAuthority` and `TerminalActivation` to the slices below; loads `routes/api.php` |
| `src/Registry/OrganizationPermissions.php` | the eight keys as constants plus declarations, all `is_branch_scoped = false` (D-12) |
| `src/Exceptions/OrganizationRefused.php` + `src/Support/OrganizationRefusal.php` | closed enum: `validation`, `not_found`, `unknown_branch`, `unknown_terminal`, `branch_inactive`, `has_active_terminals`, `last_branch`, `location_in_use`, `terminal_not_pending`, `terminal_not_active`, `terminal_revoked`, `scope_not_supported`, `actor_required` |
| `src/Support/TenantConnection.php` | mirrors access's class: same key, `tenancy.connection`. A third home already exists in the kernel; follow-up row (section 10) |
| `src/Support/Identifier.php` | `fold(string): string` (upper-case, then 26-character ULID or `validation`). The one fold for AC-61 in this package |
| `src/Support/OrgCode.php` | D-13's code rule: trim, upper-case, `^[A-Z0-9-]{1,10}$` |
| `src/Support/ActivationCode.php` | generate (31-character alphabet, `random_int`), `hashOf(secret)` (SHA-256 hex of the eight characters, no hyphen), `format(tenantPart, secret)`. Parsing lives in auth's activation slice; this class exposes the alphabet and the secret pattern as constants so both read one definition |
| `src/Support/{LocationType,TerminalStatus,BranchLookup,LocationLookup,TerminalLookup,SettingsRules,SettingsResolver,PageSize}.php` | the closed sets (D-11, D-15), the row lookups (soft-deleted excluded, `not_found`), Ruling 9's rules, and the resolver (AC-44) |
| `src/Support/DatabaseBranchDirectory.php`, `DatabaseTerminalAuthority.php` | the two contract implementations, tenant reads only |
| `src/Features/{CreateBranch,UpdateBranch,GetBranch,ListBranches,ChangeBranchStatus}/` | section B |
| `src/Features/{CreateWarehouse,UpdateLocation,GetLocation,ListLocations,ChangeLocationStatus}/` | section C. `branch_store` is created only inside `CreateBranch` |
| `src/Features/{CreateTerminal,ReissueActivationCode,UpdateTerminal,GetTerminal,ListTerminals,ChangeTerminalStatus,RevokeTerminal,ListTerminalDevices,ActivateTerminal}/` | section D. `ActivateTerminalHandler` implements `TerminalActivation` |
| `src/Features/{SetSetting,ClearSetting,ListEffectiveSettings,ListTerminalSettings}/` | section E |
| `routes/api.php` | admin routes (section 4) and `GET /api/v1/terminal/settings` |
| `tests/**` | mirror tree, namespaced `Flexipos\Organization\Tests\…`. `tests/Support/OrganizationSeed.php` seeds branches, locations, terminals and registry keys, and may call `AccessSeed` and `AccessTenantFixture` (deptrac below) |

### `packages/auth` (change)

| Path | What |
|---|---|
| `src/Tokens/TokenClaims.php` | `TYPE_TERMINAL = 'terminal'` added to `TYPES`; `terminal(string $terminalId, string $tenantId, string $branchId, string $jti, int $iat, int $ttl)` builds `{sub, token_type, iat, exp, jti, tenant, terminal_id, branch_ids:[branch]}`; `allowedNames()` and `isExactly()` cover it |
| `src/Features/IssueTokenPair/{IssueTokenPair,IssueTokenPairHandler}.php` | `?string $branchId` for terminal pairs only (the constructor refuses it elsewhere, and refuses a terminal pair without `tenantId` and `branchId` or with `employeeClaims`); `principal_type` is `terminal` for that type. `PRINCIPAL_TYPE = 'user'` stays (the agreement pin in `RecordLoginAttemptTest` is untouched), and `PRINCIPAL_TYPE_TERMINAL` is added |
| `src/Features/RefreshToken/RefreshTokenHandler.php` | the lookup gains `->where('principal_type', IssueTokenPairHandler::PRINCIPAL_TYPE)`. A terminal token presented here is "unknown": refused, nothing revoked. Today it would be treated as a user, which revokes its family |
| `src/Features/ActivateTerminal/{ActivateTerminal,ActivateTerminalHandler,ActivateTerminalResult}.php` | section 4's order |
| `src/Features/RefreshTerminalToken/{…}.php` | AC-36 with Ruling 5 |
| `src/Features/RevokeTerminalSessions/{…}.php` + `src/Tenancy/MasterTerminalSessions.php` | the `TerminalSessions` implementation |
| `src/Support/ActivationThrottle.php` | Ruling 12. Publishes `tenantKey()` and `addressKey()` so the mis-keyed control can be built |
| `src/AuthServiceProvider.php` | binds `TerminalSessions` |
| `routes/api.php` | a second group `api/v1/terminal`: `POST /activate`, `POST /refresh`. No middleware; the body is the only input channel |

### `packages/access` (change)

| Path | What |
|---|---|
| `src/Support/BranchScope.php` | `requireWellFormed()` folds to upper case (FP-309) and returns the folded value. New static `requireUsable(BranchDirectory $directory, ?string $branchId)`: null passes, `Unknown` is `unknown_branch`, `Inactive` is `branch_inactive`. The docblock's "the existence check belongs here" is now true |
| `src/Features/{AttachBranch,AssignRole,SetEmployeePermission}/*Handler.php` | inject `BranchDirectory`; call `requireUsable()` **immediately before** `requireAttached()`, or before the "already attached" read in AttachBranch. So `unknown_branch` precedes `not_attached`, and an allow or a deny at an inactive branch is refused. Detach, remove and clear never call it (D-6) |
| `src/Exceptions/AccessRefused.php` | `unknownBranch()`, `branchInactive()` |
| `src/Support/AuditWriter.php` | implements `AuditTrail::record()` with a `$module` argument; `write()` delegates with `'access'`, so every T-010 call site is unchanged |
| `src/Support/AccessLogWriter.php` | `TYPE_TERMINAL_LOGIN` joins the closed set (amends T-010 D-21); `terminalLogin()` writes `terminal_id` and `branch_id` with `employee_id` null; a small adapter implements `TerminalLoginLog` |
| `src/Support/DatabaseEmployeeDirectory.php` | `EmployeeDirectory` |
| `src/Support/Escalation.php` | `requireHoldsWhatTargetHolds(Connection, string $actorId, string $targetId)`: Ruling 13 step 5 |
| `src/Features/SetPin/SetPinHandler.php` | Ruling 13's order |
| `src/AccessServiceProvider.php` | binds the three new contracts |
| `src/Support/SystemRole.php` *(change, T-010 file; recorded after QA BUG-1)* | step 3: gains `isHeldBy()`, which Ruling 13 (D-44) reads to decide whether a PIN subject holds a system role. Additive; no existing caller changes |
| `src/Resolver/DatabasePermissionResolver.php` *(change, T-010 file; recorded after QA BUG-1)* | AC-61 access half (steps 5 and 16; landed in the step 15-16 checkpoint): `decide()` folds the branch id to upper case before it is compared. Additive; the four refusals and their order are unchanged |

### Host, `modules/host`

| Path | What |
|---|---|
| `app/Http/Controllers/Organization/*.php` | one thin invokable per routed operation. Input is read from the body only, through `AccessInput` (`text`, `optionalText`, `flag`, `fields`, `pageSize`) |
| `app/Http/Controllers/Terminal/{ActivateTerminal,RefreshTerminalToken,GetTerminalSettings}Controller.php` | the activation controller refuses (uniformly) if the query string has `code` or `activation_code`, and reads the body only |
| `app/Http/Middleware/RequireTerminalToken.php` | section 4 |
| `app/Http/Responses/OrganizationRefusals.php` *(new)*, `AccessRefusals.php` *(change)* | Ruling 8's shapes |
| `app/Http/Responses/AuthRefusals.php` *(change, T-009 file; recorded after QA BUG-1)* | step 11: gains `ACTIVATION_UNAVAILABLE` and `activationUnavailable()` for Ruling 12's 503. Additive; existing refusals unchanged |
| `config/organization.php` *(new)* | page size 50, max 100, activation lifetime 24 hours. Numbers only, as `config/access.php` does |
| `config/authentication.php` *(change)* | `terminal_activation.max_failures` 10, `window_minutes` 15 |
| `bootstrap/providers.php`, `composer.json`, `composer.lock` | add `OrganizationServiceProvider`, require `flexipos/organization`, `autoload-dev` `Flexipos\Organization\Tests\` → `../../packages/organization/tests/`. Run `composer update flexipos/organization`, never with `--lock` |
| `deptrac.yaml`, `phpstan.neon.dist` | section 5 |
| `database/migrations-tenant/2026_10_22_000501_create_branches_table.php` … `000505_create_terminal_credentials_table.php`, `000506_add_branch_foreign_keys.php` | section 4. Names may move, but must sort after `2026_10_21_000401_…` and contain no `fx` token |
| `database/catalogue/tenant_schema_versions.json` | `php artisan schema:publish --write-fixture` **only with the flag**. Without it the command publishes to the master named by the shell's `.env` (T-011 §3) |
| `.env.example` | `APP_REQUIRED_SCHEMA_VERSION` follows the catalogue |

---

## 4. Data and API changes

### Expand files (one table each; every ULID through `IdentifierColumn`, every instant `dateTime(…, 3)`, every closed set `string(…, 30)`, no `tenant_id`, no `down()`)

1. **`branches`**:
   - Columns are the spec's list. `default_location_id` is a nullable ULID, unguarded here.
   - Keys: unique(`code`); `created_by` and `updated_by` onto `employees`.
   - `JsonValidity::enforce('branches', 'address')`.
2. **`locations`**:
   - Columns are the spec's list.
   - Keys: `branch_id` nullable onto `branches`; unique(`code`); `manager_employee_id`, `created_by` and `updated_by` onto `employees`; index(`branch_id`, `type`).
   - JSON check on `address`.
3. **`terminals`**:
   - Columns are the spec's list.
   - Keys: `branch_id` onto `branches`; `location_id` onto `locations`; unique(`branch_id`, `code`); index(`activation_code_hash`).
   - `current_device_id` is unguarded here.
4. **`devices`**:
   - Columns are the spec's list.
   - Keys: `terminal_id` onto `terminals`; `revoked_by` onto `employees`; index(`fingerprint`); index(`terminal_id`, `registered_at`).
5. **`terminal_credentials`**:
   - `terminal_id` primary key onto `terminals`.
   - The spec's columns and nothing else: **no** `created_at` or `updated_at`, because the spec lists none.
   - `activated_by` and `revoked_by` onto `employees`.

None of the five has a `Schema::table` block (AC-34). Each docblock says which keys it leaves to the contract file.

### The contract file (`@phase contract`; shape illustrative, `dev` owns the text)

```php
// The six keys, in the order the rebuilds run. Restrict only (Ruling 14).
private const KEYS = [
    ['number_sequences', 'branch_id', 'branches', 'id'],
    ['employee_roles', 'branch_id', 'branches', 'id'],
    ['employee_permissions', 'branch_id', 'branches', 'id'],
    ['employee_branches', 'branch_id', 'branches', 'id'],
    ['branches', 'default_location_id', 'locations', 'id'],
    ['terminals', 'current_device_id', 'devices', 'id'],
];

public function up(): void
{
    $connection = Schema::getConnection();
    $offending = [];
    foreach (self::KEYS as [$table, $column, $parent, $parentColumn]) {   // every pre-check FIRST
        $count = $connection->table($table)->whereNotNull($column)
            ->whereNotExists(fn (Builder $q) => $q->from($parent)->whereColumn("$parent.$parentColumn", "$table.$column"))
            ->count();
        if ($count > 0) { $offending[] = sprintf('%s.%s: %d row(s)', $table, $column, $count); }
    }
    if ($offending !== []) {
        throw new RuntimeException('… '.implode('; ', $offending).' … Nothing was changed. …');  // counts, never a value
    }
    foreach (self::KEYS as [$table, $column, $parent, $parentColumn]) {
        if ($this->hasKey($table, $column)) { continue; }                // retry-safe (Ruling 14)
        Schema::table($table, fn (Blueprint $t) => $t->foreign($column)->references($parentColumn)->on($parent));
    }
}
// hasKey(): Schema::getForeignKeys($table) contains one whose columns === [$column].
```

Checks `dev` makes before step 6 closes, each of which could fail quietly:

- **(i)** The catalogue row is `contract` and **`is_destructive: false`**. The code contains no `->drop…(`, `->rename…(` or `truncate`.
- **(ii)** `SourceRules::inspect()` is empty with no exemption.
- **(iii)** No driver token and no referential action.
- **(iv)** The comparison is exact (AC-5's lower-case control).
- **(v)** The docblock says where each key was delivered and cites T-010 D-1, T-008 D-5 and T-014 D-2. It names the four columns left unguarded and the two-run requirement in words, with no version number (T-011 D-6). **No shipped file's docblock is edited (AC-8).**

### Run shape

The catalogue grows by six. Its top is a contract step, the second in the tree.

- **Fleet at T-011's top:** two runs, `--to=` the last expand version, then the top. The planner prints the remedy.
- **Fleet at 0:** three runs.
- **`provisioning`:** does not block, so FP-206 still provisions in one run.

### Endpoints (`api` group, invokable controllers, prefix `api/v1/organization` unless stated)

Every admin route carries `RequireTenantToken` plus exactly one `RequireTenantPermission:<key>`.

| Method and path | Key |
|---|---|
| GET `/branches`, GET `/branches/{branch}` | `branches.view` |
| POST `/branches`, PATCH `/branches/{branch}`, PUT `/branches/{branch}/status` (`{"is_active": bool}`) | `branches.manage` |
| GET `/locations`, GET `/locations/{location}` | `locations.view` |
| POST `/locations` (warehouse only), PATCH `/locations/{location}`, PUT `/locations/{location}/status` | `locations.manage` |
| GET `/terminals`, GET `/terminals/{terminal}`, GET `/terminals/{terminal}/devices` | `terminals.view` |
| POST `/terminals`, PATCH `/terminals/{terminal}`, POST `/terminals/{terminal}/activation-code`, PUT `/terminals/{terminal}/status` (`{"status":"inactive"\|"active"}`), POST `/terminals/{terminal}/revoke` | `terminals.manage` |
| GET `/settings?scope_type=&scope_id=` | `settings.view` |
| PUT `/settings` (body `{key, scope_type, scope_id, value}`), POST `/settings/clear` (body `{key, scope_type, scope_id}`) | `settings.manage` |
| POST `/api/v1/terminal/activate`, POST `/api/v1/terminal/refresh` | public, body only |
| GET `/api/v1/terminal/settings` | `RequireTerminalToken` |

- **No DELETE** on a branch, location, terminal or device (AC-17, AC-32, AC-40).
- **No route** moves a `revoked` terminal (AC-40).
- **No route** verifies a PIN (AC-60).
- `PUT /settings` refuses `validation` if the query string carries `value` (AC-47).

### Activation, in order (auth `ActivateTerminalHandler`)

1. `$keyring->signingKey()` comes first, so an operational failure judges and counts nothing (T-009 AC-31's shape).
2. **Shape check.** The code is a string of at most 64 characters, parsed per Ruling 7. The fingerprint matches `^[0-9a-f]{64}$`. Each device field is null or a string within its cap. Failure: refuse, and count the **address** only.
3. If the address is locked: refuse (not counted, Ruling 12).
4. Resolve `tenants.code`. If unknown: refuse, count the address.
5. If the tenant is locked: refuse.
6. If `TenantSelectionPolicy::permits(status)` is false: refuse, count both.
7. `Tenancy::enter($tenantId)`. Any `TenancyException`: refuse, count both.
8. `TerminalActivation::activate($attempt, $issue)`. Inside one tenant transaction:
   1. lock the terminal row `where activation_code_hash = ? and status = 'pending'` `for update`;
   2. refuse if expired (`now >= activation_expires_at`), if the branch is not active, or if the fingerprint is the device of another terminal with `revoked_at` null;
   3. insert the device;
   4. update the terminal conditionally (`where status = 'pending' and activation_code_hash = ?`, exactly one row): set `active`, `current_device_id`, `last_seen_at`, and clear the hash and expiry;
   5. insert the credential (`active`, `activated_at`, `activated_by` per Ruling 4);
   6. `TerminalLoginLog::record()`;
   7. **call `$issue`**, which mints the pair with a **new family** on master;
   8. commit.

   A refusal throws before any write. If `$issue` throws, the tenant transaction rolls back and nothing is left in either database. If the commit fails after the mint, a refresh row is left for a still-`pending` terminal, which is harmless: the refresh is refused by `TerminalAuthority`.
9. Leave the tenancy. Respond `{terminal_id, branch_id, access_token, refresh_token}`.

**Every refusal** is `AuthRefusals::credentials()`: byte-identical, and **no `Retry-After` header**. One `Log::info` line carries the closed reason class and the tenant id if resolved, never the code or the tenant part.

### Request-time check (`RequireTerminalToken`)

1. Bearer token; verifier with `TYPE_TERMINAL`. Wrong type: 403, as in `RequireTenantToken`. Invalid: 401.
2. `Tenancy::enter($claims['tenant'])`.
3. `TerminalAuthority::activeBranchOf($claims['terminal_id'])` must equal `$claims['branch_ids'][0]`. Otherwise 401 generic, with no reason given.
4. Run the route, then `leave()` in `finally`. There is no write per request.

### Terminal refresh (auth)

1. Lookup `where token_hash = ? and principal_type = 'terminal'`. Unknown or revoked: refuse.
2. Used: replay. Revoke the family, write `security_events` `token_reuse` with `user_id` null, `tenant_id`, and `detail` `{family_id, revoked_rows, terminal_id}` (no FK on `user_id`, measured).
3. Expired: refuse.
4. Tenant row missing or not selectable: refuse, consume nothing.
5. Enter the tenancy. `activeBranchOf()` null and the terminal `inactive`: refuse, keep the family (Ruling 5). Otherwise null: revoke the family and refuse.
6. `recordSeen()` (tenant: the terminal's and the device's `last_seen_at`). This happens **before** consumption, so a later failure cannot strand a client whose token was consumed.
7. Conditional `used_at`, then mint in the **same family**.

### Revocation (organization `RevokeTerminalHandler`)

1. Tenant transaction, row locked `for update`.
   - Already `revoked`: no change, no audit.
   - Otherwise: set the status `revoked`; set the credential (if any) to `revoked` with `revoked_at` and `revoked_by`; set `revoked_at` and `revoked_by` on every device where they are null; clear the hash and expiry; write one audit row. Commit.
2. **Then**, outside the transaction, `TerminalSessions::revokeAll($tenantId, $terminalId)`: an UPDATE of `refresh_tokens` where `principal_type = 'terminal'`, `principal_id`, `tenant_id` and `revoked_at` is null. It is idempotent.
3. If step 2 throws: catch it, log the class with no secret, and return `sessions_revoked: false` (Ruling 11).

The tenant side is authoritative, so AC-34 refuses the token from the commit in step 1 onward.

### Settings resolution

For terminal T at branch X, the first present of:

1. (`terminal`, T)
2. (`branch`, X)
3. (`company`, null)
4. the registry `default_value`

Rows of scope `employee` are never read. Branch scope uses layers 2 to 4; company scope uses 3 and 4. A write takes the existing row for (scope type, scope id, key) `for update`, then updates it or inserts one; the sentinel unique key backs it. A secret key's audit before and after values are `{"is_set": bool}`.

### Master schema

Unchanged; no master migration. **Writes to master** by this task: `refresh_tokens` rows of type `terminal`, and `security_events` `token_reuse` rows for terminals.

---

## 5. Boundary (deptrac and PHPStan) impact

- **Paths.** `deptrac.yaml` and `phpstan.neon.dist` each add `../../packages/organization/src` and `../../packages/organization/tests`.
- **Layers.**
  - `Package_Organization`: `^Flexipos\\Organization\\(?!Tests\\).*`, ruleset `[Package_Kernel, Package_Contracts]`.
  - `Package_Organization_Tests`: `^Flexipos\\Organization\\Tests\\.*`, ruleset `[Package_Organization, Package_Access, Package_Contracts, Package_Kernel]`. The tests seed employees through `AccessSeed` and `AccessTenantFixture`.

  This is a **stated new edge for test code only**; `src` stays bound to kernel and contracts. It is the same argument T-010 made for `HostTests`.
- **Grants.**
  - `HostApp` gains `Package_Organization`.
  - `HostTests` gains `Package_Organization` and `Package_Organization_Tests`.
  - **`Package_Auth` and `Package_Access` gain nothing.** They reach organization only through `Flexipos\Contracts\*`.
  - **`Module_Demo` gains nothing.** That omission is the instrument: a demo class importing `Flexipos\Organization\Features\CreateBranch\CreateBranchHandler` must fail, and the same class importing `Flexipos\Contracts\Organization\BranchDirectory` must pass (step 2's pair).
- **`QaToolchainRegistryCoverageTest`.** Its shared-package literal gains `'organization'`. That literal is the guard's published domain (T-010 step 1, extension iii).
- **Stated limit.** Deptrac proves the absence of type references only. "Organization never queries `employees`, `employee_*`, `roles`, `permissions` or master tables" is carried by step 16's query-log scan and by diff review.

---

## 6. Implementation steps

Each step ends with `composer check` green from `modules/host`, with a zero-delta-or-explained comparison against the gate as it stood when the step began. **Before each gate run, `dev` lists the foreign `php.exe` processes** (T-011 header).

**Red-list rule.** After steps 4, 5, 6 and 11, `dev` records the red list **before** fixing anything and compares it with section 9. A red file not predicted there is a **stop and report to `architect`**: it means a reader this plan did not measure, which is the T-010 step 3 lesson.

**Red-first rule (T-010 §5).** Red is shown by omission, by a fixture or by an in-memory double. No guard, resolver or middleware is ever stubbed to allow.

- [ ] **Step 0: worktree and baseline.**
  - `composer install` in `modules/host` (the worktree has no `vendor`).
  - Run `composer check` and record the counts, the test-step seconds, the ratio and the process list.
  - *Done when:* the baseline is recorded in the commit message. *(No AC.)*

- [ ] **Step 1: the rebuild spike, throwaway, deleted at the end.**
  - **Setup.** On a private in-memory SQLite connection with `foreign_key_constraints => true`, apply today's tree. Create scratch `branches`, `locations`, `terminals` and `devices` with the section 4 shapes. Populate `employee_roles`, `employee_permissions`, `employee_branches` (two employees, one default each, plus non-defaults) and `number_sequences` with null and specific branch rows.
  - **Run** Ruling 1's six `Schema::table` rebuilds.
  - **Measure, per table:**
    1. rows byte-identical;
    2. each stored generated column still correct, both ways;
    3. existing keys still reported by `pragma foreign_key_list`;
    4. the new key reported;
    5. the sentinel's null duplicate, or for `employee_branches` a second default, still refused, and the composite primary key still refuses a duplicate.
  - **For the two parents:** after the rebuild, `pragma foreign_key_check` is empty, `pragma foreign_key_list("locations")` still names `branches`, and a `locations` insert naming an unknown branch is refused.
  - **Also measure** the SQLite transaction trap of Ruling 14: the same rebuild of `branches` inside `DB::transaction()` fails. That proves the note is real.
  - **If any measurement fails, stop and report.** Do not add a driver gate.
  - *Done when:* the results are one paragraph in the commit message, and the spike files are deleted. *(Protects steps 6 and 7.)*

- [ ] **Step 2: the package, the contracts and the boundary.**
  - Create the contract files of section 3, with real value objects and declared interfaces.
  - Create the `packages/organization` skeleton (provider with an empty boot), and register it in `composer.json`, `bootstrap/providers.php`, `deptrac.yaml` and `phpstan.neon.dist` (section 5), plus the registry-coverage literal.
  - **Negative control (shape 3 pair):** the demo-imports-internal scratch class makes `composer deptrac` exit non-zero, and the contract import does not. Delete both.
  - *Done when:* the gate is green with no new exemption. *(AC-53's provider half and part of AC-18's boundary.)*

- [ ] **Step 3: the PIN rule (T-010 D-39).**
  - `Escalation::requireHoldsWhatTargetHolds()` and `SetPinHandler`'s order (Ruling 13).
  - **Pre-existing edits, stated:** `SetPinTest` cases that pass a null actor to reach the PIN policy or the missing-target check now pass the target itself (self-reset) or the owner. That is the cases around lines 151, 186 to 215 and 278. Expectations are unchanged. `VerifyPinTest:387` also passes the target as actor.
  - **New cases** in `packages/access/tests/Features/SetPin/SetPinEscalationTest.php`: AC-54 to AC-58, each with its paired control.
  - **AC-59's printed dataset** goes in `modules/host/tests/Feature/QaPinResetRuleTest.php`: every pair the criterion lists, at least one allowed and one refused row per class, plus suspended, a deny cancelling an allow, and a null-scope grant.
  - **AC-60 rerun:** the refusal paths (`no_escalation`, `actor_required`) feed T-010's six sinks with a sentinel PIN. Write new cases in `QaPinResetRuleTest` and do not edit `QaPinSinkCaptureTest`. Its table scan reads the live schema, so the five new tables join it at step 4; confirm that by reading it, and if it uses a hard list, extend that list and state the edit.
  - **Red list:** the `SetPin*`, `AccessBumpSweepTest`, `AuditWriterTest`, `QaAccessCloseOutSweepsTest` and `QaPinSinkCaptureTest` cases only if a subject holds a system role. Measure and state.
  - *Done when:* green. *(AC-54 to AC-59, AC-60's PIN half.)*

- [ ] **Step 4: the five expand migrations.**
  - The five files of section 4 and the republished catalogue (`--write-fixture`).
  - `.env.example` moves to the new top.
  - `tests/Helpers.php` `tenantTreeTables()` gains the five tables.
  - `MigrationTreeDisciplineTest`'s `toHaveCount(14)` becomes 19. The derived file-count identity still holds, because the new files each create one table.
  - `AssignRoleTest`'s AC-31 case loses only its `hasTable('branches')` line. The rest stays true until step 5 replaces the case. Stated as a scoped edit.
  - `TenantBaselineBuildsTest`'s inventory gains the new tables' keys. Its "none onto branches" assertion becomes "only the new tables reference `branches`" until step 6.
  - **Predicted red:** exactly those files plus `SchemaCataloguePublishTest` (fixed by the republish, not edited).
  - **Negative control:** a scratch sixth file with a `Schema::table` key onto `employees` in `expand` reddens `MigrationTreeDisciplineTest`'s rules case. Delete it.
  - *Done when:* green, and the red list matches. *(AC-1 stand-in half, AC-2's in-table half, AC-4's phase half for the expand files.)*

- [ ] **Step 5: branch ids become real at the application layer (AC-52, AC-15 access half, AC-61 access half).**
  - `DatabaseBranchDirectory` and its binding.
  - `BranchScope`'s fold and `requireUsable()`; the three handlers; the `RefusalReason` additions and `AccessRefusals`' rendering (Ruling 8).
  - **`AccessSeed::branch()` now inserts a real active `branches` row** (unique code from a counter) and returns its upper-case id. That one fixture change keeps 47 call sites in 25 files meaningful (measured).
  - `QaAccessGuardIsolationHttpTest`'s two `Str::ulid()` branch bodies (lines 280 and 393) use `AccessSeed::branch()`.
  - `AssignRoleTest`'s AC-31 case is **replaced** by AC-52: unknown is `validation` with `unknown_branch`; inactive is `branch_inactive`; active is accepted; detach and remove at an inactive branch succeed. The test's docblock says "superseded by T-014 AC-52, intended".
  - `RefusalReasonTest`'s closed set goes from 15 to 17.
  - **New:** `packages/access/tests/Support/BranchScopeTest.php` covers the fold (a lower-case id equals the upper one, and a skip-the-fold fixture produces two rows, which is reported) and `requireUsable` both ways.
  - *Done when:* green, and the red list matches section 9's step 5 group. *(AC-52, AC-15 access operations, AC-61 for attach, assign and `covers()`.)*

- [ ] **Step 6: the contract migration, at migration level.**
  - The file of section 4, the catalogue and `.env.example`.
  - **Pre-existing edits, stated:**
    - `TenantBaselineBuildsTest`: the inventory gains the six keys. The unguarded map becomes **exactly** `audit_logs.branch_id`, `outbox_events.branch_id`, `access_log.branch_id` and `access_log.terminal_id` (AC-9), with `toHaveCount(4)`. The `number_sequences` case seeds a branch row.
    - `QaAccessSchemaShapeTest`: "no key references `branches`" over T-010's tables becomes "the three branch columns now reference `branches.id`". The docblock-text check, "FP-209 owes", stays and still passes (AC-8).
    - `TenantAccessKeysTest`: seeds branch rows where its stand-in enforces keys.
  - **New: `modules/host/tests/Feature/BranchForeignKeysMigrationTest.php`.** It uses a private stand-in, applies files 1 to V - 1 in one `migrate` call, seeds, then applies V. V is found **by migration name**, never as a literal.
    - **AC-3:** per table, the before-key control at V - 1 is accepted. After V, unknown is refused, existing is accepted, and null is accepted for the three nullable columns.
    - **AC-5:**
      - each of the six columns dirty in turn throws, naming table, column and count, never the planted sentinel id;
      - the tables are byte-identical (rows plus `sqlite_master` SQL), with no `__temp__` table left;
      - re-running after cleaning succeeds;
      - lower-case spelling is offending;
      - clean and all-null both pass;
      - two dirty tables give both counts.
    - **AC-7:** restrict, with the unreferenced-branch control.
    - **Step 1's five properties as permanent assertions**, each with a damaged-fixture control (T-011 AC-15's shape).
    - **The retry guard:** pre-add one key in a scratch step, then run V; it succeeds with exactly one key per column.
    - **AC-8 static:** catalogue row `contract`, not destructive; `SourceRules::inspect()` empty; no driver token; no `onDelete` or `cascadeOnDelete` or `nullOnDelete` or `restrictOnDelete`; no `down()`; docblock content. "No shipped file edited" is a **diff check for `qa`** (`git diff --stat main...` touches no pre-existing file under `migrations-tenant/`). The catalogue's checksum rows for versions up to T-011's top must also be byte-identical to `main`'s, which a test can assert against a pinned copy of those rows.
  - **This file must not name `AccessTenantFixture`** (T-011 §3 trap).
  - *Done when:* green, and the red list matches. *(AC-2's cross-key half, AC-3, AC-5 migration half, AC-7, AC-8, AC-9.)*

- [ ] **Step 7: the contract migration through the runner.**
  - **New: `modules/host/tests/Feature/BranchForeignKeysContractRunTest.php`**, on T-011's `SettingsUpdatedByContractRunTest` pattern: template at V - 1 built in one call (kept under 2 s), copied per tenant, real applier for V only.
  - **AC-4 seeded:** a template at T-011's top carrying T-010 data (employees, attachments to real branches, assignments) is migrated to the top through the remedy runs, and the seeded rows are unchanged. A fresh tenant migrates to the top too.
  - **AC-4 planner:** a fleet below the last expand version is refused for a run including V, naming V and the remedy.
  - **AC-5 runner half:** A and C clean, B dirty. B fails alone, D-10 is checked at every sink of T-011 Ruling 6, the run is `completed_with_failures`, and after cleaning B the retry succeeds.
  - **Statement capture on dirty B:** the pre-check `select` is present, and no `__temp__` or `alter table` statement appears. Liveness: a clean tenant **does** show the rebuild.
  - **Watch the clock:** record the file's duration. Over 30 s, stop and report.
  - `ShippedTenantTreeTest` should pass unedited, with one more remedy round. Record its new duration.
  - *Done when:* green. *(AC-4, AC-5 runner half.)*

- [ ] **Step 8: branches.**
  - The five branch slices, controllers and routes; `branches.view` and `branches.manage` declared.
  - `AuditTrail` wired: `AuditWriter::record()` and the binding.
  - **Slice tests:**
    - **AC-10:** branch, store location and default set in one transaction. Fault injection binds a location writer that throws, or uses a unique-code collision planted on `locations`; no branch row remains.
    - **AC-11:** every invalid case plus the controls.
    - **AC-12:** refused fields, with `perm_version` unchanged.
    - **AC-13:** ordering, inactive included, soft-deleted fixture excluded, clamp, `not_found`, lower-case id resolves.
    - **AC-14:** `has_active_terminals` with seeded terminal rows by status, `last_branch`, twice is `validation`, no audit on refusal. The last-branch guard reads the active branches `for update`, like `LastOwner`.
  - Audit rows are compared as **whole-row arrays** (T-010 Ruling 11's lesson).
  - *Done when:* green. *(AC-10 to AC-14, AC-20 branch half, AC-16's stand-in shape.)*

- [ ] **Step 9: locations.**
  - The location slices; `locations.*` declared; `EmployeeDirectory` wired.
  - **AC-21** to **AC-25**, including `location_in_use` (both causes) and `branch_inactive` on reactivation and on create under an inactive branch (AC-15).
  - *Done when:* green. *(AC-21 to AC-25, AC-15 location half.)*

- [ ] **Step 10: terminal administration.**
  - Create, re-issue, list, get, update, devices, pause and resume; `terminals.*` declared.
  - `TerminalSessions` bound in auth with both methods written now. `revokeAll()` is three lines, and its tests are step 13's. No method is ever a "not yet" stub.
  - Organization slice tests bind a fake `TerminalSessions` returning a fixed tenant part.
  - **AC-26:** code format, alphabet and hash length; expiry from `RunnerClock` plus config; two terminals get different codes.
  - **AC-28**, **AC-37**'s state half, **AC-41**, **AC-42** (`has_pending_code`, never the hash), and **AC-15**'s terminal half.
  - **Ruling 7's refusal:** a non-conforming `tenants.code` writes nothing and logs at error level.
  - *Done when:* green. *(AC-15 terminal half, AC-26, AC-28, AC-37 state half, AC-41, AC-42.)*

- [ ] **Step 11: the terminal token and activation.**
  - `TokenClaims` and `IssueTokenPair` (terminal type).
  - `ActivationThrottle`; the `ActivateTerminal` auth slice; organization's `ActivateTerminalHandler`; `TerminalLoginLog`; the controller and route.
  - **Auth slice tests** (`packages/auth/tests/Features/ActivateTerminal/`) use contract doubles: the order of section 4, refusal before any write, a throwing `$issue` leaving nothing, a lockout counted per Ruling 12, and a mis-keyed throttle double reported.
  - **New host e2e: `modules/host/tests/Feature/TerminalActivationHttpTest.php`.**
    - **AC-29:** full success, rows compared whole.
    - **AC-30:** every refusal case; responses compared **byte-for-byte**, headers included, against each other; the positive control in the same test; the row counts of every table unchanged apart from the throttle.
    - **AC-31:** injected clock.
    - **AC-32**, **AC-33** (claims exact; tenant employee token claims unchanged), and **AC-43**'s stand-in half (the second request is refused).
  - **Predicted red to check:** any auth route-surface test pinning "the whole HTTP surface of T-009" (`QaAuthHttpSurfaceTest`, `HttpAuthEndpointsTest`) and `AuthCredentialsAreNeverReadFromTheUrlTest` (the new controllers must pass it by reading the body only). Record the actual list.
  - *Done when:* green. *(AC-29 to AC-33, AC-43 stand-in half.)*

- [ ] **Step 12: request-time authority and terminal refresh.**
  - `RequireTerminalToken`; `DatabaseTerminalAuthority`; `RefreshTerminalToken`; `RefreshTokenHandler`'s principal filter (new case: a terminal refresh token at `/auth/refresh` is refused and its family is untouched).
  - **New: `modules/host/tests/Feature/TerminalSessionHttpTest.php`.**
    - **AC-34:** each state its own case, with the before-change control.
    - **AC-35:** route classes printed.
    - **AC-36:** rotation, replay, 14 days, and Ruling 5's inactive case.
    - **AC-37:** the token half.
  - *Done when:* green. *(AC-34 to AC-37.)*

- [ ] **Step 13: revocation.**
  - `RevokeTerminal` and `MasterTerminalSessions::revokeAll()`.
  - **AC-38**, in `TerminalSessionHttpTest`: the tenant then master order is asserted by statement capture on both connections. Another tenant's terminal with the same id and user tokens are untouched.
  - **AC-39:** fault-injected `TerminalSessions`; repeat with no second audit row.
  - **AC-40.**
  - *Done when:* green. *(AC-38 to AC-40.)*

- [ ] **Step 14: settings.**
  - The four settings slices, `settings.*` declared, and the terminal settings route.
  - **AC-44**'s printed truth table lives in `packages/organization/tests/Features/ListEffectiveSettings/SettingsResolutionTest.php`, with every row the criterion lists.
  - **AC-45**, **AC-46**, **AC-48**, **AC-50** and **AC-51**, plus **AC-49**'s two cache doubles.
  - *Done when:* green. *(AC-44 to AC-46, AC-48 to AC-51, AC-15 settings half.)*

- [ ] **Step 15: the declarations are complete.**
  - `OrganizationPermissions` has all eight keys.
  - **AC-53:** sync twice leaves one row per key; the owner can do every operation; an `employees.view`-only employee can do none; a key declared but not routed is reported. Use the existing T-010 sweep if it covers that; otherwise add a case to step 16's sweep.
  - *Done when:* green. *(AC-53.)*

- [ ] **Step 16: the close-out sweeps (`qa`-accountable files, written by `dev`).**
  - **`QaOrganizationHttpSurfaceTest`:**
    - **AC-17:** no delete route, printed, with a fixture delete route reported.
    - **AC-18:** each route has exactly one of the eight keys, or is public or terminal-only. Platform, no-employee and terminal tokens are each refused on every employee route. The owner passes.
    - **AC-35's** classes, and **AC-40**'s no-un-revoke route.
    - **AC-60:** no terminal or settings route reaches `VerifyPinHandler`.
  - **`QaOrganizationTenantIsolationTest`:**
    - **AC-19:** A, B, A on one worker; same codes; cross-tenant id gives `not_found`; a query-log scan finds no tenant predicate and **no organization query against an access or master table**; the mis-keyed cache double.
    - **AC-49:** isolation.
  - **`QaActivationCodeSecrecyTest`** (AC-27):
    - every column of every table in both databases, enumerated live and counted;
    - logs at every level, exceptions, later responses and headers, claims;
    - the encoded, escaped and reversed forms;
    - a planted-sink control per sink kind, and the baseline-collision control;
    - the URL-path and query refusal.
  - **`QaSecretSettingSinkTest`:** AC-47, with the same harness shape.
  - **`QaBranchIdFoldTest`:** AC-61 across a path, a body, an assignment scope, a setting scope and `BranchScope`, and every stored `branch_id` upper-case.
  - **AC-9's report line** goes in the commit for the lead.
  - **Trap:** a host test that names `AccessTenantFixture` and asserts `QueryException` must call `AccessTenantFixture::enter(` (T-010 Ruling 7 sweep).
  - *Done when:* green. *(AC-17, AC-18, AC-19, AC-27, AC-35, AC-40, AC-47, AC-49, AC-60, AC-61.)*

- [ ] **Step 17: close-out, `qa`.** Mandatory mutations, each shown red and reverted:
  1. Reverse settings precedence: AC-44 goes red.
  2. Remove the PIN escalation check, then invert "system role": AC-59 goes red.
  3. Delete the contract pre-check: AC-5 goes red, with keys on and off (T-011 Ruling 7).
  4. Move one key before the pre-checks: the statement-capture case goes red.
  5. Skip `requireUsable()` in `AssignRole`: AC-52 goes red.
  6. Make `activeBranchOf()` ignore credential status: AC-34 goes red.
  7. Mint before the tenant writes: AC-30's "nothing written" goes red.
  8. Key the throttle by tenant part string instead of id, or omit the tenant: AC-31's isolation goes red.
  9. Remove the principal filter: the terminal-token-at-`/auth/refresh` case goes red.

  Then the diff checks (AC-8, and section 9's edit list), the final `composer check` with its process list, and section 11 recorded as NOT VERIFIED. *(AC-62.)*

---

## 7. Test strategy

- **Unit, pure:** `ActivationCode` (alphabet, uniformity bounds, hash), `OrgCode`, `Identifier::fold`, `SettingsRules`, `TokenClaims::terminal`, `ActivationThrottle` keys.
- **Slice (package tests, `AccessTenantFixture` with `enter()` so keys are enforced):** every feature folder's mirror test, sections B to E and G.
- **Migration level (private stand-in):** steps 6 and 7.
- **HTTP e2e (host):** activation, sessions, revocation, settings route, sweeps.
- **Which test proves which criterion:**

  | Criteria | Proved in |
  |---|---|
  | AC-1 | `OrganizationSchemaShapeTest` (new, host, step 4) |
  | AC-2 | step 4 and step 6 files |
  | AC-3 to AC-9 | steps 6 and 7 |
  | AC-10 to AC-14 | branch slices |
  | AC-15 | step 5 (access), 9, 10, 14 |
  | AC-17 to AC-19 | step 16 |
  | AC-20 | each mutating slice, whole-row |
  | AC-21 to AC-25 | location slices |
  | AC-26 to AC-43 | steps 10 to 13 |
  | AC-44 to AC-51 | step 14 |
  | AC-52 | step 5 |
  | AC-53 | step 15 |
  | AC-54 to AC-60 | step 3 and step 16 |
  | AC-61 | steps 5 and 16 |
  | AC-62 | step 17 |
- **Grades** as T-010: independently proven on the stand-in except section 11.
- **No new test writes a catalogue version as a literal.**

## 8. Risks and rollback

| # | Risk | Size | Mitigation |
|---|---|---|---|
| 1 | **Gate headroom.** Five tables and a contract step lengthen every whole-tree migration. `ShippedTenantTreeTest` AC-11 gains about 6 files × 2 tenants × 0.7 s plus one remedy round. T-011 measured a contaminated ratio of 1.91 | could stop the task | Step 0 baseline; steps 7 and 11 record durations with stop thresholds; process lists. A ratio below 2.0 goes to the lead |
| 2 | **Unmeasured readers of the tree, the route list or the RefusalReason set** | medium (T-010: 3 predicted, 20 found) | Red-list rule at steps 4, 5, 6 and 11 |
| 3 | **The SQLite rebuild of the CASE column or a parent table misbehaves** | small, blocking | Step 1 measures it before anything depends on it; stop, no driver gate |
| 4 | **Cross-database partial failure** (activation commit after mint; revoke master step) | small | Ordering of section 4; the tenant is authoritative; revoke is idempotent; a dangling refresh row for a pending terminal is refused at refresh |
| 5 | **Uniform refusal leaks by timing** (unknown tenant part answers faster) | accepted | AC-30 asks for byte identity only; the lockout bounds guessing |
| 6 | **Two concurrent activations of different terminals with one fingerprint** both succeed | small, accepted | No column may be added (AC-1); follow-up row |
| 7 | **SHA-256 of a 39.6-bit secret is brute-forceable offline from a database dump** | low (24 h lifetime, single use) | D-17 as written; HMAC with the app key is a free reversible upgrade (question 2) |
| 8 | **Ruling 4's audit-derived `activated_by`** couples to an audit action name | small | One constant per action, shared by writer and reader; `ba` may choose the column instead |
| 9 | `schema:publish` without `--write-fixture` touches the held engine | small, severe | Named as mandatory in section 3 |

**Rollback.** Everything is unpushed on the branch. No tenant database exists, so no migration has run outside a test. Steps 4 to 7 revert as a group. Reverting step 6 alone leaves the tree at its expand top and the gate green (T-011's property).

## 9. Pre-existing tests that legitimately change

Each edit is stated here so `qa` reports it as intended, not as a regression. No assertion is weakened unless the criterion withdrew it (AC-52).

| Step | File | Edit and cause |
|---|---|---|
| 3 | `packages/access/tests/Features/SetPin/SetPinTest.php` | null actors become self or owner (AC-58: actorless is `actor_required`; D-8 order) |
| 3 | `packages/access/tests/Features/VerifyPin/VerifyPinTest.php:387` | the same |
| 3 | `AccessBumpSweepTest`, `AuditWriterTest`, `QaAccessCloseOutSweepsTest`, `QaPinSinkCaptureTest` | **only if** their PIN subject holds a system role; measured and stated |
| 4 | `modules/host/tests/Helpers.php` | `tenantTreeTables()` gains five tables |
| 4 | `MigrationTreeDisciplineTest` | `toHaveCount(14)` becomes 19 |
| 4 then 6 | `TenantBaselineBuildsTest` | FK inventory; the "onto branches" case; unguarded map reduced to exactly four, adding `access_log.terminal_id`; `number_sequences` case seeds a branch |
| 4 then 5 | `packages/access/tests/Features/AssignRole/AssignRoleTest.php` | AC-31 acceptance case first trimmed, then replaced by AC-52 (**the QA-noted test AC-52 names**) |
| 5 | `packages/access/tests/Support/AccessSeed.php` | `branch()` inserts a real active branch |
| 5 | `QaAccessGuardIsolationHttpTest` | two `Str::ulid()` branch bodies; possibly body-map entries for new routes in its AC-64 sweep, whose `$skipping` list stays exactly `GET api/v1/access/me/permissions` |
| 5 | `packages/access/tests/Contracts/RefusalReasonTest.php` | closed set 15 to 17 |
| 6 | `QaAccessSchemaShapeTest` | the T-010 branch columns now reference `branches` |
| 6 | `TenantAccessKeysTest` | branch ids seeded as real rows |
| 11 | auth route-surface tests, if they pin the route list | two new public routes |
| 5 *(recorded after QA BUG-1)* | `packages/access/tests/Features/SyncPermissionRegistry/OwnerRoleSyncTest.php` | the same branch-seeding fix as `TenantAccessKeysTest`: a row naming an opaque `branch_id` now needs a real `branches` row once the contract migration's keys are in place. Seeding-only helper, no assertion changed, stated in its docblock (landed in the step 5-7 checkpoint) |
| 5 *(recorded after QA BUG-1)* | `packages/access/tests/Support/PermHashRowsTest.php` | the same branch-seeding fix, same cause; seeding-only, no assertion changed, stated in its docblock |
| 11 then 14 *(recorded after QA BUG-1)* | `modules/host/tests/Feature/PasswordHashingAndClockBindingTest.php` (T-009) | its config-key enumeration of `config/authentication.php` gains the new `terminal_activation` keys (section 3, host). No assertion weakened (landed in the step 13-14 checkpoint) |
| any | `PermissionRegistryTest`, `SyncPermissionRegistryTest`, `QaAccessHttpSurfaceTest`, `QaVerifyPinReachabilityTest`, `QaSchemaSignatureInstrumentTest`, `QaTenantBaselineStandInShapeTest`, `QaDatetimePrecisionSourceTest` | **expected unchanged** (they derive their domain), listed so a red one is investigated rather than patched |

**Expected unedited:**

- `ShippedTenantTreeTest`, `SettingsUpdatedByKeyTest`, `SettingsUpdatedByContractRunTest`, `QaSettingsUpdatedByEdgeTest` (all target by name or handle several contract steps);
- `SchemaCataloguePublishTest`;
- `RecordLoginAttemptTest`'s principal pin;
- `IssueTokenPairTest` (user rows still `user`);
- every shipped migration file.

## 10. Messages

**`architect` → lead.**

1. Rulings 1, 3, 4, 5, 6, 8, 9, 10(a) and 10(b) amend the spec or decisions and go to `ba`.
2. Ruling 7's note that FP-206 must store upper-case tenant codes.
3. Follow-up rows:
   - (a) AC-9's four unguarded columns (D-3);
   - (b) promote the tenant connection-name lookup to the kernel, now at three or more homes;
   - (c) the concurrent same-fingerprint activation race (risk 6);
   - (d) the HMAC upgrade (risk 7);
   - (e) after the task, `docs/database.md` rows for `branches`, `terminals` and `terminal_credentials` match what was built (no `settings` JSON, `activation_code_hash`, no `secret_hash`).
4. FP-309 closes with step 5; FP-310 stays open (D-9).
5. `CLAUDE.md`'s `services/host` paths are stale.

**`architect` → `dev`.** Step 1 is the step that matters most for the migration half. Step 5's `AccessSeed::branch()` change is the one that keeps 47 call sites honest. Stop on any red file section 9 does not name.

## 11. The `[ENGINE]` criteria

| Criterion | What ships now | What the engine owes |
|---|---|---|
| AC-6 | the keys added and enforced on the stand-in; one query-builder pre-check with no driver branch (step 6 static test) | each key added and enforced on MySQL 8.4 and MariaDB 10.4; the same offending set; acceptance of a restrict key on the base column of a stored generated column (Ruling 14) |
| AC-16 | the last-branch guard reads `for update` (source); the sequential case | two concurrent deactivations leave one active branch |
| AC-43 | the second sequential activation is refused | two concurrent activations: exactly one succeeds, one device row |

None is skipped, stubbed or re-worded. `qa` records each as NOT VERIFIED with this table as the reason.

## Questions for the human

None. No escalation trigger is met. The amending rulings go to `ba` through the lead. Two items are non-blocking owner choices, both reversible:

- Ruling 4's issuer column versus the audit read.
- Risk 7's HMAC upgrade.
