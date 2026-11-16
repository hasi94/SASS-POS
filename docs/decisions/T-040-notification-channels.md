# T-040 decisions: notification channels, templates and deliveries

Autonomous team-mode decisions by `ba` for `docs/specs/T-040-notification-channels.md`. Each is the most conventional, safest, reversible choice. Format: `D-n | Question | Decision | Reason | Reversible`. No escalation trigger was hit: the hard constraint (no real send, no credentials, no paid API) is honoured by design, and no legal choice was made that cannot be safely defaulted (the consent default is fail-closed and is surfaced as owner question 1).

D-1 | What does FP-295 build? | Four tables from §6.16 (`notification_templates`, `channel_configs`, `notifications`, `deliveries`), one new table (`notification_consents`, D-15), one owed column. Not built: `notification_rules` (G-18, needs the event catalogue and a rules engine), `webhook_endpoints` and `webhook_deliveries`. | The row says "channels and templates"; the spec brief names channels, templates, deliveries, queued sending. Rules and webhooks are separable and would double the surface. | Reversible: yes, each is an additive table later.

D-2 | Real providers. | Not built. Only a `log` driver and a `null` driver, plus the in-app driver (a database write), behind a driver interface and registry. Real drivers are follow-up rows needing the owner's credentials. | Hard constraint from the lead: no real email or SMS, no credentials, no paid API. | Reversible: yes, a driver is registered without changing the pipeline.

D-3 | The `webhook` template channel value. | Stays in the enum (documented), refused on write (`channel_not_supported`). | Dropping it would force an enum migration later; accepting it would create templates nothing can send. | Reversible: yes.

D-4 | Provider whitelist by data. | `channel_configs.provider` accepts only `log`, `null` (and `in_app` for the in-app channel); anything else is `provider_not_available`. | A real provider cannot be switched on by data before its driver and credentials exist; the guard is testable. | Reversible: yes, a follow-up widens the list when it ships a driver.

D-5 | One call, one channel, one recipient. | The send contract takes a single channel and recipient. Fan-out (several channels, a role) is the caller's loop or the later rules engine. | Keeps a delivery row equal to one attempt chain and the idempotency key simple; the rules task owns fan-out. | Reversible: yes.

D-6 | Recipient types. | `employee` and `customer` only; a raw typed address and supplier recipients are refused. | §6.16's rules mention supplier and webhook, but no supplier table exists and a raw address bypasses the consent check. | Reversible: yes, additive.

D-7 | Message categories. | `transactional`, `marketing`, `system`. `system` only to employees; `transactional` and `marketing` only to customers. | Consent differs by category, and an internal alert must never be a way to message a customer without consent. | Reversible: yes.

D-8 | How to exercise encrypted credentials with no real secret. | The `log` driver declares one optional, inert credential field `sandbox_token`; tests use a clearly fake sentinel. Write-only API; reads show only `has_credentials`. Ciphertext from the application's authenticated encryption with a server-side key; mechanism left to `architect`. | The brief requires the secret path to be specified and testable, with no real secret. | Reversible: yes; removing the field is a code change.

D-9 | New channel config default. | `is_active = false`: inert until an admin activates it. | Safest: a half-configured channel cannot send. | Reversible: yes.

D-10 | Is a config row needed for `in_app`? | No. In-app is always available. **Revision 2 (D-48): a config row for it is not "allowed but optional", it is refused `channel_not_configurable`.** | It is a database write, not a provider; requiring configuration would add a way for alerts to silently disappear. | Reversible: yes.

D-11 | Active configs per channel. | At most one active per channel, one row per (channel, provider). | Avoids routing ambiguity; failover between providers is a later feature. | Reversible: yes.

D-12 | Template language. | Only `{{name}}` placeholders; no expressions, sections, includes, dotted names, filters or raw form; no literal `{{`; template text is trusted (written by a `templates.manage` holder), variable values are untrusted. Substitution is single pass, values never re-evaluated. | The smallest language that cannot become an injection or code-execution surface; the documented `variables` JSON declares names and types. The doc names no engine for notifications (print templates name handlebars; not reused here). | Reversible: yes, a richer language is additive.

D-13 | Template delete. | No delete; deactivate only. | `deliveries.template_key` is a name, not a foreign key; deleting would orphan history. | Reversible: yes.

