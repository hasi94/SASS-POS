# Plan: T-040 Notification channels, templates and deliveries

Plan revision 2 (re-synced to spec revision 2 at `1efaed5`; what changed is in section 0.4).

Specs: `docs/specs/T-040-notification-channels.md` revision 2 (106 criteria: AC-1 to AC-86, AC-89, AC-91 to AC-93, AC-103 to AC-105, AC-107 to AC-119; the gaps are listed in its numbering table) and `docs/specs/T-040b-notification-inbox-retention-routes.md` (AC-B1 to AC-B17, written B1 to B17 below). Decisions: `docs/decisions/T-040-notification-channels.md` (D-1 to D-58) and `docs/decisions/T-040b-notification-inbox-retention-routes.md` (cited as "T-040b D-n").
Design authority: `docs/database.md` §6.16 (plus §3.1 to §3.3, §6.9 and §6.21), `docs/architecture.md` §3 (the as-built placement rule) and §9 (queues), `docs/patterns.md` §3 to §6, and `docs/eventbus.md`. House model: `docs/plans/T-032-chart-of-accounts.md` and the shipped `packages/finance`.
Backlog: FP-295. Branch: `ai/T-040-notification-channels`, base `81a9169` (the T-032 merge commit). Gate directory: `modules/host`.

Everything below about shipped code was measured read-only on this worktree at `644b84e`, and re-checked at `1efaed5` for section 0.4 (only documents changed between the two; no code moved).

**Hard constraint, carried into every step.** Nothing here sends a real email or SMS, calls a third-party API or needs a credential. Only three drivers exist: `log`, `null` and the in-app database write. Credential storage is built and tested with an inert sandbox value and a key generated inside the test. Nothing reads or writes `.env`. `.env.example` is edited (the schema version line only), as every schema task does.

---

## 0. Verification, findings and rulings

### 0.1 What the base actually contains (measured)

| Claim in the spec | Verdict | Evidence |
|---|---|---|
| Assumption 1 and "Current behaviour": a stale base with no customers package | **False** | `packages/customers/src/CustomersServiceProvider.php`; `modules/host/database/migrations-tenant/2026_11_10_002808_create_customer_communications_table.php` (its docblock defers `delivery_id` to "that module's own task", as `BIGINT UNSIGNED`); `packages/customers/src/Features/LogCustomerCommunication/`. The base also holds catalog, pricing, sales, workflow, platform, auth and finance chunks A to C. Groups G and K are **not** blocked |
| Audit action names are dotted (AC-103) | **Not the built convention** | Every shipped action is one snake_case token, declared as a handler's `ACTION` constant and collected in `<Module>AuditActions::ALL`, with the module passed separately to `AuditTrail::record()`. Newer packages prefix the module: `sales_sale_voided` (`packages/sales/src/Features/VoidSale/VoidSaleHandler.php:63`), `pricing_tax_created` (`packages/pricing/src/Features/CreateTax/CreateTaxHandler.php:26`), `catalog_product_created` (`packages/catalog/src/Features/CreateProduct/CreateProductHandler.php:36`). Finance has no prefix: `account_created` (`packages/finance/src/Features/CreateAccount/CreateAccountHandler.php:41`). The writer refuses an undeclared action (`packages/access/tests/Support/AuditCatalogueTest.php:97`) |
| AC-54: `aggregate_id` is the delivery's BIGINT id as a string | **Impossible** | `packages/eventbus/src/Support/OutboxWriter.php:94` validates `aggregate_id` as a 26-character upper-case ULID (`OutboxEventRefused::invalidIdentifier`). The column is `IdentifierColumn::required($table, 'aggregate_id')` (`0001_01_01_000006_create_outbox_events_table.php:58`) |
| AC-56 / AC-108: `idempotency_key` unique "on a binary collation" | **Forbidden by the source rules** | `packages/kernel/src/Schema/Support/SourceRules.php:844` reports any `->charset()`/`->collation()` in a migration. Only `IdentifierColumn` may name `ascii_bin` (its docblock, lines 14 to 31). AC-107 itself says "nothing else naming a collation" |
| AC-51: an employee must be `active` | **Different authority exists** | `packages/access/src/Support/EmployeeStatus.php` names six statuses and an *acting* set (active, probation). The published answer is `Flexipos\Contracts\Access\RoleHolders::actingAmong()` (`packages/contracts/src/Access/RoleHolders.php:37`). Liveness alone is `EmployeeDirectory::isLive()` |
| AC-52: an employee may receive on any channel | **No address exists** | `employees` has no email or phone column (`2026_10_20_000304_create_employees_table.php:58-75`). `work_email`/`phone` arrive only with T-038's `employee_profiles`, which is unmerged (branch `ai/T-038-hr-employees`) |
| AC-67: the address is read through "a published customers contract" | **None exists** | `packages/contracts/src/Customers/` holds only the credit-ledger and loyalty contracts. This task must add one |
| AC-99: "the customers module's create-communication operation" | **It is `LogCustomerCommunication`** | `LogCustomerCommunicationHandler.php` (`FIELDS` constant). It carries no permission check today. `packages/customers/tests/Schema/CustomersTenantSchemaTest.php:197-203` asserts the **absence** of `delivery_id` |
| Assumption 7: server-side encryption usable for credentials | **Holds: Laravel's encrypter with `APP_KEY`** | `modules/host/config/app.php:98-106` (`cipher` AES-256-CBC, `key` from `APP_KEY`, `previous_keys` from `APP_PREVIOUS_KEYS`). Precedent: `packages/plugin-sdk/src/Support/PluginSettingsStore.php:28,73` (`plugin_settings.value`). The gate runs **without** an `APP_KEY` (backlog T-029 row), so `PluginSettingsStoreTest.php:20-27` sets a generated key inside the test |
| Consumer machinery | **Holds** | `EventConsumer` + `DeclaresEventConsumers` (`packages/contracts/src/Events/`), group shape `^[a-z0-9][a-z0-9._-]*$` (`ConsumerRegistry.php`), run inside `Tenancy::run()` (`ConsumeEventsHandler.php:135`). Every group sees every event and acknowledges types it does not list. Model: `packages/plugin-sdk/src/Runtime/PluginAfterHookConsumer.php` |
| Scheduled commands run in production | **No (FP-319)** | `packages/access/src/AccessServiceProvider.php:180-184`: "no scheduler runs in production yet". The fleet-sweep model is `packages/access/src/Features/ArchiveAuditLogs/ArchiveAuditLogsCommand.php` |
| A `SET NULL` foreign key has precedent | **No, but nothing forbids it** | Shipped contract files are "Restrict only, no referential action" (`2026_11_10_003010_add_loyalty_sale_foreign_key.php` docblock). `SourceRules` has no rule on referential actions. `docs/database.md` §3.3 (line 125) names only RESTRICT and CASCADE |
| Tenant HTTP routes | **Exist, with heavy sweeps** | `packages/access/routes/api.php`, `packages/organization/routes/api.php`, controllers in `modules/host/app/Http/Controllers/<Module>/`. Every tenant route is swept by `QaAccessHttpSurfaceTest`, `QaAccessGuardIsolationHttpTest`, `QaRouteCrossTenantSweepTest` (a recipe per non-GET route), `OpenApiCompletenessTest` (committed document) and `RouteCachingTest` |
| Schema version | **76** | `.env.example:73`; top file `2026_11_15_003203_create_fiscal_periods_table`. T-032 chunks D to F (five more files, `2026_11_15_00320[4-8]`) are not on this base, and T-031/T-033/T-034/T-038/T-039 run in parallel worktrees |

### 0.2 Spec corrections for `ba` (numbered; S = serious)

Each correction gives the file that proves it. The plan builds the resolution shown, so `ba` may adopt it verbatim or rule otherwise before the chunk named.

**Status at plan revision 2: historical.** `ba` adopted all 24 in spec revision 2 (D-35 to D-58; the mapping is in section 0.4). The AC numbers quoted in this list are revision-1 numbers; the criteria that moved are now T-040b's B1 to B17. One amendment to correction 2: T-040 declares the first **twelve** actions; `notifications_deliveries_purged` is declared by T-040b (B16, T-040b D-5).

1. **(S) Assumption 1, "Current behaviour" (line 23), Dependencies, and D-23's reason.** The customers package and `customer_communications` are on this base. Proof: `packages/customers/src/CustomersServiceProvider.php` and `modules/host/database/migrations-tenant/2026_11_10_002808_create_customer_communications_table.php`. Groups G and K need no merge. "Current behaviour" should also stop listing only five packages. D-23's "cannot verify a customers write contract exists" should read: the write is the customers slice `LogCustomerCommunication`, with no published contract.
2. **(S) AC-103, the audit action names.** The built convention is one snake_case token per handler `ACTION` constant, with the module passed separately. Recent packages prefix the module (proof in 0.1). The plan uses `notifications_channel_created`, `notifications_channel_updated`, `notifications_channel_credentials_changed`, `notifications_channel_activated`, `notifications_channel_deactivated`, `notifications_channel_deleted`, `notifications_template_created`, `notifications_template_updated`, `notifications_template_activated`, `notifications_template_deactivated`, `notifications_consent_recorded`, `notifications_delivery_retried`, `notifications_deliveries_purged`, all under module `notifications`.
3. **(S) AC-54, the outbox aggregate id.** The outbox writer refuses a non-ULID `aggregate_id`. Proof: `packages/eventbus/src/Support/OutboxWriter.php:94` and `packages/contracts/src/Events/OutboxEventRefused.php`. **Resolution:** add `deliveries.ref CHAR(26)`, a unique server ULID. The event carries `aggregate_type = delivery`, `aggregate_id = ref` and `payload = {"delivery_id": <int>}`. Add `ref` to D-25 and AC-109.
4. **(S) AC-56, AC-108 and AC-107, the binary collation.** A migration may not name a collation. Proof: `packages/kernel/src/Schema/Support/SourceRules.php:844` and the `IdentifierColumn.php` docblock. **Resolution:** the unique index is on `idempotency_key_hash CHAR(64)`, the lower-case hex SHA-256 of the raw key, so `a` and `A` stay different keys on any collation. The raw key is kept in `idempotency_key VARCHAR(100)` with no index. Add the column to D-25, and change AC-108's index to `unique(idempotency_key_hash)`.
5. **(S) AC-51, employee eligibility.** The platform's authority is the *acting* set (active, probation), published as `RoleHolders::actingAmong()`. Proof: `packages/contracts/src/Access/RoleHolders.php:37` and `packages/access/src/Support/EmployeeStatus.php`. **Plan:** `recipient_not_found` when `EmployeeDirectory::isLive()` is false; `recipient_inactive` when the employee is live but not acting (on_leave, suspended, resigned, terminated). `ba` rules if on-leave staff should still get in-app alerts; the plan reads the one authority and copies no list.
6. **(S) AC-52, employees on non-in-app channels.** `employees` has no address (`2026_10_20_000304_create_employees_table.php`). **Resolution:** in this task an employee recipient is `in_app` only, and any other channel is refused `channel_not_supported_for_recipient`. Add a follow-up for when T-038 publishes an employee contact contract.
7. **(S) AC-67 and Assumption 4, the customers contract.** None exists (`packages/contracts/src/Customers/`). **In scope:** a new `Contracts\Customers\CustomerContacts` (`contactOf(id): ?CustomerContact`, with id, live, merged-into id, status, email and phone), implemented in `packages/customers`. The spec should say a customers-package change is in scope.
8. **AC-99 to AC-101, naming and the test edit.** The operation is `LogCustomerCommunication` (`packages/customers/src/Features/LogCustomerCommunication/LogCustomerCommunicationHandler.php`). Two kinds of test edit are required:
   - `CustomersTenantSchemaTest.php:197-203` asserts absence, so it must become presence;
   - `LogCustomerCommunicationTest`'s whole-row audit expectations gain `delivery_id`.

   AC-96 to AC-102 should permit exactly those (the T-032 F-2 precedent). AC-101 holds as built: `MergeCustomersHandler.php:114` moves communications by `customer_id` only.
9. **(S) AC-49, AC-50, AC-84 and AC-85, the request fields.** AC-49's field list omits `channel`, `priority`, `data` and `action_url`. Add them. The three in-app fields are accepted only for `in_app`, and are otherwise refused `field_not_supported_for_channel`. Name what AC-56's payload hash covers: every request field except `idempotency_key` and `source_event_id`. The in-app fields must survive from request to attempt, so add `deliveries.in_app JSON NULL` to D-25.
10. **AC-20 against D-25.** Reads expose `credentials_updated_at`, but D-25 and AC-109 do not list `channel_configs.credentials_updated_at`. Add it.
11. **(S) AC-59 and AC-75, recovery and retry wake-up.** As written, every dispatcher run re-emits an event for every fresh `queued` row whose event is still in flight. **Resolution:** add `deliveries.dispatched_at` and a configuration value `redispatch_after_seconds` (default 300). A due `queued` row is re-emitted only when one of these holds:
    - `dispatched_at` is null;
    - `dispatched_at < next_attempt_at`;
    - `dispatched_at <= now - redispatch_after_seconds`.

    State that this command also wakes transient-failure retries and daily-limit deferrals. The consumer acknowledges a not-yet-due row, so nothing else re-delivers it. Add to Assumptions: **no scheduler runs in production (FP-319)**, so until it does, retries and recovery do not fire outside tests.
12. **AC-19, AC-21 and Assumption 7, the key mechanism.** It is Laravel's encrypter (AES-256-CBC with HMAC-SHA256, authenticated) keyed by `APP_KEY`; proof in 0.1. Two clarifications:
    - A write naming credentials while no key is configured is unspecified. **Add** refusal `encryption_unavailable`, nothing written.
    - With `APP_PREVIOUS_KEYS` set, a credential encrypted under a previous key still decrypts. So AC-21's "differs" means "matches neither the current nor any previous key".
13. **AC-17, AC-35 and AC-36, one door per rule** (T-032 F-12 precedent, `docs/plans/T-032-chart-of-accounts.md` §0.2). `is_active` changes only through `Activate…`/`Deactivate…` slices; an update body naming `is_active` is refused `not_updatable_here`. Create may carry `is_active`. For configs the default is `false` (D-9). For templates the spec gives no default; the plan uses `true`, and `ba` should state it.
14. **D-10 against AC-3, an `in_app` config row.** "Allowed but optional" leaves undefined what an inactive or rate-limited `in_app` row does. **Resolution:** creating a config for `in_app` is refused `channel_not_configurable`, and AC-3 drops the `in_app` provider value. In-app is always on.
15. **AC-38, template delete.** No delete slice or route exists, so nothing can be "refused `not_supported`". Reword: "there is no delete operation" (over HTTP, an absent route).
16. **AC-2 is not provable as written.** "The full suite completes with outbound network unavailable" is a property of the environment. **Plan:** the claim is proved by AC-1's source scan, plus one end-to-end pipeline test under `Http::preventStrayRequests()` with `Mail::fake()`, asserting zero sends. A whole-suite offline run is recorded NOT VERIFIED unless `lead` runs one.
17. **AC-95 and D-27, `ON DELETE SET NULL`, is a first for this tree.** Shipped keys are RESTRICT or CASCADE, and `docs/database.md` §3.3 (line 125) names only those. The decision stands, but §3.3 needs a third clause (section 4.3 below).
18. **AC-58, "idempotent by the outbox event id".** No consumer-side processed-event store exists, and none is needed (`packages/contracts/src/Events/EventConsumer.php`: check before acting). Idempotency is by the delivery's status and `next_attempt_at`, read under the row lock. Reword.
19. **The request-time check order is unspecified** when several rules fail at once: an exception, a `suppressed` row and a `failed` row. The plan's fixed order is in Ruling 7; `ba` should adopt it as text.
20. **AC-79, `delivery_expired`.** This is underspecified. **Plan:** a `failed` delivery whose `error_code` is `expired` (set by the purge, AC-94) is refused `delivery_expired`.
21. **AC-81, `recipient` for in-app.** In-app has no address. **Plan:** `deliveries.recipient` is nullable. It is null for `in_app` (the employee is in `recipient_id`) and for `recipient_address_missing`.
22. **Informational, D-12.** Because any `}}` outside a placeholder is refused, an e-mail template holding nested CSS (`@media{a{b:c}}`) cannot be stored. Owner-visible; no change proposed.
23. **Informational, customer merge.** `MergeCustomersHandler.php:114` does not move `notification_consents`. A merged-away source is refused (`recipient_merged`), so the target's own consents govern. Marketing to the target fails closed until the target opts in. `ba` should add a sentence.
24. **The split** (section 1): `ba` divides the criteria between T-040 and a new row, as listed there.

### 0.3 Escalation check: the credential key

