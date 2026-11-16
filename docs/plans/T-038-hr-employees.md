# Plan: T-038 HR master data: employee profiles, departments, designations, documents

Spec: `docs/specs/T-038-hr-employees.md` (AC-1 to AC-104, sections A to K). Decisions: `docs/decisions/T-038-hr-employees.md` (D-1 to D-37).
Design authority: `docs/database.md` §6.12 (HR), §3.1/§3.2; `docs/architecture.md` §3 (as-built placement rule), §6; `docs/patterns.md` §3 to §6.
House model: `docs/plans/T-032-chart-of-accounts.md` and the shipped `packages/finance` (handler-level authorization, permissions registry, audit-action registry built from handler constants, refusal enum, usage ports, authorization matrix test, widths test).
Backlog: FP-270. Branch: `ai/T-038-hr-employees`. Gate directory: `modules/host`.

Everything about shipped code below was measured read-only in this worktree at `29cd436`.

---

## 0. Verification, findings and rulings

### 0.1 What the branch contains (measured)

| Claim | Verdict | Evidence |
|---|---|---|
| T-010, T-014, T-017, T-021 code is on the base | **Holds** | `packages/access`, `packages/organization`, `AuditWriter` (T-017 catalogue check), `packages/kernel/src/Api` all present; finance chunk C is the tip |
| Tenant tree today | 76 files, top version **76** (`2026_11_15_003203_create_fiscal_periods_table`), `APP_REQUIRED_SCHEMA_VERSION=76`, `tenantTreeTables()` holds 69 names | `modules/host/database/migrations-tenant/`, `.env.example:73`, `tests/Helpers.php:521`, `MigrationTreeDisciplineTest:152` |
| An identity read contract exists | **Partly** | `Contracts\Access\EmployeeDirectory::isLive(id)` only (one row, a bool). `RoleHolders::actingAmong(ids)` is the precedent for a bulk id-list contract. `packages/workflow/tests/Definitions/FakeEmployeeDirectory.php` implements `EmployeeDirectory`, so **adding a method to it breaks a test double**: a new interface is needed |
| A branch read contract exists | **Holds** | `Contracts\Organization\BranchDirectory::state(id): BranchState` (`unknown`, `active`, `inactive`; soft-deleted counts as unknown) |
| Audit writer | `AuditTrail::record(module, ?actorId, action, subjectType, subjectId, ?before, ?after, ?branchId)`; refuses an undeclared (module, action) pair; **derives** `changed_fields` from `before`/`after` values (`AuditWriter::changedFields`) | `packages/contracts/src/Audit/AuditTrail.php`, `packages/access/src/Support/AuditWriter.php` |
| Audit action naming | **Mixed, per module**: finance `account_created` (unprefixed), catalog `catalog_*` (prefixed), customers mostly unprefixed. The catalogue keys on the (module, action) pair, so names cannot collide across modules | `grep "const ACTION" packages/*/src`; `AuditCatalogue::knows()` |
| Kernel may not depend on contracts | **Holds** | `deptrac.yaml:594` `Package_Kernel: [Package_Kernel_TenancyStatus]` only |
| Routes | Only access, auth and organization ship tenant routes; catalog, customers, pricing, sales and finance ship **none** (T-032 D-24, plan §2 "Building HTTP routes now" rejected) | `find packages -name routes`; `packages/finance/src/FinanceServiceProvider.php` "It declares no route" |
| Access delete | `DeleteEmployeeHandler` reads the target with `EmployeeLookup::findLive()` **unlocked**, then `LastOwner`, update, bump, audit, in one transaction. No permission check in the handler (the route carries it) | `packages/access/src/Features/DeleteEmployee/DeleteEmployeeHandler.php` |
| Access refusal vocabulary | Closed enum of **17** in `Contracts\Access\RefusalReason`, pinned by `packages/access/tests/Contracts/RefusalReasonTest.php` (`toHaveCount(17)`) | |
| Owner gets new keys automatically | **Holds** | `SyncPermissionRegistryHandler::keepOwnerWhole()` (T-010 AC-17) |
| Tenant identity for a storage key | `Flexipos\Kernel\Tenancy\TenantContext::tenantId()` | `packages/kernel/src/Tenancy/TenantContext.php` |

### 0.2 Spec corrections routed to `ba` (numbered; S = must change before build)

The spec's inputs defect is confirmed: local `docs/specs/T-010-employees-roles-permissions.md` and `docs/specs/T-032-chart-of-accounts.md` both exist. Every T-010 citation in the spec (AC-3, AC-4, AC-11, AC-17, AC-28, AC-29, AC-63, AC-72, D-12, D-13, D-14, D-26, D-27, D-32) resolves correctly against the local copies. The defects are elsewhere:

- **C-1 (header).** "D-1 to D-32" should be D-1 to D-37 (`docs/decisions/T-038-hr-employees.md`, D-33 to D-37).
- **C-2 (Assumptions, last bullet).** "The worktree has no `docs/specs/` of its own for T-010 and T-032" is false. Proof: `docs/specs/T-010-employees-roles-permissions.md`, `docs/specs/T-032-chart-of-accounts.md`.
- **C-3 (Current behaviour).** Access declares **sixteen** audit actions, not fifteen. Proof: `packages/access/src/Support/AccessAuditActions.php` (16 entries); `packages/access/tests/Support/AuditCatalogueTest.php` "holds access s sixteen actions".
- **C-4 (S, AC-17 and D-7).** D-7 claims "the same shape as T-032 D-13", but T-032's code must **start with a letter or digit**: `^[0-9A-Z][0-9A-Z.-]{0,19}$` (`docs/specs/T-032-chart-of-accounts.md` AC-2). As written, AC-17 accepts `.AB` and `-AB`. AC-17 should adopt the T-032 regex and add the controls `.AB`, `-AB`, a tab, a NUL byte and a non-ASCII letter (each refused).
- **C-5 (informational, AC-88 and D-36).** D-36 says "follows T-010 and T-032 naming". T-032's names are unprefixed (`account_created`, `packages/finance/src/Features/CreateAccount/CreateAccountHandler.php:41`). Catalog prefixes (`catalog_brand_created`). The catalogue keys on (module, action) (`packages/access/src/Support/AuditCatalogue.php`, `knows()`), so collisions are impossible. **The eighteen unprefixed names stand, and no change is needed.** D-36's reason should say "unprefixed like T-032; uniqueness is per module". The premise that a module prefix such as `finance_*` is the house rule is not what the code does.
- **C-6 (S, AC-60 and AC-89 against the shipped writer).** `AuditWriter::changedFields()` derives `changed_fields` by comparing `before` and `after`. If HR writes `"[redacted]"` on both sides, a changed sensitive field compares equal and is **dropped from `changed_fields`**. AC-60 ("`changed_fields` names the field") is then unsatisfiable by caller-side redaction. Proof: `packages/access/src/Support/AuditWriter.php`, `changedFields()`. Plan Ruling 8 fixes this with an additive `RedactingAuditTrail` contract: the writer derives `changed_fields` from the real values first and masks second. AC-60 should say "masked by the writer". It should also settle nulls: **a sensitive field that is null on one side stays null on that side, and a non-null value becomes `"[redacted]"`**. Otherwise a create would claim a passport number exists for every profile.
- **C-7 (S, AC-85).** `has_dependents` is not in access's closed vocabulary.
  - Adding it edits `packages/contracts/src/Access/RefusalReason.php` (17 to 18) and the pre-existing `packages/access/tests/Contracts/RefusalReasonTest.php`. AC-9's "no other pre-existing test is edited" must name it.
  - Access's HTTP body is `{"error": "<reason>"}` and nothing else (`modules/host/app/Http/Responses/AccessRefusals.php`). So "the refusal names the kind of dependent" can only be true of the **in-process** refusal (`AccessRefused` detail), not of the response body. Reword it that way.
- **C-8 (S, AC-9 edit list incomplete).** AC-9 sanctions only "exact tenant table list" and "foreign keys onto `employees`" tests. A new package with new migrations must also edit the following. Each is a hard-coded literal, measured in section 9:
  - `AuditCatalogueTest` (module lists);
  - `QaToolchainRegistryCoverageTest` (package literal);
  - `MigrationTreeDisciplineTest` (69);
  - `IdempotencyKeysSchemaTest` (top version and name);
  - `TenantBaselineBuildsTest` (the **whole** foreign-key inventory, including keys onto `branches`, `departments` and `designations`, and the branch-key table list);
  - `QaAccessCloseOutSweepsTest` and `QaAccessSchemaShapeTest` (per-task exclusion lists);
  - `RefusalReasonTest` (C-7).

  AC-9 should list these by name.