D-14 | Render when? | At request time, stored as a snapshot on the delivery (`subject`, `body`). | A queued message must not change if the template is edited; retries resend the same text; invalid input is refused to the caller, not discovered in a worker. Cost: personal data in the row, covered by the 90-day retention and the `view_content` permission. | Reversible: yes, but moving to render-at-send changes semantics for queued rows.

D-15 | Where does consent live? | A new table `notification_consents` (customer, channel, category, status, source, note, recorded_by), one mutable row per triple, history in the audit log. | `customers` carries no consent or opt-out column (measured in §6.13 and T-028); the brief requires consent respected before any delivery. Owned by the Notification module, which already owns the policy. The architect amends `docs/database.md` §6.16. | Reversible: yes; a later customer-side flag can be mirrored in.

D-16 | Consent rule. | Marketing requires a recorded opt-in; no record means refused. Transactional is sent unless opted out; no record means allowed. Checked at request time and again before each attempt. Refusals are recorded as `suppressed` deliveries, not raised. | Fail closed for marketing is the conventional safe default; transactional receipts are expected; the re-check honours an opt-out that arrives while a message waits; recording the refusal gives an audit trail. Surfaced as owner question 1. | Reversible: yes, the owner can relax marketing to opt-out by data change in the rule.

D-17 | Blocked and inactive customers. | Receive transactional, never marketing. | Customer status governs sales, not whether a statement or notice can be sent; but marketing to a blocked customer is never appropriate. Owner question 4. | Reversible: yes.

D-18 | Inbound opt-out (STOP, unsubscribe link, provider callback). | Out of scope; staff record consent through the handler. | Needs real provider callbacks and signed links. Follow-up row. | Reversible: yes.

D-19 | Delivery status enum and moves. | Documented values plus `suppressed` (consent refused). `queued` to sent, failed, suppressed, delivered (in-app); `sent` to delivered or bounced; manual `failed` to `queued`. No `sending` state. `delivered` and `bounced` are only reachable through an internal transition no code calls in production yet. | Consent refusal is neither a failure of the provider nor a send, and the enum was only an add. A `sending` state would strand rows if a worker dies; a row lock gives the same protection. The callbacks are a follow-up. | Reversible: yes; adding a value is additive.

D-20 | Retry and back-off. | Max 5 attempts, exponential: base 60 s, cap 3,600 s, no jitter; transient vs permanent decided by the driver result; all configurable and clock-injected. | Mirrors T-018's relay shape (max 5, exponential, injectable clock). Jitter is a tuning concern for real providers. | Reversible: yes.

D-21 | Daily limit. | `sent_today` counts accepted sends in the current UTC day; reaching the limit defers (not fails) the delivery to the next UTC midnight and does not use an attempt; adds `channel_configs.sent_today_date` to make the reset deterministic. | The documented `sent_today` has no reset marker. Deferring loses nothing. UTC because no tenant timezone exists. Owner question 5. | Reversible: yes.

D-22 | Who sees message content. | `notifications.deliveries.view` sees metadata and a masked recipient; `notifications.deliveries.view_content` sees subject, body and full recipient. | Bodies hold personal data; least privilege. Owner question 7. | Reversible: yes.

D-23 | Auto-log to `customer_communications`. | Not done. The link column and its validation are built (in T-040b); writing a row on send is a follow-up. | It is a product choice (owner question 6). **Revision 2 (D-36): the original reason, a customers contract unverifiable on a stale base, was wrong; the write is the customers slice `LogCustomerCommunication`, which has no published contract.** | Reversible: yes.

D-24 | Is there an HTTP "send" endpoint? | No. Sending is only through the in-process published contract, as a system actor. Admin handlers cover channels, templates, consents, delivery reads, retry and the employee inbox. | An endpoint that sends arbitrary messages is an abuse and spam vector with no authorisation model to bound it. | Reversible: yes.

D-25 | Schema amendments to the schema authority. | `deliveries` gains `recipient_type`, `recipient_id`, `category`, `locale`, `subject`, `body`, `idempotency_key` (not indexed; **revision 2, D-39: uniqueness is on `idempotency_key_hash`, not a binary collation**), `ref`, `in_app`, `dispatched_at`, `payload_hash`, `next_attempt_at`, `last_attempt_at`, `delivered_at`, `error_code`, `updated_at`; status enum gains `suppressed`; `channel_configs` gains `sent_today_date`; `notifications` gains `delivery_id` (unique, FK `ON DELETE SET NULL`); new `notification_consents`. `ba` cannot edit `docs/database.md`; `architect` applies it (AC-109). | The documented `deliveries` has no way to hold a recipient reference, an idempotency key, a retry time or the snapshot; without them AC-56, AC-75 and AC-47 cannot be met. Every addition is additive. | Reversible: yes.

