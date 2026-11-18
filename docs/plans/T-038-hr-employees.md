# Plan: T-038 HR master data: employee profiles, departments, designations, documents

Spec: `docs/specs/T-038-hr-employees.md` **revision 2** (98 live criteria: AC-1 to AC-104, sections A to I and K; AC-92 to AC-97 are retired and not reused, D-38). Decisions: `docs/decisions/T-038-hr-employees.md` (D-1 to D-56; D-38 to D-56 are the revision 2 rulings on this plan's corrections C-1 to C-22).
Design authority: `docs/database.md` §6.12 (HR), §3.1/§3.2; `docs/architecture.md` §3 (as-built placement rule), §6; `docs/patterns.md` §3 to §6.
House model: `docs/plans/T-032-chart-of-accounts.md` and the shipped `packages/finance` (handler-level authorization, permissions registry, audit-action registry built from handler constants, refusal enum, usage ports, authorization matrix test, widths test).
Backlog: FP-270. Branch: `ai/T-038-hr-employees`. Gate directory: `modules/host`.

Everything about shipped code below was measured read-only in this worktree at `29cd436`. Plan revision 2 re-measured the points it newly relies on at `c4b7006` (the commits between are documents only): `modules/host/app/Http/Responses/AccessRefusals.php`, `packages/access/tests/Contracts/RefusalReasonTest.php:70`, `packages/access/src/Support/DatabaseEmployeeDirectory.php`, and the absence of a `packages/contracts/tests` directory.

---

## 0. Verification, findings and rulings

### 0.1 What the branch contains (measured)

| Claim | Verdict | Evidence |
|---|---|---|
| T-010, T-014, T-017, T-021 code is on the base | **Holds** | `packages/access`, `packages/organization`, `AuditWriter` (T-017 catalogue check), `packages/kernel/src/Api` all present; finance chunk C is the tip |
| Tenant tree today | 76 files, top version **76** (`2026_11_15_003203_create_fiscal_periods_table`), `APP_REQUIRED_SCHEMA_VERSION=76`, `tenantTreeTables()` holds 69 names | `modules/host/database/migrations-tenant/`, `.env.example:73`, `tests/Helpers.php:521`, `MigrationTreeDisciplineTest:152` |
| An identity read contract exists | **Partly** | `Contracts\Access\EmployeeDirectory::isLive(id)` only (one row, a bool), implemented by `Access\Support\DatabaseEmployeeDirectory`. `RoleHolders::actingAmong(ids)` is the precedent for a bulk id-list contract. `packages/workflow/tests/Definitions/FakeEmployeeDirectory.php` implements `EmployeeDirectory`, so **adding a method to it breaks a test double**: a new interface is needed |
| A branch read contract exists | **Holds** | `Contracts\Organization\BranchDirectory::state(id): BranchState` (`unknown`, `active`, `inactive`; soft-deleted counts as unknown) |
| Audit writer | `AuditTrail::record(module, ?actorId, action, subjectType, subjectId, ?before, ?after, ?branchId)`; refuses an undeclared (module, action) pair; **derives** `changed_fields` from `before`/`after` values (`AuditWriter::changedFields`) | `packages/contracts/src/Audit/AuditTrail.php`, `packages/access/src/Support/AuditWriter.php` |
| Audit action naming | **Mixed, per module**; the catalogue keys on the (module, action) pair, so names cannot collide across modules. The eighteen unprefixed names stand (D-53) | `grep "const ACTION" packages/*/src`; `AuditCatalogue::knows()` |
| Kernel may not depend on contracts | **Holds** | `deptrac.yaml:594` `Package_Kernel: [Package_Kernel_TenancyStatus]` only |
| No package since T-014 ships tenant routes | **Holds**; this task ships none (D-38) | `find packages -name routes`; `packages/finance/src/FinanceServiceProvider.php` "It declares no route" |
| Access delete | `DeleteEmployeeHandler` reads the target with `EmployeeLookup::findLive()` **unlocked**, then `LastOwner`, update, bump, audit, in one transaction | `packages/access/src/Features/DeleteEmployee/DeleteEmployeeHandler.php` |
| Access refusal vocabulary | Closed enum of **17** in `Contracts\Access\RefusalReason`, pinned by `RefusalReasonTest` (an explicit value list, then `toHaveCount(17)` at line 70) | `packages/access/tests/Contracts/RefusalReasonTest.php` |
| Access's refusal body | `AccessRefusals::of()` renders any reason as `{"error": "<reason>"}`, 422 for `validation` and 403 for every other reason. A new enum case therefore renders as 403 `{"error": "has_dependents"}` with **no host edit**, and `AccessRefused`'s detail never reaches the body (D-43) | `modules/host/app/Http/Responses/AccessRefusals.php` (`of`, `refused`) |
| Owner gets new keys automatically | **Holds** | `SyncPermissionRegistryHandler::keepOwnerWhole()` (T-010 AC-17) |
| Tenant identity for a storage key | `Flexipos\Kernel\Tenancy\TenantContext::tenantId()` | `packages/kernel/src/Tenancy/TenantContext.php` |
| Tests of a contract class | `packages/contracts` has no `tests/`; a contract class is tested by its owning package (access's `tests/Contracts/RefusalReasonTest.php`; finance tests its default in its own tests) | `ls packages/contracts` |

### 0.2 Spec corrections C-1 to C-22: all applied in revision 2

| C | Topic | Landed in revision 2 |
|---|---|---|
| C-1 | header range | header now D-1 to D-56 |
| C-2 | Assumptions bullet on local T-010/T-032 specs | rewritten |
| C-3 | access declares sixteen audit actions | Current behaviour |
| C-4 | code format | D-41, AC-17 (`^[0-9A-Z][0-9A-Z.-]{0,19}$`, controls `.AB`, `-AB`, tab, NUL, non-ASCII letter) |
| C-5 | audit action naming | D-53, no change |
| C-6 | redaction in the writer, null rule | D-42, AC-60 |
| C-7 | `has_dependents` in access's vocabulary | D-43, AC-85, AC-9 |
| C-8 | pre-existing tests this task may edit | D-40, AC-9 (closed, named list) |
| C-9 | AC-20 control | AC-20 |
| C-10 | AC-24 cross-reference | AC-24 cites AC-44 |
| C-11 | third column class | D-45, AC-56, Closed value sets |
| C-12 | no transport | D-38, D-56; AC-59 sinks 4 to 6, AC-67, AC-91; AC-92 to AC-97 retired |
| C-13 | `NoOrgChart` | D-52, AC-84 |
| C-14 | bulk identity reads | D-44, AC-101 |
| C-15 | "full run of this task's tests" | D-50, AC-2, AC-59, AC-101 (scripted scenario) |
| C-16 | live profile | D-46, AC-25, AC-26, AC-37 |
| C-17 | `employee_documents.updated_by` | D-39, AC-3, AC-6, AC-9, AC-65, AC-74 |
| C-18 | AC-66 wording | AC-66 |
| C-19 | missing or unknown extension | D-51, AC-67 |
| C-20 | document update/delete key-only control | D-47, AC-14 |
| C-21 | `include_sensitive` | D-49, AC-53, AC-90 |
| C-22 | [ENGINE] markers | D-48, AC-45, AC-85 |

Verified against the revised text: every one is present as stated. Residual wording points are in 0.3; none blocks the build.

### 0.3 New questions for `ba` (revision 2; none blocking, the plan builds the recommended answer)

- **Q-1 (AC-89 against AC-60).** AC-89 says sensitive fields and document `notes` "contain `"[redacted]"`" without the null rule; AC-60 and D-42 say a null side stays null. **Recommended:** AC-89 reads "contain `"[redacted]"`, or `null` where the value was null on that side (AC-60)". The plan builds AC-60/D-42.
- **Q-2 (AC-85 "its tests gain the new refusal and nothing else is edited").** This can be read as editing T-010's `DeleteEmployeeTest`, which AC-9's closed list (D-40) does not name. **Recommended:** "the refusal is proved by a new test file; T-010's existing delete tests are not edited (diff)". The plan adds `DeleteEmployeeDependentsTest` and leaves `DeleteEmployeeTest` unedited.
- **Q-3 (D-52's reason).** The reason says finance's defaults live in the finance package "and HR follows", while the decision (and AC-84) puts `NoOrgChart` in `Contracts\Hr`. **Recommended:** reason reads "the default sits beside the interface in `Contracts\Hr` so consumers' fixtures (FP-358) need not import HR; the usage-port default is the empty provider sweep, as finance's". The plan builds AC-84 as written; the test lives in HR's tests (0.1, last row).
- **Q-4 (AC-67 "the staged file's reported size").** The plan reads "reported" as the size the filesystem reports for the staged file (`filesize`), not a client-declared length (that is transport, D-38). **Recommended:** AC-67 reads "the size of the staged file as reported by the filesystem, obtained without reading its contents".

### 0.4 Rulings

**Ruling 1: placement.**
- A new path package `packages/hr` (`flexipos/hr`, namespace `Flexipos\Hr\`), registered in `bootstrap/providers.php` after `FinanceServiceProvider` (T-032 D-24 precedent; architecture §3 as-built rule: a domain others read through a contract lives under `packages/`).
- Source reaches `Package_Kernel` and `Package_Contracts` only; tests may also reach `Package_Access` (the fixture). `Module_Demo` is **not** granted `Package_Hr` (AC-8's instrument).
- Its `Support/` helpers (`TenantConnection`, `Input`, `Fields`, `Authorize`, `Limits`) are another per-package copy (FP-433). `Input` is copied from finance's strict parser, not from the older trimming copies.

**Ruling 2: the sidecar** (D-1, AC-3, AC-39). `employee_profiles` is keyed by `employee_id`: `IdentifierColumn::primary($table, 'employee_id')` plus a key onto `employees` (RESTRICT, the engine default). HR never writes, and never reads raw, any `employees` column. **Proposed `docs/database.md` §6.12 annotation** (`dev` adds it at step 25, as text describing the change, never quoting retired vocabulary; until then it is owed):

> #### As built (T-038)
> HR facts about a person live in a one-to-one sidecar, `employee_profiles`, keyed by `employee_id` (primary key and foreign key onto `employees`), not on `employees` as the row above prints. `employees` remains the identity and access table owned by the access package (T-010) and carries no HR column; an employee without a profile is valid (a cashier). The profile holds branch, department, designation, reports-to, employment type, joining, confirmation, probation-end, resignation and last-working dates, exit reason, and the personal fields (date of birth, gender, marital status, nationality, national identity number, passport number, personal and work email, phone, emergency contact, address, notes). Sixteen fields are classified sensitive in one closed list in code: they are readable only with a second permission and are never written to a log, an audit value or an event. The indexes the `employees` row lists for department, branch and reports-to live on `employee_profiles`. Not built by T-038 and still owed to the payroll tasks: the salary, pay-frequency, salary-structure, commission, sales-person, default-location, tax-number and statutory-number columns and the photo path; `departments.cost_centre_account_id`; `employee_bank_accounts` and `employment_history`. `employee_documents` adds `original_name`, `mime`, `size_bytes`, `checksum`, `updated_at` and `deleted_at`, and carries both `created_by` and `updated_by`; its key onto `employees` is RESTRICT rather than CASCADE, because employees are never hard-deleted; `file_path` holds a server-generated storage key of the form tenant / `hr/employee-documents` / ULID, read and written only through the kernel's document store.

**Ruling 3: identity through access's published contract** (D-17, D-44, AC-50 to AC-52, AC-101). There is a new additive interface, rather than a widened `EmployeeDirectory`, because a workflow test double implements that one.
- `Contracts\Access\EmployeeIdentity`: readonly `id`, `code`, `firstName`, `?lastName`, `displayName`, `status`.
- `Contracts\Access\EmployeeIdentities`:
  - `identitiesOf(list<string> $ids): array<string, EmployeeIdentity>`: live only; absent ids are omitted; callers pass at most one page.
  - `pageAmong(list<string> $among, ?string $status, ?string $nameContains, ?string $afterCode, ?string $afterId, int $limit): list<EmployeeIdentity>`: live rows of `$among`, filtered, ordered by `code, id`. The access implementation splits `$among` into chunks of 500, runs the same ordered, limited query per chunk, merges, sorts and takes `$limit`. That is exact, because each chunk's top-`limit` contains the global top-`limit` rows from that chunk, and it keeps every statement under any engine's placeholder limit. `LIKE` escapes `\`, `%` and `_`.
  - `statuses(): list<string>`: the shipped `EmployeeStatus` set, so HR validates the `status` filter without a second home for the list.
  - `holdLive(string $id): bool`: a shared lock on the row (`sharedLock`), and `LogicException` outside a transaction (Ruling 7).
- Implemented by `Access\Support\DatabaseEmployeeIdentities` and bound as a singleton in `AccessServiceProvider`.
- How HR lists use it:
  - **profile list (AC-50/51):** filter `employee_profiles` by the HR filters, take the candidate ids (one column), call `pageAmong`, then read those page rows' profiles in one `whereIn`;
  - **direct reports:** the candidates are profiles whose `reports_to_employee_id = E`;
  - **expiring documents:** `identitiesOf` the page's employee ids.
- Liveness checks use `EmployeeDirectory::isLive` (reads) or `holdLive` (inside write transactions).
- **AC-101 consequence (D-44):** every statement naming `employees` during an HR operation is issued by `DatabaseEmployeeDirectory` or `DatabaseEmployeeIdentities`, so its innermost `Flexipos\` frame is an access class. The allowance is by attribution, not by an exemption list (Ruling 12).

**Ruling 4: HR's published contracts** (`packages/contracts/src/Hr/`; AC-82, AC-84, AC-86).
- `OrgChart` has `managerOf`, `departmentHeadOf`, `departmentOf`, `designationOf` (each `?string`), `directReportsOf(string): DirectReports` (readonly `list<string> $ids`, `bool $truncated`, maximum 1000), `isLiveDepartment` and `isLiveDesignation`.
  - `NoOrgChart` is in the same namespace and answers none to every question: null, empty list with `truncated = false`, `false` (D-52, AC-84). **It ships with a test**, `packages/hr/tests/Contracts/NoOrgChartTest.php` (the access `tests/Contracts/` precedent; `packages/contracts` has no tests directory), one case per question.
  - HR binds `OrgChart` to `Hr\Org\DatabaseOrgChart`.
- `HrReferrers` has `departmentReferrers(string $id): list<HrReferrer>` and `designationReferrers(string $id): list<HrReferrer>` (`HrReferrer` is a readonly `kind`, `count`). `DeclaresHrReferrers` is a provider interface returning `list<class-string<HrReferrers>>`. HR's `Org\ReferrerCensus` sweeps `$app->getProviders(DeclaresHrReferrers::class)`, the finance `ReferrerCensus` precedent. **Default "none" is the empty sweep.**
- **Two registration points, on purpose.** The read contract has exactly one implementation (a container binding). The usage ports have many implementers (expenses, payroll, workflow), so they are a provider sweep.

**Ruling 5: the employee-delete guard in access** (D-35, D-43, D-54, AC-85).
- **Contracts, all additive:**
  - `Contracts\Access\EmployeeDependents::dependentKindsOf(string $employeeId): list<string>`, whose docblock says implementations count with locking reads and return kinds only (`department_head`, `direct_reports`), never ids;
  - `Contracts\Access\DeclaresEmployeeDependents` (a provider sweep returning `list<class-string<EmployeeDependents>>`);
  - `RefusalReason::HasDependents = 'has_dependents'` (17 to 18; `RefusalReasonTest` gains the value in its explicit list and `toHaveCount(18)`, the one edit D-40 sanctions there).
- **The "none" default:** no provider declares one, so the census is empty.
- **Access changes**, all additive:
  - `Support\EmployeeDependentsCensus` (the sweep);
  - `EmployeeLookup::findLiveForUpdate()` (new method; `findLive` is untouched);
  - `AccessRefused::hasDependents(list<string> $kinds)`, which carries the kinds as a readonly `detail` (a new optional constructor argument; no other constructor changes). The detail is **in-process only**: `AccessRefusals::refused()` renders `$refused->reason` and nothing else, so the HTTP body stays `{"error": "has_dependents"}` (403) with no host edit (D-43; 0.1).
- **`DeleteEmployeeHandler`** takes the target `findLiveForUpdate`, then `LastOwner`, then the census. A non-empty census refuses `has_dependents` before the update, the bump and the audit row. Status changes are untouched.
- **HR's side:** `HrServiceProvider implements DeclaresEmployeeDependents` returning `Hr\Org\HrEmployeeDependents`, which reports:
  - `department_head` when a live department has `manager_employee_id = E` (shared lock);
  - `direct_reports` when any profile has `reports_to_employee_id = E` **and** that profile's employee is live (`identitiesOf`).

**Ruling 6: the document store lives in the kernel** (D-9).
- **Why the kernel:** the kernel may not import contracts (deptrac), and every module may use the kernel. So the interface and its implementations sit together in `packages/kernel/src/Files/`, and expenses or leave reuse them without touching HR.
- **The classes:**
  - `DocumentStore`: `put(string $key, resource $stream): void` (refuses an existing key), `open(string $key): resource` (`DocumentMissing` if absent), `delete(string $key): void` (idempotent). Every failure is `DocumentStoreFailed`.
  - `DocumentKey`: `mint(string $tenantId, string $area): string` gives `<tenant>/<area>/<ULID>`; `parse(string): DocumentKey` exposes `tenantSegment()` and `area()`. It refuses `.`, `..`, `\`, `:`, NUL, empty segments and any tenant segment outside `\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z`.
  - `LocalDocumentStore`: the root comes from `config/files.php` `document_root`, default `storage_path('app/private/documents')`. The constructor refuses a root inside `public_path()` or `storage_path('app/public')`. It re-validates every key with `DocumentKey::parse` before touching the disk. Writes go to a temp file and are then renamed, with no overwrite.
  - `InMemoryDocumentStore`: the fake, with `failOnPut`, `failOnDelete` and `failOnOpen` toggles and put/open/delete counters (the AC-14 and AC-69 spy). It sits in `src` like the kernel's `InMemoryTenantDatabaseRepository`.
  - `FilesServiceProvider` binds `DocumentStore` to `LocalDocumentStore` (singleton), registered in `providers.php` after `ApiServiceProvider`.
- **No SDK and no network.** Content detection (`finfo`, `FILEINFO_MIME_TYPE`) and the allow-list with its extension map stay in HR (`Documents\FileRules`, one list in one place, AC-67). They are promoted only on a second user.

**Ruling 7: transactions and locks.**
- **Order of work.** Every handler runs, in order:
  1. `Authorize` (before any read or transaction, AC-14);
  2. pure parsing;
  3. `$connection->transaction(fn, attempts: 2)`.

  Write handlers never call a terminal read on a builder themselves. They call `*Rows` methods whose names state the lock mode, and `QaHrSourceRulesTest` enforces it.
- **Locks per operation:**

| Operation | Locks |
|---|---|
| Department create, update | parent and its ancestors `sharedLock` (walk ≤ 10); live siblings `lockForUpdate` (name check, index `parent_id`); head via `holdLive`; branch via `BranchDirectory` |
| Department move | **every** `departments` row `FOR UPDATE` in id order (whole-table lock, as finance re-parent); cycle and depth in PHP |
| Department delete, deactivate, reactivate | target `FOR UPDATE`; children, designations and live-profile counts `sharedLock`; referrers census |
| Designation writes | target `FOR UPDATE`; live designations in the same `department_id` group `lockForUpdate` (index `department_id`); department `sharedLock` |
| Profile create, update | profile `FOR UPDATE` (update); employee `holdLive`; branch, department and designation `sharedLock` / directory; reports-to chain walk `lockForUpdate` on each visited profile (≤ 50); a create's PK violation maps to `profile_exists` |
| Document add | `employee_documents` of the employee `lockForUpdate` count (next-key lock on index `(employee_id, type)` serialises the 100 limit on InnoDB); employee `holdLive` |
| Document update, delete | target `FOR UPDATE` |
| Access delete (Ruling 5) | employee `FOR UPDATE`, then HR's census reads with `sharedLock` |

- **The two D-48 races:** (1) two reports-to changes closing a loop (AC-45) serialise on the chain walk's `lockForUpdate` of each visited profile; (2) a head assignment against the head's soft delete (AC-85) serialises because HR's `holdLive` (shared) and access's `findLiveForUpdate` (exclusive) take the same `employees` row. Both proofs are `[ENGINE]` (step 26).
- **SQLite stand-in:** locks compile to nothing, so their presence is a **source construction** (step 24) and their effect is step 26 (`[ENGINE]`).

**Ruling 8: personal data** (D-12, D-42, D-45, D-49; AC-50 to AC-64, AC-89, AC-90).
- **`Profiles\ProfileFields` is the one closed classification**, holding exactly three lists (D-45): `SENSITIVE` (the 16), `DIRECTORY` (the AC-50 fields that live on `employee_profiles`: `employee_id`, `branch_id`, `department_id`, `designation_id`, `reports_to_employee_id`, `employment_type`, `joining_date`, `work_email`; `code`, the names and `status` come from access) and `INTERNAL` (`created_by`, `updated_by`, `created_at`, `updated_at`).
  - The directory reads build their `select` from `DIRECTORY` only, never `*`.
  - The sensitive tier adds `SENSITIVE`. No HR read selects `INTERNAL`.
- **Redaction lives in the one writer (D-42).** A new additive `Contracts\Audit\RedactingAuditTrail::recordRedacted(module, ?actorId, action, subjectType, subjectId, ?before, ?after, list<string> $redact, ?branchId)`, implemented by `AuditWriter` and bound in `AccessServiceProvider` to the same singleton. (A new interface rather than a new parameter on `AuditTrail`, because `tests/Engine/CustomersSchemaEngineTest.php` implements `AuditTrail` anonymously and a signature change would break that file at load.) Its behaviour, in order:
  1. the (module, action) catalogue check, exactly as `record`;
  2. `changed_fields` derived by the existing `changedFields()` from the **real** `before` and `after`, so a changed sensitive field is named (a nested change in `address` or `emergency_contact` names the top-level field);
  3. masking of `before` and `after` only: for each key in `$redact` present on a side, a `null` value stays `null` and **any** non-null value (string, array, object, number, empty string) becomes the literal `"[redacted]"` as a whole; a key absent from a side stays absent (none is injected); a whole side that is `null` (a create's `before`) stays `null`;
  4. the insert, through the same private row-building path `record` uses.
- **Existing callers are byte-identical (D-42, AC-60).** `record()` keeps its body and calls the shared private insert with no mask; it does not route through `recordRedacted`. Proof: a characterisation test written and run green on the base **before** the writer is edited (step 15) pins the full rows a fixed set of access operations writes under a frozen clock and fixed ids; it stays green, unedited, after the edit; and every existing access audit test stays green unedited.
- **What HR passes to the writer:**
  - profile writes pass `ProfileFields::SENSITIVE`; a create therefore shows `null` for every unsupplied sensitive field and `"[redacted]"` for every supplied one;
  - document writes pass `['notes']` and never put `file_path`, `checksum` or bytes in `before`/`after`;
  - `original_name` appears only in `employee_document_added` (D-32);
  - the sensitive-view row has `before = null`, `after = ['employee_id' => …]` and no field values.
- **The detail read (D-49, AC-53, AC-90).** `GetEmployeeProfile` takes `include_sensitive` (strict boolean, default `false`; any other type is `validation` after the key check). Sensitive values are selected, returned and `employee_profile_sensitive_viewed` is written **only** when `include_sensitive === true` **and** `Authorize::holds('hr.employees.view_sensitive')`. With the flag and without the second key the result is the directory shape, no refusal, no audit row. With both keys and no flag, the directory shape and no audit row. `view_sensitive` without `hr.employees.view` is `not_permitted` (the operation key).
- **No refusal message, exception message or log line includes a submitted value.** Messages name fields only (all fields, not only sensitive ones, so there is no list to keep in sync). A sensitive name offered as a filter, sort or search term is refused `validation` by `Fields::only` during parsing, before any state is read, naming the field and not echoing the value (AC-51, AC-59 sink 6).
- **No outbox event** is written by any HR operation (AC-61). **No token** is read or written: `packages/hr/src` references no token, claim or credential class (AC-59 sink 5, source rule in step 24).
- **D-16 risk, stated:** sensitive columns are plain text. A database dump or backup exposes them. Application encryption is the owner's call (open question 5).

**Ruling 9: refusals.**
- `Exceptions\HrRefused` (readonly `HrRefusal $reason`, `?string $field`, `array $context`) and `HrRefusal`, a backed enum of exactly the spec's 27 codes. `HrRefusalTest` pins the closed set.
- `validation` names every failing field in one refusal (`Fields::only`, the finance shape).
- Reads that hide a confidential document answer `not_found`, never `not_permitted` (AC-72).

**Ruling 10: permissions and audit actions.**
- **`Registry\HrPermissions`:** the ten keys of AC-11, module `hr`, groups `employees`, `departments`, `designations` and `documents`, all `isBranchScoped = false`, declared through `DeclaresPermissions`.
- **The key check.** Every handler declares `public const PERMISSION` (its one key, AC-13), and `Authorize::require()` checks it.
- **Second-tier checks.** `view_sensitive` and `view_confidential` are tier checks inside the read (and, for documents, inside update and delete), through `Authorize::holds()`, a non-throwing variant. They are not operation keys.
- **`Registry\HrAuditActions::ALL`** is built from each write handler's `ACTION` / `ACTION_*` constants (the finance precedent), so it cannot drift. `MODULE = 'hr'`. Each action is used with `subject_type` `department`, `designation`, `employee_profile` or `employee_document`.

**Ruling 11: operations only** (D-38, D-56).
- **The deliverable is the 28 operations, called in process.** This task builds no transport and writes no `access_log` row (AC-91). The transport follow-up is the backlog row D-56 proposes (spec, Out of scope); `lead` assigns it.
- **Upload size (AC-67, D-38).** `AddEmployeeDocument` takes a `Documents\StagedFile` (readonly `path`, `originalName`) and reads it only through a `Documents\StagedFileReader` seam (`size(StagedFile): int` from `filesize()` without opening; `open`, `hash` via `hash_file('sha256')` which streams, `detect` via `finfo_file`). The handler asks `size()` first and refuses `file_too_large` (or `file_empty`) **before** any `open`, `hash` or `detect` call. Tests bind a counting reader, so "no byte read" is an observed zero, not an argument. The bytes are streamed into the store; nothing is buffered whole.

**Ruling 12: the instruments.**
- **Operation enumeration (AC-13, AC-88, AC-104):** `Hr\Tests\Support\HrOperations::all()` reads `packages/hr/src/Features/*` with reflection on each handler's constants. It never uses a list in the test.
- **The authorization matrix** keeps a callable per slice (the finance shape) and asserts that its key set equals the enumerated directory, so a scratch slice with no matrix row fails.
- **The scripted scenario (D-50; AC-2, AC-59, AC-91, AC-99, AC-101).** `Hr\Tests\Support\HrScenario` enumerates `HrOperations::all()` and, for each operation, runs at least one success shape and at least one refusal shape from a per-slice script map. An enumerated operation with no script entry, or a script entry with only one path, fails the scenario's own guard test, so the scenario cannot silently skip a slice. It prints each operation with the paths it ran. Every scan that the spec ties to "the scripted scenario" consumes this one run.
- **Query-log scans (AC-2, AC-99, AC-101):** a listener on the tenant and master connections records SQL, bindings and the innermost `Flexipos\` frame of `debug_backtrace`. The rules:
  - **HR attribution:** a statement whose innermost `Flexipos\` frame is a `Flexipos\Hr\` class and that names `employees` as an identifier (`/\bemployees\b/` on the identifier-quoted SQL) is a violation. Statements issued by `DatabaseEmployeeDirectory` and `DatabaseEmployeeIdentities` are attributed to access and are **allowed** (D-44); no exemption list exists;
  - **other modules:** a statement whose innermost `Flexipos\` frame is **not** a `Flexipos\Hr\` class and that names an HR table is a violation (no module is exempt, access included);
  - **tenant predicate:** a statement on an HR table containing `tenant_id` is a violation;
  - **master:** a scenario statement that writes to master is a violation.

  Each rule carries a mutation fixture, and the D-44 allowance carries a **positive control**: a profile-list page in the scenario is shown to produce at least one statement on `employees`, attributed to `DatabaseEmployeeIdentities`, and not reported.
- **The sentinel harness (AC-59):**
  - it runs over the scripted scenario, with a sentinel-bearing profile;
  - it captures the DB (every column of every tenant and master table, from the live schema), the log (a Monolog `TestHandler` pushed onto the default channel), exception messages and every operation's returned value, `json_encode`d;
  - it scans for raw, URL-encoded, JSON-escaped and reversed forms;
  - it runs the baseline-first and the four controls.

---

## 1. Approach summary

1. A new `packages/hr`, registered in `providers.php`, with four expand-only tenant tables: `departments`, `designations`, `employee_profiles` (the sidecar) and `employee_documents`. `employees` is untouched.
2. 28 vertical slices, called in process (no transport, D-38). Each checks its one key first through `PermissionResolver`, parses strictly, and locks in a fixed order.
3. Identity comes only through access's contracts: `EmployeeDirectory`, plus the new additive `EmployeeIdentities` for bulk and paged reads (D-44). Branches come through `BranchDirectory`.
4. The personal-data regime is one three-class classification (sensitive, directory, internal; D-45), a directory-only select, an opt-in `include_sensitive` detail read (D-49), and writer-side redaction through a new additive `RedactingAuditTrail` (D-42), plus a sentinel harness over a scripted scenario (D-50) that proves no leak.
5. Documents are rows plus bytes behind a kernel `DocumentStore` (local disk plus an in-memory fake), with server-minted tenant-segmented keys, a size refusal before any byte is read, detected content types with an extension map (D-51), a checksum verified on read, and compensation on failure.
6. Published contracts: `Contracts\Hr\OrgChart` for FP-358, with `NoOrgChart` and its test (D-52); `HrReferrers` usage ports (a provider sweep, default none); and in access, an `EmployeeDependents` sweep that makes the T-010 delete refuse `has_dependents` (18th access reason; detail in process only, D-43).
7. Engine proofs are written and skipped under `ENGINE_HOLD` (NOT VERIFIED).

## 2. Alternatives rejected

- **HR columns on `employees`** (database.md as printed): D-1, and T-010 AC-3.
- **HR joins `employees` in SQL**: legal in one database (patterns §4.3), but the spec makes access the only reader (D-17, AC-101). The contract is the boundary.
- **Widening `EmployeeDirectory`**: breaks `FakeEmployeeDirectory` in workflow tests.
- **An optional `$redact` parameter on `AuditTrail::record`**: breaks the anonymous implementer in `CustomersSchemaEngineTest` at file load.
- **Caller-side `"[redacted]"`**: loses `changed_fields` (D-42).
- **Routing `record()` through `recordRedacted(…, [])`**: equivalent today, but it would put every existing caller on the new path; a shared private insert keeps their code path unchanged (D-42's byte-identical clause).
- **Widening access's HTTP body with the dependent kind**: an access wire decision not needed now (D-43).
- **`DocumentStore` in `packages/contracts` with the implementation in the kernel**: the kernel may not import contracts.
- **Placing it in HR**: forces expenses to import HR.
- **A single bound delete guard** (the access default, rebound by HR): the second module to guard deletes (payroll) would overwrite HR's. A sweep composes.
- **`path`/`depth` columns on `departments`**: not in the spec's column list (AC-3 exactness), and a walk of ≤ 10 levels plus a whole-table lock on a small table suffices.
- **An opaque, signed cursor**: an unsigned base64url JSON `{l: list, k: [...]}` suffices. A forged cursor can only reposition inside rows the caller may already see, because visibility is in the `WHERE`. A cursor naming another list, or a malformed one, is `cursor_invalid`.
- **Wrapping every test file in the query and sentinel listeners**: impractical; the scripted scenario (D-50) is checkable.

## 3. Files to change / create

`dev` writes every file. `Qa*` files are `qa`-accountable.

### `packages/contracts/src/` (additive)
| Path | What |
|---|---|
| `Access/EmployeeIdentity.php`, `Access/EmployeeIdentities.php` | Ruling 3 |
| `Access/EmployeeDependents.php`, `Access/DeclaresEmployeeDependents.php` | Ruling 5 |
| `Access/RefusalReason.php` | `+ case HasDependents = 'has_dependents'` (D-43) |
| `Audit/RedactingAuditTrail.php` | Ruling 8 (D-42) |
| `Hr/OrgChart.php`, `Hr/DirectReports.php`, `Hr/NoOrgChart.php`, `Hr/HrReferrers.php`, `Hr/HrReferrer.php`, `Hr/DeclaresHrReferrers.php` | Ruling 4 |

### `packages/kernel/src/Files/` (new)
`DocumentStore.php`, `DocumentKey.php`, `LocalDocumentStore.php`, `InMemoryDocumentStore.php`, `DocumentStoreFailed.php`, `DocumentMissing.php`, `FilesServiceProvider.php` (Ruling 6). Tests: `packages/kernel/tests/Files/{DocumentKeyTest,LocalDocumentStoreTest,InMemoryDocumentStoreTest}.php`.

### `packages/access` (additive)
- `src/Support/DatabaseEmployeeIdentities.php` (Ruling 3).
- `src/Support/EmployeeDependentsCensus.php`, `src/Support/EmployeeLookup.php` (`+ findLiveForUpdate`), `src/Exceptions/AccessRefused.php` (`+ hasDependents`, optional `detail`), and `src/Features/DeleteEmployee/DeleteEmployeeHandler.php` (lock plus census, three lines) (Ruling 5).
- `src/Support/AuditWriter.php` (`implements RedactingAuditTrail`, `+ recordRedacted`, a shared private insert; `record`'s body otherwise unchanged).
- `src/AccessServiceProvider.php` (three bindings).

Tests (new files, plus the one sanctioned edit to `RefusalReasonTest`):
- `tests/Support/DatabaseEmployeeIdentitiesTest.php`;
- `tests/Support/AuditWriterCharacterisationTest.php` (written green on the base before the writer edit, step 15);
- `tests/Support/AuditWriterRedactionTest.php`;
- `tests/Features/DeleteEmployee/DeleteEmployeeDependentsTest.php`.

### `packages/hr` (new)
| Path | What |
|---|---|
| `composer.json` | `flexipos/hr` 0.1.0, requires `flexipos/contracts`, `flexipos/kernel` |
| `src/HrServiceProvider.php` | binds `OrgChart` and `Documents\StagedFileReader`; implements `DeclaresPermissions`, `DeclaresAuditActions`, `DeclaresEmployeeDependents`. No routes, commands or jobs |
| `src/Support/{TenantConnection,Input,Fields,Authorize,Limits,Cursor,PageSize}.php` | Rulings 1, 7, 9; `Cursor` / `PageSize` per D-25 |
| `src/Exceptions/{HrRefused,HrRefusal}.php` | Ruling 9 |
| `src/Registry/{HrPermissions,HrAuditActions}.php` | Ruling 10 |
| `src/Departments/{DepartmentRows,DepartmentRules,DepartmentInput,NameKey}.php` | tree, cycle, depth, the name key (`mb_strtolower(trim())`) |
| `src/Designations/{DesignationRows,DesignationInput}.php` | |
| `src/Profiles/{ProfileFields,ProfileInput,ProfileRows,DateRules,ShapeRules,ReportsToChain,EmploymentType}.php` | Ruling 8; AC-41 to AC-47 |
| `src/Documents/{DocumentRows,DocumentInput,DocumentType,FileRules,DocumentVisibility,StagedFile,StagedFileReader,FilesystemStagedFileReader}.php` | AC-65 to AC-77; Ruling 11 |
| `src/Org/{DatabaseOrgChart,ReferrerCensus,HrEmployeeDependents}.php` | Rulings 4, 5 |
| `src/Features/<28 slices>/` | section 4, the slice table |
| `tests/Support/{HrSeed.php,hr-fixture.php,HrOperations.php,HrScenario.php,FakeHrReferrers.php,CountingStagedFileReader.php,SentinelHarness.php,QueryLog.php}` | Ruling 12 |
| `tests/Contracts/NoOrgChartTest.php` | D-52 |
| `tests/...` | section 7 |

### `modules/host`
| Path | What |
|---|---|
| `database/migrations-tenant/2026_11_16_003801_create_departments_table.php` … `003804_create_employee_documents_table.php` | four expand files. **No file hard-codes its catalogue version**; the version is derived by `schema:publish` from the sorted tree |
| `config/hr.php` | `page_size` 50, `max_page_size` 200, `documents.max_bytes` 10485760, `documents.allowed_types` [pdf, jpeg, png], `documents.per_employee` 100 |
| `config/files.php` | `document_root` (env `DOCUMENT_STORE_ROOT`) |
| `bootstrap/providers.php` | `FilesServiceProvider`, `HrServiceProvider` |
| `composer.json` / `composer.lock`, `deptrac.yaml`, `phpstan.neon.dist` | section 5 |
| `database/catalogue/tenant_schema_versions.json`, `.env.example` | republished; 76 → 80 (base-relative) |
| `tests/Feature/QaHrSourceRulesTest.php`, `tests/Engine/HrSchemaEngineTest.php` | steps 24 and 26 |

No file under `modules/host/app/Http/` changes (0.1: `AccessRefusals` already renders the new reason).

## 4. Data / API changes

**Schema** (tenant only; four expand migrations; no existing table altered):

| Table | Columns as built | Keys and indexes |
|---|---|---|
| `departments` | `id`, `code` VARCHAR(20), `name` VARCHAR(150), `parent_id` ULID NULL, `manager_employee_id` ULID NULL, `branch_id` ULID NULL, `is_active` default true, `created_at`, `updated_at`, `deleted_at` DATETIME(3) | unique `code`; index `parent_id`; FKs `parent_id` → departments, `manager_employee_id` → employees, `branch_id` → branches |
| `designations` | `id`, `code` VARCHAR(20), `name` VARCHAR(150), `department_id` ULID NULL, `level` TINYINT UNSIGNED, `is_active`, `created_at`, `updated_at`, `deleted_at` | unique `code`; index `department_id`; FK `department_id` → departments |
| `employee_profiles` | spec table exactly | PK `employee_id`; indexes `branch_id`, `department_id`, `designation_id`, `reports_to_employee_id`; FKs `employee_id`, `reports_to_employee_id`, `created_by`, `updated_by` → employees; `branch_id` → branches; `department_id` → departments; `designation_id` → designations |
| `employee_documents` | spec table exactly, **including `updated_by` ULID NULL** (D-39) | indexes (`employee_id`, `type`), (`expiry_date`); FKs `employee_id`, `created_by`, `updated_by` → employees |

- **Foreign keys:** 3 + 1 + 7 + 3 = **14**, all onto existing or same-batch tables (allowed in expand, T-010 AC-4). **Eight onto `employees`** (exactly AC-9's list: `departments.manager_employee_id`, `employee_profiles.employee_id`, `.reports_to_employee_id`, `.created_by`, `.updated_by`, `employee_documents.employee_id`, `.created_by`, `.updated_by`), two onto `branches`, two onto `departments`, one onto `designations`, and one is the `departments.parent_id` self key.
- **No decimals; no seed rows** (D-26).
- **Version:** the catalogue goes 76 → 80 on today's base. **At merge** with any branch that adds tenant migrations (finance chunks D to F, T-040), the second to merge **regenerates** the catalogue, `.env.example`, `tenantTreeTables()`, the count literals and `IdempotencyKeysSchemaTest`. It never hand-merges them. The `2026_11_16_0038xx` prefix sorts after every finance file (`2026_11_15_0032xx`), so HR's files stay on top without a rename. If a T-040 file sorts later, HR is renumbered by the publish, not by editing a migration.

**The 28 slices** (`Features/<X>/{X.php, XHandler.php}`, plus `XResult.php` where an id or a shaped result is returned; every command carries `?string $actorId`):

| Area | Slices | `PERMISSION` | Audit |
|---|---|---|---|
| Departments, write | `CreateDepartment`, `UpdateDepartment`, `MoveDepartment`, `DeactivateDepartment`, `ReactivateDepartment`, `DeleteDepartment` | `hr.departments.manage` | `department_*` (6) |
| Departments, read | `ListDepartments`, `GetDepartment`, `GetDepartmentTree` | `hr.departments.view` | none |
| Designations, write | `CreateDesignation`, `UpdateDesignation`, `DeactivateDesignation`, `ReactivateDesignation`, `DeleteDesignation` | `hr.designations.manage` | `designation_*` (5) |
| Designations, read | `ListDesignations`, `GetDesignation` | `hr.designations.view` | none |
| Profiles, write | `CreateEmployeeProfile`, `UpdateEmployeeProfile` | `hr.employees.manage` | 2 |
| Profiles, read | `ListEmployeeProfiles`, `GetEmployeeProfile` (`include_sensitive`, default false; tier `view_sensitive`, D-49), `ListDirectReports` | `hr.employees.view` | `employee_profile_sensitive_viewed` (Get only, flag true and both keys) |
| Documents, write | `AddEmployeeDocument` (takes a `StagedFile`), `UpdateEmployeeDocument`, `DeleteEmployeeDocument` (update and delete also need the document's view tier, D-47) | `hr.documents.manage` | 3 |
| Documents, read | `ListEmployeeDocuments`, `GetEmployeeDocument`, `DownloadEmployeeDocument` (result carries `disposition = attachment`), `ListExpiringDocuments` | `hr.documents.view` (tier `view_confidential`; `notes` needs `hr.employees.view_sensitive`) | `employee_document_downloaded` (Download only) |

**Contracts:** Rulings 3 to 6 and 8. **Permissions:** ten keys. **Transport, console, jobs, cache, events, hooks:** none (D-38). **Dependencies:** only the `flexipos/hr` path package (no new third-party library; `finfo` is the shipped `fileinfo` extension).

## 5. Boundary impact

- **`deptrac.yaml`** (the finance shape, no exemption):
  - paths `../../packages/hr/src` and `../../packages/hr/tests`;
  - `Package_Hr` = `^Flexipos\\Hr\\(?!Tests\\).*` with ruleset `[Package_Kernel, Package_Contracts]`;
  - `Package_Hr_Tests` = `[Package_Hr, Package_Contracts, Package_Kernel, Package_Access]`;
  - `HostApp` gains `Package_Hr`, and `HostTests` gains both layers;
  - **`Module_Demo` is not granted `Package_Hr`.**
  - Access gains no edge, because it uses only `Contracts\Access` and `Contracts\Audit`. The kernel's `Files/` uses only Illuminate.
- **PHPStan:** both HR paths.
- **Composer (host):** `require` gains `flexipos/hr: *`; `autoload-dev` gains `Flexipos\\Hr\\Tests\\`. Run `composer update flexipos/hr` (never `--lock`), then `composer validate --no-check-publish`. `composer.lock` may change only by that path entry.
- **phpunit:** `../../packages/*/tests` already collects HR's tests.

## 6. Implementation steps

Rules carried from T-032 §6:
- red-first, by fixture or omission;
- the red-list rule after steps 2, 3, 13 and 18 (compare with section 9 and stop on anything else);
- unique test basenames; no child processes;
- each mutation is run once, reverted, and its red-then-green counts recorded in `dev`'s hand-back.

**Within a chunk `dev` runs the targeted Pest files plus `composer lint`, `composer stan` and `composer deptrac`. `lead` schedules the single full `composer check`** (end of chunk C and at step 27 at minimum). `composer test:fast` is never the gate.

### Chunk A: package, schema, instruments

- [x] **Step 0: verify the base. STOP on any difference.** Record:
  - the tree top (76, `003203`) and `.env.example` (76);
  - the literals of every section 9 row;
  - `EmployeeDirectory`, `BranchDirectory`, `PermissionResolver::decide`, `AuditTrail::record`, `DeclaresAuditActions` and `AccessRefusals::of` as in 0.1;
  - that `AccessTenantFixture` exposes a master connection the sentinel scan can enumerate;
  - whether finance chunks D to F or T-040 landed on the base since `c4b7006` (if so, start from the regenerated literals).

  *(No AC.)*
- [x] **Step 1: package plumbing.** `composer.json`, an empty `HrServiceProvider`, `config/hr.php`, the section 5 edits, and `'hr'` in `QaToolchainRegistryCoverageTest`.
  - `HrProviderTest`: the provider is loaded.
  - **Deptrac negative control:** a scratch class in `modules/demo/src` importing `Flexipos\Hr\Profiles\ProfileFields` fails, and the same class importing `Flexipos\Contracts\Hr\OrgChart` passes. Delete both.
  - *(AC-8 instrument, AC-9 `QaToolchainRegistryCoverageTest`.)*
- [x] **Step 2: the four migrations, with `HrTenantSchemaTest`** (the finance stand-in shape).
  - Exact sorted column lists per table (AC-3), `employee_documents.updated_by` included (D-39), and the absent payroll columns by name.
  - `employees` is byte-identical before and after migrating, its column list unchanged (AC-3, AC-103).
  - The **14** keys exist. Each bites with a raw insert naming a missing target, and accepts the same row with a real target, one per key including `employee_documents.updated_by` (AC-6). Deleting a referenced `employees`, `departments`, `designations` or `branches` row directly is refused.
  - Code uniqueness by double insert, including a soft-deleted holder (AC-7).
  - `SourceRules::inspect()` reports nothing; no `down()`; every file is `@phase expand`; no `tenant_id` column (AC-2 source half, AC-4).
  - Zero rows in every new table (AC-28).
  - **Sync and republish:** republish the catalogue (`php artisan schema:publish --write-fixture`); `.env.example` 76 → 80; then section 9's step-2 rows (`tenantTreeTables`, `MigrationTreeDisciplineTest`, `IdempotencyKeysSchemaTest`, `TenantBaselineBuildsTest` with **all 14 keys** in the whole foreign-key inventory, `QaAccessCloseOutSweepsTest`, `QaAccessSchemaShapeTest`). Each keeps its liveness control (AC-9).
  - `HrMigrationCatalogueTest`: four expand rows, `is_destructive` false, increasing versions, the fixture equals the table, and earlier checksums unchanged (AC-5).
  - *(AC-1 stand-in, AC-2 source, AC-3 to AC-7, AC-9 schema rows, AC-28, AC-102, AC-103.)*
- [x] **Step 3: widths.** `HrWidthsTest` asserts every `VARCHAR`/`CHAR` width in `003801` to `003804` equals its `Support\Limits` constant. **Red-first:** a fixture `string('code', 21)` is reported. *(AC-4 width half, AC-16, AC-32, AC-47 lengths.)*

### Chunk B: contracts and the pure core

- [x] **Step 4: contracts** (section 3's contracts table, kernel `Files` excluded).
  - `HrContractShapesTest`: `DirectReports`, `HrReferrer` and `EmployeeIdentity` accept good values and refuse malformed ones.
  - **`NoOrgChartTest`** (`packages/hr/tests/Contracts/`, D-52): one case per question of AC-82 (null, null, null, null, empty `DirectReports` with `truncated = false`, `false`, `false`).
  - The contracts import nothing outside contracts or PHP core.
  - `RefusalReason` gains `has_dependents`; `RefusalReasonTest` gains the value in its list and 17 → 18 (D-43, AC-9).
  - *(AC-84; AC-9 `RefusalReasonTest`; groundwork for AC-82, AC-85, AC-86.)*
- [x] **Step 5: `Support\Input`, `Fields`, `Cursor`, `PageSize`, `HrRefusal`** (pure datasets).
  - **Code (AC-17, D-41: after upper-casing, `\A[0-9A-Z][0-9A-Z.-]{0,19}\z`):**
    - `fin-01` → `FIN-01`;
    - refused `validation`: `FIN 01`, `' FIN'`, `"FIN\n"`, a tab, NUL, `.AB`, `-AB`, a non-ASCII letter (`É`, a full-width `Ａ`), 21 characters;
    - 20 characters accepted; `A`, `9`, `A.B-1` accepted.
  - **Text (D-19):**
    - trimmed;
    - blank required → `validation`; blank optional → null;
    - 150 accepted and 151 refused, counted in characters;
    - control characters and invalid UTF-8 refused.
  - **`Fields::only`:** names every unknown and every forbidden key in one refusal. Identity keys (`code` on a profile, `first_name`, `last_name`, `display_name`, `status`, `user_id`, `pin_hash`, `perm_version`) give `not_updatable_here`; others give `validation` (AC-42, D-21). The refusal message names fields only, never a value.
  - **Page size:** 0, 201 and `"50"` give `page_size_invalid`; the default is 50.
  - **Cursor:** malformed, from another list, or with half a key gives `cursor_invalid`.
  - `HrRefusalTest` pins the 27 codes.
  - **Mutations:** M1 `^…$` for `\A…\z` (the newline row goes red); M2 trim the code (the `' FIN'` row goes red); M2b drop the first-character class (the `.AB` row goes red).
  - *(AC-16, AC-17, AC-26 and AC-38 paging rules, AC-42, AC-104 unit half.)*
- [x] **Step 6: `DepartmentRules`, `NameKey`** (pure).
  - **AC-19 order:** each failure alone, and two at once reports the first.
  - Exactly 10 levels accepted and an 11th refused.
  - `cycle` for itself, a child and a grandchild.
  - The depth check is against the deepest descendant after a move.
  - **AC-18 / AC-33:** `Sales` against `sales `, case-insensitive; the same name under another parent or in another designation group is accepted.
  - **Mutation M3:** check the depth of the moved node only (the subtree row goes red).
  - *(AC-18, AC-19, AC-23, AC-33 logic.)*
- [x] **Step 7: `ProfileFields`, `DateRules`, `ShapeRules`, `EmploymentType`, `ProfileInput`** (pure).
  - **AC-41:** `2025-02-30` refused, `2024-02-29` accepted; employment type outside the six refused.
  - **AC-46:** every pair; equal accepted; one day earlier is `date_order` naming the pair; the merged-update case; `dob` tomorrow (clock) refused.
  - **AC-47:** emails; each shape's valid example; unknown key, nested value, non-string leaf, missing `name`/`phone`; 100/101 and 150/151 lengths.
  - **AC-51:** a filter, sort or search on any `SENSITIVE` field is `validation`, and the refusal does not contain the offered value.
  - **AC-56 (pure half, D-45):** `SENSITIVE` equals the spec's sixteen (printed); `INTERNAL` equals the four; `SENSITIVE`, `DIRECTORY` and `INTERNAL` are pairwise disjoint.
  - **Mutation M4:** check dates on the request only, not the merged result.
  - *(AC-41, AC-46, AC-47, AC-51 validation half, AC-56 pure half.)*
- [x] **Step 8: `FileRules`, `DocumentType`, `DocumentInput`, `StagedFile`, `StagedFileReader`, kernel `Files`** (pure plus temp-dir).
  - **`FileRules`** (one list in one place: detected type → allowed extensions `pdf`; `jpg`, `jpeg`; `png`):
    - a real PDF, PNG and JPEG are accepted;
    - a PDF renamed `.png`, an executable (MZ header) renamed `.pdf`, `.svg`/`.html` all give `file_type_not_allowed`;
    - **D-51:** a name with no extension (`scan`), a trailing dot (`scan.`) and an extension outside the map (`scan.tiff` holding a real PNG) each give `file_type_not_allowed`;
    - empty gives `file_empty`; max + 1 gives `file_too_large`; exactly max is accepted. Both size refusals are decided from `StagedFileReader::size()` and a `CountingStagedFileReader` records **zero** `open`, `hash` and `detect` calls (AC-67, D-38).
  - **`original_name`:** separators and control characters stripped; truncated to 255.
  - **`DocumentKey`:** minted form; traversal, `\`, drive letter, NUL, empty segment and a bad tenant segment are refused.
  - **`LocalDocumentStore`:**
    - a root inside `public_path()` is refused;
    - put / open / delete round trip; put over an existing key refused; open missing gives `DocumentMissing`;
    - a forged key with `..` never reaches the filesystem.
  - **Mutations:** M5 trust the declared extension (the renamed-PDF row goes red); M5b detect before sizing (the counting-reader row goes red).
  - *(AC-66 to AC-70 unit halves.)*

### Chunk C: departments and designations on the database

- [ ] **Step 9: registry, `Authorize`, seed, the matrix.**
  - `HrPermissions` and `HrAuditActions` (the provider declares both). `HrSeed`: one employee per key, one with every key except a given one, and one with none.
  - `AuditCatalogueTest` gains `'hr'` in both literals (AC-9).
  - **`HrAuthorizationMatrixTest`** (every slice, growing with each step):
    - with every registered key except the slice's own, the result is `not_permitted`, a query listener sees **no** statement on an HR table, the store and staged-file spies count zero, and no audit row exists;
    - with only the slice's key, the result is **not** `not_permitted`. For `UpdateEmployeeDocument` and `DeleteEmployeeDocument` the cell asserts not `not_permitted` **and** `not_found`, never success (D-47: the document tier also needs `hr.documents.view`);
    - two-key reads (AC-58, AC-74) are tested with each key alone and both together;
    - a null actor and a soft-deleted actor are refused.
  - **`HrOperationsSweepTest`** (AC-13):
    - enumerates `Features/*` and prints each with its `PERMISSION`;
    - every key is registered;
    - the matrix keys equal the directory;
    - **mutations:** a scratch slice with no constant and one naming `hr.nope.view` are each reported.
  - **`HrRegistrySyncTest`** (AC-11, AC-12):
    - the ten keys with their attributes;
    - after sync, the owner holds the ten and owner holders are bumped;
    - a second sync changes nothing;
    - a custom-role holder is not bumped.
  - **Mutation M6:** move `Authorize` inside the transaction (the "no statement" assertion goes red).
  - *(AC-9 `AuditCatalogueTest`, AC-11 to AC-14, AC-64.)*
- [ ] **Step 10: `CreateDepartment`, `UpdateDepartment`, `MoveDepartment`.**
  - **Create:** AC-15, AC-16 end to end; AC-17 `code_in_use` including a soft-deleted holder; the unique backstop seeded past the pre-check maps to `code_in_use`.
  - **AC-18:** `name_in_use` on create, rename and move.
  - **AC-19:** through the handler, including the 10/11-level control.
  - **AC-20:**
    - unknown or soft-deleted head gives `reference_not_found`;
    - a suspended head is accepted (D-23);
    - one employee may head two departments;
    - null clears;
    - the control (while heading a live department the employee's delete is refused by AC-85; after clearing the head it is not) is proved in step 18, which owns the guard.
  - **AC-21:** branch unknown or inactive, then reactivated, through `BranchDirectory`.
  - **AC-22:** `not_updatable_here` for `code`, `parent_id`, `is_active`, `id`, `deleted_at`; a no-change update writes no audit row and leaves `updated_at` as it was.
  - **AC-23:** a move to the current parent is a no-op.
  - **AC-30:** a forced failure after the change and the audit row persists neither (handler seam); the control persists both.
  - **Mutation M7:** skip the whole-table lock (the source scan of step 24 must catch it).
  - *(AC-15 to AC-23, AC-30.)*
- [ ] **Step 11: `Deactivate`/`Reactivate`/`DeleteDepartment`, and the `HrReferrers` census.**
  - **AC-24:** `already_inactive`, `already_active`; reactivation under an inactive parent is `reference_inactive`; while deactivated the department is refused `reference_inactive` as a parent (AC-19) and as a designation's department (AC-34), then allowed again after reactivation. Its refusal as a profile's department is **AC-44**, proved in step 16.
  - **AC-25:** `has_children`; `in_use` from a designation, from a live profile (D-46: a profile whose employee is live; a retained profile of a soft-deleted employee does not block), and from a bound `FakeHrReferrers`.
  - **Delete success:** `deleted_at` set, the code stays reserved, the row is absent from the list and is `not_found`.
  - A deactivated department with referrers is still refused.
  - **AC-86:** the bound fake reports one referrer, the result is `in_use` and the row is unchanged; with no fake, the delete proceeds.
  - **Mutation M8:** count profiles without the liveness filter (the D-46 row goes red).
  - *(AC-24, AC-25, AC-86, AC-87 departments.)*
- [ ] **Step 12: department reads.**
  - **AC-26:** order, page size, cursor round trip over 3 rows at size 2, each filter, `name` contains with `%` literal; detail child count and **live profile count** (D-46: a profile of a soft-deleted employee is not counted).
  - **AC-27:** the tree is nested in code order; a fixture with a soft-deleted parent is returned at the top with `orphaned: true`; the query count is constant across 10 and 100 departments.
  - **AC-54 (department half):** the head is an id only.
  - *(AC-26, AC-27, AC-54.)*
- [ ] **Step 13: designations (all seven slices).** AC-31 to AC-38, end to end:
  - own code namespace (the same code as a department is accepted); the D-41 code rule applies;
  - level 0/255 accepted, -1/256 and `"5"` refused;
  - `name_in_use` per department group (null is a group);
  - AC-34 reactivation control;
  - AC-36's reactivation requires an active department; a deactivated designation's refusal as a profile's designation is **AC-44**, proved in step 16;
  - AC-37 with D-46 and the fake referrer.

  **Gate: `lead` schedules `composer check` (end of chunk C).** *(AC-31 to AC-38.)*

### Chunk D: profiles, identity contract, personal data, org chart, delete guard

- [ ] **Step 14: access's `DatabaseEmployeeIdentities`.**
  - `identitiesOf`: live only, omits unknown and soft-deleted ids.
  - `pageAmong`:
    - order `code, id`; status filter; `name` contains over code and the three names, case-insensitive, with `%`/`_` escaped;
    - keyset continuation;
    - **chunk-merge correctness:** 1,200 candidates with the chunk forced to 500 give the same page as an unchunked reference query.
  - `statuses()` equals `EmployeeStatus::shipped()`.
  - `holdLive` throws outside a transaction; inside it, the `sharedLock` call is observed on the builder seam.
  - **Mutation M9:** sort each chunk but not the merge (the chunk-merge row goes red).
  - *(AC-50/51 enablers, AC-101 via D-44.)*
- [ ] **Step 15: `RedactingAuditTrail` on `AuditWriter`** (D-42).
  - **First, before touching `AuditWriter`:** write `AuditWriterCharacterisationTest` and run it green on the base. Under a frozen clock and fixed ids it runs a fixed set of access operations that audit (create, update, suspend and delete an employee; grant a role) and pins each full `audit_logs` row (every column, `before`/`after`/`changed_fields` as stored bytes). It is not edited afterwards.
  - Then the edit, and `AuditWriterRedactionTest`:
    - changing `nic` from A to B: `changed_fields` contains `nic`, `before.nic` and `after.nic` are `"[redacted]"`, and neither A nor B appears anywhere in the row;
    - null to value: `before.nic` is `null`, `after.nic` is `"[redacted]"`; value to null: the reverse; null to null with another field changed: `nic` is `null` on both sides and absent from `changed_fields`;
    - an unchanged non-null sensitive field is `"[redacted]"` on both sides and absent from `changed_fields`;
    - a non-string value (`address` object, empty string) becomes `"[redacted]"` whole; a nested change in `address` names `address` in `changed_fields`;
    - a create (`before = null`) keeps `before` null; a redact key absent from a side is not injected;
    - non-redacted fields carry real values;
    - an undeclared action is still refused by `recordRedacted`.
  - `AuditWriterCharacterisationTest` and the existing access audit tests are green, unedited.
  - **Mutations:** M10 mask before deriving (the `changed_fields` row goes red); M10b mask null as `"[redacted]"` (the null row goes red); M10c route `record()` through the mask with a non-empty list (the characterisation test goes red).
  - *(AC-60 mechanism and its byte-identical clause, AC-89 mechanism.)*
- [ ] **Step 16: `CreateEmployeeProfile`, `UpdateEmployeeProfile`.**
  - **AC-39:** a hash of the `employees` row is identical before and after (all columns, including `perm_version` and `updated_at`).
  - **AC-40:** `not_found` for an unknown or soft-deleted employee; `profile_exists` from the pre-check and from the PK backstop; a no-profile employee still resolves permissions.
  - **AC-41 to AC-47** end to end.
  - **AC-44:** department and designation must be live (`reference_not_found`) and active (`reference_inactive`), including a department or designation deactivated by steps 11 and 13; no designation/department cross-check (D-27); a held inactive designation is not refused on an unrelated update, only when the field is set to it.
  - **AC-45:** a chain of three accepted; closing it gives `cycle`; a 51-step chain gives `depth_exceeded`; self gives `self_reference`; a suspended manager is accepted. (The concurrent half is `[ENGINE]`, step 26, D-48.)
  - **AC-48:** a no-change update writes no audit row and leaves `updated_at` as it was; null clears; `profile_not_found`.
  - **AC-49:** soft-delete the employee by raw update; then every HR read and write is `not_found` and the rows are retained.
  - **AC-60:** the create row shows `null` for unsupplied and `"[redacted]"` for supplied sensitive fields; an update of a sensitive field names it in `changed_fields` with `"[redacted]"` on non-null sides.
  - **AC-91:** `perm_version` for every fixture employee is unchanged across the step's operations.
  - *(AC-39 to AC-49, AC-57 stand-in half, AC-60, AC-91 profiles.)*
- [ ] **Step 17: `ListEmployeeProfiles`, `GetEmployeeProfile`, `ListDirectReports`.**
  - **AC-50:** item keys exactly the 13 directory names. No sensitive key, even as null; no internal key; none of `pin_hash`, `pin_failed_attempts`, `pin_locked_until`, `perm_version`, `user_id`.
  - **AC-51:** each filter narrows; the intersection; a sensitive filter is `validation`, refused before any statement (query listener) and without the value in the refusal.
  - **AC-52, AC-53, D-49:** the cells
    - `view` only, flag omitted / false / true: directory shape, no audit row;
    - `view` + `view_sensitive`, flag omitted or false: directory shape, no audit row;
    - `view` + `view_sensitive`, flag true: the 13 directory fields plus the 16 sensitive fields, one audit row;
    - `view_sensitive` alone: `not_permitted`;
    - flag `"true"`, `1`, `null` given explicitly as a non-boolean: `validation`.
  - **AC-90:** two qualifying reads write two rows; a refused read, a flag-false read and a list write none; the row carries no value.
  - **AC-54:** direct reports, paged.
  - **AC-55:** T-010's list and detail outputs, captured before and after HR rows exist, are equal.
  - **AC-56 sweep (D-45):** every live column of `employee_profiles` is in exactly one of sensitive, directory and internal (printed per class); no HR read result contains an internal column. **Mutations:** a scratch column added to a test copy of the table is reported unclassified; `nic` moved to `DIRECTORY` turns AC-50 and the sweep red; `updated_by` moved to `DIRECTORY` turns the internal assertion red.
  - **AC-62:** an employee reading their own profile needs the same keys.
  - *(AC-50 to AC-56, AC-58 profile cells, AC-62, AC-90.)*
- [ ] **Step 18: `DatabaseOrgChart`, `HrEmployeeDependents`, the access delete guard.**
  - **AC-82:** each answer, and each flips when its cause is removed (clear the manager, delete the head by raw soft delete). Not inherited. The head may be the employee asked about. `directReportsOf` is truncated at 1000 (a fixture of 1001).
  - **AC-85 (`DeleteEmployeeDependentsTest`, a new file in access's `DeleteEmployee` test directory; D-43):**
    - a head of a live department gives `has_dependents` with `AccessRefused::$detail` `['department_head']`;
    - a manager of a live profile of a live employee gives `['direct_reports']`;
    - both give both;
    - nothing changes: no `deleted_at`, no bump, no audit row;
    - the message and detail contain no id;
    - **HTTP body unchanged:** access's existing delete route answers 403 with a body exactly equal to `{"error": "has_dependents"}` (no detail key);
    - **control:** clearing the head or moving the reports makes the delete succeed (also AC-20's control);
    - a status change to `terminated` is not blocked;
    - with HR's provider not declaring (a test container), the delete succeeds (the "none" default).
  - The existing `DeleteEmployeeTest` stays green and **unedited** (Q-2).
  - **Mutation M11:** count reports of soft-deleted employees (the liveness row goes red).
  - **Gate: `lead` schedules `composer check` if chunk D is committed separately.**
  - *(AC-20 control, AC-82, AC-83 by absence, AC-85 stand-in half.)*

### Chunk E: documents

- [ ] **Step 19: `AddEmployeeDocument`** (with `InMemoryDocumentStore` and `CountingStagedFileReader` bound).
  - **AC-65:** row fields; the server key; the measured size; the SHA-256 equals `hash` of the bytes; `created_by` the actor and `updated_by` null (D-39); one `employee_document_added` row with `original_name`.
  - **AC-66:** each refusal leaves no row and no stored file (store counters); an unknown or soft-deleted employee is `not_found`; `file_path`/`checksum` in the input give `validation`; a past or equal expiry is accepted.
  - **AC-67:** end to end: `file_too_large` and `file_empty` with zero reads recorded; D-51 cases; exactly max accepted.
  - **AC-68:** the 101st is refused `document_limit`; after a soft delete the next one succeeds.
  - **AC-69:**
    - put fails: `storage_unavailable`, no row;
    - row insert fails after put (seam): the bytes are deleted, and the refusal is returned;
    - delete fails during compensation: an error-level log line, and the refusal is still returned.
  - **AC-70:** the key form; the tenant segment equals `TenantContext::tenantId()`.
  - **AC-75:** omitted means `is_confidential = true`; `medical` + false is `validation`; `contract` + false is accepted.
  - **AC-78:** an employee with no profile works.
  - **AC-79:** a forced failure after the row, audit row and file are prepared persists none of the three.
  - `Http::preventStrayRequests()` is on for the file.
  - **Mutation M12:** skip compensation (the AC-69 row goes red).
  - *(AC-65 to AC-70, AC-75, AC-78, AC-79.)*
- [ ] **Step 20: `ListEmployeeDocuments`, `GetEmployeeDocument`, `DownloadEmployeeDocument`.**
  - **AC-71:**
    - bytes, name, mime and size are returned, with `disposition = attachment`; the checksum is verified (stream hashed, then rewound);
    - a tampered byte and a missing file each give `document_corrupt` plus an error-level log line, and no bytes;
    - one `employee_document_downloaded` row with id, type and employee id and no name.
  - **AC-70:** in fixtures A and B with the same document id, each tenant gets its own bytes. A forged `file_path` naming B's tenant segment gives `document_corrupt`, and the store is never asked.
  - **AC-72:** with view only, a confidential document is absent from the list, `not_found` on detail and download, and writes no download row. `view_confidential` makes it appear. `view_confidential` alone gives `not_permitted`.
  - **AC-73:** list fields (never `file_path` or `checksum`), order, paging, the `type` filter; `notes` only in the detail and only with `hr.employees.view_sensitive`.
  - **AC-76 (read half):** soft-deleted documents are excluded.
  - *(AC-58 document cells, AC-70 to AC-73.)*
- [ ] **Step 21: `UpdateEmployeeDocument`, `DeleteEmployeeDocument`, `ListExpiringDocuments`.**
  - **AC-74:**
    - visible-only: an invisible document is `not_found`; manage without view is `not_found` for every document (D-47);
    - `updated_by` is the actor and `updated_at` moves (D-39);
    - declassifying needs `view_confidential`; classifying does not;
    - `medical` cannot be declassified;
    - immutable fields give `not_updatable_here`;
    - the audit row redacts `notes` (null stays null, D-42) and has no `original_name`.
  - **AC-76:** `deleted_at` set; bytes retained (the store still has the key); a second delete is `not_found`.
  - **AC-77:**
    - today included, N+1 excluded;
    - `include_expired`;
    - N of 0 and 366 give `validation`;
    - confidential documents hidden from view only;
    - a soft-deleted document or employee excluded;
    - moving the clock one day moves the window (AC-100).
  - **Gate: `lead` schedules `composer check` (end of chunk E).**
  - *(AC-74, AC-76, AC-77, AC-80 stand-in half, AC-87 documents, AC-100.)*

### Chunk F: sweeps, engine, documents, close-out

- [ ] **Step 22: `HrAuditSweepTest`** (AC-88).
  - Each operation is enumerated and run once in its success shape. It writes exactly one action from the list of eighteen, or none for reads other than the two audited ones (sensitive view only with the flag true and both keys), with `module = hr`, the actor and the subject.
  - `HrAuditActions::ALL` equals the spec's eighteen (printed).
  - **Mutation:** a scratch handler writing `employee_profile_xyz` is refused by the writer and reported.
  - The AC-104 sweep runs in the same file: every list operation, enumerated, refuses page size 0/201 and a malformed cursor (printed).
  - *(AC-87, AC-88, AC-104.)*
- [ ] **Step 23: `HrScenarioTest`, `HrSentinelTest` and `HrQueryLogTest`** (Ruling 12, D-50).
  - **`HrScenarioTest`:** the scenario's guard: every enumerated operation has a script with a success and a refusal path (printed). **Mutation:** a scratch slice with no script entry is reported.
  - **AC-59** over the scenario:
    - a baseline run first;
    - a ≥ 12-character sentinel per field (`nic`, `passport_no`, `personal_email`, `phone`, `address.line1`, `emergency_contact.phone`, `notes`, `exit_reason`), with every operation run in its success and refusal shapes and the forced-failure seams;
    - sinks (1) every column of every tenant and master table, (2) the log, (3) exception messages, (4) every value returned by the list, directory-detail and filter operations and every refusal message (the sensitive detail result is the only permitted carrier), (5) no token read or written (the step 24 source rule plus no statement on a token or session table in the scenario log), (6) a sentinel offered as a filter is refused `validation` before any statement and not echoed;
    - all four controls, including a deliberate leak into `audit_logs.after`, and a seeded-sink control for each of the six sink kinds;
    - the table and column counts printed.
  - **AC-61:** no `outbox_events` row is written by the scenario; a scratch event carrying a sentinel is detected.
  - **AC-2, AC-91, AC-98, AC-99, AC-101:** query-log rules over the scenario with their mutation fixtures and the D-44 positive control; `perm_version` of every fixture employee unchanged and no `access_log` row written (AC-91); fixtures A/B with the same ids run A, then B, then A; a mis-keyed cache fixture is detected (HR has no cache, which is stated).
  - **AC-63:** a stated, non-test note in the file docblock.
  - *(AC-2, AC-59, AC-61, AC-63, AC-91, AC-98, AC-99, AC-101.)*
- [ ] **Step 24: `QaHrSourceRulesTest` (host).** Code only, through `SourceRules::codeOnly`.
  - **Locked reads:** no terminal read in write slices; `*Rows` terminal reads outside `*Unlocked` carry a lock; the whole-table lock in `MoveDepartment`; `holdLive` in every write that sets an employee reference; `lockForUpdate` in `ReportsToChain`.
  - **Boundaries:**
    - no `table('employees')` / `'employees'` identifier in `packages/hr/src`;
    - no HR table name in the `src` of any package other than HR (access included);
    - no `Route::`, `Cache::`, `Storage::`, `Http::`, `curl_`, `fsockopen`, `dispatch(`, `HookGateway`, `select('*')` or `->select()` without the field lists in the profile reads;
    - no token, claim or credential class referenced in `packages/hr/src` (AC-59 sink 5);
    - no inline rendering: no `Response`, `view(` or `inline` disposition in HR (AC-71, AC-81);
    - regexes end in `\z`;
    - contracts are pure.
  - **Red-first:** a scratch `->first()` in a write handler, `table('employees')` in HR, and `table('employee_profiles')` in `packages/sales/src` are each reported. Delete all three.
  - *(AC-8, AC-59 sink 5, AC-81, AC-101 construction halves; M7.)*
- [ ] **Step 25: documents.** Describe, never quote retired vocabulary (the gate sweeps the tree).
  - `docs/database.md` §6.12: Ruling 2's annotation.
  - `docs/architecture.md` §3: HR joins the as-built `packages/` list; the kernel document store is one sentence.
  - Section 10's backlog notes are for `lead`.
- [ ] **Step 26: `HrSchemaEngineTest`** (database `flexipos_t8test_t038_tenant`, created and dropped by **exact name** through `EngineDatabase`, never a `LIKE` sweep). Under the hold only the "refuses to reach the engine" case runs. Written for G-1:
  - AC-1 live listing; AC-4 `information_schema` types and collations; AC-6 the two-engine comparison;
  - AC-7 case and pad collation on the unique `code`;
  - AC-18 / AC-33 two simultaneous same-name creates;
  - AC-29 two moves forming a cycle;
  - AC-45 two reports-to changes forming a loop (D-48);
  - AC-57 two profile creates;
  - AC-80 two adds at 99 documents;
  - AC-85 head assignment against head delete (D-48).

  *(Every `[ENGINE]` clause; NOT VERIFIED under `ENGINE_HOLD`.)*
- [ ] **Step 27: final.** `lead` runs `composer check` from `modules/host`, with counts and `test` seconds compared with step 0. *(AC-10, and every live criterion except the `[ENGINE]` clauses.)*

## 7. Test strategy

- **Unit (pure):**
  - HR: `HrInputTest`, `HrDepartmentRulesTest`, `HrProfileRulesTest`, `HrFileRulesTest`, `HrRefusalTest`, `HrContractShapesTest`, `NoOrgChartTest`;
  - kernel: `DocumentKeyTest`, `LocalDocumentStoreTest`, `InMemoryDocumentStoreTest`.

  These carry most cases as datasets.
- **Integration (SQLite stand-in, `AccessTenantFixture`):**
  - one file per slice, mirrored under `packages/hr/tests/Features/<X>/` (`VerticalSliceLayoutTest`);
  - `HrTenantSchemaTest`, `HrMigrationCatalogueTest`, `HrRegistrySyncTest`, `HrAuthorizationMatrixTest`, `HrOperationsSweepTest`, `HrAuditSweepTest`, `HrScenarioTest`, `HrSentinelTest`, `HrQueryLogTest`;
  - in access: `DatabaseEmployeeIdentitiesTest`, `AuditWriterCharacterisationTest`, `AuditWriterRedactionTest`, `DeleteEmployeeDependentsTest`.
- **Static:** `QaHrSourceRulesTest`, `HrWidthsTest`, deptrac with the demo negative control.
- **Engine:** `HrSchemaEngineTest` (skipped under the hold).
- **Mutations:** M1 to M12 with M2b, M5b, M10b, M10c.

| ACs | Proved by |
|---|---|
| 1–7, 9 (schema rows), 28, 102, 103 | steps 2, 3 (`[ENGINE]` halves step 26) |
| 9 (other sanctioned edits) | steps 1, 4, 9 (section 9) |
| 8 | steps 1, 24 |
| 10 | step 27 |
| 11–14, 64 | step 9 |
| 15–23, 30 | steps 5, 6, 10 (AC-20 control step 18) |
| 24, 25, 86 | step 11 (AC-24's profile half step 16) |
| 26, 27, 54 (departments) | step 12 |
| 29, 57, 80 | `[ENGINE]` step 26 (stand-in halves in steps 10, 16, 19) |
| 31–38 | step 13 |
| 39–49, 60, 91 | steps 7, 15, 16 (AC-91 whole-scenario step 23) |
| 50–56, 58, 62, 90 | step 17 (documents' cells step 20) |
| 59, 61, 63, 2, 98, 99, 101 | step 23 (construction halves step 24) |
| 65–70, 75, 78, 79 | steps 8, 19 |
| 71–73 | step 20 |
| 74, 76, 77, 100 | step 21 |
| 81 | stated; step 24 (no execute, no inline) |
| 82, 84, 85 | steps 4, 18 (AC-85 race step 26) |
| 83 | by absence: no `packages/workflow` file in the diff |
| 87, 88, 104 | step 22 |
| 89 | steps 15, 16, 21 |

## 8. Risks & rollback

1. **Plain-text personal data (D-16).** A dump or backup exposes the sixteen fields. This is mitigated only by access control and by a scan proving no leak through the application's own sinks. Owner question 5.
2. **Gate time.** About 350 cases, roughly 60 % of them pure. Measure at each `lead` gate; above +10 % on the `test` step, stop and report.
3. **Stand-in against engine divergence.** Locks are no-ops on SQLite. Lock presence is the source construction (step 24); lock effect is `[ENGINE]` (step 26). Known engine deadlocks are department move against a concurrent child create, and profile chain locks against a crossing chain; InnoDB rolls one back and `attempts: 2` retries it into a named refusal.
4. **Access edits.** Six additive edits to a shipped package (Rulings 3, 5, 8, the bindings). The behaviour change is limited to `has_dependents`; the audit writer change is proved byte-identical for existing callers by a characterisation test written before the edit. Section 9 lists every edited access file, and `DeleteEmployeeTest` must stay unedited.
5. **Merge renumbering.** Finance chunks D to F and T-040 add tenant migrations in parallel. Rule: the second to merge regenerates the catalogue and the count literals and never edits another task's migration. HR's migrations name no version.
6. **Files outlive rows.** No purge (D-31, D-55), so storage grows forever, and a tenant `--abandon` leaves files under the document root. This is listed for `lead` (section 10).
7. **No transport in this task (D-38).** FP-273 cannot start until the D-56 follow-up lands (section 10).

**Rollback:** every HR file is new. The migrations are forward-only and expand-only, and no tenant outside this branch has run them, so rollback is to abandon the branch. The access additions are inert without a declaring provider.

## 9. Edits to existing files (the predicted-red list; D-40's closed list)

| File | Edit | Step |
|---|---|---|
| `modules/host/composer.json`, `composer.lock` | `flexipos/hr` require, autoload-dev; the path entry only | 1 |
| `modules/host/deptrac.yaml`, `phpstan.neon.dist`, `bootstrap/providers.php` | section 5; `FilesServiceProvider` (step 8), `HrServiceProvider` | 1, 8 |
| `modules/host/tests/Feature/QaToolchainRegistryCoverageTest.php:446` | `'hr'` between `'finance'` and `'http-client'` | 1 |
| `database/catalogue/tenant_schema_versions.json` | republished (+4 rows) | 2 |
| `modules/host/.env.example` | `APP_REQUIRED_SCHEMA_VERSION` 76 → 80 | 2 |
| `modules/host/tests/Helpers.php` `tenantTreeTables()` | 4 names under a T-038 comment | 2 |
| `modules/host/tests/Feature/MigrationTreeDisciplineTest.php:152` | 69 → 73 | 2 |
| `modules/host/tests/Feature/IdempotencyKeysSchemaTest.php:122-124` | top entry → 80, `2026_11_16_003804_create_employee_documents_table`, `expand` | 2 |
| `modules/host/tests/Feature/TenantBaselineBuildsTest.php` | +**14** keys sorted into the whole inventory (the eight onto `employees` including `employee_documents.updated_by`; two onto `branches`; two onto `departments` plus the self key; one onto `designations`); the branch-key table list + `departments`, `employee_profiles` | 2 |
| `modules/host/tests/Feature/QaAccessCloseOutSweepsTest.php:151-153`, `QaAccessSchemaShapeTest.php:233-235` | `$t038 = ['departments', 'designations', 'employee_documents', 'employee_profiles']` added to the `array_diff` | 2 |
| `SchemaCataloguePublishTest` | fixed by the republish (no source edit) | 2 |
| `packages/contracts/src/Access/RefusalReason.php`; `packages/access/tests/Contracts/RefusalReasonTest.php` | `has_dependents`; the value added to the list, 17 → 18 (D-43) | 4 |
| `packages/access/tests/Support/AuditCatalogueTest.php:48` and the fixture variant | `'hr'` in both module literals | 9 |
| access tests that count registered keys or owner grants, if any | +10 (record the exact files at step 9; none found by grep at planning). Any found is reported to `architect` before editing, because D-40's list does not name one | 9 |
| `packages/access/src/{AccessServiceProvider.php, Support/AuditWriter.php, Support/EmployeeLookup.php, Exceptions/AccessRefused.php, Features/DeleteEmployee/DeleteEmployeeHandler.php}` | Rulings 3, 5 and 8 (additive) | 14, 15, 18 |
| `docs/database.md` §6.12, `docs/architecture.md` §3 | step 25 | 25 |

**Measured: no edit expected** (stay on the red-list watch):
- `TenantDecimalPrecisionTest`: HR declares no decimal;
- `QaAccessHttpSurfaceTest`: it pins only the branch-scoped key list, and HR's keys are all non-branch-scoped;
- `BranchForeignKeysMigrationTest`: its key list is one migration's nine keys, not an inventory;
- `modules/host/app/Http/Responses/AccessRefusals.php`: renders the new reason unchanged (0.1);
- `VerticalSliceLayoutTest`: walks every package; obey the mirror;
- `packages/sales/tests/Schema/LoyaltySaleIdMigrationsTest`: asserts `highestVersion() >= ` since T-032;
- `DeleteEmployeeTest`;
- `CustomersSchemaEngineTest` (no `AuditTrail` signature change);
- `packages/workflow/tests/Definitions/FakeEmployeeDirectory.php` (no `EmployeeDirectory` change).

Anything red outside this table means stop and report to `architect`.

## 10. Follow-ups for `lead` (backlog rows or notes, not built here)

- **HR transport follow-up (D-56):** the proposed backlog row is in the spec's Out of scope; `lead` assigns the number. It must land before or with FP-273. Its deployment half (request body limits in PHP and nginx) belongs there.
- **FP-358:** consume `Contracts\Hr\OrgChart`; the approver types stay refused until then (AC-83).
- **FP-280 / payroll:** bind `HrReferrers` and `EmployeeDependents` for its own references, and add the D-5 columns.
- **Expenses and leave:** reuse `Kernel\Files\DocumentStore` and `DocumentKey`; promote `FileRules` and `StagedFileReader` to the kernel on the second user.
- **Operations:** document-root backup and retention; `tenant:provision --abandon` leaves files (risk 6, D-55).
- **`ba`:** Q-1 to Q-4 (0.3), wording only.
- **FP-433:** another copy of the support helpers.
- **Optional (FP-181 shape):** derive `IdempotencyKeysSchemaTest`'s top entry from the tree instead of a literal every task edits.

## Questions for the human

None. The owner-visible items are in the decisions file (questions 1 to 12, all defaulted, including no transport in this task, the delete refusal for heads and managers, and files kept forever).
