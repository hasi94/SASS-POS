# Plan: T-040 Notification channels, templates and deliveries

Spec: `docs/specs/T-040-notification-channels.md` (AC-1 to AC-111, sections A to M). Decisions: `docs/decisions/T-040-notification-channels.md` (D-1 to D-34).
Design authority: `docs/database.md` §6.16 (plus §3.1 to §3.3, §6.9 and §6.21), `docs/architecture.md` §3 (the as-built placement rule) and §9 (queues), `docs/patterns.md` §3 to §6, and `docs/eventbus.md`. House model: `docs/plans/T-032-chart-of-accounts.md` and the shipped `packages/finance`.
Backlog: FP-295. Branch: `ai/T-040-notification-channels`, base `81a9169` (the T-032 merge commit). Gate directory: `modules/host`.

Everything below about shipped code was measured read-only on this worktree at `644b84e`.

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

### 0.4 Rulings

**Ruling 1: placement.** A new path package `packages/notifications` (`flexipos/notifications`, namespace `Flexipos\Notifications\`), registered in `bootstrap/providers.php` after `FinanceServiceProvider`. It is not a `MODULES` module, for the T-032 F-7 reason: in part 2 the customers package resolves `Contracts\Notifications\DeliveryLinks` inside its own handler. Its contracts go in `packages/contracts/src/Notifications/`. `Support/` holds another copy of the per-package helpers (FP-433), with Finance's strict `Input` copied, not the trimming `Fields`.

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
| `DeliveryLinks` / `DeliveryLinkRefused` (part 2) | `assertLinkable(int deliveryId, string customerId, string communicationChannel): void`. Must run inside the caller's transaction (`LogicException` otherwise) and takes a shared lock on the delivery. Reasons: `delivery_not_found`, `delivery_customer_mismatch`, `delivery_channel_mismatch` (AC-99) |

`packages/contracts/src/Customers/` gains `CustomerContacts` (`contactOf(string $customerId): ?CustomerContact`) and `CustomerContact` (readonly `id`, `bool live`, `?mergedIntoId`, `status`, `?email`, `?phone`). They are implemented by `packages/customers/src/Customers/DatabaseCustomerContacts.php` and bound in `CustomersServiceProvider`.

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

**Ruling 7: the request path** (`Features/RequestNotification`, AC-49 to AC-57 and AC-62 to AC-68). The order is fixed (correction 19):
1. Shape: every field type and value. Refusals so far are exceptions and write nothing: `idempotency_key_required`, the enums, the `channel` list refusal, `webhook` refused `channel_not_supported`, the in-app fields refused off `in_app`, `priority`, `data` and `action_url_invalid`.
2. **Replay.** Compute `payload_hash` (canonical JSON of the normalised request, keys sorted, `idempotency_key` and `source_event_id` excluded) and `idempotency_key_hash`. If a row holds the key hash, either return its receipt (`replayed = true`) or refuse `idempotency_key_conflict`. Replay comes before the business checks, so a replay answers the same even after a config is deactivated.
3. Recipient compatibility: `recipient_type_unsupported`; an employee on any channel but `in_app`, or a customer on `in_app`, is refused `channel_not_supported_for_recipient` (correction 6); then `category_not_allowed_for_recipient`.
4. Recipient existence through the contracts: `recipient_not_found`, `recipient_merged` (checked before liveness, because a merge soft-deletes the source), `recipient_inactive`, `recipient_not_eligible` (marketing to a blocked or inactive customer).
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

**Ruling 9: one writer of `deliveries.status`** (AC-70, AC-82). `Deliveries\DeliveryStateMachine` is pure: the allowed moves and nothing else. `Deliveries\DeliveryWriter` holds the only `insert`/`update` on `deliveries` that names `status`. Its methods are `createQueued`, `createSuppressed`, `createFailed`, `markSent`, `markDelivered`, `markFailed`, `markSuppressed`, `defer`, `retryFromFailed`, `markDispatched`, and the internal `recordProviderOutcome(id, delivered|bounced)` (AC-71: called by tests only, documented for the callback task). Every move consults the state machine, and an illegal pair throws `invalid_transition` and writes nothing. The purge's `delete` is the one other write; it lives in `PurgeDeliveries` and is scanned for.

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

**Ruling 11: the dispatcher and the consumer** (AC-58 to AC-60, correction 11).
- **`DeliveryRequestedConsumer`:** group `notifications`, types `['notification.delivery_requested']`. `handle()` reads `payload.delivery_id`, which must be an int; a malformed payload is logged and acknowledged. It then calls the `AttemptDelivery` handler. It never throws for a business outcome. A thrown exception means infrastructure (a deadlock after 2 tries, a dead connection), and the bus redelivers it.
- **`notifications:dispatch-due`:** the fleet sweep shape of `ArchiveAuditLogsCommand` (`--tenant=*`, one tenant's failure does not stop the rest). Batch default 100 (`notifications.dispatch_batch`, 1 to 1000, else it fails to start with a named error). The query is `status = queued AND next_attempt_at <= now AND (dispatched_at IS NULL OR dispatched_at < next_attempt_at OR dispatched_at <= now − redispatch_after)`, ordered by `next_attempt_at, id`. It prints the requeued count and writes no audit row. It is scheduled every minute with `withoutOverlapping()`, and inherits FP-319.

**Ruling 12: audit** (AC-103). One `AuditTrail::record('notifications', …)` per successful admin write, inside the transaction. Before and after values come from `ChannelConfigView` for configs and from the row for templates and consents. `UpdateChannelConfig` declares two constants, `ACTION` and `CREDENTIALS_ACTION`. A body changing both credentials and fields writes two rows. The credentials row's before and after values are `{has_credentials, credentials_updated_at}` only. Requests, attempts and moves are not audited.

**Ruling 13: test cost and engine.**
- Pure classes carry most cases as datasets. Each slice gets one fixture-backed file, in which every refusal writes nothing and a success writes one audit row, compared whole.
- Every `[ENGINE]` clause goes in `modules/host/tests/Engine/NotificationsSchemaEngineTest.php`, skipped under `ENGINE_HOLD` (`EngineConnection::onHold()`), on the exact database `flexipos_t8test_t040_tenant`. It is created and dropped by exact name and never swept with a `LIKE` pattern. These clauses are **NOT VERIFIED** until the hold lifts.
- Estimate: part 1 about 380 cases (about 65 % pure), part 2 about 140.

**Ruling 14: the vocabulary sweep.** Before step 4, `dev` runs `QaVocabularyRenameSweepTest`'s retired spellings against every enum value and reason code here.

---

## 1. Approach summary and the split

1. A new `packages/notifications` and a contract namespace `Contracts\Notifications`. Other modules call `NotificationSender` only; customers implement the new `CustomerContacts`.
2. Five tenant tables per §6.16, as amended (section 4), plus one owed column on `customer_communications`, expand then contract.
3. A send is validated, rendered once at request time, and stored as a snapshot on a `deliveries` row. In the same transaction an id-only outbox event is written. The `notifications` consumer group attempts it under a row lock. A scheduled dispatcher re-emits due rows (retries, deferrals, lost events).
4. Drivers sit behind `ChannelDriver`. Only `log` and `null` exist, and in-app is a database write. The configurable provider set is a data whitelist.
5. Credentials are sealed with the existing `APP_KEY` encrypter, write-only, and redacted from every sink. A sentinel test scans every table, the log, the outbox, exceptions and results.
6. Consent is fail-closed for marketing, re-checked at the attempt, and a refusal becomes a `suppressed` row.
7. Every admin slice authorizes first, at the handler, and writes one audit row. The status column has one writer.

**Proposed split: two deliverable tasks.** It is `lead`'s call; `ba` re-labels the ACs. Customers and consent cannot move to the second task: without customers, the log and null drivers have no reachable recipient (employees are in-app only, correction 6). And a customer send path without the consent check is not safe to merge.

| Task | Contents | ACs | Chunks |
|---|---|---|---|
| **T-040** (FP-295, this row) | scope guard, drivers, configs and secrets, templates and rendering, the send contract, consent store and check, delivery lifecycle, retry, dispatch, the in-app write, delivery reads, counts and retry, all ten keys, part-1 audit actions, five tables | 1–86, 89, 91–93, 103 (all but `deliveries_purged`), 104, 105, 107, 108, 109 (five tables), 110, 111 | A–E |
| **T-040b** (new row, FP-295 follow-on) | employee inbox reads, retention purge, the owed `customer_communications.delivery_id` (expand, contract, `DeliveryLinks`, `LogCustomerCommunication`), the HTTP routes for every admin operation and the inbox | 87, 88, 90, 94–102, 103 (`deliveries_purged`), 106, 109 (owed column), 110 | F–H |

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
Ruling 2: `Channel`, `Category`, `RecipientType`, `Priority`, `DeliveryStatus`, `SendOutcome`, `NotificationRequest`, `NotificationSender`, `DeliveryReceipt`, `NotificationRefused`, `NotificationRefusal`, `ChannelDriver`, `OutboundMessage`, `SendResult`, `CredentialField`, `DriverCredentials` and `DeclaresChannelDrivers`. Part 2 adds `DeliveryLinks` and `DeliveryLinkRefused`.

### `packages/contracts/src/Customers/` (two new files)
`CustomerContacts.php`, `CustomerContact.php`.

### `packages/notifications` (new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/notifications` 0.1.0, requires `flexipos/contracts` only (AC-1: no mail, SMS, HTTP or SDK package) |
| `src/NotificationsServiceProvider.php` | Binds `NotificationSender`. Implements `DeclaresAuditActions`, `DeclaresPermissions`, `DeclaresEventConsumers` and `DeclaresChannelDrivers`. Registers `notifications:dispatch-due` (part 2 adds `notifications:purge-deliveries`) and schedules them. Part 2 also loads routes |
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
| `src/Deliveries/DatabaseDeliveryLinks.php` (part 2) | `DeliveryLinks` |
| `routes/api.php` (part 2) | the `packages/organization/routes/api.php` shape |
| `tests/Support/{NotificationsSeed.php,notifications-fixture.php,SpyLogger.php,ThrowingDriver.php,ScriptedDriver.php,SentinelHarness.php}` | raw-insert seeds, one employee per permission profile, a driver scripted per attempt (AC-10, AC-75, AC-76), the sentinel capture |
| `tests/<area>/*Test.php`, `tests/Features/<X>/<X>Test.php` | section 7. `VerticalSliceLayoutTest` requires the mirror |

### `packages/customers`
- **Part 1:** `src/Customers/DatabaseCustomerContacts.php`, and `CustomersServiceProvider` binds it. It is a plain read, `whereNull` not applied, so a merged or deleted customer is still answered with `live = false`.
- **Part 2:** `LogCustomerCommunicationHandler` gains `delivery_id` in `FIELDS`. It must be a JSON int ≥ 1, and is checked through `DeliveryLinks` inside the existing transaction. The edited tests are listed in section 9.

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
| `config/notifications.php` | numbers only: `max_attempts` 5 (1–20), `backoff_base_seconds` 60, `backoff_cap_seconds` 3600 (1–86400, cap ≥ base), `dispatch_batch` 100, `redispatch_after_seconds` 300, `retention_days` 90 (7–3650), `purge_batch` 500, `page_size` 25, `max_page_size` 100. Each is validated at use and fails with a named error |
| `tests/Feature/QaNotificationsScopeGuardTest.php` | AC-1 (step 3) |
| `tests/Feature/QaNotificationsSourceRulesTest.php` | step 19 |
| `tests/Feature/QaNotificationsNoHttpSurfaceTest.php` | part 1 (the `QaEventbusNoHttpSurfaceTest` shape). Deleted by step 27 |
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
- **Signedness (AC-98):** `deliveries.id` uses `$table->id()` (unsigned), and both `delivery_id` columns use `unsignedBigInteger`. The schema test reads both declarations.

### 4.2 Contracts, events, config and surface
- **Contracts:** Ruling 2.
- **Event:** `notification.delivery_requested` v1, consumed by group `notifications`.
- **Commands:** `notifications:dispatch-due` (part 1) and `notifications:purge-deliveries` (part 2), both with `--tenant=*`.
- **Permissions:** ten keys. **Audit actions:** thirteen (correction 2).
- **Config:** `config/notifications.php`. **HTTP:** none in part 1; the routes are part 2.
- **Dependencies:** only the path package. The `composer.lock` diff adds only that entry.

### 4.3 Proposed `docs/database.md` amendments (applied by `architect` at step 21 / 28, not now; describe, never quote retired vocabulary)

**§6.16, the table rows replaced:**

| Table | Columns / notes |
|---|---|
| notification_templates | `[ID]` `[TS]`, key VARCHAR(80) (lower case `^[a-z][a-z0-9_.]{0,79}$`), channel enum(email, sms, in_app, whatsapp, push, webhook) (`webhook` refused on write until the webhook task), locale VARCHAR(10), subject VARCHAR(255) NULL, body MEDIUMTEXT, variables JSON, is_active (default true), plugin_name NULL; unique (key, channel, locale) |
| channel_configs | `[ID]` `[TS]`, channel, provider VARCHAR(50), credentials TEXT NULL (ciphertext from the application encrypter, `APP_KEY`; write-only), credentials_updated_at NULL, settings JSON NULL (never a secret), from_address VARCHAR(191) NULL, is_active (default false), daily_limit INT UNSIGNED NULL, sent_today INT UNSIGNED, **sent_today_date DATE NULL** (the UTC day `sent_today` counts), active_channel generated stored (`CASE WHEN is_active THEN channel END`); unique (channel, provider), unique (active_channel) — at most one active config per channel. No row for `in_app` |
| notifications (in-app) | `[ID]` `[TS]`, recipient_employee_id FK, type, title, body, data JSON, priority enum(low, normal, high), read_at, action_url, **delivery_id BIGINT UNSIGNED NULL unique FK deliveries ON DELETE SET NULL**; index (recipient_employee_id, created_at), (recipient_employee_id, read_at). Never purged |
| deliveries | id BIGINT UNSIGNED AUTO PK, **ref CHAR(26) unique** (the outbox aggregate id), channel, template_key, **locale, category enum(transactional, marketing, system), recipient_type enum(employee, customer), recipient_id CHAR(26)** (no FK, polymorphic), recipient VARCHAR(191) NULL (address snapshot; null for in-app), **subject, body MEDIUMTEXT** (rendered snapshot), **in_app JSON NULL**, provider NULL, status enum(queued, sent, delivered, failed, bounced, **suppressed**), provider_ref, **error_code**, error VARCHAR(500), attempts, **idempotency_key VARCHAR(100), idempotency_key_hash CHAR(64) unique, payload_hash CHAR(64)**, source_event_id CHAR(26) NULL, **next_attempt_at, last_attempt_at, dispatched_at**, sent_at, **delivered_at**, created_at, **updated_at**. Index (created_at), (status, created_at), (status, next_attempt_at), (recipient_type, recipient_id, created_at). 90-day retention |
| **notification_consents** (new) | `[ID]` `[TS]`, customer_id FK, channel enum(email, sms, whatsapp, push), category enum(transactional, marketing), status enum(opted_in, opted_out), source VARCHAR(50), note VARCHAR(255) NULL, recorded_by FK employees; unique (customer_id, channel, category). History lives in `audit_logs` |

`notification_rules` and `webhook_endpoints` / `webhook_deliveries` stay as documented, marked "not built (T-040 D-1)".

**§6.16, a new "As built (T-040)" paragraph** covering:
- the request-time render snapshot;
- the id-only outbox event;
- the consumer group and the dispatcher's due rule;
- the status moves (only `failed` → `queued` by manual retry);
- why the idempotency key is unique by digest rather than by collation;
- the fail-closed marketing consent;
- the in-app write's uniqueness;
- the log, null and in-app drivers as the only ones;
- FP-319.

**§6.9 `customer_communications`:** the T-028 as-built note says `delivery_id` is delivered (part 2): BIGINT UNSIGNED, expand then contract, SET NULL.

**§3.3, one clause added after the RESTRICT/CASCADE rule:** "`SET NULL` for a reference to a row that a retention purge deletes (`deliveries`), where the referencing row keeps its own content (T-040 D-27)."

**§1/§15:** nothing.

## 5. Boundary impact and merge order

- **`deptrac.yaml`:**
  - `Package_Notifications` = `^Flexipos\\Notifications\\(?!Tests\\).*` with `[Package_Kernel, Package_Contracts]`.
  - `Package_Notifications_Tests` = itself plus `Package_Contracts`, `Package_Kernel`, `Package_Access` (fixture), and `Package_Eventbus` (stand-in bus, the plugin-sdk-tests precedent).
  - `HostApp` and `HostTests` gain both layers. **`Module_Demo` is not granted `Package_Notifications`** (the AC-110 instrument; step 1's negative control).
  - Customers gains no edge (it uses `Contracts\Notifications`).
- **PHPStan:** both paths. **Composer (host):** require `flexipos/notifications: *` and the autoload-dev tests namespace; then `composer update flexipos/notifications` (never `--lock`) and `composer validate --no-check-publish`.
- **`bootstrap/providers.php`:** `NotificationsServiceProvider` after `FinanceServiceProvider`.
- **Merge order.** T-032 (chunks D to F, five files) and T-031/T-033/T-034/T-038/T-039 all add tenant migrations in parallel. Whichever merges second **regenerates** the catalogue (`php artisan schema:publish --write-fixture`), `.env.example`'s version, `tenantTreeTables()`, `MigrationTreeDisciplineTest`'s count, `IdempotencyKeysSchemaTest`'s top entry, `TenantBaselineBuildsTest`'s key list and `BranchForeignKeysContractRunTest`'s run count. Version numbers in this plan are base-relative (base 76).

## 6. Implementation steps

These rules carry over from T-032 §6:
- red-first, by fixture or omission, never by stubbing a guard open;
- the red-list rule after steps 2, 13, 25 and 27: compare with section 9, and stop on any file it does not name;
- unique test basenames, prefixed `Notifications…`;
- no child processes;
- mutations run once, reverted, and recorded red-then-green in `dev`'s hand-back.

**Inside a chunk** `dev` runs only the targeted Pest files plus `composer lint`, `composer stan` and `composer deptrac`. **The full `composer check` is scheduled by `lead`** (marked "Gate (lead)" below), and run without an `APP_KEY` (baseline 13 failed, by name).

### PART 1: T-040

#### Chunk A: plumbing, schema, static instruments

- [ ] **Step 0: verify every named symbol, by reading source. STOP on any difference and report to `architect`.**
  - `OutboxWriter::validate` refuses a non-ULID aggregate id;
  - the `EventConsumer`/`DeclaresEventConsumers`/`ConsumerRegistry` shapes;
  - `RoleHolders::actingAmong` and `EmployeeDirectory::isLive`;
  - `AuditTrail::record` and the undeclared-action refusal;
  - `PermissionResolver::decide`;
  - `IdentifierColumn::{primary,required,nullable}` and `JsonValidity::enforce`;
  - `SourceRules` collation rule, `SourceRules` FK-in-contract rule (FKs on a created table are allowed in expand), and that `nullOnDelete()` is not reported;
  - `RunnerClock::{now,timestamp,fixedAt,advanceSeconds}`;
  - `Crypt` precedent and `config/app.php` previous keys;
  - the section 9 literals (`AuditCatalogueTest:49/:92`, `QaToolchainRegistryCoverageTest:446`, `MigrationTreeDisciplineTest:152` = 69, `IdempotencyKeysSchemaTest:122-123` = 76 / `…003203…`, `QaAccessCloseOutSweepsTest:151`, `QaAccessSchemaShapeTest:233`, `CustomersTenantSchemaTest:197-203`);
  - whether T-032 chunks D to F have landed on this branch (`git log --oneline | grep T-032`). If they have, re-base the literals.
  - *(No AC.)*
- [ ] **Step 1: package plumbing.**
  - `composer.json`; an empty provider (declares nothing yet); `config/notifications.php`; section 5's edits; `'notifications'` added to `QaToolchainRegistryCoverageTest:446`.
  - **`NotificationsProviderTest`:** the provider is loaded.
  - **Negative control:** a scratch class in `modules/demo/src` importing `Flexipos\Notifications\Rendering\Renderer` fails deptrac, while one importing `Flexipos\Contracts\Notifications\NotificationSender` passes. Delete both.
  - *(AC-110 instrument.)*
- [ ] **Step 2: the five migrations (`004001` to `004005`), with `NotificationsTenantSchemaTest`** (own SQLite stand-in, the T-032 shape).
  - The exact column lists and nullability (4.1).
  - Uniques by raw double insert:
    - (key, channel, locale);
    - (channel, provider);
    - `active_channel`: two active `email` refused; active `email` plus inactive `email` coexist; active `email` plus active `sms` coexist;
    - `ref`, `idempotency_key_hash` and `notifications.delivery_id`;
    - (customer, channel, category).
  - The four FKs. Deleting a `deliveries` row sets `notifications.delivery_id` to null (stand-in, foreign keys enforced).
  - `deliveries.id` and `notifications.delivery_id` are both unsigned (AC-98, declaration half).
  - `SourceRules::inspect()` reports nothing on each file; no `down()`.
  - Then: republish the catalogue; update `tenantTreeTables()` (+5 under a T-040 comment), `MigrationTreeDisciplineTest` (69 → 74), `IdempotencyKeysSchemaTest` (top 81, `…004005_create_notification_consents_table`), `TenantBaselineBuildsTest` (+4), the `$t040` lists in `QaAccessCloseOutSweepsTest` and `QaAccessSchemaShapeTest`, and `.env.example` (76 → 81).
  - **Predicted red:** section 9's step-2 rows only.
  - *(The schema halves of AC-12, AC-17, AC-26, AC-56, AC-61, AC-86, AC-98, AC-107, AC-108.)*
- [ ] **Step 3: static instruments.**
  - **`NotificationsWidthsTest`:** every VARCHAR/CHAR width in `004001` to `004005` equals its `Support\Limits` constant. **Red-first:** a fixture declaring `string('key', 81)` is reported.
  - **`QaNotificationsScopeGuardTest` (AC-1),** over `packages/notifications/{src,tests}`, the notifications migrations and `config/notifications.php`, through `SourceRules::codeOnly`. It reports, by name, any of:
    - `Illuminate\Mail`, `Mail::`, `Illuminate\Notifications`, `Illuminate\Support\Facades\Http`, `Http::` (except `Http::preventStrayRequests` in tests), `Illuminate\Http\Client`, `GuzzleHttp`, `Psr\Http\Client`, `Flexipos\HttpClient`, `Symfony\Component\Mailer`, `curl_`, `fsockopen`, `stream_socket_client`, `socket_create`, `mail(`, `file_get_contents('http`, `Swift_`, `Aws\`, `Twilio`, `Vonage`, `SendGrid`, `Mailgun`;
    - and a `composer.json` require other than `flexipos/contracts` / `php`.
  - It also checks that the configurable provider set equals `['log', 'null']`.
  - **Red-first:** planted files with `Mail::raw(` and with `curl_init(`, and a composer require of a mail SDK, are each reported. Delete them.
  - **Gate (lead).**
  - *(AC-1, AC-3 whitelist half, AC-107.)*

#### Chunk B: the pure core

- [ ] **Step 4: contracts** (Ruling 2, plus `CustomerContacts`), after Ruling 14's sweep.
  - **`NotificationsEnumsTest`:** the exact value lists; `Channel::isSendable()`/`isOutbound()`; every reason code in the spec plus 0.2's appears in `NotificationRefusal` exactly once.
  - **`NotificationsShapesTest`:**
    - the readonly shapes refuse a malformed ULID by name;
    - `DriverCredentials` renders no value through `var_export`, `print_r`, `json_encode`, `serialize` or `__debugInfo` (sentinel).
  - *(AC-6, AC-49 shapes; groundwork for AC-20.)*
- [ ] **Step 5: `Support\Input`, `Fields::only`, `ChannelConfigInput`, `ProviderPolicy`** (pure datasets).
  - **AC-3:** `log` and `null` for the four outbound channels; `ses`, `smtp`, `''` and `LOG` are each `provider_not_available`.
  - **AC-5 / correction 14:** `webhook` is `channel_not_supported`; `in_app` is `channel_not_configurable`.
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
  - **AC-18:** with `log`'s declared fields, `{"sandbox_token": "…"}` passes; `{"x": 1}` is `credential_field_unknown`; a required-field case uses a fixture driver; `null` or in-app with credentials is `credentials_not_supported`.
  - **AC-22 shape:** `{}` is refused; forbidden keys (`id`, `sent_today`, `sent_today_date`, `created_at`, `updated_at`, `is_active` per correction 13, unknown) are named **together**.
  - **Mutations:** **N1** trims the from-address, making the leading-space row go red; **N2** uses `strcasecmp` for the provider, making `LOG` go red.
  - *(AC-3, AC-5, AC-12 to AC-16, AC-18, AC-22 parsing.)*
- [ ] **Step 6: `TemplateSyntax` and `TemplateInput`** (pure).
  - **AC-27:** `Welcome`, `'welcome '`, 81 characters, `1abc` and `a-b` are refused; `order.receipt_v2` passes.
  - **AC-28:** `en` and `en-GB` pass; `EN`, `en-gb`, `eng` and `en_GB` are refused.
  - **AC-29 and AC-30:** subject rules per channel; `subject_not_supported` for sms, whatsapp and push; a CR in the subject is refused.
  - **AC-31:** 20,000 / 20,001 characters; sms 1,600 / 1,601; blank-after-trim refused; NUL refused; the stored body is byte-identical.
  - **AC-32:** 31 entries; a name `1a`; `{type: 'money'}`; `max_length` 0 and 1,001; an unknown inner key; `required` defaults to true and `max_length` to 200.
  - **AC-33:** each of `{{{x}}}`, `{{#x}}`, `{{>x}}`, `{{a.b}}`, `{{a|f}}`, `{{x`, `x}}` and `}}` alone gives `malformed_placeholder` with the position; `{{ name }}` passes; `{ single }` passes.
  - **AC-34:** an undeclared variable is named; one unused declaration passes.
  - **AC-35:** immutable keys are named together.
  - **Mutations:** **N3** `^…$` instead of `\A…\z` (a key with a trailing newline goes red); **N4** allow dotted placeholder names (`{{a.b}}` goes red).
  - *(AC-26 to AC-35 logic.)*
- [ ] **Step 7: `VariableRules`, `Renderer`, `PlainText`, `Masking`** (pure).
  - **AC-39:** missing, unknown and invalid variables are all named in one refusal.
  - **AC-40:**
    - number: `12`, `"-3.1415"`; refused: `1.5` (float), `"1e3"`, `"1.12345"`, 19 digits;
    - date: `2026-02-30` refused, `2028-02-29` passes;
    - url: refused for `http://`, `javascript:`, `data:`, relative, `https://u:p@h`, whitespace and 501 characters;
    - arrays, objects, null and booleans are refused for every type.
  - **AC-41:** `<script>alert(1)</script>` becomes `&lt;script&gt;alert(1)&lt;/script&gt;`; `&amp;` in a value becomes `&amp;amp;` (escaped exactly once); `'` becomes `&#039;`.
  - **AC-42:** for every plain-text field, `"a\r\nBcc: x@y"` gives `aBcc: x@y`, and a tab gives a space; U+2028, U+202E and U+2066 are removed; `&` stays `&`.
  - **AC-44:** a value `{{other}}` is emitted literally.
  - **AC-45:** `<b>Hi</b>` in template text is unchanged.
  - **AC-46:** rendering twice is byte-identical.
  - **AC-48:** a rendered sms over 1,600 characters is `rendered_too_long`.
  - **AC-8 masking:** `alice@example.com` gives `a***@example.com`; `+94771234567` gives `***67`.
  - **Mutations:**
    - **N5** escapes the e-mail subject too, making the `&` row go red;
    - **N6** re-renders the output (two passes), making the AC-44 row go red;
    - **N7** drops the bidi removal, making the U+202E row go red.
  - *(AC-8 masking, AC-39 to AC-48 logic, AC-92 masking.)*
- [ ] **Step 8: `DeliveryStateMachine`, `Backoff`, `DailyLimit`, `ConsentPolicy`, `RecipientRules`, `PayloadHash`** (pure).
  - **AC-70:** all 36 (from, to) pairs; only the 8 listed pass.
  - **AC-75:** 60, 120, 240 and 480 seconds; the cap holds at large attempts; config validation (base 0, cap < base).
  - **AC-76:** reaching the maximum gives `max_attempts_exceeded`.
  - **AC-77:** next UTC midnight; reset when the date differs; null never defers.
  - **AC-62 to AC-64, AC-68:** a {no row, opted_in, opted_out} × {marketing, transactional} × {active, blocked, inactive} table; employees are never consulted.
  - **AC-53:** the category × recipient table.
  - **AC-56:** hash equality ignores key order in `variables`, and differs on any field except the two excluded.
  - **Mutation N8:** allow `sent` → `queued`, making the matrix go red.
  - **Gate (lead).**
  - *(AC-53, AC-56 hash, AC-62 to AC-64, AC-68, AC-70, AC-75 to AC-77 logic.)*

#### Chunk C: secrets, drivers, configs, templates and consents on the database

- [ ] **Step 9: permissions, `Authorize`, audit registry, authorization matrix.**
  - `NotificationsPermissions`, which the provider declares, and `NotificationsAuditActions` (it grows with each slice). `AuditCatalogueTest:49/:92` gains `'notifications'` when the first action lands.
  - `NotificationsSeed` provides one employee per key, plus one with none.
  - **`NotificationsAuthorizationMatrixTest`** is one dataset, grown by every later slice:
    - without the key the slice is `not_permitted`, and a query listener proves **no** notification-table statement ran;
    - a null actor is refused, and so is a soft-deleted employee;
    - `deliveries.view` does not grant `retry` or `view_content`;
    - `channels.view` does not grant `manage`.
  - **Mutation N9:** move `Authorize` after the target read, making "no statement ran" go red.
  - *(AC-104.)*
- [ ] **Step 10: `CredentialVault`, `LogDriver`, `NullDriver`, `DriverRegistry`.**
  - Tests set `config(['app.key' => 'base64:'.base64_encode(random_bytes(32))])` and `app()->forgetInstance('encrypter')` in `beforeEach` (the `PluginSettingsStoreTest` shape). They **never** read `.env`.
  - **AC-19:** seal the sentinel `SANDBOX-T040-NOT-A-SECRET-…`; the stored string lacks it; `open` round-trips.
  - **AC-21:**
    - after swapping to a second generated key, `open` gives `credentials_undecryptable`;
    - with the first key listed in `app.previous_keys`, it decrypts (correction 12);
    - with no key, `credentials_undecryptable`;
    - truncated ciphertext also gives `credentials_undecryptable`.
  - **AC-8:** `SpyLogger` records exactly one line with keys `{delivery_id, channel, provider, template_key, recipient}` and the recipient masked; neither subject, body, sentinel nor address appears. The result is `accepted log:<id>`.
  - **AC-9:** `null` writes zero log lines and returns `null:<id>`.
  - **AC-7:** resolving by (channel, provider); an unregistered pair returns none; duplicate declarations throw at sweep.
  - **AC-10 (vault half):** `redact` replaces each stored value and truncates to 500.
  - **Mutation N10:** log the unmasked address, which makes the AC-8 row go red.
  - *(AC-6 to AC-10, AC-19, AC-21 vault half.)*
- [ ] **Step 11: the channel config slices** (`Create`, `Update`, `Activate`, `Deactivate`, `Delete`, `List`, `Get`).
  - **AC-12:** defaults (inactive, `sent_today` 0); a duplicate (channel, provider) is `channel_config_exists` naming the first.
  - **AC-17 on the stand-in:**
    - activating a second config for a channel is `channel_already_active` naming the holder;
    - the backstop: a raw-inserted active holder past the pre-check seam is still refused, and the holder is re-read with a lock.
  - **AC-18 to AC-22 end to end:**
    - **write-only:** omitting `credentials` leaves them unchanged; `null` clears them; `{}` is refused;
    - **audit rows:** a credentials change plus a field change writes two audit rows, and the credentials row's before and after values are `{has_credentials, credentials_updated_at}` only;
    - **reads:** reads show `has_credentials`/`credentials_updated_at` and never `credentials`;
    - **no key:** with no key, a write naming credentials is `encryption_unavailable` and nothing is written.
  - **AC-23:** deleting an active config is `config_active`; a second delete is `config_not_found`; the row is gone whole.
  - **AC-25:** in two tenant fixtures (`tenant-a`, `tenant-b`), each lists only its own; a credential sealed in A is absent from B's every column.
  - **AC-103:** one audit row per write, compared whole; a refusal writes none.
  - **Mutation N11:** put `credentials` into the audit `after`, which makes the scan in step 18 go red (that proves the scan).
  - *(AC-12 to AC-23, AC-25, AC-103 channel actions.)*
- [ ] **Step 12: the template slices** (`Create`, `Update`, `Activate`, `Deactivate`, `List`, `Get`).
  - **AC-26:** `plugin_name` is null; a duplicate is `template_exists` naming the id.
  - **AC-35:** stored-plus-supplied re-validation. For example, updating `variables` to drop a used name gives `undeclared_variable`.
  - **AC-36:** deactivate twice gives `already_inactive`; reactivate is allowed; `already_active` applies too.
  - **AC-37:** filters, key-prefix `LIKE` with `\`, `%` and `_` escaped, order, default 25, limits 0/101/`"5"` refused.
  - **AC-38:** no delete slice exists. A reflection test over `Features/` finds no `Delete*Template*`.
  - *(AC-26 to AC-38, AC-103 template actions.)*
- [ ] **Step 13: `DatabaseCustomerContacts` (customers) and the consent slices** (`RecordConsent`, `ListConsents`).
  - **Contacts:** live, merged (soft-deleted with `merged_into_id`), deleted and unknown ids each give the right snapshot. `CustomersServiceProvider` binds it. The customers package suite still passes unchanged.
  - **AC-61:**
    - recording the same triple twice updates one row, with two audit rows (`notifications_consent_recorded`);
    - `channel` `in_app` and category `system` are refused;
    - `source` must be 1 to 50 characters;
    - `note` up to 255;
    - `recorded_by` is the actor.
  - **AC-69:** list for a customer, paged as AC-37; an unknown customer is `recipient_not_found`.
  - **Predicted red:** none outside the notifications package. The red-list rule applies.
  - **Gate (lead).**
  - *(AC-61, AC-67 contract half, AC-69, AC-103 consent action.)*

#### Chunk D: the send path

- [ ] **Step 14: `RequestNotification` and `DatabaseNotificationSender`** (Ruling 7).
  - **AC-49 and AC-50:** the contract resolves; a list-valued `channel` is refused.
  - **AC-51, AC-52, AC-53:** every recipient and template refusal, in Ruling 7's order. Two failures at once report the first. The employee-on-email case is `channel_not_supported_for_recipient` (correction 6). The `en` fallback works for `fr`.
  - **AC-24:** no active config is `channel_not_configured`, with nothing written.
  - **AC-54:**
    - one delivery plus one outbox row, with exact type, aggregate (`delivery`, `ref`) and payload `{delivery_id}`;
    - a caller's rolled-back outer transaction leaves neither row.
  - **AC-55:** the outbox row holds no address, subject, body, variable value or sentinel.
  - **AC-56:**
    - a replay returns the same id with `replayed`, and writes no second row;
    - changed variables give `idempotency_key_conflict`;
    - `a` against `A` gives two deliveries;
    - 0 or 101 characters, a control byte, or a non-ASCII byte gives `idempotency_key_required`.
  - **AC-57 stand-in half:** a raw-inserted holder past the replay pre-check makes the unique backstop return the holder.
  - **AC-62, AC-63, AC-66 request half:** marketing with no row gives a `suppressed consent_missing` receipt, with no outbox row and attempts 0; transactional with `opted_out` gives `suppressed consent_opted_out`.
  - **AC-67:** an empty email gives `failed recipient_address_missing`, no outbox row, and the address snapshot is stored when present.
  - **AC-68:** marketing to a blocked customer is `recipient_not_eligible`; transactional to the same customer is queued.
  - **AC-84, AC-85:** priority and data limits; each `action_url` case, including `//host`, `\` and `http:`.
  - **AC-47:** editing the template after the request leaves the stored snapshot unchanged.
  - **Mutation N12:** check consent after the address, making the "consent before address" row go red.
  - *(AC-24 request half, AC-47, AC-49 to AC-57, AC-62 to AC-68 request half, AC-84, AC-85.)*
- [ ] **Step 15: `DeliveryWriter` and `AttemptDelivery`** (Rulings 8 and 9), driven by `ScriptedDriver`/`ThrowingDriver` bound through a fixture `DeclaresChannelDrivers` provider, with a fixed `RunnerClock`.
  - **AC-74:** an accepted send sets every listed field in one transaction, including `sent_today + 1` and `sent_today_date`.
  - **AC-75, AC-76, AC-80:**
    - the full ladder of 5 transients is driven by advancing the clock: the delays are 60/120/240/480, then `failed max_attempts_exceeded` with the last driver code in `error`;
    - a permanent failure fails at once;
    - no real wait occurs.
  - **AC-10:**
    - a throw, or an out-of-range outcome, becomes `transient driver_error`;
    - the stored error contains `[redacted]` where the sentinel was and is at most 500 characters;
    - the consumer sees no exception.
  - **AC-21:** with a swapped key, the attempt is `failed credentials_undecryptable`, and the driver is never called (spy count 0).
  - **AC-24 attempt half:** a config deactivated after queueing gives `failed channel_not_configured`.
  - **AC-7:** a config for a provider with no driver gives `failed driver_not_registered`.
  - **AC-65, AC-66, AC-73:** an opt-out recorded after queueing gives `suppressed` at the attempt, with attempts unchanged and the driver not called. A recipient deactivated after queueing gives `failed recipient_inactive`. The order of the four re-checks is pinned.
  - **AC-77:** at the limit the attempt is deferred to midnight with attempts unchanged; on the next UTC day the counter resets and the send proceeds.
  - **AC-83, AC-86, AC-89:**
    - an in-app delivery writes one `notifications` row (type, title, body, data, priority and action URL as given), and the delivery is `delivered` with both timestamps;
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
  - *(AC-7, AC-10, AC-11, AC-21, AC-24, AC-65, AC-66, AC-70 to AC-78, AC-80 to AC-83, AC-86, AC-89.)*
- [ ] **Step 16: the consumer and `notifications:dispatch-due`**, end to end on the in-memory bus (the `PluginPipelineTest` shape: request, `eventbus:relay`, `eventbus:consume --group=notifications --once`).
  - **AC-58:**
    - a redelivered event for a `sent` row is a no-op and is acknowledged;
    - a not-yet-due row is a no-op;
    - a purged id is logged and acknowledged;
    - a malformed payload is acknowledged.
  - **AC-59:**
    - a deleted outbox row (lost event) is recovered by one dispatch run;
    - running twice before consumption writes one more event at most per row, because of `dispatched_at`;
    - a transient retry becomes due and is re-emitted;
    - ordering is by `next_attempt_at, id`;
    - the count is printed and no audit row is written.
  - **AC-60:** batch 0, 1,001 and `"x"` fail to start, naming the key.
  - **AC-2:** the whole pipeline under `Http::preventStrayRequests()` plus `Mail::fake()`; `Mail::assertNothingSent()`, and no stray request.
  - **Mutation N16:** drop `dispatched_at` from the due rule, making the "twice" row go red.
  - *(AC-2 in part, AC-58 to AC-60.)*
- [ ] **Step 17: delivery reads and retry** (`ListDeliveries`, `GetDelivery`, `CountDeliveries`, `RetryDelivery`).
  - **AC-91:**
    - each filter, valid and invalid (each invalid one named);
    - `created_from`/`created_to` dates and UTC date-times;
    - cursor on `id` descending;
    - default 25, maximum 100.
  - **AC-92:** without `view_content`, subject and body are absent and the recipient is masked; with it, both are present.
  - **AC-93:** grouped counts for a range, with the query count asserted as 1.
  - **AC-79:**
    - `failed` → `queued` with attempts 0, errors cleared, a new outbox row, and one audit row whose before value holds the old error;
    - retrying a `sent` delivery is `invalid_transition`;
    - an `expired` failed delivery is `delivery_expired`;
    - consent is re-checked on the next attempt (an opt-out recorded after the failure leads to `suppressed`).
  - *(AC-79, AC-91 to AC-93, AC-103 retry action.)*
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
  - **Gate (lead).**
  - *(AC-19, AC-20 for every sink except HTTP responses, which are part 2.)*

#### Chunk E: scans, engine, documents, close-out of part 1

- [ ] **Step 19: `QaNotificationsSourceRulesTest` (host)**, code only.
  - **One writer:**
    - outside `DeliveryWriter`, no code names `table('deliveries')` together with `update(`/`insert(` naming `status`;
    - `delete(` on `deliveries` appears only in `PurgeDeliveries` (part 2);
    - outside `packages/notifications/src` and the T-040 migrations, no code names `table('deliveries')`, `table('channel_configs')`, `table('notification_templates')`, `table('notifications')` or `table('notification_consents')`.
  - **Locked reads:**
    - every terminal read in `DeliveryRows` and `ChannelConfigRows` outside `*Unlocked` methods chains `lockForUpdate()`/`sharedLock()`;
    - write slices call no `first(`, `get(`, `exists(`, `count(`, `value(` or `pluck(` themselves.
  - **Encrypter:** only `CredentialVault` names `Crypt`, `encrypter`, `encryptString` or `decryptString`.
  - **Internal transition:** `recordProviderOutcome` has no caller under `src/`.
  - **No HTTP surface** in part 1 (the `QaNotificationsNoHttpSurfaceTest` file).
  - **Regexes:** none in `src/` ends `$/`.
  - **Contracts purity:** `packages/contracts/src/Notifications` imports nothing outside contracts and PHP core.
  - **Red-first:** each rule is fed a planted file and reports it; delete them.
  - *(AC-6 boundary, AC-72, AC-82, AC-110 construction halves.)*
- [ ] **Step 20: `NotificationsSchemaEngineTest`.** Under the hold only the "refuses to reach the engine" case runs. Written for G-1, with explicit ids and connection B calling the **real** handler:
  - **AC-17:** two simultaneous activations give one winner;
  - **AC-26:** `welcome` against a raw `WELCOME` on the real collation is refused by the key (the stand-in proves the regex);
  - **AC-57:** two simultaneous requests with one key give one delivery, and the other returns it;
  - **AC-72:** two workers on one delivery give one driver call (spy count 1);
  - **AC-95 (notifications half):** deleting a delivery nulls the in-app row's reference on the engine;
  - **AC-98:** a signed `delivery_id` against unsigned is rejected by the engine;
  - generated-column uniqueness on `active_channel`; JSON validity; migrating twice is a no-op.
  - *(The `[ENGINE]` clauses of part 1: NOT VERIFIED under ENGINE_HOLD.)*
- [ ] **Step 21: documents and the final gate for part 1.**
  - `architect` applies section 4.3's §6.16 and §3.3 amendments; `docs/architecture.md` row 84 ("Notification") gains the as-built sentence; backlog notes (section 10).
  - **Gate (lead):** `composer check` with counts and `test` seconds compared with the base.
  - *(AC-107, AC-109 for five tables, AC-110, AC-111: the provisioning test asserts zero templates, configs and consents after `tenant:provision` on the fixture.)*

### PART 2: T-040b (new row)

#### Chunk F: inbox and retention

- [ ] **Step 22: `ListMyNotifications`, `MarkNotificationRead`, `MarkAllNotificationsRead`** (key `notifications.inbox.read`; the recipient is always the actor).
  - **AC-87:** only the actor's own rows; newest first, keyset (`created_at`, `id`); the `unread` filter; the total unread count; another employee's id is `not_found`, never forbidden.
  - **AC-88:** `read_at` is set once; a repeat is unchanged and succeeds; mark-all touches only the actor's unread rows; no audit row.
  - *(AC-87, AC-88.)*
- [ ] **Step 23: `PurgeDeliveries` plus `notifications:purge-deliveries`.**
  - **AC-94:**
    - terminal rows older than the period are deleted in batches;
    - `queued` rows older than the period become `failed expired` (through `DeliveryWriter`) and survive one cycle;
    - fresh rows are untouched;
    - one audit row (`notifications_deliveries_purged`, counts in `after`, `subject_id` = the cutoff date) per tenant run that changed anything; none when nothing changed;
    - retention 6 and 3,651 fail to start.
  - **AC-90, AC-95 stand-in:** the in-app row survives with `delivery_id` null.
  - *(AC-90, AC-94, AC-95 stand-in half.)*
- [ ] **Step 24: chunk F close.**
  - `QaNotificationsSourceRulesTest` gains two rules:
    - `delete(` on `deliveries` appears only in `PurgeDeliveriesHandler`;
    - the inbox slices filter by the actor and by nothing a caller supplies (`recipient_employee_id` is never read from input).

    Red-first with planted files.
  - The authorization matrix gains the three inbox slices: `inbox.read` grants only the actor's own inbox, and no key grants another employee's.
  - **Gate (lead).**
  - *(AC-87 and AC-104 inbox half, AC-82 purge half.)*

#### Chunk G: the owed column

- [ ] **Step 25: `004006` (expand) and `004007` (contract), with `OwedDeliveryColumnMigrationsTest`.**
  - **AC-96:** on a fixture tenant already holding communications, after `004006` the column exists, is BIGINT UNSIGNED and nullable, every row is null, an index exists and no key exists.
  - **AC-97:**
    - **red-first:** a seeded non-null `delivery_id` with no delivery makes `004007` refuse, naming the table, the column and `1 row(s)` (never an id), and changes nothing;
    - when clean, the key exists with SET NULL; a re-run is a no-op;
    - the two steps are separate files; `@phase contract`; `is_destructive = false`.
  - **AC-98:** the declarations are read from both files.
  - Republish; `.env.example` 81 → 83; `IdempotencyKeysSchemaTest` top; `TenantBaselineBuildsTest` +1; `BranchForeignKeysContractRunTest` gains one run (section 9); `CustomersTenantSchemaTest:197-203` absence becomes presence (correction 8).
  - **Mutation S1:** combine the key into `004006`, and `SourceRules` reports it.
  - *(AC-96 to AC-98, AC-109 owed column.)*
- [ ] **Step 26: `DatabaseDeliveryLinks` and `LogCustomerCommunication`.**
  - **AC-99:**
    - `delivery_not_found`, `delivery_customer_mismatch` and `delivery_channel_mismatch` (each pair from the mapping, plus `push`/`in_app` refused);
    - direction `in` is refused;
    - a non-integer or 0 is refused by customers' own validation, naming the field;
    - the shared-lock call is observed; outside a transaction it throws.
  - **AC-100:** two communications can reference one delivery.
  - **AC-101:** a merge keeps `delivery_id` (`MergeCustomersTest` gains a case).
  - **AC-102:** a sent customer delivery writes no communication.
  - **AC-95 stand-in:** purging nulls the communication's reference.
  - The `LogCustomerCommunicationTest` whole-row edits are listed in section 9.
  - *(AC-95, AC-99 to AC-102.)*

#### Chunk H: HTTP, scans, engine, close-out

- [ ] **Step 27: routes and controllers** (`packages/notifications/routes/api.php`, `modules/host/app/Http/Controllers/Notifications/*`). Every admin operation and inbox operation gets one route with `RequireTenantToken` plus `RequireTenantPermission:<key>`, and errors through `ApiResponse`. The provider implements `DeclaresErrorCodes` for every reason code.
  - **AC-106:** no token gives 401 before the handler (handler spy count 0); a token without the key gives `not_permitted`.
  - **AC-20 HTTP half:** the sentinel harness gains every response body and header.
  - **AC-105:** no route reaches `NotificationSender`. The route list is scanned for a controller importing it.
  - Delete `QaNotificationsNoHttpSurfaceTest`. Regenerate the committed OpenAPI document. Add the new placeholders and the write recipes to the route sweeps (section 9).
  - *(AC-20 HTTP half, AC-87 over HTTP, AC-105, AC-106.)*
- [ ] **Step 28: scans, engine, documents, final.**
  - `QaNotificationsSourceRulesTest` gains `DeliveryLinks`' `sharedLock`.
  - The engine test gains:
    - AC-95: the communication's key nulls on the engine;
    - AC-97: the key on the engine;
    - AC-99: a purge waits behind a shared lock held by `LogCustomerCommunication`.
  - `architect` applies the §6.9 note.
  - **Gate (lead).**
  - *(AC-95, AC-97 `[ENGINE]` halves NOT VERIFIED; AC-110 final.)*

## 7. Test strategy

- **Unit (pure):**
  - `InputTest`, `ChannelConfigInputTest`, `TemplateSyntaxTest`, `TemplateInputTest`;
  - `VariableRulesTest`, `RendererTest`, `PlainTextTest`, `MaskingTest`;
  - `DeliveryStateMachineTest`, `BackoffTest`, `DailyLimitTest`, `ConsentPolicyTest`, `RecipientRulesTest`, `PayloadHashTest`;
  - `NotificationsEnumsTest`, `NotificationsShapesTest`.
- **Integration (SQLite stand-in through `AccessTenantFixture`, in-memory bus):**
  - one file per slice;
  - `CredentialVaultTest`, `LogDriverTest`, `NullDriverTest`, `DriverRegistryTest`;
  - `NotificationsPipelineTest` (step 16), `NotificationsSentinelTest`, `NotificationsAuthorizationMatrixTest`;
  - `NotificationsTenantSchemaTest`, `NotificationsWidthsTest`;
  - customers: `DatabaseCustomerContactsTest` (part 1) and the edited communication tests (part 2).
- **Static (host):**
  - `QaNotificationsScopeGuardTest` (AC-1);
  - `QaNotificationsSourceRulesTest`;
  - `QaNotificationsNoHttpSurfaceTest` (part 1);
  - deptrac with the demo negative control.
- **Engine:** `NotificationsSchemaEngineTest`, skipped under the hold, **NOT VERIFIED**.
- **Mutations:** N1 to N16 and S1. They cover the parser traps, escaping, single pass, bidi, the state matrix, consent order, attempt counting, the driver-before-config check, daily reset, the dispatcher's due rule, authorization order and the sentinel scan's reach.

| ACs | Proved by |
|---|---|
| 1 | step 3 |
| 2 | step 16 (+ AC-1); whole-suite offline NOT VERIFIED (correction 16) |
| 3–5 | steps 3, 5, 11 |
| 6–11 | steps 4, 10, 15, 19 |
| 12–18, 22–23 | steps 5, 11 |
| 17 race | `[ENGINE]` step 20 |
| 19–21 | steps 10, 11, 15, 18 (HTTP half step 27) |
| 24 | steps 14, 15 |
| 25 | step 11 |
| 26–38 | steps 6, 12 (26 collation `[ENGINE]` step 20) |
| 39–48 | steps 7, 14 |
| 49–56 | step 14 |
| 57 | step 14; `[ENGINE]` step 20 |
| 58–60 | step 16 |
| 61, 69 | step 13 |
| 62–68 | steps 8, 14, 15 |
| 70–83, 86, 89 | steps 8, 15 (72 `[ENGINE]` step 20); 79 step 17 |
| 84–85 | step 14 |
| 87–88, 90 | steps 22, 23 |
| 91–93 | step 17 |
| 94–95 | steps 23, 26; `[ENGINE]` steps 20, 28 |
| 96–102 | steps 25, 26 (97, 98 `[ENGINE]` halves) |
| 103 | every write slice's test; purge step 23 |
| 104 | step 9 (grown by each slice) |
| 105 | steps 14, 19, 27 |
| 106 | step 27 |
| 107–108 | steps 2, 3 |
| 109 | steps 21, 28 |
| 110 | steps 1, 19, 21, 28 |
| 111 | step 21 |

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
| `packages/customers/src/CustomersServiceProvider.php` | bind `CustomerContacts` | 13 |
| `modules/host/tests/Feature/BranchForeignKeysContractRunTest.php:367` (and its run helpers) | one more contract step means one more run ("five runs" becomes six), plus a `t040Contract()` finder by name | 25 |
| `packages/kernel/tests/Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php` | verify only: it derives contract versions from the catalogue | 25 |
| `packages/customers/tests/Schema/CustomersTenantSchemaTest.php:197-203` | absence becomes presence of `delivery_id` (correction 8) | 25 |
| `modules/host/tests/Engine/CustomersSchemaEngineTest.php` | column list, if it lists `customer_communications` columns | 25 |
| `packages/customers/src/Features/LogCustomerCommunication/LogCustomerCommunicationHandler.php` | `delivery_id` in `FIELDS`, checked via `DeliveryLinks` | 26 |
| `packages/customers/tests/Features/LogCustomerCommunication/LogCustomerCommunicationTest.php`, `packages/customers/tests/Features/MergeCustomers/MergeCustomersTest.php`, `packages/customers/tests/Support/CustomersSeed.php` | whole-row expectations gain `delivery_id: null`; new AC-99 to AC-101 cases | 26 |
| `modules/host/tests/Feature/QaAccessHttpSurfaceTest.php:433-437`, `QaAccessGuardIsolationHttpTest.php:269` | new placeholders (`{config}`, `{template}`, `{delivery}`, `{notification}`, `{customer}`) in the substitution lists | 27 |
| `modules/host/tests/Feature/QaRouteCrossTenantSweepTest.php` | a recipe for each new non-GET tenant route; by-id GET routes classified | 27 |
| `modules/host/tests/Feature/OpenApiCompletenessTest.php` + the committed OpenAPI document | regenerated | 27 |
| `modules/host/tests/Feature/QaNotificationsNoHttpSurfaceTest.php` | deleted (routes now exist) | 27 |
| `docs/database.md`, `docs/architecture.md` | section 4.3 (by `architect`) | 21, 28 |

Anything red that is not in this table means stop and report to `architect`.

## 10. Follow-ups for `lead` (backlog rows or notes, not built here)

- **T-040b** (part 2), if `lead` accepts the split.
- **The real-driver rows** (spec follow-ups 1 to 3) inherit risk 2: move the network call out of the config lock and add an attempt lease. They also widen `ProviderPolicy::AVAILABLE`.
- **Employee contact contract** after T-038 merges (correction 6). Until then employees are in-app only.
- **FP-319:** the scheduler; this task adds two scheduled commands to its list.
- **Roles task:** grant the ten notification keys. Check whether the owner role acquires new keys automatically (the T-032 Ruling 5 question).
- **Owner:** whether provider credentials need a key separate from `APP_KEY` (0.3, a new secret).
- **`ba`:** corrections 1 to 24.
- **FP-433:** another copy of the support helpers.
- The spec's twelve proposed follow-up rows, unchanged.

## Questions for the human

None blocking; team mode applies. Owner-visible items for `lead` to carry:
1. **Credential key.** The default applied is the existing `APP_KEY`, so no new secret is needed. A separate key would be a new secret and an escalation (0.3).
2. **No scheduler in production (FP-319).** Retries and lost-event recovery are built but inert in production until it lands (risk 1).
3. **Employees receive in-app notifications only** until an employee contact contract exists (correction 6).
4. **Nested CSS in e-mail templates** is refused by the minimal template language (correction 22).