D-26 | `deliveries.id` type. | `BIGINT UNSIGNED AUTO_INCREMENT`, as §6.16 and T-028 F-2 require. | The foreign key from `customer_communications.delivery_id` must match exactly. | Reversible: no once data exists (changing a primary key type); chosen deliberately.

D-27 | Foreign key action for `delivery_id`. | `ON DELETE SET NULL` for both `customer_communications.delivery_id` and `notifications.delivery_id`. | The documented 90-day retention deletes deliveries; `RESTRICT` would make the purge fail or force deleting communications. A communication keeps its own content. | Reversible: yes, a later migration changes the action.

D-28 | Expand and contract for the owed column. | Two forward-only files: expand adds `delivery_id BIGINT UNSIGNED NULL` plus an index (after the `deliveries` create migration); contract adds the FK with a data-clean pre-check. No backfill, no `NOT NULL`. | T-027 D-3/D-4 and T-028 D-6 precedent and the codebase's migration source rules: an FK onto an existing table is always a separate, later step. | Reversible: yes.

D-29 | Outbox event shape. | Type `notification.delivery_requested`, aggregate `delivery`, payload the delivery id only; consumer idempotent by event id and delivery status; a dispatch command re-emits events for due `queued` rows. | Personal data and credentials never travel on the bus (T-018 only needs an id); recovery from a lost or dead-lettered event needs no new table. | Reversible: yes.

D-30 | Retention behaviour. | 90 days default for terminal deliveries (documented); stale `queued` rows are first marked `failed` (`expired`), then purged next cycle; in-app `notifications` are not purged. | §6.16 documents 90 days for deliveries and says nothing for in-app. Silently deleting a queued message would lose an undelivered alert. | Reversible: yes.

D-31 | Default locale fallback. | Exact locale, else `en`, else refuse. | No tenant locale exists; `en` is the conventional platform default. | Reversible: yes.

D-32 | Known duplicate-send window. | Documented, not eliminated: a crash after the driver accepts and before the row updates may resend once; mitigated by passing the delivery id as the client reference. | Exactly-once is impossible across a network call (T-018 AC-23 reasoning); stating it avoids a false guarantee. | Reversible: n/a.

D-33 | Customer merge. | A communication's `delivery_id` is kept when T-028 reassigns it; the customer match rule applies at create only. | Merge is a bookkeeping move, not a new link. | Reversible: yes.

D-34 | Control and bidi characters in plain-text values. | Stripped (not escaped) for email subjects, SMS, WhatsApp, push and in-app text. | Prevents header injection, line-spoofing and bidi-spoofing; escaping would corrupt the plain text. | Reversible: yes.

## Revision 2 (after the architect's check against the code, plan section 0.2)

The lead accepted the architect's split. The architect's resolution was adopted for each correction unless stated. "Corr n" is the plan's correction number.

D-35 | Split of the task (corr 24). | T-040 keeps AC-1 to 86, 89, 91 to 93, the permission, audit (twelve actions), schema and gate criteria, and new AC-112 to AC-119 (106 criteria). T-040b (`docs/specs/T-040b-notification-inbox-retention-routes.md`, own decisions file) takes the inbox reads (old 87, 88, 90 as B1 to B3), the purge (94, 95 as B4, B5), the owed column (96 to 102 as B6 to B12), the routes (106 as B13) and new B14 to B17. T-040 keeps its AC numbers (gaps are listed in its table); T-040b is numbered B1 to B17. Customers and consent stay in T-040 because without customers the log and null drivers have no reachable recipient and a customer send without the consent check is not safe to merge. | The lead decided it; the routes force six route sweeps and the owed column edits customers tests. | Reversible: yes.

D-36 | The customers package exists on this base (corr 1). | Assumption 1, "Current behaviour" and Dependencies are corrected; Groups G and the owed column are not blocked. D-23's reason is corrected: the write is the customers slice `LogCustomerCommunication`, which has no published contract. The decision itself (no automatic logging) stands. | Measured by the architect: `packages/customers`, `create_customer_communications_table`. | Reversible: yes.

