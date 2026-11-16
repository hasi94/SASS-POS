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

D-10 | Is a config row needed for `in_app`? | No. In-app is always available; a config row for it is allowed but optional. | It is a database write, not a provider; requiring configuration would add a way for alerts to silently disappear. | Reversible: yes.

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

D-23 | Auto-log to `customer_communications`. | Not done. The link column and its validation are built; writing a row on send is a follow-up. | It needs a published customers write contract that this task cannot verify exists on this stale base, and it is a product choice (owner question 6). | Reversible: yes.

D-24 | Is there an HTTP "send" endpoint? | No. Sending is only through the in-process published contract, as a system actor. Admin handlers cover channels, templates, consents, delivery reads, retry and the employee inbox. | An endpoint that sends arbitrary messages is an abuse and spam vector with no authorisation model to bound it. | Reversible: yes.

D-25 | Schema amendments to the schema authority. | `deliveries` gains `recipient_type`, `recipient_id`, `category`, `locale`, `subject`, `body`, `idempotency_key` (unique, binary collation), `payload_hash`, `next_attempt_at`, `last_attempt_at`, `delivered_at`, `error_code`, `updated_at`; status enum gains `suppressed`; `channel_configs` gains `sent_today_date`; `notifications` gains `delivery_id` (unique, FK `ON DELETE SET NULL`); new `notification_consents`. `ba` cannot edit `docs/database.md`; `architect` applies it (AC-109). | The documented `deliveries` has no way to hold a recipient reference, an idempotency key, a retry time or the snapshot; without them AC-56, AC-75 and AC-47 cannot be met. Every addition is additive. | Reversible: yes.

D-26 | `deliveries.id` type. | `BIGINT UNSIGNED AUTO_INCREMENT`, as §6.16 and T-028 F-2 require. | The foreign key from `customer_communications.delivery_id` must match exactly. | Reversible: no once data exists (changing a primary key type); chosen deliberately.

D-27 | Foreign key action for `delivery_id`. | `ON DELETE SET NULL` for both `customer_communications.delivery_id` and `notifications.delivery_id`. | The documented 90-day retention deletes deliveries; `RESTRICT` would make the purge fail or force deleting communications. A communication keeps its own content. | Reversible: yes, a later migration changes the action.

D-28 | Expand and contract for the owed column. | Two forward-only files: expand adds `delivery_id BIGINT UNSIGNED NULL` plus an index (after the `deliveries` create migration); contract adds the FK with a data-clean pre-check. No backfill, no `NOT NULL`. | T-027 D-3/D-4 and T-028 D-6 precedent and the codebase's migration source rules: an FK onto an existing table is always a separate, later step. | Reversible: yes.

D-29 | Outbox event shape. | Type `notification.delivery_requested`, aggregate `delivery`, payload the delivery id only; consumer idempotent by event id and delivery status; a dispatch command re-emits events for due `queued` rows. | Personal data and credentials never travel on the bus (T-018 only needs an id); recovery from a lost or dead-lettered event needs no new table. | Reversible: yes.

D-30 | Retention behaviour. | 90 days default for terminal deliveries (documented); stale `queued` rows are first marked `failed` (`expired`), then purged next cycle; in-app `notifications` are not purged. | §6.16 documents 90 days for deliveries and says nothing for in-app. Silently deleting a queued message would lose an undelivered alert. | Reversible: yes.

D-31 | Default locale fallback. | Exact locale, else `en`, else refuse. | No tenant locale exists; `en` is the conventional platform default. | Reversible: yes.

D-32 | Known duplicate-send window. | Documented, not eliminated: a crash after the driver accepts and before the row updates may resend once; mitigated by passing the delivery id as the client reference. | Exactly-once is impossible across a network call (T-018 AC-23 reasoning); stating it avoids a false guarantee. | Reversible: n/a.

D-33 | Customer merge. | A communication's `delivery_id` is kept when T-028 reassigns it; the customer match rule applies at create only. | Merge is a bookkeeping move, not a new link. | Reversible: yes.

D-34 | Control and bidi characters in plain-text values. | Stripped (not escaped) for email subjects, SMS, WhatsApp, push and in-app text. | Prevents header injection, line-spoofing and bidi-spoofing; escaping would corrupt the plain text. | Reversible: yes.