- **C-9 (S, AC-20).** The control sentence is garbled ("leaves the department intact but and …") and contradicts AC-85: soft-deleting a department head is **refused**. Reword it as: "Control: after the head is cleared, the same employee's soft delete is no longer refused by AC-85."
- **C-10 (AC-24 cross-reference).** "(AC-45, AC-19 as parent, AC-34)": AC-45 is reports-to. A profile's department is AC-44.
- **C-11 (S, AC-56).** `employee_profiles` has `created_by`, `updated_by`, `created_at` and `updated_at`, which are neither sensitive nor directory. The "every column classified" sweep is red on day one. Add a third closed class, **internal** (never returned by an HR read), and list those four columns in "Closed value sets".
- **C-12 (S, section J and D-37).** T-032 built no routes (`docs/decisions/T-032-chart-of-accounts.md` D-24 "no route layer exists"; `docs/plans/T-032-chart-of-accounts.md` §2). No package since T-014 has shipped routes. The plan follows T-032 and **takes D-37's own fallback: operations only** (Ruling 11). `ba` should:
  - move AC-92 to AC-97 to Out of scope, with a follow-up row;
  - amend AC-59's sinks: (4) becomes "every value returned by the list, directory-detail and filter operations, and every refusal message"; (5) becomes "HR adds no token claim"; (6) moves to the route task;
  - turn AC-94's body-size refusal into an operation-level `file_too_large`, decided from the staged file's size **before any byte is read**. The HTTP 413-before-buffering half moves to the route task.