D-37 | Audit action names (corr 2). | Snake_case, module-prefixed, one token per handler, module `notifications` passed separately: `notifications_channel_created`, `_updated`, `_credentials_changed`, `_activated`, `_deactivated`, `_deleted`, `notifications_template_created`, `_updated`, `_activated`, `_deactivated`, `notifications_consent_recorded`, `notifications_delivery_retried`, and `notifications_deliveries_purged` (declared by T-040b). The writer refuses undeclared actions. | The built convention (`sales_sale_voided`, `pricing_tax_created`); the dotted names of revision 1 would be refused. | Reversible: yes (names are catalogue data), cheap before first release.

D-38 | Outbox aggregate id (corr 3). | `deliveries` gains `ref CHAR(26)`, a unique server ULID. The event is `notification.delivery_requested`, `aggregate_type = delivery`, `aggregate_id = ref`, `payload = {"delivery_id": <int>}`. | The outbox writer refuses a non-ULID aggregate id; the bigint id stays the key the other tables reference. | Reversible: yes.

D-39 | Idempotency uniqueness without a collation (corr 4). | Unique index on `idempotency_key_hash CHAR(64)`, the lower-case hex SHA-256 of the raw key; the raw key sits in `idempotency_key VARCHAR(100)` without an index. | Migration source rules forbid naming a collation; a digest of the bytes keeps `a` and `A` distinct on any collation. | Reversible: yes.

D-40 | Which employees receive in-app alerts (corr 5). | Those live and in the platform's acting set (`active` or `probation`) per `RoleHolders::actingAmong`, never a copied list. Not live: `recipient_not_found`. Live but not acting (on leave, suspended, resigned, terminated): `recipient_inactive`. **On-leave staff therefore do not receive in-app alerts.** | One authority for "who is working"; an alert to someone away is noise, and the caller can re-send. Owner question 13 asks if alerts should queue instead. | Reversible: yes.

D-41 | Employees on non-in-app channels (corr 6). | An employee recipient is in-app only; any other channel is refused `channel_not_supported_for_recipient` until T-038 publishes employee contact data (follow-up row 13). | `employees` has no email or phone, so there is no address to send to. | Reversible: yes.

D-42 | Customer contact lookup (corr 7). | A new published contract `CustomerContacts` (by customer id: id, liveness, merged-into id, status, email, phone; a merged or deleted customer is still answered with liveness false), implemented and bound in the customers package. A customers-package change is in scope in T-040. | None exists, and the Notification package may not read customers tables. | Reversible: yes.

D-43 | Request fields and payload hash (corr 9). | The request adds `channel`, and the in-app-only `priority`, `data`, `action_url` (refused `field_not_supported_for_channel` on other channels). The payload hash covers every request field except `idempotency_key` and `source_event_id`, in canonical form. `deliveries.in_app JSON NULL` carries the in-app fields from request to attempt. | They were missing from AC-49; without storing them the attempt cannot write the in-app row. | Reversible: yes.

D-44 | Further schema additions (corr 10 and the others). | `channel_configs.credentials_updated_at` (AC-20 exposes it), the stored active-channel marker (uniqueness backstop for AC-17), `deliveries.dispatched_at`, `deliveries.ref`, `deliveries.in_app`, `deliveries.idempotency_key_hash`. D-25 and AC-109 list them for the architect's `docs/database.md` edit. | Needed by AC-17, AC-20, AC-54, AC-56, AC-116. | Reversible: yes.

D-45 | Retry wake-up and lost-event recovery (corr 11). | `deliveries.dispatched_at` plus `redispatch_after_seconds` (default 300, 1 to 86,400). A due `queued` row is re-emitted only if never dispatched, or `dispatched_at < next_attempt_at`, or `dispatched_at` older than `redispatch_after_seconds`, re-tested under the row lock. The command is also what wakes transient retries and daily-limit deferrals. **FP-319: no scheduler runs in production**, stated as an assumption: until one exists these do not fire by themselves. | Without it every run re-emitted every in-flight row; the consumer acknowledges not-yet-due rows, so nothing else re-delivers them. | Reversible: yes.