**No new secret is required.** The ciphertext is produced by `Illuminate\Contracts\Encryption\StringEncrypter` (the container's `encrypter`), keyed by the existing `APP_KEY`, with rotation via the existing `APP_PREVIOUS_KEYS`. This is the `plugin_settings` precedent. Tests generate a random key in `beforeEach` and never read `.env`.

**One owner-visible trade-off, which is not a decision made here.** `docs/database.md` §4.4 deliberately keys tenant database credentials with a separate `DB_CREDENTIAL_KEY`, not `APP_KEY`. That key is documented but **not built** (no code reads it). If the owner wants provider credentials under a key separate from `APP_KEY`, that is a **new secret** and goes to `lead` as an escalation. The plan isolates the choice in one class (`Channels\CredentialVault`), so switching keys is a one-file change.

### 0.4 Revision 2 re-sync (spec revision 2, T-040b spec, D-35 to D-58)

**AC mapping used everywhere below.** Revision-1 numbers that moved:

| Revision 1 | Now | Built at |
|---|---|---|
| AC-87, AC-88, AC-90 | B1, B2, B3 (inbox) | steps 22, 23 |
| AC-94, AC-95 | B4, B5 (purge, both `SET NULL` keys) | steps 23, 26, 28 |
| AC-96 to AC-102 | B6 to B12 (owed column) | steps 25, 26 |
| AC-103, the purge action only | B16 | step 23 |
| AC-106 | B13 (routes) | step 27 |
| AC-109, the owed column only | B17 (§6.9 note) | step 28 |
| AC-98's `notifications.delivery_id` half | **AC-117** (stays in T-040) | steps 2, 20 |
| AC-95's `notifications` half | **AC-117** (T-040); B5 re-proves it with the purge | steps 2, 20, 23 |
| none | B14 (`DeliveryLinks`), B15 (the two customers test edits) | step 26 |
| none | AC-112 to AC-119 (T-040) | see the step list |

**What changed in this plan.**
1. Every AC citation in section 1, the steps (each now ends with a **Targeted tests** line naming exact files) and section 7 is the final list. T-040 cites no B-number, and T-040b cites no revision-1 number that moved.
2. T-040 declares **twelve** audit actions. `notifications_deliveries_purged` is declared, with its first user, in step 23 (T-040b). Declaring it in part 1 would leave a catalogue entry no handler records.
3. Step 2 names the `deliveries` columns `ref`, `in_app`, `dispatched_at` and `idempotency_key_hash` explicitly, with their indexes, and carries the AC-117 declaration test. Step 4 adds the `CustomerContacts` contract; step 13 adds its customers implementation (`ContractCustomerContacts`, the `ContractCreditLedger` naming precedent) and binding (AC-115).
4. Section 4.3 now gives the exact `docs/database.md` text. T-040 (step 21) applies §6.16 and the §3.3 `ON DELETE SET NULL` clause; only the §6.9 note moves to T-040b (step 28, B17).
5. `DeliveryLinks` and `DeliveryLinkRefused` stay in part 2 (B14, step 26).
6. `RetryDelivery` sets `dispatched_at = now` (AC-79; check A below). `redispatch_after_seconds` gains its range 1 to 86,400 (AC-116).
7. The retry audit row's subject is the delivery's `ref` (a ULID), not its BIGINT id, because `audit_logs.subject_id` is an identifier column (`0001_01_01_000005_create_audit_logs_table.php:51`, `IdentifierColumn::nullable`). The purge's audit row (T-040b) uses a fresh ULID per run as its subject, with the cutoff in `after`.
8. Step 27 states that T-040b's route work **deletes** `QaNotificationsNoHttpSurfaceTest` (AC-105's "no route" test from T-040) and replaces it in the same step with `QaNotificationsNoSendRouteTest`, which proves that no route reaches `NotificationSender` (B13 together with AC-105).
9. `bootstrap/providers.php`: the provider goes **last**, after `SalesServiceProvider` (the file's order is now Finance, Pricing, Sales).

**Check A: AC-79, AC-114 and AC-116 against the rulings (the spec writer's request 6).**
- **AC-79, `dispatched_at = now` on retry: correct, and required.** The retry writes its own outbox event in the same transaction (Ruling 9's `retryFromFailed`). If it left the old `dispatched_at`, that value is earlier than the new `next_attempt_at = now`, so the next dispatcher run would match `dispatched_at < next_attempt_at` and emit a second event for the same retry. That is harmless (AC-58) but wasteful, and step 16's "one event per row" assertion would go red. `retryFromFailed` therefore sets `next_attempt_at` and `dispatched_at` from **one** `$now` value, so the two are equal to the millisecond and the dispatcher's middle clause stays false. Ruling 9 and step 17 now say so.
- **AC-114 matches Ruling 7 step for step**, including the outcome kinds (exception for steps 1 to 7, `suppressed` row for 8, `failed` row for 9) and replay before every business check. One clarification, not a contradiction: step 1 covers a key that is present but invalid (0 or 101 characters, a control or non-ASCII byte) as well as a missing one, both `idempotency_key_required` (AC-56). No spec edit is needed.
- **AC-116 matches Ruling 11** (the three clauses, `dispatched_at = now` under the delivery's row lock after re-testing). The spec adds the range 1 to 86,400, which the plan lacked; `config/notifications.php` now validates it, and step 16 tests 0 and 86,401.

**Check B: `RoleHolders::actingAmong` is reachable (AC-51; the spec writer's request 7). Confirmed.**
- The interface is `Flexipos\Contracts\Access\RoleHolders` (`packages/contracts/src/Access/RoleHolders.php`), with `actingAmong(array $employeeIds): array`: "the given employee ids, filtered to live, acting employees".
- It is bound in `packages/access/src/AccessServiceProvider.php:139` (`singleton(RoleHolders::class, DatabaseRoleHolders::class)`), and `AccessServiceProvider` is always loaded (`bootstrap/providers.php`).
- The implementation (`packages/access/src/Support/DatabaseRoleHolders.php:68-83`) filters `whereNull('deleted_at')` and `whereIn('status', $this->status->acting())`, so it reads the one authority (`EmployeeStatus`) and copies no list.
- `deptrac.yaml`: `Package_Contracts` collects `^Flexipos\\Contracts\\.*`, and the new `Package_Notifications` is granted `[Package_Kernel, Package_Contracts]` (section 5), so the edge is allowed. Precedent: `packages/workflow/src/Engine/ApproverResolver.php:7,47` calls `actingAmong` under exactly that grant (`Package_Workflow: [Package_Kernel, Package_Contracts]`, `deptrac.yaml:659-661`).
- `EmployeeDirectory::isLive` (`packages/contracts/src/Access/EmployeeDirectory.php`, bound at `AccessServiceProvider.php:133`) is reached the same way.
- Because `actingAmong` already requires liveness, the recipient rule is: `isLive` false gives `recipient_not_found`; `isLive` true and `actingAmong([$id]) === []` gives `recipient_inactive`. Two unlocked reads, advisory at request time and repeated at the attempt (AC-73).

**New questions for `ba`** are listed at the end of this plan ("Questions for `ba` (revision 2)").

### 0.5 Rulings

**Ruling 1: placement.** A new path package `packages/notifications` (`flexipos/notifications`, namespace `Flexipos\Notifications\`), registered last in `bootstrap/providers.php`, after `SalesServiceProvider`. It is not a `MODULES` module, for the T-032 F-7 reason: in part 2 the customers package resolves `Contracts\Notifications\DeliveryLinks` inside its own handler. Its contracts go in `packages/contracts/src/Notifications/`. `Support/` holds another copy of the per-package helpers (FP-433), with Finance's strict `Input` copied, not the trimming `Fields`.

**Ruling 2: the published contracts.**

`packages/contracts/src/Notifications/` (plain PHP, no vendor imports):

| Symbol | Shape |
|---|---|
| `Channel` | backed enum `email, sms, in_app, whatsapp, push, webhook`. `isSendable()` is false for `webhook` (D-3). `isOutbound()` is false for `in_app` |
| `Category`, `RecipientType`, `Priority`, `DeliveryStatus`, `SendOutcome` | backed enums with the spec's exact values. `DeliveryStatus` includes `suppressed`. `SendOutcome` is `accepted, transient_failure, permanent_failure` |
| `NotificationRequest` | readonly. `templateKey`, `channel`, `?locale`, `recipientType`, `recipientId`, `category`, `array variables`, `idempotencyKey`, `?sourceEventId`, `?priority`, `?array data`, `?actionUrl`. Every enumerated field is a **string**, so "refused, never coerced" (AC-13 style) is testable by name |
| `NotificationSender` | `request(NotificationRequest): DeliveryReceipt` (AC-49). Joins the caller's open transaction (savepoint) or opens one |
| `DeliveryReceipt` | readonly `int deliveryId`, `DeliveryStatus status`, `?errorCode`, `bool replayed` (AC-56, AC-66) |
| `NotificationRefused` / `NotificationRefusal` | `RuntimeException`, `public readonly NotificationRefusal $reason`, `?field`, `array context`. The enum holds every reason code in the spec, plus 0.2's (`field_not_supported_for_channel`, `encryption_unavailable`, `channel_not_configurable`, `not_updatable_here`, `already_active`, `already_inactive`, `page_size_invalid`, `cursor_invalid`, `not_permitted`, `delivery_not_found`). It is used by every slice in the package, so there is one vocabulary |
| `ChannelDriver` | `channel(): Channel`, `provider(): string`, `credentialFields(): list<CredentialField>`, `send(OutboundMessage, DriverCredentials): SendResult` (AC-6) |
| `OutboundMessage` | readonly `clientReference` (the delivery id as a string, AC-78), `templateKey`, `recipientAddress`, `?subject`, `body`, `?senderAddress` |
| `SendResult` | readonly `SendOutcome outcome`, `?providerRef`, `?errorCode`, `?errorMessage` |
| `CredentialField` | readonly `name`, `bool required` |
| `DriverCredentials` | final. Values are private, read by `get(name): ?string`. `__debugInfo()` and `__serialize()` expose names only, so a dump or a log context cannot carry a value |
| `DeclaresChannelDrivers` | provider interface `channelDrivers(): list<class-string<ChannelDriver>>`, swept through `$app->getProviders()` (the `ConsumerRegistry`/`AuditCatalogue` precedent, AC-4) |
| `DeliveryLinks` / `DeliveryLinkRefused` (**part 2 only**, T-040b step 26) | `assertLinkable(int deliveryId, string customerId, string communicationChannel): void`. Must run inside the caller's transaction (`LogicException` otherwise) and takes a shared lock on the delivery. Reasons: `delivery_not_found`, `delivery_customer_mismatch`, `delivery_channel_mismatch` (B9, B14). Not created in part 1: no part-1 code would use it |

`packages/contracts/src/Customers/` gains `CustomerContacts` (`contactOf(string $customerId): ?CustomerContact`) and `CustomerContact` (readonly `id`, `bool live`, `?mergedIntoId`, `status`, `?email`, `?phone`) in part 1 (AC-115). They are implemented by `packages/customers/src/Customers/ContractCustomerContacts.php` (named after `Ledger\ContractCreditLedger`, the package's existing contract implementation) and bound as a singleton in `CustomersServiceProvider::register()`, beside the two existing contract bindings (lines 35 to 36).

**Ruling 3: the driver registry and the provider whitelist** (AC-3, AC-4, AC-7, D-4).
- `Drivers\DriverRegistry` sweeps `DeclaresChannelDrivers` providers and keys drivers by `(channel, provider)`. A duplicate pair throws `LogicException` at sweep time.
- The *configurable* provider set is a separate constant, `Channels\ProviderPolicy::AVAILABLE = ['log', 'null']`. A follow-up driver task widens it (D-4).
- `NotificationsServiceProvider` declares `LogDriver` and `NullDriver` once per outbound channel (`email`, `sms`, `whatsapp`, `push`): one class each, constructed per channel by a small factory. `channel()` returns the channel it was built for.
- **In-app is not a `ChannelDriver`.** It is `Drivers\InAppWriter`, called by the attempt inside the attempt's own transaction (AC-11, AC-83). No config is resolved for it (D-10, correction 14).

**Ruling 4: credentials** (AC-18 to AC-22, D-8). `Channels\CredentialVault` is the only class that touches the encrypter.
- `seal(array $credentials): string` does `encryptString(json_encode($credentials))`.
- `open(string $ciphertext): DriverCredentials` maps `DecryptException`, `MissingAppKeyException` and an invalid JSON result to `credentials_undecryptable`.
- `redact(string $message, DriverCredentials): string` replaces every stored value with `[redacted]`, then truncates to 500 characters with `mb_substr` (AC-10).

A write naming credentials with no key configured is refused `encryption_unavailable` (correction 12). Reads and audit rows use `Channels\ChannelConfigView`, the one projection, which drops `credentials` and adds `has_credentials` (AC-20). The log driver declares exactly `sandbox_token` (optional); null declares none.

**Ruling 5: authorization** (AC-104). `Support\Authorize::require(?string $actorId, string $key)` (the Finance copy) is the first statement of every admin slice, before any transaction and any read. The ten keys live in `Registry\NotificationsPermissions` (module `notifications`, all `isBranchScoped = false`):

| Group | Keys |
|---|---|
| channels | `notifications.channels.view`, `notifications.channels.manage` |
| templates | `notifications.templates.view`, `notifications.templates.manage` |
| deliveries | `notifications.deliveries.view`, `notifications.deliveries.view_content`, `notifications.deliveries.retry` |
| consents | `notifications.consents.view`, `notifications.consents.manage` |
| inbox | `notifications.inbox.read` |

`view_content` is not a gate on the slice. It is a second `decide()` inside `GetDelivery`/`ListDeliveries` that chooses the projection (AC-92). The sender, the attempt, the dispatcher and the purge are system paths with no key (AC-105). All ten keys are declared in part 1, so part 2 adds no key.

**Ruling 6: the lock table and the global order.** Write slices run `Authorize`, then pure parsing, then `$connection->transaction(fn, attempts: 2)`. **Global order: `deliveries` row, then `channel_configs` row, then any insert.** No path locks a config before a delivery, so no two of these paths can deadlock each other.

| Operation | Locks | Notes |
|---|---|---|
| Config create | none, then insert | `unique(channel, provider)` gives `channel_config_exists`; generated `active_channel` unique gives `channel_already_active`. Both are caught and the holder re-read **with a lock** |
| Config update / activate / deactivate / delete | target `FOR UPDATE` | activate: a locking read of the current active holder for that channel first, with the generated column as the backstop (AC-17) |
| Template create / update / activate / deactivate | target `FOR UPDATE` (update and state changes) | `unique(key, channel, locale)` gives `template_exists` |
| Consent record | `(customer, channel, category)` row `FOR UPDATE`, else insert | unique backstop, then re-read and update (AC-61) |
| Request (sender) | none on configs, templates or consents | these request-time reads are **advisory**: the attempt re-checks all of them under lock (AC-65, AC-73). The idempotency backstop is the unique hash; on violation, re-read the holder `FOR UPDATE` |
| Attempt | delivery `FOR UPDATE`; then its channel's active config `FOR UPDATE`; then the in-app insert | the driver call runs **inside** this transaction (D-19: the row lock is what replaces a `sending` state). Risk 2 covers what this means for a real driver |
| Retry | delivery `FOR UPDATE` | |
| Dispatcher | per row: delivery `FOR UPDATE`, re-test the due condition, write the outbox row, set `dispatched_at` | bounded batch of ids from one unlocked select |
| Purge (part 2) | per batch: the expire update by id, then delete by id | never touches configs |
| `DeliveryLinks` (part 2) | delivery `sharedLock` inside the customers transaction | |

**Ruling 7: the request path** (`Features/RequestNotification`, AC-49 to AC-57, AC-62 to AC-68 and AC-114, which adopted this order verbatim). The order is fixed (correction 19):
1. Shape: every field type and value. Refusals so far are exceptions and write nothing: `idempotency_key_required`, the enums, the `channel` list refusal, `webhook` refused `channel_not_supported`, the in-app fields refused off `in_app`, `priority`, `data` and `action_url_invalid`.
2. **Replay.** Compute `payload_hash` (canonical JSON of the normalised request, keys sorted, `idempotency_key` and `source_event_id` excluded) and `idempotency_key_hash`. If a row holds the key hash, either return its receipt (`replayed = true`) or refuse `idempotency_key_conflict`. Replay comes before the business checks, so a replay answers the same even after a config is deactivated.
3. Recipient compatibility: `recipient_type_unsupported`; an employee on any channel but `in_app`, or a customer on `in_app`, is refused `channel_not_supported_for_recipient` (correction 6); then `category_not_allowed_for_recipient`.
4. Recipient existence through the contracts: `recipient_not_found`, `recipient_merged` (checked before liveness, because a merge soft-deletes the source), `recipient_inactive`, `recipient_not_eligible` (marketing to a blocked or inactive customer). Employees: `EmployeeDirectory::isLive` false gives `recipient_not_found`; live but absent from `RoleHolders::actingAmong([$id])` gives `recipient_inactive` (section 0.4, check B). Customers: `CustomerContacts::contactOf` null gives `recipient_not_found`; `mergedIntoId` set gives `recipient_merged`; `live` false gives `recipient_not_found`.
5. Template: exact `(key, channel, locale)`, else the `en` fallback, else `template_not_found`; an inactive template is `template_inactive`.
6. Variables and rendering: `missing_variable`, `unknown_variable`, `invalid_variable` (all failing names together), then `rendered_too_long`.
7. Channel config: no active config for an outbound channel is `channel_not_configured`.
8. **Consent** (customers only): the result is a `suppressed` row (`consent_missing` / `consent_opted_out`), no outbox row, and a receipt is returned.
9. **Address** (customers only): an empty address gives a `failed` row (`recipient_address_missing`), no outbox row, and a receipt.
10. Otherwise: one `queued` row (`attempts = 0`, `next_attempt_at = now`, `dispatched_at = now`), plus one outbox row through `Contracts\Events\Outbox` (`notification.delivery_requested`, aggregate `delivery`/`ref`, payload `{delivery_id}`, actor `EventActor::system()`), in the same transaction.

**Ruling 8: the attempt** (`Features/AttemptDelivery`, AC-58 and AC-70 to AC-78, AC-81, AC-83, AC-86). Inside one transaction:
1. Lock the delivery. If it is missing, log at info and stop (purged). If it is not `queued`, or `next_attempt_at > now`, stop (no-op).
2. Re-check the recipient, giving `failed` with the reason. Then re-check consent (customers), giving `suppressed`. Neither counts an attempt.
3. **In-app:** `InAppWriter` inserts `notifications` with `delivery_id`. The unique key makes a second write impossible (AC-86). The delivery becomes `delivered` (`sent_at = delivered_at = now`, `attempts + 1`).
4. **Outbound:** lock the active config for the channel; none gives `failed channel_not_configured`. Reset `sent_today` when `sent_today_date ≠ today`. If the limit is reached, set `next_attempt_at` to the next 00:00:00 UTC and leave attempts unchanged (AC-77). Resolve the driver: none gives `failed driver_not_registered`. Open the credentials: failure gives `failed credentials_undecryptable`.
5. Call the driver inside `try/catch (Throwable)`. A throw, or an outcome outside the three, becomes `transient_failure driver_error` with the message redacted and truncated (AC-10).
6. Apply the outcome through `DeliveryWriter`:
   - **accepted:** `sent`, plus `sent_today + 1`;
   - **transient:** `attempts + 1`. At max attempts the delivery is `failed max_attempts_exceeded`, with the driver code kept in `error`. Otherwise `next_attempt_at = now + min(cap, base × 2^(attempts−1))`;
   - **permanent:** `failed` with the driver's code, `attempts + 1`.

**Ruling 9: one writer of `deliveries.status`** (AC-70, AC-82). `Deliveries\DeliveryStateMachine` is pure: the allowed moves and nothing else. `Deliveries\DeliveryWriter` holds the only `insert`/`update` on `deliveries` that names `status`. Its methods are `createQueued`, `createSuppressed`, `createFailed`, `markSent`, `markDelivered`, `markFailed`, `markSuppressed`, `defer`, `retryFromFailed`, `markDispatched`, and the internal `recordProviderOutcome(id, delivered|bounced)` (AC-71: called by tests only, documented for the callback task). Every move consults the state machine, and an illegal pair throws `invalid_transition` and writes nothing. The purge's `delete` is the one other write; it lives in `PurgeDeliveries` (T-040b) and is scanned for.

The `dispatched_at` writes are exactly three: `createQueued` (request, AC-54) and `retryFromFailed` (retry, AC-79) each set `next_attempt_at` and `dispatched_at` from **one** `$now` value, so the two are equal and the dispatcher's `dispatched_at < next_attempt_at` clause is false for a row whose event was just written; `markDispatched` (dispatcher, AC-116) sets `dispatched_at = now` only. `retryFromFailed` also sets `attempts = 0` and clears `error_code` and `error`. `defer` (daily limit) and the transient branch of the attempt move `next_attempt_at` forward and leave `dispatched_at` alone, which is what makes them due for the dispatcher later.

**Ruling 10: rendering** (AC-33 and AC-39 to AC-48, D-12, D-34).
- **`Templates\TemplateSyntax::parse(string)`** returns a token list `[text|placeholder(name)]`.
  - A placeholder is `\{\{[ ]*([a-z][a-z0-9_]{0,39})[ ]*\}\}`.
  - After the placeholders are removed, any remaining `{{` or `}}` is `malformed_placeholder` with its byte and character offset.
  - Regexes use `\A…\z`, never `^…$`.
- **`Rendering\VariableRules`** applies AC-40's types:
  - a number is an `is_int`, or a string matching `\A-?\d{1,18}(\.\d{1,4})?\z`; never `is_float`;
  - a date matches `\A\d{4}-\d{2}-\d{2}\z` and passes `checkdate`;
  - a URL starts with `https://`, is at most 500 characters, and contains no `@` before the host, no whitespace and no `\p{Cc}`.
- **`Rendering\Renderer::render(tokens, values, Channel, field)`** makes a single pass over the tokens.
  - E-mail body: `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`, once.
  - Everything else: `Rendering\PlainText::clean`. Tab becomes a space. Every other `\p{Cc}` is removed, and so are U+2028, U+2029, U+202A to U+202E and U+2066 to U+2069.
  - Integers are rendered with `(string)`. No locale, no time, and no randomness (AC-46).
- **`Rendering\Masking`** masks e-mail as first character, `***`, then `@domain`, and a phone as `***` plus the last two digits (AC-8, AC-92).

**Ruling 11: the dispatcher and the consumer** (AC-58 to AC-60 and AC-116, which adopted the due rule below verbatim; correction 11). `redispatch_after_seconds` is an integer 1 to 86,400 (default 300), validated like the batch size.
- **`DeliveryRequestedConsumer`:** group `notifications`, types `['notification.delivery_requested']`. `handle()` reads `payload.delivery_id`, which must be an int; a malformed payload is logged and acknowledged. It then calls the `AttemptDelivery` handler. It never throws for a business outcome. A thrown exception means infrastructure (a deadlock after 2 tries, a dead connection), and the bus redelivers it.
- **`notifications:dispatch-due`:** the fleet sweep shape of `ArchiveAuditLogsCommand` (`--tenant=*`, one tenant's failure does not stop the rest). Batch default 100 (`notifications.dispatch_batch`, 1 to 1000, else it fails to start with a named error). The query is `status = queued AND next_attempt_at <= now AND (dispatched_at IS NULL OR dispatched_at < next_attempt_at OR dispatched_at <= now − redispatch_after)`, ordered by `next_attempt_at, id`. It prints the requeued count and writes no audit row. It is scheduled every minute with `withoutOverlapping()`, and inherits FP-319.

**Ruling 12: audit** (AC-103). One `AuditTrail::record('notifications', …)` per successful admin write, inside the transaction. Before and after values come from `ChannelConfigView` for configs and from the row for templates and consents. `UpdateChannelConfig` declares two constants, `ACTION` and `CREDENTIALS_ACTION`. A body changing both credentials and fields writes two rows. The credentials row's before and after values are `{has_credentials, credentials_updated_at}` only. Requests, attempts and moves are not audited.

T-040 declares **twelve** actions in `Registry\NotificationsAuditActions::ALL`, each the `ACTION` (or `CREDENTIALS_ACTION`) constant of the handler that records it: `notifications_channel_{created,updated,credentials_changed,activated,deactivated,deleted}`, `notifications_template_{created,updated,activated,deactivated}`, `notifications_consent_recorded`, `notifications_delivery_retried`. T-040b adds `notifications_deliveries_purged` with `PurgeDeliveriesHandler` (B16), so every declared action has a recorder in the same task.

**Subjects.** `audit_logs.subject_id` is an identifier column (`IdentifierColumn::nullable`, `0001_01_01_000005_create_audit_logs_table.php:51`), so every subject is a ULID: the config, template or consent row id; for the retry, `subject_type = delivery` and `subject_id = ref`, with `delivery_id` (the integer) inside `before` and `after`; for the purge (T-040b), `subject_type = deliveries_purge` and a fresh ULID per tenant run, with `{cutoff, expired, deleted}` in `after`.

**Ruling 13: test cost and engine.**
- Pure classes carry most cases as datasets. Each slice gets one fixture-backed file, in which every refusal writes nothing and a success writes one audit row, compared whole.
- Every `[ENGINE]` clause goes in `modules/host/tests/Engine/NotificationsSchemaEngineTest.php`, skipped under `ENGINE_HOLD` (`EngineConnection::onHold()`), on the exact database `flexipos_t8test_t040_tenant`. It is created and dropped by exact name and never swept with a `LIKE` pattern. These clauses are **NOT VERIFIED** until the hold lifts.
- Estimate: part 1 about 380 cases (about 65 % pure), part 2 about 140.

**Ruling 14: the vocabulary sweep.** Before step 4, `dev` runs `QaVocabularyRenameSweepTest`'s retired spellings against every enum value and reason code here.

---

## 1. Approach summary and the split

1. A new `packages/notifications` and a contract namespace `Contracts\Notifications`. Other modules call `NotificationSender` only; customers implement the new `CustomerContacts`.
2. Five tenant tables per §6.16, as amended (section 4) in T-040; one owed column on `customer_communications`, expand then contract, in T-040b.
3. A send is validated, rendered once at request time, and stored as a snapshot on a `deliveries` row. In the same transaction an id-only outbox event is written. The `notifications` consumer group attempts it under a row lock. A scheduled dispatcher re-emits due rows (retries, deferrals, lost events).
4. Drivers sit behind `ChannelDriver`. Only `log` and `null` exist, and in-app is a database write. The configurable provider set is a data whitelist.
5. Credentials are sealed with the existing `APP_KEY` encrypter, write-only, and redacted from every sink. A sentinel test scans every table, the log, the outbox, exceptions and results.
6. Consent is fail-closed for marketing, re-checked at the attempt, and a refusal becomes a `suppressed` row.
7. Every admin slice authorizes first, at the handler, and writes one audit row. The status column has one writer.

**The split: two deliverable tasks** (accepted by `lead`, D-35 and T-040b D-1). Customers and consent cannot move to the second task: without customers, the log and null drivers have no reachable recipient (employees are in-app only, correction 6). And a customer send path without the consent check is not safe to merge.

| Task | Contents | ACs (final) | Chunks |
|---|---|---|---|
| **T-040** (FP-295, this row) | scope guard, drivers, configs and secrets, templates and rendering, the send contract, the `CustomerContacts` contract and its customers implementation, consent store and check, delivery lifecycle, retry, dispatch, the in-app write, delivery reads, counts and retry, all ten keys, twelve audit actions, five tables, the §6.16 and §3.3 documentation | AC-1 to AC-86, AC-89, AC-91 to AC-93, AC-103 (twelve actions), AC-104, AC-105, AC-107 to AC-111, AC-112 to AC-119 (106 criteria) | A–E |
| **T-040b** (new row, FP-295 follow-on) | employee inbox reads, retention purge, the owed `customer_communications.delivery_id` (expand, contract, `DeliveryLinks`, `LogCustomerCommunication`), the HTTP routes for every admin operation and the inbox, the thirteenth audit action, the §6.9 note | B1 to B17 (B1–B3 inbox, B4–B5 purge, B6–B12 and B14–B15 owed column, B13 routes, B16 purge action, B17 documents and gate) | F–H |

T-040b changes no T-040 table. Its only schema is the owed column. The routes are kept apart because six existing route sweeps must learn every new route (section 9).

## 2. Alternatives rejected

- **Notifications as a `MODULES` leaf.** Rejected by Ruling 1 (the customers part depends on its contract).
- **Laravel's own `Illuminate\Notifications`.** It carries a mail channel and its own `notifications` table shape. AC-1 forbids the reference, and §6.16's shape differs.
- **Rendering at send time.** D-14 says otherwise, and it would move "invalid input" into a worker.
- **A `sending` status or a lease column.** D-19: the row lock covers it. A lease is a real-driver follow-up (risk 2).
- **A processed-event table for consumer idempotency.** The delivery's status under lock already decides it (correction 18).
- **A separate credential key now.** That would be a new secret, which is an escalation (0.3).
- **Routes in T-040.** They bring large sweep churn for no consumer yet; Finance shipped without them too.
- **One delete-capable template slice refusing `not_supported`.** A slice that only refuses is dead code (correction 15).

## 3. Files to change / create

`dev` writes every file. `Qa*` files are `qa`-accountable.

### `packages/contracts/src/Notifications/` (new)
Ruling 2: `Channel`, `Category`, `RecipientType`, `Priority`, `DeliveryStatus`, `SendOutcome`, `NotificationRequest`, `NotificationSender`, `DeliveryReceipt`, `NotificationRefused`, `NotificationRefusal`, `ChannelDriver`, `OutboundMessage`, `SendResult`, `CredentialField`, `DriverCredentials` and `DeclaresChannelDrivers` (part 1, step 4). Part 2 adds `DeliveryLinks` and `DeliveryLinkRefused` (step 26, B14).

### `packages/contracts/src/Customers/` (two new files, part 1, step 4)
`CustomerContacts.php`, `CustomerContact.php` (AC-115). `QaCustomersSourceRulesTest`'s contracts-purity case (line 628) already scans this directory, so the new files must import nothing outside `Flexipos\Contracts` and PHP core.

### `packages/notifications` (new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/notifications` 0.1.0, requires `flexipos/contracts` only (AC-1: no mail, SMS, HTTP or SDK package) |
| `src/NotificationsServiceProvider.php` | Binds `NotificationSender`. Implements `DeclaresAuditActions` (twelve actions in part 1, thirteen after step 23), `DeclaresPermissions`, `DeclaresEventConsumers` and `DeclaresChannelDrivers`. Registers `notifications:dispatch-due` (part 2 adds `notifications:purge-deliveries`) and schedules them. Part 2 also loads routes |
| `src/Channels/{ChannelConfigInput,ProviderPolicy,ChannelConfigRows,ChannelConfigView,CredentialVault}.php` | Rulings 3 and 4 |
| `src/Drivers/{LogDriver,NullDriver,DriverRegistry,InAppWriter}.php` | Ruling 3 |
| `src/Templates/{TemplateSyntax,TemplateInput,TemplateRows,TemplateResolver}.php` | Ruling 10, AC-26 to AC-38, AC-52 |
| `src/Rendering/{VariableRules,Renderer,PlainText,Masking}.php` | Ruling 10 |
| `src/Recipients/{RecipientRules,RecipientResolver}.php` | AC-51 to AC-53 and AC-68, through `EmployeeDirectory`, `RoleHolders` and `CustomerContacts` |
| `src/Consent/{ConsentPolicy,ConsentRows}.php` | AC-61 to AC-66 |
| `src/Deliveries/{DeliveryStateMachine,DeliveryWriter,DeliveryRows,Backoff,DailyLimit,PayloadHash}.php` | Rulings 8 and 9 |
| `src/Pipeline/{DatabaseNotificationSender,DeliveryRequestedConsumer}.php` | Rulings 7 and 11 |
| `src/Support/{TenantConnection,Input,Fields,Limits,Authorize,Page}.php` | the per-package helper copies (Finance's) |
| `src/Registry/{NotificationsAuditActions,NotificationsPermissions}.php` | Rulings 5 and 12 |
| `src/Features/…` | Part 1: `CreateChannelConfig`, `UpdateChannelConfig`, `ActivateChannelConfig`, `DeactivateChannelConfig`, `DeleteChannelConfig`, `ListChannelConfigs`, `GetChannelConfig`, `CreateTemplate`, `UpdateTemplate`, `ActivateTemplate`, `DeactivateTemplate`, `ListTemplates`, `GetTemplate`, `RecordConsent`, `ListConsents`, `RequestNotification`, `AttemptDelivery`, `DispatchDueDeliveries` (plus `…Command`), `ListDeliveries`, `GetDelivery`, `CountDeliveries`, `RetryDelivery`. Part 2: `ListMyNotifications`, `MarkNotificationRead`, `MarkAllNotificationsRead`, `PurgeDeliveries` (plus `…Command`) |
| `src/Deliveries/DatabaseDeliveryLinks.php` (part 2, step 26) | `DeliveryLinks` (B14), bound by `NotificationsServiceProvider` |
| `routes/api.php` (part 2) | the `packages/organization/routes/api.php` shape |
| `tests/Support/{NotificationsSeed.php,notifications-fixture.php,SpyLogger.php,ThrowingDriver.php,ScriptedDriver.php,SentinelHarness.php}` | raw-insert seeds, one employee per permission profile, a driver scripted per attempt (AC-10, AC-75, AC-76), the sentinel capture |
| `tests/<area>/*Test.php`, `tests/Features/<X>/<X>Test.php` | section 7. `VerticalSliceLayoutTest` requires the mirror |

### `packages/customers`
- **Part 1 (step 13, AC-115):** `src/Customers/ContractCustomerContacts.php` implements `Contracts\Customers\CustomerContacts`, and `CustomersServiceProvider::register()` binds it as a singleton beside the two existing contract bindings. It is one plain read of `customers` by id selecting `id`, `deleted_at`, `merged_into_id`, `status`, `email` and `phone`, with **no** `whereNull('deleted_at')`, so a merged or soft-deleted customer is still answered with `live = false`; an unknown id answers null. It names no maintained column (`credit_balance`, loyalty points), so `QaCustomersSourceRulesTest`'s AC-41 cases stay green unedited. Test: `packages/customers/tests/Customers/ContractCustomerContactsTest.php`.
- **Part 2 (step 26, B9):** `LogCustomerCommunicationHandler` gains `delivery_id` in `FIELDS`. It must be a JSON int ≥ 1, and is checked through `DeliveryLinks` inside the existing transaction. The edited tests are listed in section 9.

### `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/2026_11_20_004001_create_notification_templates_table.php` | expand |
| `…004002_create_channel_configs_table.php` | expand |
| `…004003_create_deliveries_table.php` | expand |
| `…004004_create_notifications_table.php` | expand (FKs on its own new table: `recipient_employee_id` RESTRICT, `delivery_id` SET NULL, unique `delivery_id`) |
| `…004005_create_notification_consents_table.php` | expand |
| `…004006_add_delivery_id_to_customer_communications_table.php` (part 2) | expand: column and index, no key |
| `…004007_add_customer_communication_delivery_foreign_key.php` (part 2) | `@phase contract`, the `003010` shape, `ON DELETE SET NULL` |
| `config/notifications.php` | numbers only: `max_attempts` 5 (1–20), `backoff_base_seconds` 60, `backoff_cap_seconds` 3600 (1–86400, cap ≥ base), `dispatch_batch` 100 (1–1000), `redispatch_after_seconds` 300 (1–86400, AC-116), `retention_days` 90 (7–3650; read only by T-040b's purge, but the file is created once in part 1), `purge_batch` 500, `page_size` 25, `max_page_size` 100. Each is validated at use and fails with a named error |
| `tests/Feature/QaNotificationsScopeGuardTest.php` | AC-1 (step 3) |
| `tests/Feature/QaNotificationsSourceRulesTest.php` | step 19 |
| `tests/Feature/QaNotificationsNoHttpSurfaceTest.php` | part 1, step 19: AC-105's "no route at all" test (the `QaEventbusNoHttpSurfaceTest` shape). **T-040b deletes it in step 27**, because its routes make it false by design |
| `tests/Feature/QaNotificationsNoSendRouteTest.php` (part 2) | step 27: replaces the file above in the same step. No registered route's controller imports or resolves `NotificationSender`, and no route path or name contains `send` (B13, AC-105) |
| `tests/Feature/OwedDeliveryColumnMigrationsTest.php` (part 2) | step 25 |
| `tests/Engine/NotificationsSchemaEngineTest.php` | steps 20 and 28 |
| `app/Http/Controllers/Notifications/*Controller.php` (part 2) | invokable, one per route |

**Migration prefix.** `2026_11_20_0040NN` sorts after T-032's pending `2026_11_15_00320[4-8]`, and leaves room for the parallel tasks. Migrations **never** name a catalogue version. Every test edit below is by migration **name** where it can be, and the version literals are regenerated at merge, never hand-merged.

## 4. Data / API changes

### 4.1 Schema (tenant only; no master change; no decimal column)

| Table | Columns as built | Keys and indexes |
|---|---|---|
| `notification_templates` | `id` ULID; `created_at`, `updated_at` DATETIME(3); `key` VARCHAR(80); `channel` VARCHAR(30); `locale` VARCHAR(10); `subject` VARCHAR(255) NULL; `body` MEDIUMTEXT; `variables` JSON (`JsonValidity::enforce`); `is_active` BOOL default true; `plugin_name` VARCHAR(100) NULL | unique (`key`, `channel`, `locale`) |
| `channel_configs` | `id` ULID; `[TS]`; `channel` VARCHAR(30); `provider` VARCHAR(50); `credentials` TEXT NULL (ciphertext); `credentials_updated_at` DATETIME(3) NULL; `settings` JSON NULL; `from_address` VARCHAR(191) NULL; `is_active` BOOL default false; `daily_limit` INT UNSIGNED NULL; `sent_today` INT UNSIGNED default 0; `sent_today_date` DATE NULL; `active_channel` VARCHAR(30) NULL **stored** `CASE WHEN is_active THEN channel END` | unique (`channel`, `provider`); unique `active_channel` (AC-17 backstop; the `customer_groups.default_marker` shape) |
| `deliveries` | `id` BIGINT UNSIGNED AUTO PK; `ref` ULID; `channel` VARCHAR(30); `template_key` VARCHAR(80); `locale` VARCHAR(10); `category` VARCHAR(30); `recipient_type` VARCHAR(30); `recipient_id` ULID; `recipient` VARCHAR(191) NULL; `subject` VARCHAR(255) NULL; `body` MEDIUMTEXT NULL; `in_app` JSON NULL; `provider` VARCHAR(50) NULL; `status` VARCHAR(30); `provider_ref` VARCHAR(191) NULL; `error_code` VARCHAR(50) NULL; `error` VARCHAR(500) NULL; `attempts` SMALLINT UNSIGNED default 0; `idempotency_key` VARCHAR(100); `idempotency_key_hash` CHAR(64); `payload_hash` CHAR(64); `source_event_id` ULID NULL (no key: outbox has 30-day retention); `next_attempt_at`, `last_attempt_at`, `dispatched_at`, `sent_at`, `delivered_at` DATETIME(3) NULL; `created_at`, `updated_at` DATETIME(3) | unique `ref`; unique `idempotency_key_hash`; index (`created_at`), (`status`, `created_at`), (`status`, `next_attempt_at`), (`recipient_type`, `recipient_id`, `created_at`). No key on the recipient: it is polymorphic, the §3.3 ledger exception |
| `notifications` | `id` ULID; `[TS]`; `recipient_employee_id` ULID; `type` VARCHAR(80); `title` VARCHAR(255); `body` TEXT; `data` JSON NULL; `priority` VARCHAR(30) default `normal`; `read_at` DATETIME(3) NULL; `action_url` VARCHAR(500) NULL; `delivery_id` BIGINT UNSIGNED NULL | unique `delivery_id`; index (`recipient_employee_id`, `created_at`), (`recipient_employee_id`, `read_at`); FK `recipient_employee_id` → employees (RESTRICT), FK `delivery_id` → deliveries **ON DELETE SET NULL** |
| `notification_consents` | `id` ULID; `[TS]`; `customer_id` ULID; `channel` VARCHAR(30); `category` VARCHAR(30); `status` VARCHAR(30); `source` VARCHAR(50); `note` VARCHAR(255) NULL; `recorded_by` ULID | unique (`customer_id`, `channel`, `category`); FK `customer_id` → customers (RESTRICT; customers are never hard-deleted), FK `recorded_by` → employees |
| `customer_communications` (part 2) | + `delivery_id` BIGINT UNSIGNED NULL, no default, no backfill | index `delivery_id` (expand); FK → `deliveries.id` ON DELETE SET NULL (contract only) |

- **Foreign keys:** part 1 adds +4 to `TenantBaselineBuildsTest`'s inventory; part 2 adds +1.
- **Tables:** `tenantTreeTables()` gains +5.
- **Signedness (AC-117 in T-040; B8 in T-040b):** `deliveries.id` uses `$table->id()` (BIGINT UNSIGNED AUTO_INCREMENT), and both `delivery_id` columns use `unsignedBigInteger`. The SQLite stand-in does not report signedness, so the declaration half is a source read: through `SourceRules::codeOnly`, `004003` must contain `$table->id()` and no `bigInteger(`/`increments(` for `id`, and `004004` must declare `unsignedBigInteger('delivery_id')`. Red-first: a fixture copy declaring `bigInteger('delivery_id')` is reported. The engine half (a signed column is refused by the key) is `[ENGINE]`, step 20.

### 4.2 Contracts, events, config and surface
- **Contracts:** Ruling 2.
- **Event:** `notification.delivery_requested` v1, consumed by group `notifications`.
- **Commands:** `notifications:dispatch-due` (part 1) and `notifications:purge-deliveries` (part 2), both with `--tenant=*`.
- **Permissions:** ten keys, all declared in part 1. **Audit actions:** twelve declared in part 1 (AC-103); the thirteenth, `notifications_deliveries_purged`, is declared in part 2 with its handler (B16).
- **Config:** `config/notifications.php`. **HTTP:** none in part 1; the routes are part 2.
- **Dependencies:** only the path package. The `composer.lock` diff adds only that entry.

### 4.3 Proposed `docs/database.md` amendments (exact text; applied by `architect` at step 21 for T-040 and step 28 for T-040b, not now; describe, never quote retired vocabulary)

**T-040, step 21 (AC-109): §6.16 and §3.3.** **T-040b, step 28 (B17): §6.9 only.** Nothing else in `docs/database.md` changes in either task.

**§6.16, the table rows replaced:**

| Table | Columns / notes |
|---|---|
| notification_templates | `[ID]` `[TS]`, key VARCHAR(80) (lower case `^[a-z][a-z0-9_.]{0,79}$`), channel enum(email, sms, in_app, whatsapp, push, webhook) (`webhook` refused on write until the webhook task), locale VARCHAR(10), subject VARCHAR(255) NULL, body MEDIUMTEXT, variables JSON, is_active (default true), plugin_name NULL; unique (key, channel, locale) |
| channel_configs | `[ID]` `[TS]`, channel, provider VARCHAR(50), credentials TEXT NULL (ciphertext from the application encrypter, `APP_KEY`; write-only), credentials_updated_at NULL, settings JSON NULL (never a secret), from_address VARCHAR(191) NULL, is_active (default false), daily_limit INT UNSIGNED NULL, sent_today INT UNSIGNED, **sent_today_date DATE NULL** (the UTC day `sent_today` counts), active_channel generated stored (`CASE WHEN is_active THEN channel END`); unique (channel, provider), unique (active_channel) — at most one active config per channel. No row for `in_app` |
| notifications (in-app) | `[ID]` `[TS]`, recipient_employee_id FK, type, title, body, data JSON, priority enum(low, normal, high), read_at, action_url, **delivery_id BIGINT UNSIGNED NULL unique FK deliveries ON DELETE SET NULL**; index (recipient_employee_id, created_at), (recipient_employee_id, read_at). Never purged |
| deliveries | id BIGINT UNSIGNED AUTO PK, **ref CHAR(26) unique** (the outbox aggregate id), channel, template_key, **locale, category enum(transactional, marketing, system), recipient_type enum(employee, customer), recipient_id CHAR(26)** (no FK, polymorphic), recipient VARCHAR(191) NULL (address snapshot; null for in-app and for a missing address), **subject VARCHAR(255) NULL, body MEDIUMTEXT NULL** (rendered snapshot), **in_app JSON NULL**, provider NULL, status enum(queued, sent, delivered, failed, bounced, **suppressed**), provider_ref, **error_code**, error VARCHAR(500), attempts, **idempotency_key VARCHAR(100), idempotency_key_hash CHAR(64) unique, payload_hash CHAR(64)**, source_event_id CHAR(26) NULL, **next_attempt_at, last_attempt_at, dispatched_at**, sent_at, **delivered_at**, created_at, **updated_at**. Index (created_at), (status, created_at), (status, next_attempt_at), (recipient_type, recipient_id, created_at). 90-day retention |
| **notification_consents** (new) | `[ID]` `[TS]`, customer_id FK, channel enum(email, sms, whatsapp, push), category enum(transactional, marketing), status enum(opted_in, opted_out), source VARCHAR(50), note VARCHAR(255) NULL, recorded_by FK employees; unique (customer_id, channel, category). History lives in `audit_logs` |

`notification_rules` and `webhook_endpoints` / `webhook_deliveries` stay as documented, marked "not built (T-040 D-1)".

**§6.16 (T-040), a new paragraph after the table, exactly:**

> #### As built (T-040)
>
> T-040 builds `notification_templates`, `channel_configs`, `notifications`, `deliveries` and the new `notification_consents`; `notification_rules` and the two webhook tables are not built (T-040 D-1). **A send is rendered once, at request time**, and the rendered subject and body are stored on the `deliveries` row as a snapshot, so editing a template never changes a queued message. In the same transaction the one outbox writer records `notification.delivery_requested` with `aggregate_type = delivery`, `aggregate_id = ref` (the outbox accepts only a ULID there, so `ref` exists beside the BIGINT `id`) and a payload holding the delivery id only; no address, body or credential travels on the bus. The consumer group `notifications` attempts the delivery under the row's lock, and idempotency is the row's own status and `next_attempt_at`, not a store of processed events. `notifications:dispatch-due` re-emits a due `queued` row only when it was never dispatched, when `dispatched_at` is earlier than `next_attempt_at` (a retry or a daily-limit deferral has come due), or when `dispatched_at` is older than `redispatch_after_seconds`; it is also the only thing that wakes retries and deferrals, and no scheduler runs it in production until FP-319. **Status moves** are `queued` to `sent`, `failed`, `suppressed` or (in-app only) `delivered`; `sent` to `delivered` or `bounced`; and `failed` back to `queued` by manual retry only, all through one writer. `suppressed` records a consent refusal, which is neither a send nor a provider failure. **The idempotency key is unique by its SHA-256 digest** (`idempotency_key_hash`), not by a binary collation, because migrations may not name a collation; the digest keeps keys that differ only in case distinct on any collation. **Consent fails closed for marketing**: a customer is sent marketing only with a recorded opt-in, and transactional messages unless opted out, checked at request time and again at the attempt. **One delivery writes at most one in-app row**, enforced by the unique `notifications.delivery_id`. The only outbound drivers are `log` and `null`, and the configurable provider set is exactly those two; in-app is a database write with no driver and no `channel_configs` row. Provider credentials are encrypted with the application encrypter keyed by `APP_KEY` (previous keys honoured), are write-only, and appear in no read, log, audit row or event.

**§3.3 (T-040), one bullet inserted directly after the first bullet ("Foreign keys everywhere inside a database"), exactly:**

> - **`SET NULL` for a reference to a row that a retention purge deletes.** When the referenced row is removed by a documented retention purge and the referencing row keeps its own content, the key is `ON DELETE SET NULL` on a nullable column, so the purge is never blocked and never deletes the referencing row. It is used only for references to `deliveries` (90-day retention, §6.16), first by `notifications.delivery_id` (T-040 D-27, D-50). Everything else stays `RESTRICT` or `CASCADE` as above.

**§6.9 (T-040b, step 28, B17), two edits, exactly:**
1. In the `customer_communications` row of the table, `delivery_id FK NULL` becomes `delivery_id BIGINT UNSIGNED NULL FK deliveries ON DELETE SET NULL (T-040b)`.
2. A new paragraph after the "As built (T-030)" paragraph at the end of "As built (T-028)":

> **As built (T-040b).** `customer_communications.delivery_id` is now built, so the deferral above no longer holds for that column: an expand migration adds it as `BIGINT UNSIGNED NULL` with an index and no backfill, and a separate contract migration adds the foreign key onto `deliveries.id` with `ON DELETE SET NULL` (§3.3), after a data-clean check that refuses, changing nothing, when any value names no delivery. A delivery may be referenced by more than one communication, so there is no unique key. `LogCustomerCommunication` accepts a `delivery_id` only through the published `DeliveryLinks` contract, which checks that the delivery exists, belongs to the same customer and matches the channel; the customers package never reads `deliveries`. Nothing writes a communication automatically when a message is sent.

**§1/§15:** nothing.

## 5. Boundary impact and merge order

- **`deptrac.yaml`:**
  - `Package_Notifications` = `^Flexipos\\Notifications\\(?!Tests\\).*` with `[Package_Kernel, Package_Contracts]`.
  - `Package_Notifications_Tests` = itself plus `Package_Contracts`, `Package_Kernel`, `Package_Access` (fixture), and `Package_Eventbus` (stand-in bus, the plugin-sdk-tests precedent).
  - `HostApp` and `HostTests` gain both layers. **`Module_Demo` is not granted `Package_Notifications`** (the AC-110 instrument; step 1's negative control).
  - Customers gains no edge: in part 1 it implements `Contracts\Customers\CustomerContacts`, in part 2 it resolves `Contracts\Notifications\DeliveryLinks`; both are `Package_Contracts`, which `Package_Customers` already reaches.
  - `RoleHolders` and `EmployeeDirectory` are `Package_Contracts` too, so `Package_Notifications: [Package_Kernel, Package_Contracts]` covers AC-51 (section 0.4, check B).
- **PHPStan:** both paths. **Composer (host):** require `flexipos/notifications: *` and the autoload-dev tests namespace; then `composer update flexipos/notifications` (never `--lock`) and `composer validate --no-check-publish`.
- **`bootstrap/providers.php`:** `NotificationsServiceProvider` last, after `SalesServiceProvider`.
- **Merge order.** T-032 (chunks D to F, five files) and T-031/T-033/T-034/T-038/T-039 all add tenant migrations in parallel. Whichever merges second **regenerates** the catalogue (`php artisan schema:publish --write-fixture`), `.env.example`'s version, `tenantTreeTables()`, `MigrationTreeDisciplineTest`'s count, `IdempotencyKeysSchemaTest`'s top entry, `TenantBaselineBuildsTest`'s key list and `BranchForeignKeysContractRunTest`'s run count. Version numbers in this plan are base-relative (base 76).

## 6. Implementation steps

These rules carry over from T-032 §6:
- red-first, by fixture or omission, never by stubbing a guard open;
- the red-list rule after steps 2, 13, 25 and 27: compare with section 9, and stop on any file it does not name;
- unique test basenames: every non-slice test file in `packages/notifications/tests` is named `Notifications<Name>Test.php` (an unprefixed `BackoffTest.php` already exists in the tree); slice tests mirror the slice, `tests/Features/<X>/<X>Test.php`, and none of the 26 slice names exists elsewhere (measured);
- no child processes;
- mutations run once, reverted, and recorded red-then-green in `dev`'s hand-back.

**Inside a chunk** `dev` runs only the step's **Targeted tests** (from `modules/host`: `"E:\xampp\php\php.exe" vendor/bin/pest <paths>`) plus `composer lint`, `composer stan` and `composer deptrac`. **The full `composer check` is scheduled by `lead`** (marked "Gate (lead)" below), and run without an `APP_KEY` (baseline 13 failed, by name). In the paths below, `N/` is `packages/notifications/tests/`, `C/` is `packages/customers/tests/` and `H/` is `modules/host/tests/`.

### PART 1: T-040

#### Chunk A: plumbing, schema, static instruments

- [ ] **Step 0: verify every named symbol, by reading source. STOP on any difference and report to `architect`.**
  - `OutboxWriter::validate` refuses a non-ULID aggregate id;
  - the `EventConsumer`/`DeclaresEventConsumers`/`ConsumerRegistry` shapes;
  - `RoleHolders::actingAmong` and `EmployeeDirectory::isLive` (confirmed at `1efaed5`, section 0.4 check B; re-read only);
  - `AuditTrail::record` and the undeclared-action refusal; `audit_logs.subject_id` is an identifier column;
  - `PermissionResolver::decide`;
  - `IdentifierColumn::{primary,required,nullable}` and `JsonValidity::enforce`;
  - `SourceRules` collation rule, `SourceRules` FK-in-contract rule (FKs on a created table are allowed in expand), and that `nullOnDelete()` is not reported;
  - `RunnerClock::{now,timestamp,fixedAt,advanceSeconds}`;
  - `Crypt` precedent and `config/app.php` previous keys;
  - the section 9 literals (`AuditCatalogueTest:49/:92`, `QaToolchainRegistryCoverageTest:446`, `MigrationTreeDisciplineTest:152` = 69, `IdempotencyKeysSchemaTest:122-123` = 76 / `…003203…`, `QaAccessCloseOutSweepsTest:151`, `QaAccessSchemaShapeTest:233`);
  - whether T-032 chunks D to F have landed on this branch (`git log --oneline | grep T-032`). If they have, re-base the literals.
  - *(No AC.)*
- [ ] **Step 1: package plumbing.**
  - Files: `packages/notifications/composer.json`; `packages/notifications/src/NotificationsServiceProvider.php` (declares nothing yet); `modules/host/config/notifications.php` (every key and range of section 3); section 5's edits (`deptrac.yaml`, `phpstan.neon.dist`, `composer.json`/`composer.lock`, `bootstrap/providers.php`, last); `'notifications'` added to `QaToolchainRegistryCoverageTest:446`.
  - **`NotificationsProviderTest`:** the provider is loaded; each config key outside its range (including `redispatch_after_seconds` 0 and 86,401) fails with an error naming the key.
  - **Negative control:** a scratch class in `modules/demo/src` importing `Flexipos\Notifications\Rendering\Renderer` fails deptrac, while one importing `Flexipos\Contracts\Notifications\NotificationSender` passes. Delete both.
  - **Targeted tests:** `N/Support/NotificationsProviderTest.php`, `H/Feature/QaToolchainRegistryCoverageTest.php`.
  - *(AC-110 instrument; AC-116 configuration range.)*
- [ ] **Step 2: the five migrations, with `NotificationsTenantSchemaTest`** (own SQLite stand-in, the T-032 shape).
  - Files: `modules/host/database/migrations-tenant/2026_11_20_004001_create_notification_templates_table.php`, `…004002_create_channel_configs_table.php`, `…004003_create_deliveries_table.php`, `…004004_create_notifications_table.php`, `…004005_create_notification_consents_table.php`. Column lists exactly as 4.1. `deliveries` names, explicitly:
    - `ref` (`IdentifierColumn::required`, unique; the outbox aggregate id, AC-54);
    - `in_app` (JSON NULL, `JsonValidity::enforce`; carries priority, data and action URL from request to attempt, AC-83);
    - `dispatched_at` (DATETIME(3) NULL; the dispatcher's due rule, AC-116);
    - `idempotency_key` (VARCHAR(100), **no index**) and `idempotency_key_hash` (CHAR(64), unique; AC-56, AC-108);
    - `payload_hash` (CHAR(64)).
  - The exact column lists and nullability (4.1).
  - Uniques by raw double insert:
    - (key, channel, locale);
    - (channel, provider);
    - `active_channel`: two active `email` refused; active `email` plus inactive `email` coexist; active `email` plus active `sms` coexist;
    - `ref`, `idempotency_key_hash` and `notifications.delivery_id`;
    - (customer, channel, category).
  - `idempotency_key` carries no index (AC-108); the six `deliveries` indexes of AC-108 exist by name.
  - The four FKs. Deleting a `deliveries` row sets `notifications.delivery_id` to null and keeps the in-app row (stand-in, foreign keys enforced; AC-117).
  - **AC-117, declaration half** (4.1, "Signedness"): `004003` declares `$table->id()`; `004004` declares `unsignedBigInteger('delivery_id')` with `->nullOnDelete()`. **Red-first:** a fixture copy of `004004` declaring `bigInteger('delivery_id')` is reported.
  - `SourceRules::inspect()` reports nothing on each file; no `down()`.
  - Then: republish the catalogue; update `tenantTreeTables()` (+5 under a T-040 comment), `MigrationTreeDisciplineTest` (69 → 74), `IdempotencyKeysSchemaTest` (top 81, `…004005_create_notification_consents_table`), `TenantBaselineBuildsTest` (+4), the `$t040` lists in `QaAccessCloseOutSweepsTest` and `QaAccessSchemaShapeTest`, and `.env.example` (76 → 81).
  - **Predicted red:** section 9's step-2 rows only.
  - **Targeted tests:** `N/Schema/NotificationsTenantSchemaTest.php`, then the step-2 rows of section 9: `H/Feature/MigrationTreeDisciplineTest.php`, `H/Feature/IdempotencyKeysSchemaTest.php`, `H/Feature/TenantBaselineBuildsTest.php`, `H/Feature/QaAccessCloseOutSweepsTest.php`, `H/Feature/QaAccessSchemaShapeTest.php`, `H/Feature/SchemaCataloguePublishTest.php`, `H/Feature/TenantDecimalPrecisionTest.php`, `packages/sales/tests/Schema/LoyaltySaleIdMigrationsTest.php`.
  - *(The schema halves of AC-12, AC-17, AC-26, AC-54, AC-56, AC-61, AC-86, AC-107, AC-108; AC-117 stand-in half.)*
- [ ] **Step 3: static instruments.**
  - **`NotificationsWidthsTest`:** every VARCHAR/CHAR width in `004001` to `004005` equals its `Support\Limits` constant. **Red-first:** a fixture declaring `string('key', 81)` is reported.
  - **`QaNotificationsScopeGuardTest` (AC-1),** over `packages/notifications/{src,tests}`, the notifications migrations and `config/notifications.php`, through `SourceRules::codeOnly`. It reports, by name, any of:
    - `Illuminate\Mail`, `Mail::`, `Illuminate\Notifications`, `Illuminate\Support\Facades\Http`, `Http::` (except `Http::preventStrayRequests` and `Mail::fake`/`Mail::assertNothingSent` in tests), `Illuminate\Http\Client`, `GuzzleHttp`, `Psr\Http\Client`, `Flexipos\HttpClient`, `Symfony\Component\Mailer`, `curl_`, `fsockopen`, `stream_socket_client`, `socket_create`, `mail(`, `file_get_contents('http`, `Swift_`, `Aws\`, `Twilio`, `Vonage`, `SendGrid`, `Mailgun`;
    - and a `composer.json` require other than `flexipos/contracts` / `php`.
  - It also checks that the configurable provider set equals `['log', 'null']`.
  - **Red-first:** planted files with `Mail::raw(` and with `curl_init(`, and a composer require of a mail SDK, are each reported. Delete them.
  - **Targeted tests:** `N/Schema/NotificationsWidthsTest.php`, `H/Feature/QaNotificationsScopeGuardTest.php`.
  - **Gate (lead).**
  - *(AC-1, AC-3 whitelist half, AC-107.)*

#### Chunk B: the pure core

- [ ] **Step 4: contracts** (Ruling 2, plus `CustomerContacts`), after Ruling 14's sweep.
  - Files: `packages/contracts/src/Notifications/{Channel,Category,RecipientType,Priority,DeliveryStatus,SendOutcome,NotificationRequest,NotificationSender,DeliveryReceipt,NotificationRefused,NotificationRefusal,ChannelDriver,OutboundMessage,SendResult,CredentialField,DriverCredentials,DeclaresChannelDrivers}.php`; `packages/contracts/src/Customers/{CustomerContacts,CustomerContact}.php` (AC-115; no implementation yet, step 13). **Not** `DeliveryLinks`/`DeliveryLinkRefused` (part 2, step 26).
  - **`NotificationsEnumsTest`:** the exact value lists; `Channel::isSendable()`/`isOutbound()`; every reason code in the spec appears in `NotificationRefusal` exactly once, including the revision-2 codes (`field_not_supported_for_channel`, `encryption_unavailable`, `channel_not_configurable`, `not_updatable_here`, `already_active`, `already_inactive`, `delivery_expired`, `delivery_not_found`). T-040b's link reasons live in `DeliveryLinkRefused`, not here.
  - **`NotificationsShapesTest`:**
    - the readonly shapes refuse a malformed ULID by name;
    - `CustomerContact` holds exactly id, live, merged-into id, status, email and phone;
    - `DriverCredentials` renders no value through `var_export`, `print_r`, `json_encode`, `serialize` or `__debugInfo` (sentinel).
  - **Targeted tests:** `N/Contracts/NotificationsEnumsTest.php`, `N/Contracts/NotificationsShapesTest.php`, `H/Feature/QaCustomersSourceRulesTest.php` (its contracts-purity case now also covers the two new customers contract files).
  - *(AC-6, AC-49 shapes, AC-115 contract half; groundwork for AC-20.)*
- [ ] **Step 5: `Support\Input`, `Fields::only`, `ChannelConfigInput`, `ProviderPolicy`** (pure datasets).
  - **AC-3:** `log` and `null` for the four outbound channels; `ses`, `smtp`, `''` and `LOG` are each `provider_not_available`.
  - **AC-5, AC-113:** `webhook` is `channel_not_supported`; `in_app` is `channel_not_configurable`.
  - **AC-13:** `Email`, `' email'`, `"true"` and `1` are refused.
  - **AC-14:**
    - email `from_address`: valid; display name, comma, CR/LF, `\x07` and 192 characters refused;
    - sms: 1 to 50 characters;
    - never trimmed.
  - **AC-15:** null, 1 and 1,000,000 pass; 0, −1, 1.5, `"5"` and 1,000,001 are refused.
  - **AC-16:**
    - 21 keys, a 201-character value, a 4,001-character serialisation and a nested value are each refused;
    - `ApiKey`, `password_x`, `myToken`, `credential` and `monkey` are `secret_in_settings`;
    - `region` passes.
  - **AC-18:** with `log`'s declared fields, `{"sandbox_token": "…"}` passes; `{"x": 1}` is `credential_field_unknown`; a required-field case uses a fixture driver; `null` with credentials is `credentials_not_supported`.
  - **AC-22 and AC-118 shape:** `{}` is refused; forbidden keys (`id`, `channel`, `provider`, `sent_today`, `sent_today_date`, `credentials_updated_at`, `created_at`, `updated_at`, unknown) are named **together**; `is_active` in an update is `not_updatable_here`; `is_active` in a create is accepted as a JSON boolean and defaults to `false`.
  - **Mutations:** **N1** trims the from-address, making the leading-space row go red; **N2** uses `strcasecmp` for the provider, making `LOG` go red.
  - **Targeted tests:** `N/Support/NotificationsInputTest.php`, `N/Channels/NotificationsChannelConfigInputTest.php`.
  - *(AC-3, AC-5, AC-12 to AC-16, AC-18, AC-22 parsing, AC-113, AC-118 config parsing.)*
- [ ] **Step 6: `TemplateSyntax` and `TemplateInput`** (pure).
  - **AC-27:** `Welcome`, `'welcome '`, 81 characters, `1abc` and `a-b` are refused; `order.receipt_v2` passes.
  - **AC-28:** `en` and `en-GB` pass; `EN`, `en-gb`, `eng` and `en_GB` are refused.
  - **AC-29 and AC-30:** subject rules per channel; `subject_not_supported` for sms, whatsapp and push; a CR in the subject is refused.
  - **AC-31:** 20,000 / 20,001 characters; sms 1,600 / 1,601; blank-after-trim refused; NUL refused; the stored body is byte-identical.
  - **AC-32:** 31 entries; a name `1a`; `{type: 'money'}`; `max_length` 0 and 1,001; an unknown inner key; `required` defaults to true and `max_length` to 200.
  - **AC-33:** each of `{{{x}}}`, `{{#x}}`, `{{>x}}`, `{{a.b}}`, `{{a|f}}`, `{{x`, `x}}` and `}}` alone gives `malformed_placeholder` with the position; `{{ name }}` passes; `{ single }` passes; `@media{a{b:c}}` is refused (D-55).
  - **AC-34:** an undeclared variable is named; one unused declaration passes.
  - **AC-35 and AC-118:** immutable keys (`key`, `channel`, `locale`, `id`, `plugin_name`, `created_at`, `is_active`, unknown) are named together, `not_updatable_here`; a create without `is_active` defaults to `true`.
  - **Mutations:** **N3** `^…$` instead of `\A…\z` (a key with a trailing newline goes red); **N4** allow dotted placeholder names (`{{a.b}}` goes red).
  - **Targeted tests:** `N/Templates/NotificationsTemplateSyntaxTest.php`, `N/Templates/NotificationsTemplateInputTest.php`.
  - *(AC-26 to AC-35 logic, AC-118 template parsing.)*
- [ ] **Step 7: `VariableRules`, `Renderer`, `PlainText`, `Masking`** (pure).
  - **AC-39:** missing, unknown and invalid variables are all named in one refusal.
  - **AC-40:**
    - number: `12`, `"-3.1415"`; refused: `1.5` (float), `"1e3"`, `"1.12345"`, 19 digits;
    - date: `2026-02-30` refused, `2028-02-29` passes;
    - url: refused for `http://`, `javascript:`, `data:`, relative, `https://u:p@h`, whitespace and 501 characters;
    - arrays, objects, null and booleans are refused for every type.
  - **AC-41:** `<script>alert(1)</script>` becomes `&lt;script&gt;alert(1)&lt;/script&gt;`; `&amp;` in a value becomes `&amp;amp;` (escaped exactly once); `'` becomes `&#039;`.
  - **AC-42:** for every plain-text field, `"a\r\nBcc: x@y"` gives `aBcc: x@y`, and a tab gives a space; U+2028, U+202E and U+2066 are removed; `&` stays `&`.
  - **AC-43:** a `url` variable in an e-mail body passes AC-40 then AC-41.
  - **AC-44:** a value `{{other}}` is emitted literally.
  - **AC-45:** `<b>Hi</b>` in template text is unchanged.
  - **AC-46:** rendering twice is byte-identical.
  - **AC-48:** a rendered sms over 1,600 characters is `rendered_too_long`.
  - **AC-8 masking:** `alice@example.com` gives `a***@example.com`; `+94771234567` gives `***67`; null stays null.
  - **Mutations:**
    - **N5** escapes the e-mail subject too, making the `&` row go red;
    - **N6** re-renders the output (two passes), making the AC-44 row go red;
    - **N7** drops the bidi removal, making the U+202E row go red.
  - **Targeted tests:** `N/Rendering/NotificationsVariableRulesTest.php`, `N/Rendering/NotificationsRendererTest.php`, `N/Rendering/NotificationsPlainTextTest.php`, `N/Rendering/NotificationsMaskingTest.php`.
  - *(AC-8 masking, AC-39 to AC-48 logic, AC-92 masking.)*
- [ ] **Step 8: `DeliveryStateMachine`, `Backoff`, `DailyLimit`, `ConsentPolicy`, `RecipientRules`, `PayloadHash`** (pure).
  - **AC-70:** all 36 (from, to) pairs; only the 8 listed pass.
  - **AC-75:** 60, 120, 240 and 480 seconds; the cap holds at large attempts; config validation (base 0, cap < base, 86,401).
  - **AC-76:** reaching the maximum gives `max_attempts_exceeded`.
  - **AC-77:** next UTC midnight; reset when the date differs; null never defers.
  - **AC-62 to AC-64, AC-68:** a {no row, opted_in, opted_out} × {marketing, transactional} × {active, blocked, inactive} table; employees are never consulted.
  - **AC-53:** the category × recipient table. **AC-52 compatibility half:** employee off `in_app` and customer on `in_app` are `channel_not_supported_for_recipient`.
  - **AC-56:** hash equality ignores key order in `variables` and `data`, and differs on any field except the two excluded.
  - **Mutation N8:** allow `sent` → `queued`, making the matrix go red.
  - **Targeted tests:** `N/Deliveries/NotificationsDeliveryStateMachineTest.php`, `N/Deliveries/NotificationsBackoffTest.php`, `N/Deliveries/NotificationsDailyLimitTest.php`, `N/Deliveries/NotificationsPayloadHashTest.php`, `N/Consent/NotificationsConsentPolicyTest.php`, `N/Recipients/NotificationsRecipientRulesTest.php`.
  - **Gate (lead).**
  - *(AC-52 compatibility, AC-53, AC-56 hash, AC-62 to AC-64, AC-68, AC-70, AC-75 to AC-77 logic.)*

#### Chunk C: secrets, drivers, configs, templates and consents on the database

- [ ] **Step 9: permissions, `Authorize`, audit registry, authorization matrix.**
  - Files: `src/Registry/NotificationsPermissions.php` (ten keys, declared by the provider), `src/Registry/NotificationsAuditActions.php` (empty `ALL` now; it grows with each slice to **twelve** by step 17), `src/Support/Authorize.php`, `tests/Support/NotificationsSeed.php` (one employee per key, plus one with none).
  - **`NotificationsAuthorizationMatrixTest`** is one dataset, grown by every later slice:
    - without the key the slice is `not_permitted`, and a query listener proves **no** notification-table statement ran;
    - a null actor is refused, and so is a soft-deleted employee;
    - `deliveries.view` does not grant `retry` or `view_content`;
    - `channels.view` does not grant `manage`.
  - **Mutation N9:** move `Authorize` after the target read, making "no statement ran" go red.
  - **Targeted tests:** `N/Security/NotificationsAuthorizationMatrixTest.php`, `N/Support/NotificationsProviderTest.php` (ten keys, none branch-scoped).
  - *(AC-104.)*
- [ ] **Step 10: `CredentialVault`, `LogDriver`, `NullDriver`, `DriverRegistry`.**
  - Tests set `config(['app.key' => 'base64:'.base64_encode(random_bytes(32))])` and `app()->forgetInstance('encrypter')` in `beforeEach` (the `PluginSettingsStoreTest` shape). They **never** read `.env`.
  - **AC-19:** seal the sentinel `SANDBOX-T040-NOT-A-SECRET-…`; the stored string lacks it; `open` round-trips.
  - **AC-21:**
    - after swapping to a second generated key, `open` gives `credentials_undecryptable`;
    - with the first key listed in `app.previous_keys`, it decrypts;
    - with no key, `credentials_undecryptable`;
    - truncated ciphertext also gives `credentials_undecryptable`.
  - **AC-112 (vault half):** `seal` with no key configured throws the `encryption_unavailable` refusal.
  - **AC-8:** `SpyLogger` records exactly one line with keys `{delivery_id, channel, provider, template_key, recipient}` and the recipient masked; neither subject, body, sentinel nor address appears. The result is `accepted log:<id>`.
  - **AC-9:** `null` writes zero log lines and returns `null:<id>`.
  - **AC-7:** resolving by (channel, provider); an unregistered pair returns none; duplicate declarations throw at sweep.
  - **AC-10 (vault half):** `redact` replaces each stored value and truncates to 500.
  - **Mutation N10:** log the unmasked address, which makes the AC-8 row go red.
  - **Targeted tests:** `N/Channels/NotificationsCredentialVaultTest.php`, `N/Drivers/NotificationsLogDriverTest.php`, `N/Drivers/NotificationsNullDriverTest.php`, `N/Drivers/NotificationsDriverRegistryTest.php`.
  - *(AC-6 to AC-10, AC-19, AC-21 vault half, AC-112 vault half.)*
- [ ] **Step 11: the channel config slices** (`Create`, `Update`, `Activate`, `Deactivate`, `Delete`, `List`, `Get`).
  - **AC-12:** defaults (inactive, `sent_today` 0); a duplicate (channel, provider) is `channel_config_exists` naming the first.
  - **AC-113:** a create for `in_app` is `channel_not_configurable`, nothing written.
  - **AC-17 on the stand-in:**
    - activating a second config for a channel is `channel_already_active` naming the holder;
    - the backstop: a raw-inserted active holder past the pre-check seam is still refused, and the holder is re-read with a lock.
  - **AC-118:** activating an active config is `already_active`; deactivating an inactive one is `already_inactive`; an update naming `is_active` is `not_updatable_here`.
  - **AC-18 to AC-22 end to end:**
    - **write-only:** omitting `credentials` leaves them unchanged; `null` clears them; `{}` is refused;
    - **audit rows:** a credentials change plus a field change writes two audit rows, and the credentials row's before and after values are `{has_credentials, credentials_updated_at}` only;
    - **reads:** reads show `has_credentials`/`credentials_updated_at` and never `credentials`.
  - **AC-112:** with no key, a create or update naming credentials is `encryption_unavailable` and nothing is written (row count and audit count unchanged); the same update without `credentials` succeeds.
  - **AC-23:** deleting an active config is `config_active`; a second delete is `config_not_found`; the row is gone whole.
  - **AC-25:** in two tenant fixtures (`tenant-a`, `tenant-b`), each lists only its own; a credential sealed in A is absent from B's every column.
  - **AC-103:** one audit row per write, compared whole; a refusal writes none. The six channel actions join `NotificationsAuditActions::ALL`, and `packages/access/tests/Support/AuditCatalogueTest.php:48-49, :92` gains `'notifications'` (section 9).
  - **Mutation N11:** put `credentials` into the audit `after`, which makes the scan in step 18 go red (that proves the scan).
  - **Targeted tests:** `N/Features/{CreateChannelConfig,UpdateChannelConfig,ActivateChannelConfig,DeactivateChannelConfig,DeleteChannelConfig,ListChannelConfigs,GetChannelConfig}/<X>Test.php`, `N/Security/NotificationsAuthorizationMatrixTest.php`, `packages/access/tests/Support/AuditCatalogueTest.php`.
  - *(AC-12 to AC-23, AC-25, AC-103 channel actions, AC-112, AC-113, AC-118 configs.)*
- [ ] **Step 12: the template slices** (`Create`, `Update`, `Activate`, `Deactivate`, `List`, `Get`).
  - **AC-26:** `plugin_name` is null; `is_active` defaults to `true`; a duplicate is `template_exists` naming the id.
  - **AC-35:** stored-plus-supplied re-validation. For example, updating `variables` to drop a used name gives `undeclared_variable`.
  - **AC-36 and AC-118:** deactivate twice gives `already_inactive`; reactivate is allowed; `already_active` applies too; an update naming `is_active` is `not_updatable_here`.
  - **AC-37:** filters, key-prefix `LIKE` with `\`, `%` and `_` escaped, order, default 25, limits 0/101/`"5"` refused.
  - **AC-38:** no delete operation exists. A reflection test over `Features/` finds no `Delete*Template*`.
  - **Targeted tests:** `N/Features/{CreateTemplate,UpdateTemplate,ActivateTemplate,DeactivateTemplate,ListTemplates,GetTemplate}/<X>Test.php`, `N/Security/NotificationsAuthorizationMatrixTest.php`.
  - *(AC-26 to AC-38, AC-103 template actions, AC-118 templates.)*
- [ ] **Step 13: `ContractCustomerContacts` (customers) and the consent slices** (`RecordConsent`, `ListConsents`).
  - **Files (customers, AC-115):** `packages/customers/src/Customers/ContractCustomerContacts.php`; `packages/customers/src/CustomersServiceProvider.php` gains `$this->app->singleton(CustomerContacts::class, ContractCustomerContacts::class)` beside lines 35 to 36, and its class docblock gains one line naming the third binding. No other customers file changes.
  - **Contacts (`ContractCustomerContactsTest`):** live, merged (soft-deleted with `merged_into_id`), soft-deleted without merge, blocked, and unknown ids each give the right snapshot (unknown gives null); email and phone are returned as stored (null stays null); the container resolves `CustomerContacts` to the class. The customers package suite still passes unchanged.
  - **AC-61:**
    - recording the same triple twice updates one row, with two audit rows (`notifications_consent_recorded`);
    - `channel` `in_app` and category `system` are refused;
    - `source` must be 1 to 50 characters;
    - `note` up to 255;
    - `recorded_by` is the actor;
    - an unknown, soft-deleted or merged customer is `recipient_not_found` (through `CustomerContacts`).
  - **AC-69:** list for a customer, paged as AC-37; an unknown customer is `recipient_not_found`.
  - **Predicted red:** none outside the notifications package and the new customers test file. The red-list rule applies.
  - **Targeted tests:** `C/Customers/ContractCustomerContactsTest.php`, the whole customers suite once (`packages/customers/tests`, to prove it is unchanged), `N/Features/{RecordConsent,ListConsents}/<X>Test.php`, `N/Security/NotificationsAuthorizationMatrixTest.php`, `H/Feature/QaCustomersSourceRulesTest.php`.
  - **Gate (lead).**
  - *(AC-61, AC-69, AC-103 consent action, AC-115.)*

#### Chunk D: the send path

- [ ] **Step 14: `RequestNotification` and `DatabaseNotificationSender`** (Ruling 7).
  - **AC-49 and AC-50:** the contract resolves; a list-valued `channel` is refused.
  - **AC-51, AC-52, AC-53:** every recipient and template refusal. Employee: not live is `recipient_not_found`; live but on leave, suspended, resigned or terminated is `recipient_inactive` (through `RoleHolders::actingAmong`; a moved-value case seeds `probation` and expects success). Customer: unknown or soft-deleted is `recipient_not_found`, merged is `recipient_merged`. The employee-on-email case is `channel_not_supported_for_recipient`. The `en` fallback works for `fr`.
  - **AC-114, the fixed order:** one dataset of pairs, each request failing two adjacent steps at once (shape + replay, replay + recipient compatibility, compatibility + existence, existence + template, template + variables, variables + config, config + consent, consent + address), asserting that the earlier step decides and the outcome kind (exception, `suppressed` receipt, `failed` receipt) matches the step. A replay of a stored key after its config is deactivated still returns the receipt.
  - **AC-24:** no active config is `channel_not_configured`, with nothing written.
  - **AC-54:**
    - one delivery (`queued`, `attempts = 0`, `next_attempt_at = dispatched_at = now`, `ref` a ULID) plus one outbox row, with exact type, aggregate (`delivery`, `ref`) and payload `{delivery_id}`;
    - a caller's rolled-back outer transaction leaves neither row.
  - **AC-55:** the outbox row holds no address, subject, body, variable value or sentinel.
  - **AC-56:**
    - a replay returns the same id with `replayed`, and writes no second row;
    - changed variables (or changed `priority`, `data`, `action_url`) give `idempotency_key_conflict`; a changed `source_event_id` is still a replay;
    - `a` against `A` gives two deliveries;
    - 0 or 101 characters, a control byte, or a non-ASCII byte gives `idempotency_key_required`.
  - **AC-57 stand-in half:** a raw-inserted holder past the replay pre-check makes the unique backstop return the holder.
  - **AC-62, AC-63, AC-66 request half:** marketing with no row gives a `suppressed consent_missing` receipt, with no outbox row and attempts 0; transactional with `opted_out` gives `suppressed consent_opted_out`.
  - **AC-67:** an empty email gives `failed recipient_address_missing`, `recipient` null, no outbox row; the address snapshot is stored when present.
  - **AC-68:** marketing to a blocked customer is `recipient_not_eligible`; transactional to the same customer is queued.
  - **AC-81 request half:** an in-app delivery stores `recipient` null.
  - **AC-84, AC-85:** priority and data limits; the three fields on `email` are each `field_not_supported_for_channel`, named together; each `action_url` case, including `//host`, `\` and `http:`. The accepted values are stored in `deliveries.in_app`.
  - **AC-47:** editing the template after the request leaves the stored snapshot unchanged.
  - **AC-119:** a request naming a merged-away source customer is `recipient_merged`, even when the source had an `opted_in` marketing consent; marketing to the target with no consent row of its own is `suppressed consent_missing`. (That the merge itself never touches `notification_consents` is proved by step 19's table-name scan over production code, which `MergeCustomersHandler` is part of.)
  - **Mutation N12:** check consent after the address, making the "consent before address" row go red.
  - **Targeted tests:** `N/Features/RequestNotification/RequestNotificationTest.php`.
  - *(AC-24 request half, AC-47, AC-49 to AC-57, AC-62 to AC-68 request half, AC-81 request half, AC-84, AC-85, AC-114, AC-119.)*
- [ ] **Step 15: `DeliveryWriter` and `AttemptDelivery`** (Rulings 8 and 9), driven by `ScriptedDriver`/`ThrowingDriver` bound through a fixture `DeclaresChannelDrivers` provider, with a fixed `RunnerClock`.
  - **AC-74:** an accepted send sets every listed field in one transaction, including `sent_today + 1` and `sent_today_date`.
  - **AC-75, AC-76, AC-80:**
    - the full ladder of 5 transients is driven by advancing the clock: the delays are 60/120/240/480, then `failed max_attempts_exceeded` with the last driver code in `error`;
    - a transient failure leaves `dispatched_at` unchanged (so the dispatcher wakes it, AC-116);
    - a permanent failure fails at once;
    - no real wait occurs.
  - **AC-10:**
    - a throw, or an out-of-range outcome, becomes `transient driver_error`;
    - the stored error contains `[redacted]` where the sentinel was and is at most 500 characters;
    - the consumer sees no exception.
  - **AC-21:** with a swapped key, the attempt is `failed credentials_undecryptable`, and the driver is never called (spy count 0).
  - **AC-24 attempt half:** a config deactivated after queueing gives `failed channel_not_configured`.
  - **AC-7:** a config for a provider with no driver gives `failed driver_not_registered`.
  - **AC-65, AC-66, AC-73:** an opt-out recorded after queueing gives `suppressed` at the attempt, with attempts unchanged and the driver not called. An employee moved out of the acting set after queueing gives `failed recipient_inactive`. The order of the four re-checks is pinned.
  - **AC-77:** at the limit the attempt is deferred to midnight with attempts and `dispatched_at` unchanged; on the next UTC day the counter resets and the send proceeds.
  - **AC-83, AC-86, AC-89:**
    - an in-app delivery writes one `notifications` row (type, title, body, and data, priority and action URL from `deliveries.in_app`), and the delivery is `delivered` with both timestamps;
    - a second attempt on the same id is a no-op;
    - a raw second insert hits the unique key;
    - a stored `<b>` reads back byte-identical.
  - **AC-71:** `recordProviderOutcome` moves `sent` to `delivered` and `sent` to `bounced`, refuses from `queued`, and has no production caller (step 19 scans for this).
  - **AC-81:** the field rules hold on every terminal row.
  - **AC-72 stand-in half:** the `lockForUpdate` call is observed on the builder seam.
  - **Mutations:**
    - **N13** increments attempts on a consent suppression, making the AC-66 row go red;
    - **N14** calls the driver before the config check, making the spy-count row go red;
    - **N15** omits the `sent_today` reset, making the next-day row go red.
  - **Targeted tests:** `N/Deliveries/NotificationsDeliveryWriterTest.php`, `N/Features/AttemptDelivery/AttemptDeliveryTest.php`.
  - *(AC-7, AC-10, AC-11, AC-21, AC-24, AC-65, AC-66, AC-70 to AC-78, AC-80 to AC-83, AC-86, AC-89.)*
- [ ] **Step 16: the consumer and `notifications:dispatch-due`**, end to end on the in-memory bus (the `PluginPipelineTest` shape: request, `eventbus:relay`, `eventbus:consume --group=notifications --once`).
  - **AC-58:**
    - a redelivered event for a `sent` row is a no-op and is acknowledged;
    - a not-yet-due row is a no-op;
    - a missing id is logged and acknowledged;
    - a malformed payload is acknowledged.
  - **AC-59 and AC-116:**
    - a deleted outbox row (lost event) is not re-emitted before `redispatch_after_seconds`, and is re-emitted once after it;
    - running twice before consumption writes no second event, because the first run set `dispatched_at`;
    - a transient retry (`dispatched_at < next_attempt_at`) becomes due and is re-emitted once;
    - a daily-limit deferral is re-emitted at the next UTC midnight;
    - a row whose `dispatched_at` was just set by the request or by a retry is **not** re-emitted (the equal-timestamp case);
    - the condition is re-tested under the row lock: a row moved to `sent` between the select and the lock is skipped;
    - ordering is by `next_attempt_at, id`;
    - the count is printed and no audit row is written.
  - **AC-60:** batch 0, 1,001 and `"x"` fail to start, naming the key; `redispatch_after_seconds` 0 and 86,401 fail the same way.
  - **AC-2:** the whole pipeline under `Http::preventStrayRequests()` plus `Mail::fake()`; `Mail::assertNothingSent()`, and no stray request.
  - **Mutation N16:** drop `dispatched_at` from the due rule, making the "twice" row go red.
  - **Targeted tests:** `N/Features/DispatchDueDeliveries/DispatchDueDeliveriesTest.php`, `N/Pipeline/NotificationsPipelineTest.php`.
  - *(AC-2 in part, AC-58 to AC-60, AC-116.)*
- [ ] **Step 17: delivery reads and retry** (`ListDeliveries`, `GetDelivery`, `CountDeliveries`, `RetryDelivery`).
  - **AC-91:**
    - each filter, valid and invalid (each invalid one named);
    - `created_from`/`created_to` dates and UTC date-times;
    - cursor on `id` descending; an invalid cursor is `cursor_invalid`;
    - default 25, maximum 100.
  - **AC-92:** without `view_content`, subject and body are absent and the recipient is masked (null stays null); with it, both are present; an unknown id is `delivery_not_found`.
  - **AC-93:** grouped counts for a range, with the query count asserted as 1.
  - **AC-79:**
    - `failed` → `queued` with `attempts = 0`, `next_attempt_at = dispatched_at = now` (equal values), errors cleared, a new outbox row, and one audit row (`notifications_delivery_retried`, subject `delivery`/`ref`) whose before value holds the old error;
    - a dispatcher run straight after the retry emits nothing more (the AC-116 equal-timestamp case);
    - retrying a `sent` delivery is `invalid_transition`;
    - a raw-inserted `failed` delivery with `error_code = expired` is `delivery_expired` (in T-040 nothing writes `expired`; T-040b's purge will);
    - consent is re-checked on the next attempt (an opt-out recorded after the failure leads to `suppressed`).
  - With this step `NotificationsAuditActions::ALL` holds all **twelve** actions; the provider test asserts exactly twelve.
  - **Targeted tests:** `N/Features/{ListDeliveries,GetDelivery,CountDeliveries,RetryDelivery}/<X>Test.php`, `N/Security/NotificationsAuthorizationMatrixTest.php`, `N/Support/NotificationsProviderTest.php`.
  - *(AC-79, AC-91 to AC-93, AC-103 retry action and the twelve-action set.)*
- [ ] **Step 18: the sentinel test, `NotificationsSentinelTest`** (AC-19, AC-20; the T-038 AC-59 harness shape).
  - A sentinel credential goes through create, update, activate, a send to `log`, a forced driver error that echoes the sentinel, a forced undecryptable attempt, every read, and every refusal.
  - Then the harness scans for the plaintext **and** for the ciphertext as stored:
    - every column of every tenant table, enumerated from the live schema, except `channel_configs.credentials` for the ciphertext;
    - every log record at every level (`SpyLogger` plus the log channel);
    - every exception message;
    - every handler result serialised;
    - the outbox payloads.

    It prints the table and column count.
  - **Controls:** a deliberate leak into each sink kind is detected; a baseline run without the sentinel is clean.
  - **Mutation:** N11 is caught.
  - **Targeted tests:** `N/Security/NotificationsSentinelTest.php`.
  - **Gate (lead).**
  - *(AC-19, AC-20 for every sink except HTTP responses, which are T-040b's B13.)*

#### Chunk E: scans, engine, documents, close-out of part 1

- [ ] **Step 19: `QaNotificationsSourceRulesTest` and `QaNotificationsNoHttpSurfaceTest` (host)**, code only. The table and writer rules scan **production code** (`packages/*/src`, `modules/*/src`, `modules/host/app`, `modules/host/database`), never tests, which seed by raw insert.
  - **One writer:**
    - outside `DeliveryWriter`, no code names `table('deliveries')` together with `update(`/`insert(` naming `status`;
    - no code calls `delete(` on `deliveries` (T-040b step 24 narrows this to "only `PurgeDeliveriesHandler`");
    - outside `packages/notifications/src` and the T-040 migrations, no code names `table('deliveries')`, `table('channel_configs')`, `table('notification_templates')`, `table('notifications')` or `table('notification_consents')` (this also proves AC-119's "the merge never moves consents": `MergeCustomersHandler` is in the scanned set).
  - **Locked reads:**
    - every terminal read in `DeliveryRows` and `ChannelConfigRows` outside `*Unlocked` methods chains `lockForUpdate()`/`sharedLock()`;
    - write slices call no `first(`, `get(`, `exists(`, `count(`, `value(` or `pluck(` themselves.
  - **Encrypter:** only `CredentialVault` names `Crypt`, `encrypter`, `encryptString` or `decryptString`.
  - **Internal transition:** `recordProviderOutcome` has no caller under `src/`.
  - **Regexes:** none in `src/` ends `$/`.
  - **Contracts purity:** `packages/contracts/src/Notifications` imports nothing outside contracts and PHP core.
  - **Seeds nothing (AC-111, migration half):** no notifications migration calls `insert(`.
  - **`QaNotificationsNoHttpSurfaceTest` (AC-105, the `QaEventbusNoHttpSurfaceTest` shape):** `packages/notifications` has no `routes/` directory and no registered route's action is a `Flexipos\Notifications\` class or a controller under `App\Http\Controllers\Notifications`. **This file lives only until T-040b step 27, which deletes and replaces it.**
  - **Red-first:** each rule is fed a planted file and reports it; delete them.
  - **Targeted tests:** `H/Feature/QaNotificationsSourceRulesTest.php`, `H/Feature/QaNotificationsNoHttpSurfaceTest.php`.
  - *(AC-6 boundary, AC-72, AC-82, AC-105, AC-110 construction halves, AC-111 migration half, AC-119 merge half.)*
- [ ] **Step 20: `NotificationsSchemaEngineTest`.** Under the hold only the "refuses to reach the engine" case runs. Written for G-1, with explicit ids and connection B calling the **real** handler:
  - **AC-17:** two simultaneous activations give one winner;
  - **AC-26:** `welcome` against a raw `WELCOME` on the real collation is refused by the key (the stand-in proves the regex);
  - **AC-57:** two simultaneous requests with one key give one delivery, and the other returns it;
  - **AC-72:** two workers on one delivery give one driver call (spy count 1);
  - **AC-117:** deleting a delivery nulls the in-app row's reference on the engine; a scratch table declaring a signed `delivery_id` against `deliveries.id` is refused by the engine;
  - generated-column uniqueness on `active_channel`; JSON validity; migrating twice is a no-op.
  - **Targeted tests:** `H/Engine/NotificationsSchemaEngineTest.php` (skips under `ENGINE_HOLD`).
  - *(The `[ENGINE]` clauses of AC-17, AC-26, AC-57, AC-72 and AC-117: NOT VERIFIED under ENGINE_HOLD.)*
- [ ] **Step 21: documents and the final gate for part 1.**
  - `architect` applies section 4.3's **§6.16** text (the table rows and the "As built (T-040)" paragraph) and the **§3.3** `SET NULL` bullet to `docs/database.md`, exactly as written there; **not** the §6.9 note (T-040b, step 28). `docs/architecture.md` row 84 ("Notification") gains the as-built sentence; backlog notes (section 10).
  - **AC-111, provisioning half:** `H/Feature/NotificationsProvisioningSeedsNothingTest.php` asserts zero templates, configs and consents after `tenant:provision` on the fixture.
  - **Targeted tests:** `H/Feature/NotificationsProvisioningSeedsNothingTest.php`, then `H/Feature/QaVocabularyRenameSweepTest.php` (the documents just edited are in its corpus).
  - **Gate (lead):** `composer check` with counts and `test` seconds compared with the base.
  - *(AC-107, AC-109, AC-110, AC-111.)*

### PART 2: T-040b (new row)

Every criterion here is T-040b's (B1 to B17). Nothing in part 2 edits a T-040 table, slice or permission; it adds one audit action.

#### Chunk F: inbox and retention

- [ ] **Step 22: `ListMyNotifications`, `MarkNotificationRead`, `MarkAllNotificationsRead`** (key `notifications.inbox.read`; the recipient is always the actor).
  - **B1:** only the actor's own rows; newest first, keyset (`created_at`, `id`); the `unread` filter; the total unread count; page size 0, 101 and `"5"` are `page_size_invalid`; another employee's id is `not_found`, never forbidden; without the key, `not_permitted`.
  - **B2:** `read_at` is set once; a repeat is unchanged and succeeds with no second write; mark-all touches only the actor's unread rows and returns the count; no audit row.
  - **Targeted tests:** `N/Features/{ListMyNotifications,MarkNotificationRead,MarkAllNotificationsRead}/<X>Test.php`, `N/Security/NotificationsAuthorizationMatrixTest.php`.
  - *(B1, B2.)*
- [ ] **Step 23: `PurgeDeliveries` plus `notifications:purge-deliveries`.**
  - `PurgeDeliveriesHandler::ACTION = 'notifications_deliveries_purged'` joins `NotificationsAuditActions::ALL` (thirteen; the provider test is updated from twelve). `AuditCatalogueTest` needs no edit (the module is already listed since step 11).
  - **B4:**
    - terminal rows older than the period are deleted in batches;
    - `queued` rows older than the period become `failed expired` (through `DeliveryWriter`) and survive one cycle;
    - fresh rows are untouched;
    - one audit row (`notifications_deliveries_purged`, subject `deliveries_purge`/a fresh ULID, `{cutoff, expired, deleted}` in `after`, no delivery content) per tenant run that changed anything; none when nothing changed;
    - retention 6 and 3,651 fail to start; `--tenant=*` continues past a failing tenant;
    - an expired delivery is refused `delivery_expired` by `RetryDelivery` (T-040 AC-79, now reachable end to end).
  - **B3, B5 stand-in:** the in-app row survives with `delivery_id` null.
  - **Targeted tests:** `N/Features/PurgeDeliveries/PurgeDeliveriesTest.php`, `N/Support/NotificationsProviderTest.php`, `N/Features/RetryDelivery/RetryDeliveryTest.php`.
  - *(B3, B4, B5 `notifications` half on the stand-in, B16.)*
- [ ] **Step 24: chunk F close.**
  - `QaNotificationsSourceRulesTest` gains two rules:
    - `delete(` on `deliveries` appears only in `PurgeDeliveriesHandler` (replacing step 19's "no `delete(`" rule);
    - the inbox slices filter by the actor and by nothing a caller supplies (`recipient_employee_id` is never read from input).

    Red-first with planted files.
  - The authorization matrix gains the three inbox slices: `inbox.read` grants only the actor's own inbox, and no key grants another employee's.
  - **Targeted tests:** `H/Feature/QaNotificationsSourceRulesTest.php`, `N/Security/NotificationsAuthorizationMatrixTest.php`.
  - **Gate (lead).**
  - *(B1 isolation, B4 one-deleter rule.)*

#### Chunk G: the owed column

- [ ] **Step 25: `004006` (expand) and `004007` (contract), with `OwedDeliveryColumnMigrationsTest`.**
  - Files: `modules/host/database/migrations-tenant/2026_11_20_004006_add_delivery_id_to_customer_communications_table.php`, `…004007_add_customer_communication_delivery_foreign_key.php`.
  - **B6:** on a fixture tenant already holding communications, after `004006` the column exists, is BIGINT UNSIGNED and nullable, every row is null, an index exists and no key exists.
  - **B7:**
    - **red-first:** a seeded non-null `delivery_id` with no delivery makes `004007` refuse, naming the table, the column and `1 row(s)` (never an id), and changes nothing;
    - when clean, the key exists with SET NULL; a re-run is a no-op;
    - the two steps are separate files; `@phase contract`; `is_destructive = false`.
  - **B8:** the unsigned declarations are read from `004003` and `004006` (the step 2 mechanism).
  - **B10:** no unique index on `customer_communications.delivery_id`.
  - Republish; `.env.example` 81 → 83; `IdempotencyKeysSchemaTest` top; `TenantBaselineBuildsTest` +1; `BranchForeignKeysContractRunTest` gains one run (section 9); `CustomersTenantSchemaTest:197-203` absence becomes presence (B15, the first of its two allowed customers test edits).
  - **Mutation S1:** combine the key into `004006`, and `SourceRules` reports it.
  - **Targeted tests:** `H/Feature/OwedDeliveryColumnMigrationsTest.php`, `C/Schema/CustomersTenantSchemaTest.php`, `H/Feature/IdempotencyKeysSchemaTest.php`, `H/Feature/TenantBaselineBuildsTest.php`, `H/Feature/BranchForeignKeysContractRunTest.php`, `H/Feature/MigrationTreeDisciplineTest.php`, `H/Feature/SchemaCataloguePublishTest.php`, `packages/kernel/tests/Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php`, `packages/sales/tests/Schema/LoyaltySaleIdMigrationsTest.php`.
  - *(B6 to B8, B10; B15 schema half.)*
- [ ] **Step 26: `DeliveryLinks`, `DatabaseDeliveryLinks` and `LogCustomerCommunication`.**
  - Files: `packages/contracts/src/Notifications/{DeliveryLinks,DeliveryLinkRefused}.php` (B14; created here, not in part 1); `packages/notifications/src/Deliveries/DatabaseDeliveryLinks.php`, bound in `NotificationsServiceProvider`; `packages/customers/src/Features/LogCustomerCommunication/LogCustomerCommunicationHandler.php` (`delivery_id` in `FIELDS`).
  - **B14:** outside a transaction it throws `LogicException`; the `sharedLock` call is observed; `delivery_not_found`, `delivery_customer_mismatch` and `delivery_channel_mismatch` (each pair from the mapping, plus `push`/`in_app` refused).
  - **B9:** through `LogCustomerCommunication`: the three refusals surface naming `delivery_id`; direction `in` with a `delivery_id` is refused (code: question 1 for `ba` below); a string, a float, 0 and a negative are refused by customers' own validation, naming the field; the audit row includes `delivery_id`.
  - **B10:** two communications can reference one delivery.
  - **B11:** a merge keeps `delivery_id`. This goes in a **new** file, `C/Features/MergeCustomers/MergeCustomersKeepsDeliveryIdTest.php`, seeding through `CustomersSeed`'s existing override argument, so `MergeCustomersTest.php` and `CustomersSeed.php` are **not** edited (B15 allows exactly two existing customers test edits).
  - **B12:** a sent customer delivery writes no communication.
  - **B5 stand-in, communications half:** purging nulls the communication's reference and keeps the communication.
  - **B15, the second allowed edit:** `LogCustomerCommunicationTest`'s whole-row audit expectations gain `delivery_id`; the new B9 cases go in the same file.
  - **Targeted tests:** `N/Deliveries/NotificationsDeliveryLinksTest.php`, `C/Features/LogCustomerCommunication/LogCustomerCommunicationTest.php`, `C/Features/MergeCustomers/MergeCustomersKeepsDeliveryIdTest.php`, then the whole customers suite once (the B15 red-list check), `N/Features/PurgeDeliveries/PurgeDeliveriesTest.php`.
  - *(B5 stand-in half, B9 to B12, B14, B15.)*

#### Chunk H: HTTP, scans, engine, close-out

- [ ] **Step 27: routes and controllers** (`packages/notifications/routes/api.php`, `modules/host/app/Http/Controllers/Notifications/*`). Every admin operation and inbox operation listed in B13 gets one route with `RequireTenantToken` plus `RequireTenantPermission:<key>`, and errors through `ApiResponse`. The provider implements `DeclaresErrorCodes` for every reason code. No template delete route; no send route.
  - **B13:** no token gives 401 before the handler (handler spy count 0); a token without the key gives `not_permitted`; a JSON body refusal names the field and code.
  - **T-040 AC-20, HTTP half (B13):** the sentinel harness gains every response body and header.
  - **Delete `modules/host/tests/Feature/QaNotificationsNoHttpSurfaceTest.php`.** It is T-040's AC-105 "no route at all" test, and these routes make it false by design. **In the same step** add `modules/host/tests/Feature/QaNotificationsNoSendRouteTest.php`, so AC-105's remaining promise stays guarded: no registered route's controller imports or resolves `NotificationSender`, and no route path or name contains `send`. **Red-first:** a planted controller importing `NotificationSender` is reported. Deleting without the replacement is a stop condition.
  - Regenerate the committed OpenAPI document. Add the new placeholders and the write recipes to the route sweeps (section 9).
  - **Targeted tests:** `H/Feature/NotificationsRoutesTest.php` (new), `H/Feature/QaNotificationsNoSendRouteTest.php` (new), `N/Security/NotificationsSentinelTest.php`, `H/Feature/QaAccessHttpSurfaceTest.php`, `H/Feature/QaAccessGuardIsolationHttpTest.php`, `H/Feature/QaRouteCrossTenantSweepTest.php`, `H/Feature/OpenApiCompletenessTest.php`, `H/Feature/RouteCachingTest.php`.
  - *(B1 and B2 over HTTP, B13, T-040 AC-105 kept true.)*
- [ ] **Step 28: scans, engine, documents, final.**
  - `QaNotificationsSourceRulesTest` gains `DeliveryLinks`' `sharedLock`, and the rule that `packages/customers/src` names no `Flexipos\Notifications\` symbol and no notification table (B17's boundary half, beside deptrac).
  - The engine test gains:
    - B5: the communication's key nulls on the engine;
    - B7: the key on the engine;
    - B9: a purge waits behind a shared lock held by `LogCustomerCommunication`.
  - `architect` applies section 4.3's **§6.9** text (the row edit and the "As built (T-040b)" paragraph) to `docs/database.md`.
  - **Targeted tests:** `H/Feature/QaNotificationsSourceRulesTest.php`, `H/Engine/NotificationsSchemaEngineTest.php`, `H/Feature/QaVocabularyRenameSweepTest.php`.
  - **Gate (lead).**
  - *(B5, B7, B9 `[ENGINE]` halves NOT VERIFIED; B17.)*

## 7. Test strategy

- **Unit (pure)** under `packages/notifications/tests`, every basename prefixed `Notifications`:
  - `Support/NotificationsInputTest`, `Channels/NotificationsChannelConfigInputTest`, `Templates/NotificationsTemplateSyntaxTest`, `Templates/NotificationsTemplateInputTest`;
  - `Rendering/NotificationsVariableRulesTest`, `NotificationsRendererTest`, `NotificationsPlainTextTest`, `NotificationsMaskingTest`;
  - `Deliveries/NotificationsDeliveryStateMachineTest`, `NotificationsBackoffTest`, `NotificationsDailyLimitTest`, `NotificationsPayloadHashTest`, `Consent/NotificationsConsentPolicyTest`, `Recipients/NotificationsRecipientRulesTest`;
  - `Contracts/NotificationsEnumsTest`, `Contracts/NotificationsShapesTest`.
- **Integration (SQLite stand-in through `AccessTenantFixture`, in-memory bus):**
  - one file per slice, `tests/Features/<X>/<X>Test.php`;
  - `Channels/NotificationsCredentialVaultTest`, `Drivers/NotificationsLogDriverTest`, `NotificationsNullDriverTest`, `NotificationsDriverRegistryTest`, `Deliveries/NotificationsDeliveryWriterTest`;
  - `Pipeline/NotificationsPipelineTest` (step 16), `Security/NotificationsSentinelTest`, `Security/NotificationsAuthorizationMatrixTest`;
  - `Schema/NotificationsTenantSchemaTest`, `Schema/NotificationsWidthsTest`, `Support/NotificationsProviderTest`;
  - customers: `packages/customers/tests/Customers/ContractCustomerContactsTest.php` (T-040); the two edited files and `MergeCustomersKeepsDeliveryIdTest` (T-040b); `Deliveries/NotificationsDeliveryLinksTest` (T-040b).
- **Static and host (`modules/host/tests/Feature`):**
  - `QaNotificationsScopeGuardTest` (AC-1);
  - `QaNotificationsSourceRulesTest`;
  - `QaNotificationsNoHttpSurfaceTest` (T-040 only; deleted and replaced by `QaNotificationsNoSendRouteTest` in T-040b step 27);
  - `NotificationsProvisioningSeedsNothingTest` (AC-111);
  - `OwedDeliveryColumnMigrationsTest`, `NotificationsRoutesTest` (T-040b);
  - deptrac with the demo negative control.
- **Engine:** `modules/host/tests/Engine/NotificationsSchemaEngineTest.php`, skipped under the hold, **NOT VERIFIED**.
- **Mutations:** N1 to N16 and S1. They cover the parser traps, escaping, single pass, bidi, the state matrix, consent order, attempt counting, the driver-before-config check, daily reset, the dispatcher's due rule, authorization order and the sentinel scan's reach.

**T-040 (106 criteria):**

| ACs | Proved by |
|---|---|
| 1 | step 3 |
| 2 | step 16 (+ AC-1); whole-suite offline NOT VERIFIED (D-49) |
| 3–5 | steps 3, 5, 11 |
| 6–11 | steps 4, 10, 15, 19 |
| 12–18, 22–23 | steps 5, 11 |
| 17 race | `[ENGINE]` step 20 |
| 19–21 | steps 10, 11, 15, 18 (HTTP half: T-040b step 27) |
| 24 | steps 14, 15 |
| 25 | step 11 |
| 26–38 | steps 6, 12 (26 collation `[ENGINE]` step 20) |
| 39–48 | steps 7, 14 |
| 49–56 | step 14 (54 schema half step 2) |
| 57 | step 14; `[ENGINE]` step 20 |
| 58–60 | step 16 |
| 61, 69 | step 13 |
| 62–68 | steps 8, 14, 15 |
| 70–78, 80–83, 86, 89 | steps 8, 15 (72 `[ENGINE]` step 20) |
| 79 | step 17 |
| 84–85 | step 14 |
| 91–93 | step 17 |
| 103 (twelve actions) | steps 11, 12, 13, 17 |
| 104 | step 9 (grown by each slice) |
| 105 | steps 14, 19 (`QaNotificationsNoHttpSurfaceTest`) |
| 107–108 | steps 2, 3 |
| 109 | step 21 |
| 110 | steps 1, 19, 21 |
| 111 | steps 19, 21 |
| 112 | steps 10, 11 |
| 113 | steps 5, 11 |
| 114 | step 14 |
| 115 | steps 4, 13 |
| 116 | steps 1, 15, 16, 17 |
| 117 | step 2; `[ENGINE]` step 20 |
| 118 | steps 5, 6, 11, 12 |
| 119 | steps 14, 19 |

**T-040b (B1 to B17):**

| Criteria | Proved by |
|---|---|
| B1, B2 | steps 22, 24, 27 |
| B3 | step 23 |
| B4 | steps 23, 24 |
| B5 | steps 23, 26; `[ENGINE]` step 28 |
| B6, B7, B8, B10 | step 25 (B7 `[ENGINE]` step 28; B10 also step 26) |
| B9, B11, B12 | step 26 (B9 lock `[ENGINE]` step 28) |
| B13 | step 27 |
| B14 | step 26 |
| B15 | steps 25, 26 |
| B16 | step 23 |
| B17 | step 28 |

## 8. Risks & rollback

1. **No scheduler in production (FP-319).** Retries, deferrals, lost-event recovery and the purge do not run outside tests until FP-319 lands. Nothing real is sent, so no message is lost today, but the design depends on it. Owner-visible.
2. **The driver call runs inside the attempt transaction, holding the delivery and config row locks** (D-19). For `log`/`null` this costs nothing. A real network driver would serialise every send on a channel behind the config lock, and hold a transaction across a network call. **The first real-driver row must restructure this:** reserve the daily slot in a short transaction, and use a lease or `sending` state with a timeout for the call. Recorded as a follow-up, not built.
3. **`APP_KEY` dependency.** If the key is lost, or rotated without `APP_PREVIOUS_KEYS`, every stored credential becomes `credentials_undecryptable`: the deliveries fail permanently and an admin must re-enter the credentials. Separating the key would be a new secret (0.3, escalation).
4. **Stand-in and engine divergence.** Locks compile to nothing on SQLite. The construction scan (step 19) and the engine file (steps 20 and 28) carry the proofs. Every `[ENGINE]` clause stays NOT VERIFIED.
5. **Gate time.** About 380 cases for part 1, two-thirds pure. `lead` measures at each chunk gate; above 10 %, stop and report.
6. **Parallel migration branches** (T-032 D to F, T-031, T-033, T-034, T-038, T-039). Literal collisions in section 9 at merge. Mitigation: regenerate them, never hand-merge (section 5).
7. **Route sweep churn in part 2.** Six sweep files must learn the new routes (section 9). This is why the routes are kept out of part 1.
8. **Consumer fan-out.** Every group sees every event; the `notifications` group acknowledges foreign types as no-ops. The cost is one acknowledge per event per group, which is accepted (T-018 Ruling 3).

**Rollback:** every file is new except section 9's edits. The migrations are forward-only and have run on no tenant outside this branch, so rollback is to abandon the branch. Part 2 reverts alone, because no part 1 file depends on it.

## 9. Edits to existing files and hard-coded tests (the predicted-red list)

| File | Edit | Step |
|---|---|---|
| `modules/host/composer.json`, `composer.lock` | require and autoload-dev; the path package entry only | 1 |
| `modules/host/deptrac.yaml`, `phpstan.neon.dist`, `bootstrap/providers.php` | section 5 | 1 |
| `modules/host/tests/Feature/QaToolchainRegistryCoverageTest.php:446` | `'notifications'` added to the package literal | 1 |
| `modules/host/database/catalogue/tenant_schema_versions.json` | republished (`schema:publish --write-fixture`), never hand-edited | 2, 25 |
| `modules/host/.env.example` (the `APP_REQUIRED_SCHEMA_VERSION` line only) | 76 → 81 → 83 (base-relative) | 2, 25 |
| `modules/host/tests/Helpers.php` `tenantTreeTables()` (line 521) | +5 names under a T-040 comment | 2 |
| `modules/host/tests/Feature/MigrationTreeDisciplineTest.php:152` | 69 → 74 | 2 |
| `modules/host/tests/Feature/IdempotencyKeysSchemaTest.php:122-123` | top version and migration name | 2, 25 |
| `modules/host/tests/Feature/TenantBaselineBuildsTest.php` (~line 383) | +4 keys, then +1 | 2, 25 |
| `modules/host/tests/Feature/QaAccessCloseOutSweepsTest.php:151-153`, `QaAccessSchemaShapeTest.php:233-235` | a `$t040` list of five tables, added to the `array_diff` | 2 |
| `modules/host/tests/Feature/SchemaCataloguePublishTest.php` | fixed by the republish | 2, 25 |
| `modules/host/tests/Feature/TenantDecimalPrecisionTest.php` | **no edit** (T-040 declares no decimal); must stay green | 2 |
| `modules/host/tests/Feature/VerticalSliceLayoutTest.php` | **no edit**; every new slice has a mirrored `tests/Features/<X>/` | each slice |
| `packages/sales/tests/Schema/LoyaltySaleIdMigrationsTest.php:426` | **no edit expected** (asserts `>=`, and its glob is `0030*`); verify at steps 2 and 25 | 2, 25 |
| `packages/access/tests/Support/AuditCatalogueTest.php:48-49, :92` | `'notifications'` between `finance` and `organization` (and after `fixture` in the fixture variant); test titles updated | 11 |
| `packages/customers/src/CustomersServiceProvider.php` | bind `CustomerContacts` to `Customers\ContractCustomerContacts` (AC-115); one docblock line | 13 |
| `modules/host/tests/Feature/QaCustomersSourceRulesTest.php` | **no edit**; its contracts-purity case (line 628) and AC-41 cases must stay green over the new files | 4, 13 |
| `modules/host/tests/Feature/BranchForeignKeysContractRunTest.php:367` (and its run helpers) | one more contract step means one more run ("five runs" becomes six), plus a `t040Contract()` finder by name | 25 |
| `packages/kernel/tests/Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php` | verify only: it derives contract versions from the catalogue | 25 |
| `packages/customers/tests/Schema/CustomersTenantSchemaTest.php:197-203` | absence becomes presence of `delivery_id` (B15, first allowed edit) | 25 |
| `modules/host/tests/Engine/CustomersSchemaEngineTest.php` | **no edit**: line 143 lists table names only, not `customer_communications` columns (measured) | 25 |
| `packages/customers/src/Features/LogCustomerCommunication/LogCustomerCommunicationHandler.php` | `delivery_id` in `FIELDS`, checked via `DeliveryLinks` | 26 |
| `packages/customers/tests/Features/LogCustomerCommunication/LogCustomerCommunicationTest.php` | whole-row audit expectations gain `delivery_id` (B15, second allowed edit); the new B9 and B10 cases | 26 |
| `packages/customers/tests/Features/MergeCustomers/MergeCustomersTest.php`, `packages/customers/tests/Support/CustomersSeed.php` | **no edit** (B15 allows exactly two customers test edits). `MergeCustomersTest:174` asserts with `toHaveKey`, and `CustomersSeed:257` takes overrides through `array_merge`, so the B11 case lives in the new `MergeCustomersKeepsDeliveryIdTest.php` | 26 |
| `modules/host/tests/Feature/QaAccessHttpSurfaceTest.php:433-437`, `QaAccessGuardIsolationHttpTest.php:269` | new placeholders (`{config}`, `{template}`, `{delivery}`, `{notification}`, `{customer}`) in the substitution lists | 27 |
| `modules/host/tests/Feature/QaRouteCrossTenantSweepTest.php` | a recipe for each new non-GET tenant route; by-id GET routes classified | 27 |
| `modules/host/tests/Feature/OpenApiCompletenessTest.php` + the committed OpenAPI document | regenerated | 27 |
| `modules/host/tests/Feature/QaNotificationsNoHttpSurfaceTest.php` | **deleted by T-040b** (it is T-040's AC-105 "no route" test, made false by the routes) and replaced in the same step by the new `QaNotificationsNoSendRouteTest.php` | 27 |
| `docs/database.md` | section 4.3 exact text, by `architect`: §6.16 and §3.3 at step 21 (T-040); §6.9 at step 28 (T-040b) | 21, 28 |
| `docs/architecture.md` | row 84 as-built sentence, by `architect` | 21 |

Anything red that is not in this table means stop and report to `architect`.

## 10. Follow-ups for `lead` (backlog rows or notes, not built here)

- **T-040b** (part 2): accepted (D-35); the backlog row is `lead`'s to register.
- **The real-driver rows** (spec follow-ups 1 to 3) inherit risk 2: move the network call out of the config lock and add an attempt lease. They also widen `ProviderPolicy::AVAILABLE`.
- **Employee contact contract** after T-038 merges (correction 6). Until then employees are in-app only.
- **FP-319:** the scheduler; this task adds two scheduled commands to its list.
- **Roles task:** grant the ten notification keys. Check whether the owner role acquires new keys automatically (the T-032 Ruling 5 question).
- **Owner:** whether provider credentials need a key separate from `APP_KEY` (0.3, a new secret).
- **`ba`:** corrections 1 to 24 are adopted (D-35 to D-58). The revision-2 questions are below.
- **FP-433:** another copy of the support helpers.
- The spec's twelve proposed follow-up rows, unchanged.

## Questions for `ba` (revision 2)

None blocks a chunk; the plan builds the recommended answer, so `ba` may adopt it as text or rule otherwise before the step named.

1. **B9, the code for a `delivery_id` on an inbound communication** (T-040b, step 26). B9 says the direction must be `out` but names no reason code, and B14's three reasons belong to the contract, which never sees the direction. **Recommended:** customers refuses it with `delivery_requires_outbound`, naming `delivery_id`, checked before `DeliveryLinks` is called.
2. **AC-51, the code for a customer who is soft-deleted but not merged** (T-040, step 14). AC-51 says the customer must "be live" but names no code for failing that. AC-114 step 4 lists `recipient_inactive`, which AC-51 uses only for employees. **Recommended:** `recipient_not_found`, the same as an employee who is not live; a deleted customer is treated as absent. AC-61's consent record uses the same rule.
3. **AC-105 and B13, the "no route" test** (T-040b, step 27). AC-105 says T-040 adds a test asserting the package registers no route. T-040b's routes make that test false, but B13 does not say it is deleted, and nothing in T-040b names a replacement. **Recommended:** add one sentence to B13: "T-040's no-route test is deleted and replaced by one asserting that no route reaches `NotificationSender` and no route sends a message."
4. **B4, the purge audit row's subject** (T-040b, step 23). `audit_logs.subject_id` is an identifier column, and a purge has no single subject. **Recommended:** subject type `deliveries_purge`, a fresh ULID per tenant run, with the cutoff and the two counts in `after`. This is a wording addition, not a change of behaviour.

## Questions for the human

None blocking; team mode applies. Owner-visible items for `lead` to carry:
1. **Credential key.** The default applied is the existing `APP_KEY`, so no new secret is needed. A separate key would be a new secret and an escalation (0.3).
2. **No scheduler in production (FP-319).** Retries and lost-event recovery are built but inert in production until it lands (risk 1).
3. **Employees receive in-app notifications only** until an employee contact contract exists (correction 6).
4. **Nested CSS in e-mail templates** is refused by the minimal template language (correction 22).