- **C-13 (AC-84 citation).** "T-032 D-7 pattern; the null default lives with the interface" misstates T-032. Finance's defaults live in the finance package (`packages/finance/src/Accounts/NoAccountUsage.php`), bound by finance's own provider. HR is a `packages/` package registered in `bootstrap/providers.php` (T-032 D-24 precedent), so "a deployment without HR" does not arise. Reword AC-84: "`Contracts\Hr` ships a `NoOrgChart` that answers none to every question; a test proves it; production binds HR's implementation."
- **C-14 (S, AC-101).** "Except the single-row existence and liveness lookups" forbids the bulk identity read a 200-row page needs, which D-17 explicitly allows. Reword it as: "except queries issued by access's own implementations of its published contracts (`EmployeeDirectory`, `EmployeeIdentities`)". Attribution is by the innermost `Flexipos\` frame of the query's backtrace (Ruling 12).
- **C-15 (AC-2, AC-59, AC-101 "over a full run of this task's tests").** A listener cannot practically wrap every test file. Reword it as: "over a scripted scenario that runs every operation, enumerated from the operations directory, with success and refusal paths".
- **C-16 (S, AC-25 and AC-37 "live profile").** Define it as "a profile whose employee is live". Otherwise a department referenced by a soft-deleted employee's retained profile (which AC-49 makes unwritable) can never be deleted.
- **C-17 (data table, `employee_documents`).** `docs/database.md` §6.12 gives the row `[BY]` (`created_by` **and** `updated_by`). The spec builds only `created_by`, although metadata is editable (AC-74), and D-11 does not state the omission. Either add `updated_by` (nullable FK `employees`, the profile precedent) or state the deviation in D-11. The plan builds the spec as written; if `ba` adds the column, step 2 gains one column and one key.
- **C-18 (AC-66 wording).** "`employee_not_found` as `not_found`" names a code that does not exist. Use: "an unknown or soft-deleted employee is `not_found`".
- **C-19 (AC-67).** Missing and unknown extensions are undefined. Plan: a name with no extension, or with an extension outside the map, is `file_type_not_allowed`.
- **C-20 (AC-14 against the document tiers).** A holder of only `hr.documents.manage` passes the key check, but every update or delete then answers `not_found`, because the tier needs `hr.documents.view` (authorization matrix). AC-14's "only that key passes" control for those two operations must assert "not `not_permitted`", not success.
- **C-21 (AC-53).** With both keys, every detail read returns sensitive data and writes an audit row, even when the caller wants only the directory shape. The plan adds `include_sensitive` (default false). The sensitive tier and the audit row apply only when it is true **and** both keys are held. AC-53 and AC-90 should say so.
- **C-22 ([ENGINE] markers missing).** Two concurrent reports-to changes that together close a loop (AC-45), and a head assignment racing the head's soft delete (AC-85), are both guarded by row locks (Ruling 7). Their proofs are `[ENGINE]`.

### 0.3 Rulings

**Ruling 1: placement.**
- A new path package `packages/hr` (`flexipos/hr`, namespace `Flexipos\Hr\`), registered in `bootstrap/providers.php` after `FinanceServiceProvider` (T-032 D-24 precedent; architecture §3 as-built rule: a domain others read through a contract lives under `packages/`).
- Source reaches `Package_Kernel` and `Package_Contracts` only; tests may also reach `Package_Access` (the fixture). `Module_Demo` is **not** granted `Package_Hr` (AC-8's instrument).
- Its `Support/` helpers (`TenantConnection`, `Input`, `Fields`, `Authorize`, `Limits`) are another per-package copy (FP-433). `Input` is copied from finance's strict parser, not from the older trimming copies.

**Ruling 2: the sidecar** (D-1, AC-3, AC-39). `employee_profiles` is keyed by `employee_id`: `IdentifierColumn::primary($table, 'employee_id')` plus a key onto `employees` (RESTRICT, the engine default). HR never writes, and never reads raw, any `employees` column. **Proposed `docs/database.md` §6.12 annotation** (`dev` adds it at step 25, as text describing the change, never quoting retired vocabulary; until then it is owed):

> #### As built (T-038)
> HR facts about a person live in a one-to-one sidecar, `employee_profiles`, keyed by `employee_id` (primary key and foreign key onto `employees`), not on `employees` as the row above prints. `employees` remains the identity and access table owned by the access package (T-010) and carries no HR column; an employee without a profile is valid (a cashier). The profile holds branch, department, designation, reports-to, employment type, joining, confirmation, probation-end, resignation and last-working dates, exit reason, and the personal fields (date of birth, gender, marital status, nationality, national identity number, passport number, personal and work email, phone, emergency contact, address, notes). Sixteen fields are classified sensitive in one closed list in code: they are readable only with a second permission and are never written to a log, an audit value or an event. The indexes the `employees` row lists for department, branch and reports-to live on `employee_profiles`. Not built by T-038 and still owed to the payroll tasks: the salary, pay-frequency, salary-structure, commission, sales-person, default-location, tax-number and statutory-number columns and the photo path; `departments.cost_centre_account_id`; `employee_bank_accounts` and `employment_history`. `employee_documents` adds `original_name`, `mime`, `size_bytes`, `checksum`, `updated_at` and `deleted_at`; its key onto `employees` is RESTRICT rather than CASCADE, because employees are never hard-deleted; `file_path` holds a server-generated storage key of the form tenant / `hr/employee-documents` / ULID, read and written only through the kernel's document store.

**Ruling 3: identity through access's published contract** (D-17, AC-50 to AC-52, AC-101). There is a new additive interface, rather than a widened `EmployeeDirectory`, because a workflow test double implements that one.
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

**Ruling 4: HR's published contracts** (`packages/contracts/src/Hr/`; AC-82, AC-84, AC-86; spec open question 10(f)).
- `OrgChart` has `managerOf`, `departmentHeadOf`, `departmentOf`, `designationOf` (each `?string`), `directReportsOf(string): DirectReports` (readonly `list<string> $ids`, `bool $truncated`, maximum 1000), `isLiveDepartment` and `isLiveDesignation`.
  - `NoOrgChart` is in the same namespace and answers none: null, empty, false (C-13).
  - HR binds `OrgChart` to `Hr\Org\DatabaseOrgChart`.
- `HrReferrers` has `departmentReferrers(string $id): list<HrReferrer>` and `designationReferrers(string $id): list<HrReferrer>` (`HrReferrer` is a readonly `kind`, `count`). `DeclaresHrReferrers` is a provider interface returning `list<class-string<HrReferrers>>`. HR's `Org\ReferrerCensus` sweeps `$app->getProviders(DeclaresHrReferrers::class)`, the finance `ReferrerCensus` precedent. **Default "none" is the empty sweep.**
- Answer to 10(f): **two registration points, on purpose.** The read contract has exactly one implementation (a container binding). The usage ports have many implementers (expenses, payroll, workflow), so they are a provider sweep.

**Ruling 5: the employee-delete guard in access** (D-35, AC-85).
- **Contracts, all additive:**
  - `Contracts\Access\EmployeeDependents::dependentKindsOf(string $employeeId): list<string>`, whose docblock says implementations count with locking reads;
  - `Contracts\Access\DeclaresEmployeeDependents` (a provider sweep returning `list<class-string<EmployeeDependents>>`);
  - `RefusalReason::HasDependents = 'has_dependents'`.
- **The "none" default:** no provider declares one, so the census is empty.
- **Access changes**, all additive:
  - `Support\EmployeeDependentsCensus` (the sweep);
  - `EmployeeLookup::findLiveForUpdate()` (new method; `findLive` is untouched);
  - `AccessRefused::hasDependents(list<string> $kinds)`, which carries the kinds as a readonly `detail` (a new optional constructor argument; no other constructor changes).
- **`DeleteEmployeeHandler`** takes the target `findLiveForUpdate`, then `LastOwner`, then the census. A non-empty census refuses `has_dependents` before the update, the bump and the audit row. Status changes are untouched.
- **HR's side:** `HrServiceProvider implements DeclaresEmployeeDependents` returning `Hr\Org\HrEmployeeDependents`, which reports:
  - `department_head` when a live department has `manager_employee_id = E` (shared lock);
  - `direct_reports` when any profile has `reports_to_employee_id = E` **and** that profile's employee is live (`identitiesOf`).

  It returns kinds only, never ids.

**Ruling 6: the document store lives in the kernel** (D-9, spec open question 10(d)).
- **Why the kernel:** the kernel may not import contracts (deptrac), and every module may use the kernel. So the interface and its implementations sit together in `packages/kernel/src/Files/`, and expenses or leave reuse them without touching HR.
- **The classes:**
  - `DocumentStore`: `put(string $key, resource $stream): void` (refuses an existing key), `open(string $key): resource` (`DocumentMissing` if absent), `delete(string $key): void` (idempotent). Every failure is `DocumentStoreFailed`.
  - `DocumentKey`: `mint(string $tenantId, string $area): string` gives `<tenant>/<area>/<ULID>`; `parse(string): DocumentKey` exposes `tenantSegment()` and `area()`. It refuses `.`, `..`, `\`, `:`, NUL, empty segments and any tenant segment outside `\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z`.
  - `LocalDocumentStore`: the root comes from `config/files.php` `document_root`, default `storage_path('app/private/documents')`. The constructor refuses a root inside `public_path()` or `storage_path('app/public')`. It re-validates every key with `DocumentKey::parse` before touching the disk. Writes go to a temp file and are then renamed, with no overwrite.
  - `InMemoryDocumentStore`: the fake, with `failOnPut`, `failOnDelete` and `failOnOpen` toggles and put/open/delete counters (the AC-14 and AC-69 spy). It sits in `src` like the kernel's `InMemoryTenantDatabaseRepository`.
  - `FilesServiceProvider` binds `DocumentStore` to `LocalDocumentStore` (singleton), registered in `providers.php` after `ApiServiceProvider`.
- **No SDK and no network.** Content detection (`finfo`, `FILEINFO_MIME_TYPE`) and the allow-list stay in HR (`Documents\FileRules`, one list in one place, AC-67). They are promoted only on a second user.

**Ruling 7: transactions and locks** (the T-032 W-4/W-1 construction, applied here).
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
| Department delete, deactivate, reactivate | target `FOR UPDATE`; children, designations and profiles counts `sharedLock`; referrers census |
| Designation writes | target `FOR UPDATE`; live designations in the same `department_id` group `lockForUpdate` (index `department_id`); department `sharedLock` |
| Profile create, update | profile `FOR UPDATE` (update); employee `holdLive`; branch, department and designation `sharedLock` / directory; reports-to chain walk `lockForUpdate` on each visited profile (≤ 50); a create's PK violation maps to `profile_exists` |
| Document add | `employee_documents` of the employee `lockForUpdate` count (next-key lock on index `(employee_id, type)` serialises the 100 limit on InnoDB); employee `holdLive` |
| Document update, delete | target `FOR UPDATE` |
| Access delete (Ruling 5) | employee `FOR UPDATE`, then HR's census reads with `sharedLock` |

- **Head assignment against head delete:** HR's `holdLive` (shared) and access's `findLiveForUpdate` (exclusive) on the same `employees` row serialise the race (C-22). The proof is `[ENGINE]`.
- **SQLite stand-in:** locks compile to nothing, so their presence is a **source construction** (step 24) and their effect is step 26 (`[ENGINE]`).

**Ruling 8: personal data** (AC-50 to AC-64, AC-89, AC-90).
- **`Profiles\ProfileFields` is the one closed classification**, holding three lists: `SENSITIVE` (the 16), `DIRECTORY` and `INTERNAL` (`created_by`, `updated_by`, `created_at`, `updated_at`; C-11).
  - The directory list read builds its `select` from `DIRECTORY` only, never `*`.
  - The sensitive tier adds `SENSITIVE`.
- **Redaction lives in the one writer.** It is a new additive `Contracts\Audit\RedactingAuditTrail::recordRedacted(module, actorId, action, subjectType, subjectId, before, after, list<string> $redact, ?branchId)`, implemented by `AuditWriter` and bound in `AccessServiceProvider` to the same singleton. The writer computes `changed_fields` from the real values, replaces every non-null value of a `$redact` key with `"[redacted]"`, and inserts. (C-6; a new interface rather than a new parameter on `AuditTrail`, because `tests/Engine/CustomersSchemaEngineTest.php` implements `AuditTrail` anonymously and a signature change would break that file at load.)
- **What HR passes to the writer:**
  - profile writes pass `ProfileFields::SENSITIVE`;
  - document writes pass `['notes']` and never put `file_path`, `checksum` or bytes in `before`/`after`;
  - `original_name` appears only in `employee_document_added` (D-32);
  - the sensitive-view row has `before = null`, `after = ['employee_id' => …]`.
- **No refusal message, exception message or log line includes a submitted value.** Messages name fields only (all fields, not only sensitive ones, so there is no list to keep in sync).
- **No outbox event** is written by any HR operation (AC-61).
- **Query-string and path sink:** not applicable without routes (C-12).
- **D-16 risk, stated:** sensitive columns are plain text. A database dump or backup exposes them. Application encryption is the owner's call (open question 5).

**Ruling 9: refusals.**
- `Exceptions\HrRefused` (readonly `HrRefusal $reason`, `?string $field`, `array $context`) and `HrRefusal`, a backed enum of exactly the spec's 27 codes. `HrRefusalTest` pins the closed set.
- `validation` names every failing field in one refusal (`Fields::only`, the finance shape).
- Reads that hide a confidential document answer `not_found`, never `not_permitted` (AC-72).

**Ruling 10: permissions and audit actions.**
- **`Registry\HrPermissions`:** the ten keys of AC-11, module `hr`, groups `employees`, `departments`, `designations` and `documents`, all `isBranchScoped = false`, declared through `DeclaresPermissions`.
- **The key check.** Every handler declares `public const PERMISSION` (its one key, AC-13), and `Authorize::require()` checks it.
- **Second-tier checks.** `view_sensitive` and `view_confidential` are tier checks inside the read, through `Authorize::holds()`, a non-throwing variant. They are not operation keys.
- **`Registry\HrAuditActions::ALL`** is built from each write handler's `ACTION` / `ACTION_*` constants (the finance precedent), so it cannot drift. `MODULE = 'hr'`. Each action is used with `subject_type` `department`, `designation`, `employee_profile` or `employee_document`.

**Ruling 11: no routes** (C-12, D-37's fallback; T-032 D-24, T-032 plan §2).
- **The deliverable is the 28 operations.** AC-92 to AC-97 are not built; FP-273 (screens) or the API task adds thin routes with `RequireTenantPermission` declaring the same keys. That brings the `access_log` denial rows (AC-96).
- **Upload size (AC-67, AC-94).** `AddEmployeeDocument` takes a **staged file path** (what a future controller gets from `UploadedFile::getRealPath()`) plus the original name. It refuses `file_too_large` from `filesize()` **before opening the file**. It hashes with `hash_file('sha256')`, which streams, detects the type with `finfo_file`, and streams the bytes into the store, so nothing is buffered whole.
- **Owed to the route task:** HTTP 413 before buffering, which means `post_max_size` and `upload_max_filesize` ≥ maximum plus headroom, nginx `client_max_body_size`, and Laravel's `ValidatePostSize`. This is listed in section 10.

**Ruling 12: the instruments.**
- **Operation enumeration (AC-13, AC-88, AC-104):** `Hr\Tests\Support\HrOperations::all()` reads `packages/hr/src/Features/*` with reflection on each handler's constants. It never uses a list in the test.
- **The authorization matrix** keeps a callable per slice (the finance shape) and asserts that its key set equals the enumerated directory, so a scratch slice with no matrix row fails.
- **Query-log scans (AC-2, AC-99, AC-101):** a listener on the tenant and master connections records SQL, bindings and the innermost `Flexipos\` frame of `debug_backtrace`. The rules:
  - **HR attribution:** an HR-attributed statement naming `employees` as an identifier (`/\bemployees\b/` on the identifier-quoted SQL) is a violation;
  - **other modules:** a statement from any non-HR, non-access frame naming an HR table is a violation;
  - **tenant predicate:** a statement on an HR table containing `tenant_id` is a violation;
  - **master:** an HR scenario that writes to master is a violation.

  Each rule carries a mutation fixture.
- **The sentinel harness (AC-59):**
  - it captures the DB (every column of every tenant and master table, from the live schema), the log (a Monolog `TestHandler` pushed onto the default channel), exception messages and every operation's returned value, `json_encode`d;
  - it scans for raw, URL-encoded, JSON-escaped and reversed forms;
  - it runs the baseline-first and the four controls.

---

## 1. Approach summary

1. A new `packages/hr`, registered in `providers.php`, with four expand-only tenant tables: `departments`, `designations`, `employee_profiles` (the sidecar) and `employee_documents`. `employees` is untouched.
2. 28 vertical slices. Each checks its one key first through `PermissionResolver`, parses strictly, and locks in a fixed order.
3. Identity comes only through access's contracts: `EmployeeDirectory`, plus the new additive `EmployeeIdentities` for bulk and paged reads. Branches come through `BranchDirectory`.
4. The personal-data regime is one classification class, a directory-only select, and writer-side redaction through a new additive `RedactingAuditTrail`, plus a sentinel harness that proves no leak.
5. Documents are rows plus bytes behind a kernel `DocumentStore` (local disk plus an in-memory fake), with server-minted tenant-segmented keys, detected content types, a checksum verified on read, and compensation on failure.
6. Published contracts: `Contracts\Hr\OrgChart` for FP-358, with `NoOrgChart`; `HrReferrers` usage ports (a provider sweep, default none); and in access, an `EmployeeDependents` sweep that makes the T-010 delete refuse `has_dependents`.
7. No HTTP routes (the T-032 precedent). Engine proofs are written and skipped under `ENGINE_HOLD` (NOT VERIFIED).

## 2. Alternatives rejected

- **HR columns on `employees`** (database.md as printed): D-1, and T-010 AC-3.
- **HR joins `employees` in SQL**: legal in one database (patterns §4.3), but the spec makes access the only reader (D-17, AC-101). The contract is the boundary.
- **Widening `EmployeeDirectory`**: breaks `FakeEmployeeDirectory` in workflow tests.
- **An optional `$redact` parameter on `AuditTrail::record`**: breaks the anonymous implementer in `CustomersSchemaEngineTest` at file load.
- **Caller-side `"[redacted]"`**: loses `changed_fields` (C-6).
- **`DocumentStore` in `packages/contracts` with the implementation in the kernel**: the kernel may not import contracts.
- **Placing it in HR**: forces expenses to import HR.
- **A single bound delete guard** (the access default, rebound by HR): the second module to guard deletes (payroll) would overwrite HR's. A sweep composes.
- **`path`/`depth` columns on `departments`**: not in the spec's column list (AC-3 exactness), and a walk of ≤ 10 levels plus a whole-table lock on a small table suffices.
- **Thin routes now** (section J): Ruling 11.
- **An opaque, signed cursor**: an unsigned base64url JSON `{l: list, k: [...]}` suffices. A forged cursor can only reposition inside rows the caller may already see, because visibility is in the `WHERE`. A cursor naming another list, or a malformed one, is `cursor_invalid`.

## 3. Files to change / create

`dev` writes every file. `Qa*` files are `qa`-accountable.

### `packages/contracts/src/` (additive)
| Path | What |
|---|---|
| `Access/EmployeeIdentity.php`, `Access/EmployeeIdentities.php` | Ruling 3 |
| `Access/EmployeeDependents.php`, `Access/DeclaresEmployeeDependents.php` | Ruling 5 |
| `Access/RefusalReason.php` | `+ case HasDependents = 'has_dependents'` |
| `Audit/RedactingAuditTrail.php` | Ruling 8 |
| `Hr/OrgChart.php`, `Hr/DirectReports.php`, `Hr/NoOrgChart.php`, `Hr/HrReferrers.php`, `Hr/HrReferrer.php`, `Hr/DeclaresHrReferrers.php` | Ruling 4 |

### `packages/kernel/src/Files/` (new)
`DocumentStore.php`, `DocumentKey.php`, `LocalDocumentStore.php`, `InMemoryDocumentStore.php`, `DocumentStoreFailed.php`, `DocumentMissing.php`, `FilesServiceProvider.php` (Ruling 6). Tests: `packages/kernel/tests/Files/{DocumentKeyTest,LocalDocumentStoreTest,InMemoryDocumentStoreTest}.php`.

### `packages/access` (additive)
- `src/Support/DatabaseEmployeeIdentities.php` (Ruling 3).
- `src/Support/EmployeeDependentsCensus.php`, `src/Support/EmployeeLookup.php` (`+ findLiveForUpdate`), `src/Exceptions/AccessRefused.php` (`+ hasDependents`, optional `detail`), and `src/Features/DeleteEmployee/DeleteEmployeeHandler.php` (lock plus census, three lines) (Ruling 5).
- `src/Support/AuditWriter.php` (`implements RedactingAuditTrail`, `+ recordRedacted`).
- `src/AccessServiceProvider.php` (three bindings).

Tests (new files only, plus C-7's one edit):
- `tests/Support/DatabaseEmployeeIdentitiesTest.php`;
- `tests/Support/AuditWriterRedactionTest.php`;
- `tests/Features/DeleteEmployee/DeleteEmployeeDependentsTest.php`.

### `packages/hr` (new)
| Path | What |
|---|---|
| `composer.json` | `flexipos/hr` 0.1.0, requires `flexipos/contracts`, `flexipos/kernel` |
| `src/HrServiceProvider.php` | binds `OrgChart`; implements `DeclaresPermissions`, `DeclaresAuditActions`, `DeclaresEmployeeDependents`. No routes, commands or jobs |
| `src/Support/{TenantConnection,Input,Fields,Authorize,Limits,Cursor,PageSize}.php` | Rulings 1, 7, 9; `Cursor` / `PageSize` per D-25 |
| `src/Exceptions/{HrRefused,HrRefusal}.php` | Ruling 9 |
| `src/Registry/{HrPermissions,HrAuditActions}.php` | Ruling 10 |
| `src/Departments/{DepartmentRows,DepartmentRules,DepartmentInput,NameKey}.php` | tree, cycle, depth, the name key (`mb_strtolower(trim())`) |
| `src/Designations/{DesignationRows,DesignationInput}.php` | |
| `src/Profiles/{ProfileFields,ProfileInput,ProfileRows,DateRules,ShapeRules,ReportsToChain,EmploymentType}.php` | Ruling 8; AC-41 to AC-47 |
| `src/Documents/{DocumentRows,DocumentInput,DocumentType,FileRules,DocumentVisibility}.php` | AC-65 to AC-77 |
| `src/Org/{DatabaseOrgChart,ReferrerCensus,HrEmployeeDependents}.php` | Rulings 4, 5 |
| `src/Features/<28 slices>/` | section 4, the slice table |
| `tests/Support/{HrSeed.php,hr-fixture.php,HrOperations.php,FakeHrReferrers.php,SentinelHarness.php,QueryLog.php}` | |
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

## 4. Data / API changes

**Schema** (tenant only; four expand migrations; no existing table altered):

| Table | Columns as built | Keys and indexes |
|---|---|---|
| `departments` | `id`, `code` VARCHAR(20), `name` VARCHAR(150), `parent_id` ULID NULL, `manager_employee_id` ULID NULL, `branch_id` ULID NULL, `is_active` default true, `created_at`, `updated_at`, `deleted_at` DATETIME(3) | unique `code`; index `parent_id`; FKs `parent_id` → departments, `manager_employee_id` → employees, `branch_id` → branches |
| `designations` | `id`, `code` VARCHAR(20), `name` VARCHAR(150), `department_id` ULID NULL, `level` TINYINT UNSIGNED, `is_active`, `created_at`, `updated_at`, `deleted_at` | unique `code`; index `department_id`; FK `department_id` → departments |
| `employee_profiles` | spec table exactly | PK `employee_id`; indexes `branch_id`, `department_id`, `designation_id`, `reports_to_employee_id`; FKs `employee_id`, `reports_to_employee_id`, `created_by`, `updated_by` → employees; `branch_id` → branches; `department_id` → departments; `designation_id` → designations |
| `employee_documents` | spec table exactly (+`updated_by` only if `ba` adopts C-17) | indexes (`employee_id`, `type`), (`expiry_date`); FKs `employee_id`, `created_by` → employees |

- **Foreign keys:** 3 + 1 + 7 + 2 = **13**, all onto existing or same-batch tables (allowed in expand, T-010 AC-4). Seven are onto `employees` (AC-9's list), two onto `branches`, two onto `departments`, one onto `designations`, and one is the self key.
- **No decimals; no seed rows** (D-26).
- **Version:** the catalogue goes 76 → 80 on today's base. **At merge** with any branch that adds tenant migrations (finance chunks D to F, T-040), the second to merge **regenerates** the catalogue, `.env.example`, `tenantTreeTables()`, the count literals and `IdempotencyKeysSchemaTest`. It never hand-merges them. The `2026_11_16_0038xx` prefix sorts after every finance file (`2026_11_15_0032xx`), so HR's files stay on top without a rename. If a T-040 file sorts later, HR is renumbered by the publish, not by editing a migration.

**The 28 slices** (`Features/<X>/{X.php, XHandler.php}`, plus `XResult.php` where an id is returned; every command carries `?string $actorId`):

| Area | Slices | `PERMISSION` | Audit |
|---|---|---|---|
| Departments, write | `CreateDepartment`, `UpdateDepartment`, `MoveDepartment`, `DeactivateDepartment`, `ReactivateDepartment`, `DeleteDepartment` | `hr.departments.manage` | `department_*` (6) |
| Departments, read | `ListDepartments`, `GetDepartment`, `GetDepartmentTree` | `hr.departments.view` | none |
| Designations, write | `CreateDesignation`, `UpdateDesignation`, `DeactivateDesignation`, `ReactivateDesignation`, `DeleteDesignation` | `hr.designations.manage` | `designation_*` (5) |
| Designations, read | `ListDesignations`, `GetDesignation` | `hr.designations.view` | none |
| Profiles, write | `CreateEmployeeProfile`, `UpdateEmployeeProfile` | `hr.employees.manage` | 2 |
| Profiles, read | `ListEmployeeProfiles`, `GetEmployeeProfile` (`include_sensitive`, tier `view_sensitive`), `ListDirectReports` | `hr.employees.view` | `employee_profile_sensitive_viewed` (Get only) |
| Documents, write | `AddEmployeeDocument`, `UpdateEmployeeDocument`, `DeleteEmployeeDocument` | `hr.documents.manage` | 3 |
| Documents, read | `ListEmployeeDocuments`, `GetEmployeeDocument`, `DownloadEmployeeDocument`, `ListExpiringDocuments` | `hr.documents.view` (tier `view_confidential`; `notes` needs `hr.employees.view_sensitive`) | `employee_document_downloaded` (Download only) |

**Contracts:** Rulings 3 to 6 and 8. **Permissions:** ten keys. **HTTP, console, jobs, cache, events, hooks:** none. **Dependencies:** only the `flexipos/hr` path package (no new third-party library; `finfo` is the shipped `fileinfo` extension).

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

- [ ] **Step 0: verify the base. STOP on any difference.** Record:
  - the tree top (76, `003203`) and `.env.example` (76);
  - the literals of every section 9 row;
  - `EmployeeDirectory`, `BranchDirectory`, `PermissionResolver::decide`, `AuditTrail::record` and `DeclaresAuditActions` signatures as in 0.1;
  - that `AccessTenantFixture` exposes a master connection the sentinel scan can enumerate;
  - whether finance chunks D to F or T-040 landed on the base since `29cd436` (if so, start from the regenerated literals).

  *(No AC.)*
- [ ] **Step 1: package plumbing.** `composer.json`, an empty `HrServiceProvider`, `config/hr.php`, the section 5 edits, and `'hr'` in `QaToolchainRegistryCoverageTest`.
  - `HrProviderTest`: the provider is loaded.
  - **Deptrac negative control:** a scratch class in `modules/demo/src` importing `Flexipos\Hr\Profiles\ProfileFields` fails, and the same class importing `Flexipos\Contracts\Hr\OrgChart` passes. Delete both.
  - *(AC-8 instrument.)*
- [ ] **Step 2: the four migrations, with `HrTenantSchemaTest`** (the finance stand-in shape).
  - Exact sorted column lists per table (AC-3), and the absent payroll columns by name.
  - `employees` is byte-identical before and after migrating, its column list unchanged (AC-3, AC-103).
  - The 13 keys exist. Each bites with a raw insert naming a missing target, and accepts the same row with a real target (AC-6). Deleting a referenced row directly is refused.
  - Code uniqueness by double insert, including a soft-deleted holder (AC-7).
  - `SourceRules::inspect()` reports nothing; no `down()`; every file is `@phase expand`; no `tenant_id` (AC-2 source half, AC-4).
  - Zero rows in every new table (AC-28).
  - **Sync and republish:** republish the catalogue (`php artisan schema:publish --write-fixture`); `.env.example` 76 → 80; then section 9's step-2 rows (`tenantTreeTables`, `MigrationTreeDisciplineTest`, `IdempotencyKeysSchemaTest`, `TenantBaselineBuildsTest`, `QaAccessCloseOutSweepsTest`, `QaAccessSchemaShapeTest`).
  - `HrMigrationCatalogueTest`: four expand rows, `is_destructive` false, increasing versions, the fixture equals the table, and earlier checksums unchanged (AC-5).
  - *(AC-1 stand-in, AC-2 source, AC-3 to AC-7, AC-9, AC-28, AC-102, AC-103.)*
- [ ] **Step 3: widths.** `HrWidthsTest` asserts every `VARCHAR`/`CHAR` width in `003801` to `003804` equals its `Support\Limits` constant. **Red-first:** a fixture `string('code', 21)` is reported. *(AC-4 width half, AC-16, AC-32, AC-47 lengths.)*

### Chunk B: contracts and the pure core

- [ ] **Step 4: contracts** (section 3's contracts table, kernel `Files` excluded).
  - `HrContractShapesTest`: `DirectReports`, `HrReferrer` and `EmployeeIdentity` accept good values and refuse malformed ones.
  - `NoOrgChart` answers none to all seven questions (AC-84).
  - The contracts import nothing outside contracts or PHP core.
  - `RefusalReason` gains `has_dependents`, and `RefusalReasonTest` 17 → 18 (C-7).
  - *(AC-84; groundwork for AC-82, AC-85, AC-86.)*
- [ ] **Step 5: `Support\Input`, `Fields`, `Cursor`, `PageSize`, `HrRefusal`** (pure datasets).
  - **Code (AC-17 plus C-4):**
    - `fin-01` → `FIN-01`;
    - refused: `FIN 01`, `' FIN'`, `"FIN\n"`, a tab, NUL, `.AB`, `-AB`, a full-width letter, 21 characters;
    - 20 characters accepted.
  - **Text:**
    - trimmed;
    - blank required → `validation`; blank optional → null;
    - 150 accepted and 151 refused, counted in characters;
    - control characters and invalid UTF-8 refused.
  - **`Fields::only`:** names every unknown and every forbidden key in one refusal. Identity keys (`code` on a profile, `first_name`, `last_name`, `display_name`, `status`, `user_id`, `pin_hash`, `perm_version`) give `not_updatable_here`; others give `validation` (AC-42, D-21).
  - **Page size:** 0, 201 and `"50"` give `page_size_invalid`; the default is 50.
  - **Cursor:** malformed, from another list, or with half a key gives `cursor_invalid`.
  - `HrRefusalTest` pins the 27 codes.
  - **Mutations:** M1 `^…$` for `\A…\z` (the newline row goes red); M2 trim the code (the `' FIN'` row goes red).
  - *(AC-16, AC-17, AC-26 and AC-38 paging rules, AC-42, AC-104 unit half.)*
- [ ] **Step 6: `DepartmentRules`, `NameKey`** (pure).
  - **AC-19 order:** each failure alone, and two at once reports the first.
  - Exactly 10 levels accepted and an 11th refused.
  - `cycle` for itself, a child and a grandchild.
  - The depth check is against the deepest descendant after a move.
  - **AC-18 / AC-33:** `Sales` against `sales `, case-insensitive; the same name under another parent or in another designation group is accepted.
  - **Mutation M3:** check the depth of the moved node only (the subtree row goes red).
  - *(AC-18, AC-19, AC-23, AC-33 logic.)*
- [ ] **Step 7: `ProfileFields`, `DateRules`, `ShapeRules`, `EmploymentType`, `ProfileInput`** (pure).
  - **AC-41:** `2025-02-30` refused, `2024-02-29` accepted; employment type outside the six refused.
  - **AC-46:** every pair; equal accepted; one day earlier is `date_order` naming the pair; the merged-update case; `dob` tomorrow (clock) refused.
  - **AC-47:** emails; each shape's valid example; unknown key, nested value, non-string leaf, missing `name`/`phone`; 100/101 and 150/151 lengths.
  - **AC-51:** a filter on any `SENSITIVE` field is `validation`.
  - **AC-56 (pure half):** `SENSITIVE` equals the spec's sixteen (printed); `SENSITIVE ∩ DIRECTORY = ∅`.
  - **Mutation M4:** check dates on the request only, not the merged result.
  - *(AC-41, AC-46, AC-47, AC-51 validation half, AC-56 pure half.)*
- [ ] **Step 8: `FileRules`, `DocumentType`, `DocumentInput`, kernel `Files`** (pure plus temp-dir).
  - **`FileRules`:**
    - a real PDF, PNG and JPEG are accepted;
    - a PDF renamed `.png`, an executable (MZ header) renamed `.pdf`, a name with no extension, and `.svg`/`.html` all give `file_type_not_allowed`;
    - empty gives `file_empty`; max + 1 gives `file_too_large` (decided by `filesize()`; a spy proves the file was not opened); exactly max is accepted.
  - **`original_name`:** separators and control characters stripped; truncated to 255.
  - **`DocumentKey`:** minted form; traversal, `\`, drive letter, NUL, empty segment and a bad tenant segment are refused.
  - **`LocalDocumentStore`:**
    - a root inside `public_path()` is refused;
    - put / open / delete round trip; put over an existing key refused; open missing gives `DocumentMissing`;
    - a forged key with `..` never reaches the filesystem.
  - **Mutation M5:** trust the declared extension (the renamed-PDF row goes red).
  - *(AC-66 to AC-70 unit halves.)*

### Chunk C: departments and designations on the database

- [ ] **Step 9: registry, `Authorize`, seed, the matrix.**
  - `HrPermissions` and `HrAuditActions` (the provider declares both). `HrSeed`: one employee per key, one with every key except a given one, and one with none.
  - `AuditCatalogueTest` gains `'hr'` in both literals.
  - **`HrAuthorizationMatrixTest`** (every slice, growing with each step):
    - with every registered key except the slice's own, the result is `not_permitted`, a query listener sees **no** statement on an HR table, the store spy counts zero, and no audit row exists;
    - with only the slice's key, the result is not `not_permitted` (C-20 for documents);
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
  - *(AC-11 to AC-14, AC-64.)*
- [ ] **Step 10: `CreateDepartment`, `UpdateDepartment`, `MoveDepartment`.**
  - **Create:** AC-15, AC-16 end to end; AC-17 `code_in_use` including a soft-deleted holder; the unique backstop seeded past the pre-check maps to `code_in_use`.
  - **AC-18:** `name_in_use` on create, rename and move.
  - **AC-19:** through the handler, including the 10/11-level control.
  - **AC-20:**
    - unknown or soft-deleted head gives `reference_not_found`;
    - a suspended head is accepted (D-23);
    - one employee may head two departments;
    - null clears;
    - the C-9 control.
  - **AC-21:** branch unknown or inactive, then reactivated, through `BranchDirectory`.
  - **AC-22:** `not_updatable_here` for `code`, `parent_id`, `is_active`, `id`, `deleted_at`; a no-change update writes no audit row and leaves `updated_at` as it was.
  - **AC-23:** a move to the current parent is a no-op.
  - **AC-30:** a forced failure after the change and the audit row persists neither (handler seam); the control persists both.
  - **Mutation M7:** skip the whole-table lock (the source scan of step 24 must catch it).
  - *(AC-15 to AC-23, AC-30.)*
- [ ] **Step 11: `Deactivate`/`Reactivate`/`DeleteDepartment`, and the `HrReferrers` census.**
  - **AC-24:** `already_inactive`, `already_active`; reactivation under an inactive parent is `reference_inactive`; the department is refused for new assignments, then allowed again.
  - **AC-25:** `has_children`; `in_use` from a designation, from a profile of a live employee (C-16; a profile of a soft-deleted employee does not block), and from a bound `FakeHrReferrers`.
  - **Delete success:** `deleted_at` set, the code stays reserved, the row is absent from the list and is `not_found`.
  - A deactivated department with referrers is still refused.
  - **AC-86:** the bound fake reports one referrer, the result is `in_use` and the row is unchanged; with no fake, the delete proceeds.
  - **Mutation M8:** count profiles without the liveness filter (the C-16 row goes red).
  - *(AC-24, AC-25, AC-86, AC-87 departments.)*
- [ ] **Step 12: department reads.**
  - **AC-26:** order, page size, cursor round trip over 3 rows at size 2, each filter, `name` contains with `%` literal, detail counts.
  - **AC-27:** the tree is nested in code order; a fixture with a soft-deleted parent is returned at the top with `orphaned: true`; the query count is constant across 10 and 100 departments.
  - **AC-54 (department half):** the head is an id only.
  - *(AC-26, AC-27, AC-54.)*
- [ ] **Step 13: designations (all seven slices).** AC-31 to AC-38, end to end:
  - own code namespace (the same code as a department is accepted);
  - level 0/255 accepted, -1/256 and `"5"` refused;
  - `name_in_use` per department group (null is a group);
  - AC-34 reactivation control;
  - AC-36's reactivation requires an active department;
  - AC-37 with C-16 and the fake referrer.

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
  - *(AC-50/51 enablers, AC-101.)*
- [ ] **Step 15: `RedactingAuditTrail` on `AuditWriter`.**
  - Changing `nic` from A to B: `changed_fields` contains `nic`, `before.nic` and `after.nic` are `"[redacted]"`, and neither A nor B appears in the row.
  - A null-to-value change gives before null and after `"[redacted]"`.
  - Non-redacted fields carry real values.
  - An undeclared action is still refused.
  - The plain `record()` is unchanged (the existing `AuditCatalogueTest` stays green).
  - **Mutation M10:** mask before deriving (the `changed_fields` row goes red).
  - *(AC-60, AC-89 mechanism.)*
- [ ] **Step 16: `CreateEmployeeProfile`, `UpdateEmployeeProfile`.**
  - **AC-39:** a hash of the `employees` row is identical before and after (all columns, including `perm_version` and `updated_at`).
  - **AC-40:** `not_found` for an unknown or soft-deleted employee; `profile_exists` from the pre-check and from the PK backstop; a no-profile employee still resolves permissions.
  - **AC-41 to AC-47** end to end.
  - **AC-44:** no designation/department cross-check; a held inactive designation is not refused on an unrelated update.
  - **AC-45:** a chain of three accepted; closing it gives `cycle`; a 51-step chain gives `depth_exceeded`; self gives `self_reference`; a suspended manager is accepted.
  - **AC-48:** a no-change update writes no audit row and leaves `updated_at` as it was; null clears; `profile_not_found`.
  - **AC-49:** soft-delete the employee by raw update; then every HR read and write is `not_found` and the rows are retained.
  - **AC-60:** the profile create and update rows are redacted.
  - **AC-91:** `perm_version` for every fixture employee is unchanged across the step's operations.
  - *(AC-39 to AC-49, AC-57 stand-in half, AC-60, AC-91 profiles.)*
- [ ] **Step 17: `ListEmployeeProfiles`, `GetEmployeeProfile`, `ListDirectReports`.**
  - **AC-50:** item keys exactly the 13 directory names. No sensitive key, even as null; none of `pin_hash`, `pin_failed_attempts`, `pin_locked_until`, `perm_version`, `user_id`.
  - **AC-51:** each filter narrows; the intersection; a sensitive filter is `validation`.
  - **AC-52, AC-53 plus C-21:** view only + `include_sensitive` gives the directory shape and no row; both keys + `include_sensitive` gives 16 more fields and one row; both keys without the flag gives directory and no row; `view_sensitive` alone gives `not_permitted`.
  - **AC-90:** two reads write two rows; a refused read and a list write none; the row carries no value.
  - **AC-54:** direct reports, paged.
  - **AC-55:** T-010's list and detail outputs, captured before and after HR rows exist, are equal.
  - **AC-56 sweep:** every live column of `employee_profiles` is in exactly one of the three lists (printed). **Mutations:** a scratch column added to a test copy of the table is reported; `nic` moved to `DIRECTORY` turns AC-50 red.
  - **AC-62:** an employee reading their own profile needs the same keys.
  - *(AC-50 to AC-56, AC-58 profile cells, AC-62, AC-90.)*
- [ ] **Step 18: `DatabaseOrgChart`, `HrEmployeeDependents`, the access delete guard.**
  - **AC-82:** each answer, and each flips when its cause is removed (clear the manager, delete the head by raw soft delete). Not inherited. The head may be the employee asked about. `directReportsOf` is truncated at 1000 (a fixture of 1001).
  - **AC-85 (`DeleteEmployeeDependentsTest`, a new file in access's `DeleteEmployee` test directory):**
    - a head of a live department gives `has_dependents` with detail `['department_head']`;
    - a manager of a live profile of a live employee gives `['direct_reports']`;
    - both give both;
    - nothing changes: no `deleted_at`, no bump, no audit row;
    - the message and detail contain no id;
    - **control:** clearing the head or moving the reports makes the delete succeed;
    - a status change to `terminated` is not blocked;
    - with HR's provider not declaring (a test container), the delete succeeds (the "none" default).
  - The existing `DeleteEmployeeTest` stays green and **unedited**.
  - **Mutation M11:** count reports of soft-deleted employees (the liveness row goes red).
  - **Gate: `lead` schedules `composer check` if chunk D is committed separately.**
  - *(AC-82, AC-83 by absence, AC-85.)*

### Chunk E: documents

- [ ] **Step 19: `AddEmployeeDocument`** (with `InMemoryDocumentStore` bound).
  - **AC-65:** row fields; the server key; the measured size; the SHA-256 equals `hash` of the bytes; `created_by`; one `employee_document_added` row with `original_name`.
  - **AC-66:** each refusal leaves no row and no stored file (store counters); `file_path`/`checksum` in the input give `validation`; a past or equal expiry is accepted.
  - **AC-67:** end to end.
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
    - bytes, name, mime and size are returned; the checksum is verified (stream hashed, then rewound);
    - a tampered byte and a missing file each give `document_corrupt` plus an error-level log line, and no bytes;
    - one `employee_document_downloaded` row with id, type and employee id and no name.
  - **AC-70:** in fixtures A and B with the same document id, each tenant gets its own bytes. A forged `file_path` naming B's tenant segment gives `document_corrupt`, and the store is never asked.
  - **AC-72:** with view only, a confidential document is absent from the list, `not_found` on detail and download, and writes no download row. `view_confidential` makes it appear. `view_confidential` alone gives `not_permitted`.
  - **AC-73:** list fields (never `file_path` or `checksum`), order, paging, the `type` filter; `notes` only with `hr.employees.view_sensitive`.
  - **AC-76 (read half):** soft-deleted documents are excluded.
  - *(AC-58 document cells, AC-70 to AC-73.)*
- [ ] **Step 21: `UpdateEmployeeDocument`, `DeleteEmployeeDocument`, `ListExpiringDocuments`.**
  - **AC-74:**
    - visible-only (C-20);
    - declassifying needs `view_confidential`; classifying does not;
    - `medical` cannot be declassified;
    - immutable fields give `not_updatable_here`;
    - the audit row redacts `notes` and has no `original_name`.
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
  - Each operation is enumerated and run once in its success shape. It writes exactly one action from the list of eighteen, or none for reads other than the two audited ones, with `module = hr`, the actor and the subject.
  - `HrAuditActions::ALL` equals the spec's eighteen (printed).
  - **Mutation:** a scratch handler writing `employee_profile_xyz` is refused by the writer and reported.
  - The AC-104 sweep runs in the same file: every list operation, enumerated, refuses page size 0/201 and a malformed cursor (printed).
  - *(AC-87, AC-88, AC-104.)*
- [ ] **Step 23: `HrSentinelTest` and `HrQueryLogTest`** (Ruling 12).
  - **AC-59** in the C-12 amended form:
    - a baseline run first;
    - a ≥ 12-character sentinel per field, with every operation run in its success and forced-failure shapes;
    - all four controls, including a deliberate leak into `audit_logs.after`;
    - the table and column counts printed.
  - **AC-61:** no `outbox_events` row is written by HR operations; a scratch event carrying a sentinel is detected.
  - **AC-2, AC-98, AC-99, AC-101:** query-log rules with their mutation fixtures; fixtures A/B with the same ids run A, then B, then A; a mis-keyed cache fixture is detected (HR has no cache, which is stated).
  - **AC-63:** a stated, non-test note in the file docblock.
  - *(AC-2, AC-59, AC-61, AC-63, AC-98, AC-99, AC-101.)*
- [ ] **Step 24: `QaHrSourceRulesTest` (host).** Code only, through `SourceRules::codeOnly`.
  - **Locked reads:** no terminal read in write slices; `*Rows` terminal reads outside `*Unlocked` carry a lock; the whole-table lock in `MoveDepartment`; `holdLive` in every write that sets an employee reference.
  - **Boundaries:**
    - no `table('employees')` / `'employees'` identifier in `packages/hr/src`;
    - no HR table name in any other package's `src`, except `packages/access/src` naming none of them;
    - no `Route::`, `Cache::`, `Storage::`, `Http::`, `curl_`, `fsockopen`, `dispatch(`, `HookGateway`, `select('*')` or `->select()` without the field lists in the profile reads;
    - regexes end in `\z`;
    - contracts are pure.
  - **Red-first:** a scratch `->first()` in a write handler, `table('employees')` in HR, and `table('employee_profiles')` in `packages/sales/src` are each reported. Delete all three.
  - *(AC-8, AC-101 construction halves; M7.)*
- [ ] **Step 25: documents.** Describe, never quote retired vocabulary (the gate sweeps the tree).
  - `docs/database.md` §6.12: Ruling 2's annotation.
  - `docs/architecture.md` §3: HR joins the as-built `packages/` list; the kernel document store is one sentence.
  - Section 10's backlog notes are for `lead`.
- [ ] **Step 26: `HrSchemaEngineTest`** (database `flexipos_t8test_t038_tenant`, created and dropped by **exact name** through `EngineDatabase`, never a `LIKE` sweep). Under the hold only the "refuses to reach the engine" case runs. Written for G-1:
  - AC-1 live listing; AC-4 `information_schema` types and collations; AC-6 the two-engine comparison;
  - AC-7 case and pad collation on the unique `code`;
  - AC-18 / AC-33 two simultaneous same-name creates;
  - AC-29 two moves forming a cycle;
  - AC-45 two reports-to changes forming a loop;
  - AC-57 two profile creates;
  - AC-80 two adds at 99 documents;
  - AC-85 head assignment against head delete.

  *(Every `[ENGINE]` clause; NOT VERIFIED under `ENGINE_HOLD`.)*
- [ ] **Step 27: final.** `lead` runs `composer check` from `modules/host`, with counts and `test` seconds compared with step 0. *(AC-10, and every criterion except the `[ENGINE]` clauses and section J.)*

## 7. Test strategy

- **Unit (pure):**
  - HR: `HrInputTest`, `HrDepartmentRulesTest`, `HrProfileRulesTest`, `HrFileRulesTest`, `HrRefusalTest`, `HrContractShapesTest`;
  - kernel: `DocumentKeyTest`, `LocalDocumentStoreTest`, `InMemoryDocumentStoreTest`.

  These carry most cases as datasets.
- **Integration (SQLite stand-in, `AccessTenantFixture`):**
  - one file per slice, mirrored under `packages/hr/tests/Features/<X>/` (`VerticalSliceLayoutTest`);
  - `HrTenantSchemaTest`, `HrMigrationCatalogueTest`, `HrRegistrySyncTest`, `HrAuthorizationMatrixTest`, `HrOperationsSweepTest`, `HrAuditSweepTest`, `HrSentinelTest`, `HrQueryLogTest`;
  - in access: `DatabaseEmployeeIdentitiesTest`, `AuditWriterRedactionTest`, `DeleteEmployeeDependentsTest`.
- **Static:** `QaHrSourceRulesTest`, `HrWidthsTest`, deptrac with the demo negative control.
- **Engine:** `HrSchemaEngineTest` (skipped under the hold).
- **Mutations:** M1 to M12.

| ACs | Proved by |
|---|---|
| 1–7, 9, 28, 102, 103 | steps 2, 3 (`[ENGINE]` halves step 26) |
| 8 | steps 1, 24 |
| 10 | step 27 |
| 11–14, 64 | step 9 |
| 15–23, 30 | steps 5, 6, 10 |
| 24, 25, 86 | step 11 |
| 26, 27, 54 (departments) | step 12 |
| 29, 57, 80 | `[ENGINE]` step 26 (stand-in halves in steps 10, 16, 19) |
| 31–38 | step 13 |
| 39–49, 60, 91 | steps 7, 15, 16 |
| 50–56, 58, 62, 90 | step 17 (documents' cells step 20) |
| 59, 61, 63, 2, 98, 99, 101 | step 23 |
| 65–70, 75, 78, 79 | steps 8, 19 |
| 71–73 | step 20 |
| 74, 76, 77, 100 | step 21 |
| 81 | stated; step 24 (no execute, no inline) |
| 82, 84, 85 | steps 4, 18 |
| 83 | by absence: no `packages/workflow` file in the diff |
| 87, 88, 104 | step 22 |
| 89 | steps 15, 16, 21 |
| 92–97 | **not built** (C-12, Ruling 11) |

## 8. Risks & rollback

1. **Plain-text personal data (D-16).** A dump or backup exposes the sixteen fields. This is mitigated only by access control and by a scan proving no leak through the application's own sinks. Owner question 5.
2. **Gate time.** About 330 cases, roughly 60 % of them pure. Measure at each `lead` gate; above +10 % on the `test` step, stop and report.
3. **Stand-in against engine divergence.** Locks are no-ops on SQLite. Lock presence is the source construction (step 24); lock effect is `[ENGINE]` (step 26). Known engine deadlocks are department move against a concurrent child create, and profile chain locks against a crossing chain; InnoDB rolls one back and `attempts: 2` retries it into a named refusal.
4. **Access edits.** Six additive edits to a shipped package (Ruling 5, Ruling 8, the bindings). The behaviour change is limited to `has_dependents`. Section 9 lists every edited access file, and `DeleteEmployeeTest` must stay unedited.
5. **Merge renumbering.** Finance chunks D to F and T-040 add tenant migrations in parallel. Rule: the second to merge regenerates the catalogue and the count literals and never edits another task's migration. HR's migrations name no version.
6. **Files outlive rows.** No purge (D-31), so storage grows forever, and a tenant `--abandon` leaves files under the document root. This is listed for `lead` (section 10).
7. **No route, no screen.** FP-273 cannot start until a route task lands (section 10).

**Rollback:** every HR file is new. The migrations are forward-only and expand-only, and no tenant outside this branch has run them, so rollback is to abandon the branch. The access additions are inert without a declaring provider.

## 9. Edits to existing files (the predicted-red list)

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
| `modules/host/tests/Feature/TenantBaselineBuildsTest.php` | +13 keys sorted in; the branch-key table list + `departments`, `employee_profiles` | 2 |
| `modules/host/tests/Feature/QaAccessCloseOutSweepsTest.php:151-153`, `QaAccessSchemaShapeTest.php:233-235` | `$t038 = ['departments', 'designations', 'employee_documents', 'employee_profiles']` added to the `array_diff` | 2 |
| `SchemaCataloguePublishTest` | fixed by the republish | 2 |
| `packages/contracts/src/Access/RefusalReason.php`; `packages/access/tests/Contracts/RefusalReasonTest.php` | `has_dependents`; 17 → 18 (C-7) | 4 |
| `packages/access/tests/Support/AuditCatalogueTest.php:48` and the fixture variant | `'hr'` in both module literals | 9 |
| access tests that count registered keys or owner grants, if any | +10 (record the exact files at step 9; none found by grep at planning) | 9 |
| `packages/access/src/{AccessServiceProvider.php, Support/AuditWriter.php, Support/EmployeeLookup.php, Exceptions/AccessRefused.php, Features/DeleteEmployee/DeleteEmployeeHandler.php}` | Rulings 3, 5 and 8 (additive) | 14, 15, 18 |
| `docs/database.md` §6.12, `docs/architecture.md` §3 | step 25 | 25 |

**Measured: no edit expected** (stay on the red-list watch):
- `TenantDecimalPrecisionTest`: HR declares no decimal;
- `QaAccessHttpSurfaceTest`: it pins only the branch-scoped key list, and HR's keys are all non-branch-scoped with no routes;
- `VerticalSliceLayoutTest`: walks every package; obey the mirror;
- `packages/sales/tests/Schema/LoyaltySaleIdMigrationsTest`: asserts `highestVersion() >= ` since T-032;
- `DeleteEmployeeTest`;
- `CustomersSchemaEngineTest` (no `AuditTrail` signature change);
- `packages/workflow/tests/Definitions/FakeEmployeeDirectory.php` (no `EmployeeDirectory` change).

Anything red outside this table means stop and report to `architect`.

## 10. Follow-ups for `lead` (backlog rows or notes, not built here)

- **Route task (or FP-273's prerequisite):**
  - thin routes for the 28 operations with `RequireTenantPermission` and the same keys;
  - `access_log` denials (AC-96); OpenAPI (AC-97); `Cache-Control: no-store` and `nosniff` (AC-95);
  - an HTTP 413 before buffering (`post_max_size`, `upload_max_filesize`, nginx `client_max_body_size`, `ValidatePostSize`) (AC-94);
  - refusing sensitive values in the URL (AC-59 sink 6).
- **FP-358:** consume `Contracts\Hr\OrgChart`; the approver types stay refused until then (AC-83).
- **FP-280 / payroll:** bind `HrReferrers` and `EmployeeDependents` for its own references, and add the D-5 columns.
- **Expenses and leave:** reuse `Kernel\Files\DocumentStore` and `DocumentKey`; promote `FileRules` to the kernel on the second user.
- **Operations:** document-root backup and retention; `tenant:provision --abandon` leaves files (risk 6).
- **`ba`:** C-1 to C-22.
- **FP-433:** another copy of the support helpers.
- **Optional (FP-181 shape):** derive `IdempotencyKeysSchemaTest`'s top entry from the tree instead of a literal every task edits.

## Questions for the human

None. The owner-visible items are `ba`'s questions 1 to 9 (decisions file, already defaulted). The architecture adds three:
- **(a)** there are no HTTP routes yet, so screens wait for a route task;
- **(b)** deleting an employee who heads a department or manages others will now be refused (D-35, owner question 9);
- **(c)** uploaded files are kept forever and are not removed when a shop is abandoned.