D-46 | Credential encryption key (corr 12). | Laravel's encrypter (authenticated AES) keyed by the existing `APP_KEY`, with `APP_PREVIOUS_KEYS` honoured, confined to one class so the key choice is a one-file change. No new secret is needed, so no escalation. A credentials write with no key configured is refused `encryption_unavailable`. "Undecryptable" means it matches neither the current nor a previous key. A separate key (as `docs/database.md` §4.4 uses for tenant database credentials) is an owner-visible option and would be a new secret. Owner-visible risk: losing `APP_KEY` makes stored credentials unreadable (owner question 10). Tests generate a key; nothing reads `.env`. | Precedent `plugin_settings`; lowest cost and fully reversible while no real credential exists. | Reversible: yes (re-encrypt on key change).

D-47 | One door per rule (corr 13). | `is_active` of configs and templates changes only through activate and deactivate operations; an update naming it is refused `not_updatable_here`; repeating a state is `already_active` / `already_inactive`. Create may carry `is_active`: config default `false` (D-9), template default `true`. | T-032 F-12 precedent; revision 1 gave no template default, and a template that is inert on creation is surprising. | Reversible: yes.

D-48 | `in_app` config rows (corr 14). | Creating a config for `in_app` is refused `channel_not_configurable`; `in_app` is not a provider value in AC-3. | "Allowed but optional" left undefined what an inactive or rate-limited in-app row does; in-app must always be on (D-10 corrected). | Reversible: yes.

D-49 | Proof of "nothing real is sent" (corr 16). | AC-2 becomes AC-1's source scan plus one end-to-end pipeline test with outbound HTTP blocked and mail faked; a whole-suite offline run is NOT VERIFIED unless the lead runs one. Template delete is stated as "there is no delete operation" (corr 15, AC-38). | A property of the environment cannot be an automated test; a slice that only refuses is dead code. | Reversible: yes.

D-50 | `ON DELETE SET NULL` is a first (corr 17). | The decision of D-27 stands for `notifications.delivery_id` (T-040, AC-117) and `customer_communications.delivery_id` (T-040b). `docs/database.md` §3.3 needs a third clause; the architect writes it (AC-109). | Shipped keys are RESTRICT or CASCADE; the purge needs neither to block nor to delete the referencing row. | Reversible: yes.

D-51 | Consumer idempotency (corr 18). | By the delivery's own status and `next_attempt_at` read under a row lock, not by a processed-event store. | None exists and none is needed. | Reversible: yes.

D-52 | Request-time check order (corr 19). | Adopted verbatim from Ruling 7 as AC-114: shape; replay; recipient compatibility; recipient existence and eligibility; template; variables and rendering; channel config; consent (suppressed row); address (failed row); queued row plus outbox row. Replay precedes business checks. | Several rules can fail at once and the outcome kind (exception, suppressed, failed) must be deterministic. | Reversible: yes.

D-53 | `delivery_expired` (corr 20). | A `failed` delivery with `error_code = expired` (written by T-040b's purge) is refused on manual retry. | AC-79 was underspecified. | Reversible: yes.

D-54 | `recipient` for in-app (corr 21). | `deliveries.recipient` is nullable: null for `in_app` and for `recipient_address_missing`; masked reads keep null. | In-app has no address. | Reversible: yes.

D-55 | Nested CSS in email templates (corr 22). | Not changed: a stray `}}` is refused (AC-33), so nested CSS like `@media{a{b:c}}` cannot be stored. Owner-visible (question 11). | The smallest safe template language (D-12) outweighs the rare case; a workaround exists (spaces, flat CSS). | Reversible: yes.

D-56 | Customer merge and consents (corr 23). | Merging does not move consents (AC-119); a merged-away source is refused `recipient_merged`, so the target's own consents govern and marketing fails closed until it opts in. Owner-visible (question 12). | Fail-closed is the safe default; copying an opt-in across identities is a legal question. | Reversible: yes.

D-57 | Owner-visible items recorded. | (a) No scheduler in production (FP-319), D-45. (b) Losing `APP_KEY` makes credentials unreadable, D-46. (c) Before a real driver ships, the send call must move out of the row-locking transaction (follow-up row 14; AC-78). (d) Nested CSS refused, D-55. (e) Merging customers does not move consents, D-56. (f) A separate credential key is an option, D-46. | The lead asked these to be visible to the owner; they are in the spec's Owner-visible questions 8 to 13. | Reversible: n/a.

D-58 | The `log` and `null` driver set and the `in_app` credentials rule. | The null driver declares no credentials; in-app has no driver at all (it is a writer called inside the attempt). AC-18 no longer mentions an in-app driver. | Follows from D-48 and the plan's Ruling 3. | Reversible: yes.
