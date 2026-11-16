# T-040: Notification channels, templates and deliveries

Backlog row FP-295 (Notification module, backend). Written autonomously (team mode): every question that would have blocked is resolved in `docs/decisions/T-040-notification-channels.md` as D-n and cited here. Sources read: `CLAUDE.md`, `docs/architecture.md`, `docs/database.md` §6.16 (the schema authority) and the T-028 "As built" note, `docs/patterns.md`, `docs/backlog.md` (FP-295 row), `docs/specs/T-018-event-bus-outbox.md`, `docs/specs/T-019-plugin-framework.md`, `docs/specs/T-028-customers.md`, and `docs/specs/T-032-chart-of-accounts.md` as the style model.

HARD CONSTRAINT, restated because it governs everything below: this task must never send a real email or SMS, never need a credential, and never call a paid or third-party API. The only outbound drivers built are a `log` driver and a `null` driver; the in-app channel is a database write. Real provider drivers are follow-up rows that need the owner's credentials (AC-4, AC-5, "Proposed follow-up rows").

## Problem / goal

Modules that need to tell someone something (a receipt to a customer, a low-stock alert to a manager) have no way to do it. `docs/database.md` §6.16 documents the tables (`notification_templates`, `channel_configs`, `notifications`, `deliveries`) and nothing is built. T-028 also left a debt: `customer_communications.delivery_id` was deferred until `deliveries` exists (T-028 AC-36, D-6).

Goal: a Notification module in which (1) a calling module asks for "this template, this recipient, these variables" through one published contract; (2) the request is validated, checked against the recipient's consent, rendered with safe escaping, recorded as a `deliveries` row and queued through the existing outbox and event bus; (3) a consumer hands it to a channel driver behind an interface, with retry, back-off and idempotency; (4) every attempt and its outcome is recorded; (5) tenant admins can configure channels (secrets stored encrypted) and manage templates; (6) in-app notifications reach employees; (7) `customer_communications.delivery_id` exists and is linked.

## Users affected

- Tenant admin / manager: configures channels, edits templates, reads deliveries, retries failed ones.
- Employee: receives and reads in-app notifications.
- Customer: is the recipient of customer messages; their consent and opt-out are honoured. Nothing real is sent to them in this task.
- Developers of later modules (sales receipts, AR reminders, payroll, stock alerts, notification rules, webhook, real providers): consume the published contracts.
- Not affected: cashiers at the till; no sale flow changes.

## Current behaviour

Measured on the worktree for this task (a stale base: it holds the event bus, plugin SDK, access, kernel and organization packages, but no customers package; Assumption 1):
- No `notification_templates`, `channel_configs`, `notifications` or `deliveries` table exists. No Notification package exists.
- The outbox and event bus (T-018) exist: one outbox writer, a relay, consumer groups, idempotency by event id, dead-letter handling, no HTTP surface.
- `docs/database.md` §6.16 documents the four tables but no consent store: `customers` (§6.13) carries `phone` and `email` and no marketing or consent column, and no other table records opt-in or opt-out.
- `customer_communications.delivery_id` is not built (T-028 AC-36).

## User stories

- As a developer of another module, I want one published contract to request a notification by template key, recipient and variables, so that I never touch a channel, a driver or a provider.
- As a tenant admin, I want to configure which provider serves each channel and keep its credentials encrypted and unreadable afterwards, so that a leaked admin screen or log does not leak a secret.
- As a tenant admin, I want templates with variables per channel and locale, so that messages are consistent and editable without a release.
- As a customer, I want my opt-out respected before anything is sent to me, so that I am not messaged against my wishes.
- As a tenant admin, I want every attempt recorded with a status and reason, so that I can see what was sent, what failed and why, and retry what is safe to retry.
- As an employee, I want in-app notifications I can list and mark as read, so that I see alerts without email.
- As the owner of the platform, I want a log driver and no real sender, so that this task can be tested without credentials or cost.
- As the FP-295 successor (customer team), I want `customer_communications.delivery_id` linked to `deliveries`, so that a logged message points at the attempt that carried it.

## Acceptance criteria

Conventions for every criterion below. Refusals are whole-request: nothing is written, the response names the offending field and carries a stable snake_case reason code. "Live" means `deleted_at IS NULL` where the table has it. Timestamps are UTC and come from an injectable clock; no test waits in real time. Money does not appear in this task. Criteria marked `[ENGINE]` need a real MySQL or MariaDB engine (or real Redis) to prove and are recorded NOT VERIFIED until one runs them (T-018 D-1, T-028 D-15 precedent); everything else is provable on the SQLite stand-in and the in-memory bus.

### A. Scope guard: nothing real is sent

- AC-1: Given the whole package and its tests, When the source is scanned, Then it contains no SMTP, mail-facade, HTTP-client, socket, cURL or provider-SDK call and no `composer.json` dependency on a mail, SMS or messaging SDK. A mechanical test fails the build on any such reference, naming it.
- AC-2: Given the full test suite and the application booted with default configuration, When it runs, Then it completes with outbound network access unavailable, and no code path in this task opens a network connection.
- AC-3: Given `channel_configs.provider`, When a config is created or updated, Then the only accepted values are `log` and `null` (for `email`, `sms`, `whatsapp`, `push`) and `in_app` (for the `in_app` channel); any other value is refused `provider_not_available`. The refusal is by data, so no real send path can be switched on by configuration alone.
- AC-4: Given a developer wanting a real provider, When they implement the driver interface (AC-6) and register it, Then no change to the delivery pipeline, the contracts or the schema is required; this task ships no such driver and no stub that pretends to be one.
- AC-5: Given the `webhook` value in the template channel enum, When a template or config is created for it, Then it is refused `channel_not_supported`. The value stays in the enum so a later task adds no enum migration (D-3).

### B. Channel drivers

- AC-6: Given the driver interface, When it is read by another module, Then it exposes only: the channel and provider it serves, the credential fields it declares, and one send operation that takes an immutable outbound message (delivery id as client reference, recipient address, subject or null, body, sender address or null) and returns an immutable result with an outcome (`accepted`, `transient_failure`, `permanent_failure`), an optional provider reference and an error code and message. It never exposes a model class or a table (module boundary; deptrac clean).
- AC-7: Given the driver registry, When a delivery needs a driver, Then it is resolved by (channel, provider) from the active channel config; a registered driver is selected by configuration only, never by business code. A (channel, provider) with no registered driver fails the delivery `driver_not_registered` (permanent).
- AC-8: Given the `log` driver, When it sends, Then it writes exactly one structured log line carrying delivery id, channel, provider, template key and a masked recipient (email: first character, `***`, domain; phone: last two digits only), never the subject, the body, the credentials or the unmasked address, and returns `accepted` with provider reference `log:<delivery id>`.
- AC-9: Given the `null` driver, When it sends, Then it writes nothing anywhere and returns `accepted` with provider reference `null:<delivery id>`.
- AC-10: Given a driver that throws instead of returning, or returns an outcome outside the three allowed, When the pipeline handles it, Then the attempt is recorded as a `transient_failure` with error code `driver_error`, the stored error message is truncated to 500 characters and has any substring equal to a stored credential value replaced with `[redacted]`, and the exception never escapes to the queue consumer.
- AC-11: Given the in-app channel, When a delivery is for `in_app`, Then it is handled by the in-app driver (Group I), which writes a database row and calls no external system.

### C. Channel configuration and secrets

- AC-12: Given a valid `channel`, `provider`, optional `from_address`, `settings` (JSON object), `is_active` and `daily_limit`, When a channel config is created, Then a live row exists with a ULID id, `sent_today = 0`, and `is_active` default `false` (a new config is inert until explicitly activated, D-9). Only one config exists per (channel, provider); a second is refused `channel_config_exists` naming the first.
- AC-13: Given `channel`, `provider` or `is_active`, When the value is not exactly one of the lower-case documented values, or `is_active` is not a JSON boolean, Then it is refused; `Email`, ` email`, `"true"`, `1` are all refused and never coerced.
- AC-14: Given `from_address`, When the channel is `email`, Then it must be a syntactically valid single address of at most 191 characters, with no display name, no comma, no CR/LF and no control character; for `sms`, `whatsapp` and `push` it is optional, 1 to 50 characters, with no control character; it is null for `in_app`. Violations are refused whole and never trimmed or truncated.
- AC-15: Given `daily_limit`, When it is supplied, Then it is null (unlimited) or an integer from 1 to 1,000,000; zero, negative, fractional, numeric string and over-range are refused.
- AC-16: Given `settings`, When it is supplied, Then it is a JSON object of at most 20 keys, each value a string up to 200 characters, a boolean or an integer, serialised at most 4,000 characters; a nested object or array value is refused `settings_invalid`. `settings` never holds a secret; a key whose name contains `secret`, `password`, `token`, `key` or `credential` (case-insensitive) is refused `secret_in_settings`.
- AC-17: Given at most one config active per channel, When a second config for the same channel is activated while another is active, Then it is refused `channel_already_active` naming the holder; deactivating first is required. `[ENGINE]` for the two-connection race: of two simultaneous activations exactly one succeeds.
- AC-18: Given credentials, When they are supplied, Then they are accepted only as a JSON object whose keys are all in the driver's declared credential fields (AC-6); an undeclared key is refused `credential_field_unknown`; a missing required field is refused `credential_field_missing`. The `log` driver declares exactly one optional field, `sandbox_token`, which has no effect and exists so the encrypted-secret path is exercised without a real secret (D-8). The `null` and in-app drivers declare none, and credentials supplied for them are refused `credentials_not_supported`.
- AC-19: Given credentials are stored, When the row is read from the database directly, Then `credentials` holds only ciphertext produced by the application's authenticated encryption with a key from server-side configuration (never from the tenant database); the plaintext value never appears in any column. A test writes a clearly fake sentinel value and asserts it is absent from the stored column.
- AC-20: Given a stored credential, When any read, list, audit row, log line, exception message, outbox payload, delivery row or HTTP response is produced, Then it never contains the plaintext or the ciphertext; reads expose only `has_credentials` (boolean) and `credentials_updated_at`. The same sentinel test scans all of these.
- AC-21: Given the server key is missing or differs from the key that encrypted a stored credential, When a driver needs the credential, Then decryption fails loudly with `credentials_undecryptable`, the delivery fails permanently with that code, and no partial or garbled value is passed to a driver. Rotating the key is out of scope (follow-up).
- AC-22: Given an update body, When it names credentials, Then the stored value is replaced whole (write-only); omitting `credentials` leaves it unchanged; an explicit `null` clears it; an empty object is refused. Any update naming `id`, `sent_today`, `sent_today_date`, `created_at`, `updated_at` or an unknown key is refused naming every offending key.
- AC-23: Given a config, When it is deleted, Then it is allowed only while inactive (`config_active`), it is removed whole including its ciphertext, and a second delete is refused `config_not_found`.
- AC-24: Given a channel with no active config (other than `in_app`, which needs none, D-10), When a send is requested for it, Then it is refused `channel_not_configured` and nothing is written. Given the config is deactivated after a delivery was queued, When the attempt runs, Then the delivery fails permanently `channel_not_configured`.
- AC-25: Given two tenants, When each configures a channel, Then each uses only its own tenant database's config and credentials; a send in tenant A never reads tenant B's row (verified with two tenant fixtures).

### D. Templates and variables

- AC-26: Given `key`, `channel`, `locale`, `subject`, `body`, `variables` and `is_active`, When a template is created, Then a live row exists with a ULID id and `plugin_name = null`; (key, channel, locale) is unique, and a duplicate is refused `template_exists` naming the existing id. Proven on the stand-in and `[ENGINE]` on the real collation.
- AC-27: Given `key`, When it is submitted, Then it must match `^[a-z][a-z0-9_.]{0,79}$` exactly (lower case, never coerced, never trimmed), so a collation that ignores case cannot create two keys that collide; `Welcome`, `welcome ` and a 81-character key are refused.
- AC-28: Given `locale`, When it is submitted, Then it must match `^[a-z]{2}(-[A-Z]{2})?$` (`en`, `en-GB`); anything else is refused.
- AC-29: Given `channel`, When it is one of `email`, `sms`, `in_app`, `whatsapp`, `push`, Then it is accepted; `webhook` is refused (AC-5); anything else is refused.
- AC-30: Given `subject`, When the channel is `email` or `in_app` (where it is the title), Then it is required, 1 to 255 characters counted as characters, valid UTF-8, with no control character including CR and LF; for `sms`, `whatsapp` and `push` it must be null and a non-null value is refused `subject_not_supported`.
- AC-31: Given `body`, When it is submitted, Then it is required, valid UTF-8, 1 to 20,000 characters (`sms` and `whatsapp`: at most 1,600), not blank after trimming, with no NUL byte. Over-length input is refused, never truncated. The stored body is not trimmed or altered.
- AC-32: Given `variables`, When it is submitted, Then it is a JSON object (at most 30 entries) mapping a variable name matching `^[a-z][a-z0-9_]{0,39}$` to `{type, required, max_length}`, where `type` is one of `string`, `number`, `date`, `url`, `required` defaults to `true`, and `max_length` is an integer 1 to 1,000 (default 200) that applies to `string` and `url` only. Unknown keys inside an entry are refused.
- AC-33: Given the template syntax, When `subject` or `body` is stored, Then the only construct allowed is a placeholder `{{name}}` with optional spaces inside the braces. There are no expressions, no filters, no sections, no includes, no dotted names, no raw or unescaped form. Any other use of `{{` or `}}` (including `{{{x}}}`, `{{#x}}`, `{{>x}}`, `{{a.b}}`, `{{a|f}}`, an unclosed `{{`, a stray `}}`) is refused `malformed_placeholder` with the offending position. There is no way to write a literal `{{` (D-12).
- AC-34: Given a placeholder naming a variable not in `variables`, When the template is stored, Then it is refused `undeclared_variable` naming each. A declared variable that is never used is allowed.
- AC-35: Given a template update, When it is submitted, Then `key`, `channel` and `locale` are immutable (refused `not_updatable_here`), as are `id`, `plugin_name`, `created_at` and any unknown key, all named. `subject`, `body`, `variables` and `is_active` are updatable and re-validated by AC-30 to AC-34 on the stored-plus-supplied values.
- AC-36: Given a template, When it is deactivated, Then it stays readable, is no longer used for new sends (`template_inactive`, AC-52), and queued deliveries are unaffected because they hold a rendered snapshot (AC-47). Reactivation is allowed. Deactivating an inactive template is refused (no-op with a reason).
- AC-37: Given templates, When listed, Then they filter by `key` prefix, `channel`, `locale` and `is_active`, ordered by `key`, `channel`, `locale`, with paging (default 25, maximum 100); a limit of 0, 101 or non-integer is refused.
- AC-38: Given a template, When a delete is requested, Then it is refused `not_supported`: there is no delete, templates are deactivated only, because deliveries refer to a key by name (D-13).

### E. Rendering and escaping

- AC-39: Given a send request and a resolved template, When it is rendered, Then every required variable must be supplied (`missing_variable`), every supplied variable must be declared (`unknown_variable`, a typo is caught loudly), and each value must satisfy its declared type (`invalid_variable`). The whole request is refused naming every failing variable; nothing is written.
- AC-40: Given types, When a value is checked, Then `string` is a JSON string of valid UTF-8 within `max_length` characters, with no NUL; `number` is a JSON integer or a JSON string matching `^-?\d{1,18}(\.\d{1,4})?$` and never a JSON float (money and quantities stay exact, no float rule); `date` is a strict `Y-m-d` that is a real calendar date; `url` is an absolute `https://` URL of at most 500 characters with no user-info, no whitespace and no control character. Arrays, objects, null and booleans are refused for every type.
- AC-41: Given an `email` body, When a value is substituted, Then it is HTML-escaped (`&`, `<`, `>`, `"`, `'` to entities, UTF-8), so a value `<script>alert(1)</script>` appears as `&lt;script&gt;alert(1)&lt;/script&gt;` and never as markup. A value is escaped exactly once (no double escaping).
- AC-42: Given an `email` subject, or any `sms`, `whatsapp`, `push` body, or an `in_app` title or body, When a value is substituted, Then it is inserted as plain text: no HTML escaping is applied (so `&` stays `&`), but every ASCII control character is removed or, for tab, replaced by one space, CR and LF are removed, and the Unicode line and paragraph separators (U+2028, U+2029) and bidirectional override and isolate characters (U+202A to U+202E, U+2066 to U+2069) are removed. A value containing `\r\nBcc: x@y` therefore cannot add a header or a line.
- AC-43: Given a `url` variable inside an `email` body, When rendered, Then it passes AC-40 and AC-41, so `javascript:`, `data:`, `http://` and relative values are refused at validation, never emitted.
- AC-44: Given rendering, When a value itself contains `{{other}}`, Then it is inserted literally and never re-evaluated; rendering is a single pass over the template text only, so a value cannot inject a placeholder, and one request's values can never reveal another variable.
- AC-45: Given the template author's own markup in an `email` body (for example `<b>Hello</b>`), When rendered, Then it is emitted unchanged: template text is trusted content written by a holder of `notifications.templates.manage`; only variable values are untrusted (D-12). The spec states this plainly so no reviewer expects template text to be sanitised.
- AC-46: Given the same template, variables and clock, When rendered twice, Then the output is byte-identical (no random, no time-dependent content).
- AC-47: Given a send request, When it succeeds, Then the rendered subject and body are stored on the delivery as a snapshot at queue time and the driver sends the snapshot, so editing or deactivating the template afterwards cannot change a queued message (D-14).
- AC-48: Given a rendered result, When its length exceeds the channel limit (AC-31), Then the request is refused `rendered_too_long`; it is never truncated.

### F. Requesting a send, queueing through the outbox

- AC-49: Given the published contract `NotificationSender`, When another module calls it with `template_key`, `locale` (optional), `recipient` (type and id), `category`, `variables`, `idempotency_key` and optional `source_event_id`, Then the module-boundary rule holds: it takes and returns plain immutable values and never a model, and it is the only way another module requests a notification.
- AC-50: Given a request, When it is made, Then it carries exactly one `channel` (a list of channels is refused); one call produces one delivery for one recipient on one channel. Fan-out to several channels, roles or recipients is the caller's loop or a later rules task (D-5).
- AC-51: Given `recipient`, When it names an employee, Then that employee must exist, be live and be `active` (`recipient_not_found`, `recipient_inactive`); when it names a customer, Then that customer must exist, be live and not be merged into another (`recipient_not_found`, `recipient_merged`); a raw address (an email or phone typed in the request) is refused `recipient_type_unsupported` (D-6). The same applies to the same checks at attempt time (AC-73).
- AC-52: Given a `template_key`, `channel` and `locale`, When the template is resolved, Then the exact (key, channel, locale) is used; if absent and the locale is not `en`, the `en` template is used; otherwise the request is refused `template_not_found`. An inactive template is refused `template_inactive`. A request for a customer on `in_app` is refused `channel_not_supported_for_recipient` (customers have no inbox); a request for an employee on any channel is allowed.
- AC-53: Given `category`, When it is one of `transactional`, `marketing`, `system`, Then it is accepted; anything else is refused. A customer recipient with `system` is refused `category_not_allowed_for_recipient`; an employee recipient with `transactional` or `marketing` is refused the same way (D-7).
- AC-54: Given a valid request, When it is accepted, Then in one database transaction exactly one `deliveries` row (status `queued`, `attempts = 0`, `next_attempt_at = now`) and exactly one outbox row are written, the outbox row through the one outbox writer (T-018 AC-5): `type = notification.delivery_requested`, `aggregate_type = delivery`, `aggregate_id` the delivery id as a string, `payload` containing the delivery id only. A test that rolls back the caller's transaction finds neither row (T-018 AC-6).
- AC-55: Given the outbox payload, When inspected, Then it contains no recipient address, no subject, no body, no variable value and no credential; the consumer reads everything from the delivery row.
- AC-56: Given an idempotent request, When the same `idempotency_key` (1 to 100 characters, case-sensitive, ASCII printable, compared on a binary collation so `a` and `A` are different keys) is submitted again with the same effective inputs (same template key, channel, locale, recipient, category and variables, compared by a stored payload hash), Then no second delivery and no second outbox row is written and the original delivery id and status are returned. With different inputs it is refused `idempotency_key_conflict`. A missing or invalid key is refused `idempotency_key_required`.
- AC-57: Given two simultaneous requests with the same key, When both run, Then exactly one delivery exists afterwards and the other returns it. `[ENGINE]` (unique index race).
- AC-58: Given a consumer in the consumer group `notifications` (the queue name already in `docs/architecture.md` §9), When it receives `notification.delivery_requested`, Then it processes the delivery by id, is idempotent by the outbox event id and by the delivery's own status (T-018 AC-23): a redelivered message for a delivery that is no longer `queued`, or whose `next_attempt_at` is in the future, changes nothing and is acknowledged; a missing delivery id (purged) is acknowledged and logged, never retried forever.
- AC-59: Given an outbox event that was dead-lettered or never relayed, When the scheduled command `notifications:dispatch-due` runs, Then it finds `queued` deliveries whose `next_attempt_at <= now` in bounded batches (default 100, ordered by `next_attempt_at` then `id`; never a cursor on a ULID, T-018 AC-14) and writes a fresh `notification.delivery_requested` outbox row for each, so a lost event recovers; running it twice before consumption is harmless because of AC-58. It reports how many it requeued and writes no audit row.
- AC-60: Given the dispatch command's batch size, When configured, Then it is an integer 1 to 1,000; outside that range the command fails to start with a named error.

### G. Consent and opt-out

- AC-61: Given the consent store (new table `notification_consents`, D-15), When a consent is recorded, Then it holds one row per (customer, channel, category) with `status` `opted_in` or `opted_out`, `source` (1 to 50 characters, for example `staff`, `customer_request`, `import`), an optional `note` (up to 255 characters), `recorded_by` (employee), and timestamps; `channel` is one of `email`, `sms`, `whatsapp`, `push`; `category` is `transactional` or `marketing`. Recording again for the same triple updates the row (it never creates a second) and each change writes an audit row (AC-103).
- AC-62: Given a customer recipient and `category = marketing`, When a send is requested, Then it is allowed only if a consent row for that (customer, channel, marketing) is `opted_in`; no row, or `opted_out`, results in a delivery recorded as `suppressed` (AC-66) and nothing is sent (D-16, fail closed).
- AC-63: Given a customer recipient and `category = transactional`, When a send is requested, Then it is allowed unless a consent row for that (customer, channel, transactional) is `opted_out`; no row means allowed (D-16).
- AC-64: Given an employee recipient (`system` category), When a send is requested, Then consent rows are not consulted; an employee cannot be opted out through this store, and the `system` category is never offered to a customer (AC-53).
- AC-65: Given the consent check, When it runs, Then it runs twice: at request time (AC-62, AC-63) and again immediately before the driver is called (AC-73), so an opt-out recorded after a delivery was queued still stops it.
- AC-66: Given a consent refusal, When it occurs at either time, Then the delivery is recorded (or moved) to status `suppressed` with `error_code` `consent_missing` or `consent_opted_out`, `attempts` unchanged, and the driver is never called. A `suppressed` delivery is terminal and is not retried by the dispatcher; it can be re-requested only by a new idempotency key after consent changes. At request time the call returns the suppressed delivery (not an exception), so a caller can tell "refused by policy" from "invalid request".
- AC-67: Given a customer recipient, When the address for the channel is missing (`email` empty for `email`; `phone` empty for `sms`, `whatsapp` and `push`), Then the delivery is recorded `failed` with `error_code = recipient_address_missing`, no attempt is counted and no driver is called. The address is read through a published customers contract (never the customers table) and a snapshot is stored in `recipient` at request time.
- AC-68: Given a customer in status `blocked` or `inactive`, When a `transactional` send is requested, Then it is allowed; a `marketing` send to such a customer is refused `recipient_not_eligible` (D-17).
- AC-69: Given consent records, When listed for a customer, Then every row for that customer is returned with channel, category, status, source, note and `updated_at`, with paging as AC-37. Inbound opt-out handling (a customer replying STOP, an unsubscribe link, a provider webhook) is out of scope (D-18).

### H. Delivery lifecycle, retry, back-off and idempotency

- AC-70: Given the `deliveries` status values, When a delivery moves, Then the only allowed moves are: `queued` to `sent`; `queued` to `failed`; `queued` to `suppressed`; `queued` to `delivered` (in-app only, AC-83); `sent` to `delivered`; `sent` to `bounced`; and, by the manual retry only (AC-79), `failed` to `queued`. Every other pair is refused and writes nothing; `suppressed`, `delivered` and `bounced` are terminal; `sent` is never moved back (D-19). The enum is `queued, sent, delivered, failed, bounced, suppressed` (`suppressed` is an addition to the documented enum, D-19).
- AC-71: Given a `sent` delivery, When nothing external can report back (this task builds no provider callback), Then it stays `sent`; `delivered` and `bounced` are reachable only through an internal transition operation that a future provider-callback task will call, and which this task exercises in tests and nowhere else.
- AC-72: Given a delivery attempt on a `queued` row whose `next_attempt_at <= now`, When it starts, Then the row is read under a row lock and every rule is evaluated on the locked values, so two workers on one delivery leave exactly one driver call and the other becomes a no-op (AC-58). `[ENGINE]` for the two-connection proof.
- AC-73: Given an attempt, When it starts, Then before any driver call it re-checks, in this order: the recipient is still live and eligible (AC-51, AC-68), the consent (AC-65), the channel config is active (AC-24), and the daily limit (AC-77); a failure of the first three ends the attempt without counting it (`suppressed`, or `failed` with the reason).
- AC-74: Given a driver call, When it returns `accepted`, Then the delivery becomes `sent` with `provider`, `provider_ref`, `sent_at = now`, `attempts` incremented, `error_code` and `error` cleared, and the channel's `sent_today` incremented, all in one transaction.
- AC-75: Given a driver call, When it returns `transient_failure`, Then `attempts` is incremented, `last_attempt_at = now`, `error_code` and a truncated `error` are stored, the status stays `queued`, and `next_attempt_at = now + min(cap, base x 2^(attempts - 1))` with default base 60 seconds and cap 3,600 seconds, so the delays are 60, 120, 240, 480 seconds for attempts 1 to 4, with no jitter in this task (D-20). Base and cap are configuration, integers 1 to 86,400, and the cap may not be below the base.
- AC-76: Given the maximum number of attempts (default 5, configuration integer 1 to 20), When a transient failure brings `attempts` to the maximum, Then the delivery becomes `failed` with `error_code = max_attempts_exceeded` and the last driver error code kept in `error`; a `permanent_failure` makes it `failed` at once with the driver's code. A `failed` delivery is never picked up by the dispatcher.
- AC-77: Given `daily_limit` is set and `sent_today` has reached it for the channel's current UTC day, When an attempt starts, Then the delivery stays `queued`, `attempts` is not incremented, and `next_attempt_at` becomes 00:00:00 UTC of the next day; `sent_today` resets when `sent_today_date` is not today's UTC date (D-21). A limit of null never defers.
- AC-78: Given a provider reference, When any driver is called, Then it receives the delivery id as the client reference, so a real driver can de-duplicate. The spec records the known limitation: a worker that dies after the driver accepted and before the row is updated can cause one duplicate send on the next attempt; no exactly-once claim is made (T-018 AC-23 reasoning).
- AC-79: Given a `failed` delivery, When a holder of `notifications.deliveries.retry` retries it, Then it becomes `queued` with `attempts = 0`, `next_attempt_at = now`, `error_code` and `error` cleared (kept in the audit row), a new outbox event is written in the same transaction, and consent, recipient and config are re-checked at the attempt exactly as for a new delivery (a retry never bypasses consent). Retrying a delivery that is not `failed` is refused `invalid_transition`; retrying one whose recipient purge or expiry makes it impossible is refused `delivery_expired`.
- AC-80: Given retry timing, When tests run, Then the clock and the attempts are injected so the full retry ladder runs in milliseconds with no real wait (T-018 AC-17 convention).
- AC-81: Given a delivery row, When it is written, Then `recipient` holds the address snapshot (at most 191 characters); `provider` is the config provider at attempt time; `error` is at most 500 characters and never contains a credential (AC-10, AC-20); `sent_at` is set only on the move to `sent`; `delivered_at` only on `delivered`.
- AC-82: Given the lifecycle, When any status change is made, Then it happens only through the one delivery-state operation, which enforces AC-70; no handler updates `deliveries.status` directly. A source scan finds no second writer, the same one-writer rule as `audit_logs` and `outbox_events`.

### I. In-app notifications

- AC-83: Given an in-app delivery to an employee, When the attempt runs, Then in one transaction a `notifications` row is written (`recipient_employee_id`, `type` = the template key, `title` = rendered subject, `body` = rendered body, `data` = JSON object or null, `priority` `normal` by default, `read_at` null, `action_url`) and the delivery becomes `delivered` with `delivered_at` and `sent_at` set and `attempts` incremented. No channel config row is needed (D-10).
- AC-84: Given `priority`, When supplied on the request, Then it is one of `low`, `normal`, `high`; anything else is refused. `data` is a JSON object of at most 20 scalar entries and 4,000 serialised characters, containing no value that is another object or array; it is stored as given and never rendered as a template.
- AC-85: Given `action_url`, When supplied, Then it is either a path starting with exactly one `/` (not `//`), at most 500 characters, with no control character and no `\`, or an absolute `https://` URL; `javascript:`, `data:`, `http:` and protocol-relative `//host` values are refused `action_url_invalid`.
- AC-86: Given an in-app delivery that was already written (redelivered message, retry), When the attempt runs again, Then a unique reference from `notifications` to its delivery makes the second write impossible, so one delivery yields at most one in-app row (idempotent, T-018 AC-23).
- AC-87: Given an employee, When they list their notifications, Then only their own are returned, newest first, with paging (default 25, maximum 100), filterable by `unread`, and the response includes the total unread count; another employee's notification id is answered `not_found` (never "forbidden", so existence is not leaked).
- AC-88: Given an own unread notification, When it is marked read, Then `read_at = now` is set once; marking an already read notification leaves `read_at` unchanged and succeeds without a second audit row (none is written for reads). A bulk "mark all read" sets `read_at` only on that employee's unread rows.
- AC-89: Given in-app content, When the title and body are stored, Then they are stored as plain text (AC-42) and the contract is that consumers display them as text, never as markup; a stored `<b>` is returned as the same characters.
- AC-90: Given an in-app row, When retention is applied, Then in-app rows are never purged by this task (§6.16 sets no retention for `notifications`); the link to a purged delivery is set to null (AC-95).

### J. Reading deliveries and retention

- AC-91: Given deliveries, When listed, Then filters are `status`, `channel`, `recipient_type`, `recipient_id`, `template_key`, `category`, `created_from`, `created_to` (strict `Y-m-d` or UTC date-time), ordered newest first by `id` (a BIGINT, so a cursor on it is safe), with cursor paging (default 25, maximum 100); invalid filters are refused naming the filter.
- AC-92: Given a delivery detail read, When the caller holds `notifications.deliveries.view` but not `notifications.deliveries.view_content`, Then `subject` and `body` are omitted from the response and `recipient` is returned masked as in AC-8; with `view_content` they are returned in full (D-22).
- AC-93: Given a read of the delivery counts, When requested, Then the response gives counts by status for a given date range, computed by one grouped query on `(status, created_at)`, so an admin sees queued, sent, failed and suppressed totals.
- AC-94: Given the retention period (default 90 days, configuration integer 7 to 3,650), When `notifications:purge-deliveries` runs, Then it deletes deliveries in a terminal status (`delivered`, `failed`, `bounced`, `suppressed`, or `sent` older than the period) whose `created_at` is older than the period, in bounded batches (default 500), and first marks any still-`queued` delivery older than the period as `failed` with `error_code = expired` so it stays visible for one more cycle. It writes one audit row with the counts (no per-row audit), and a run with nothing to purge changes nothing and does not error.
- AC-95: Given `customer_communications.delivery_id` and `notifications.delivery_id` reference a purged delivery, When the purge deletes it, Then the foreign keys are `ON DELETE SET NULL`, so the purge is never blocked by a referencing row and no communication or in-app row is deleted. `[ENGINE]` for the foreign-key behaviour.

### K. The owed column: `customer_communications.delivery_id` (T-028 D-6, AC-36)

- AC-96: Given the expand step, When the migrations run on every tenant, Then `customer_communications` gains `delivery_id BIGINT UNSIGNED NULL`, with no default, no backfill, no foreign key in this step, and an index. It is one migration file, forward-only, run by the per-tenant runner against every tenant database, and ordered after the migration that creates `deliveries`. A test shows the column exists on a tenant that already held communication rows, with every existing row's value null.
- AC-97: Given the contract step, When it runs, Then a separate, later migration file adds the foreign key `customer_communications.delivery_id` to `deliveries.id` with `ON DELETE SET NULL`, after a pre-check that any non-null `delivery_id` has a matching delivery; a non-matching value stops the migration with a named error rather than deleting data. The two steps are never combined in one file (the codebase's own migration source rules, T-027 D-3). `[ENGINE]` for the foreign key itself.
- AC-98: Given the types, When the foreign key is created, Then `deliveries.id` is `BIGINT UNSIGNED AUTO_INCREMENT` (not signed and not a ULID) so the two columns match exactly; a signedness mismatch is rejected by the engine, so a test asserts both declarations are unsigned. `[ENGINE]` for the engine rejection; the declaration check is provable on the stand-in.
- AC-99: Given the customers module's create-communication operation, When `delivery_id` is supplied, Then it must be an integer that exists in `deliveries`, the delivery's `recipient_type` must be `customer` and its `recipient_id` must equal the communication's `customer_id` (`delivery_customer_mismatch`), the communication's `direction` must be `out`, and its `channel` must correspond to the delivery's channel (`email` to `email`, `sms` to `sms`, `whatsapp` to `whatsapp`; any other pair refused `delivery_channel_mismatch`). It is checked through a published Notification contract, never by reading `deliveries` from the customers package. A delivery id that does not exist is refused `delivery_not_found`.
- AC-100: Given a delivery, When two different communications try to reference it, Then both are allowed (a delivery may be logged more than once); this task adds no unique index on `delivery_id`.
- AC-101: Given T-028's customer merge, When communications are reassigned to the target customer, Then their `delivery_id` is preserved unchanged and AC-99's customer check is not re-run (it applies at create only).
- AC-102: Given this task, When a customer delivery reaches `sent`, Then no `customer_communications` row is written automatically (D-23); automatic logging is a follow-up row. A caller that wants one creates it through the customers operation with the delivery id.

### L. Permissions and audit

- AC-103: Given each successful channel, template, consent or manual-retry write, When it commits, Then one audit row is written in the same transaction naming actor, action, target id and the before and after values of the changed fields, with these actions: `notifications.channel.created`, `notifications.channel.updated`, `notifications.channel.credentials_changed`, `notifications.channel.activated`, `notifications.channel.deactivated`, `notifications.channel.deleted`, `notifications.template.created`, `notifications.template.updated`, `notifications.template.activated`, `notifications.template.deactivated`, `notifications.consent.recorded`, `notifications.delivery.retried`, `notifications.deliveries.purged`. A refused request writes none. The `credentials_changed` row records that a change happened and never a value, plaintext or ciphertext; an update that changes credentials and other fields writes both rows. Sends, attempts and status moves are not audited (the delivery row is the record), and the seed writes none.
- AC-104: Given a caller lacking a permission, When they call a handler, Then it refuses `not_permitted`, checked first, before any transaction and before any read of the target: `notifications.channels.view` for reading channel configs; `notifications.channels.manage` for creating, updating, activating, deactivating, deleting and changing credentials; `notifications.templates.view` and `notifications.templates.manage`; `notifications.deliveries.view` for the list, detail and counts; `notifications.deliveries.view_content` for subject and body (AC-92); `notifications.deliveries.retry` for manual retry; `notifications.consents.view` and `notifications.consents.manage`; `notifications.inbox.read` for an employee's own in-app notifications (never for anyone else's, and no key grants reading another employee's inbox). The registration of these keys in the permission registry is in scope; granting them to roles is the roles task's job.
- AC-105: Given the module's published send contract (AC-49), When another module calls it, Then it does so as a system actor with no permission key (the calling module has already authorised its own action); the contract is not exposed over HTTP, and no route lets a user type a recipient and send an arbitrary message (D-24).
- AC-106: Given a tenant authenticated route for each admin operation, When it is called without a tenant token, Then it is refused by the existing token middleware before the handler; with a token and without the permission, the handler's `not_permitted` applies. Input is a JSON body; every refusal names the field and a stable code.

### M. Schema and framework integrity

- AC-107: Given the new tables (`notification_templates`, `channel_configs`, `notifications`, `deliveries`, `notification_consents`) and the added column, When the schema catalogue and migration set are checked, Then every migration is forward-only, runs through the per-tenant runner against every tenant database, is expand-and-contract where it alters an existing table, and the catalogue test, the identifier-collation rule (ULID columns on the shared helper, nothing else naming a collation) and the decimal-precision test stay green. `notification_rules`, `webhook_endpoints` and `webhook_deliveries` are not built (D-1).
- AC-108: Given the delivery table, When indexes are checked, Then they include `(created_at)`, `(status, created_at)`, `(status, next_attempt_at)`, a unique `(idempotency_key)`, and `(recipient_type, recipient_id, created_at)`.
- AC-109: Given the schema amendments (D-25), When the branch is delivered, Then `docs/database.md` §6.16 is amended by the architect to list the added `deliveries` columns (`recipient_type`, `recipient_id`, `category`, `locale`, `subject`, `body`, `idempotency_key`, `payload_hash`, `next_attempt_at`, `last_attempt_at`, `delivered_at`, `error_code`, `updated_at`), the added `suppressed` status, `channel_configs.sent_today_date`, `notifications.delivery_id`, and the new `notification_consents` table, so the schema authority matches the code.
- AC-110: Given `composer check` (lint, static analysis, boundary rules, tests), When run on the branch, Then it exits 0 with no new suppression; the boundary rules show the Notification module uses only the kernel and other modules' published contracts (customers contact lookup, employee lookup, audit writer, outbox writer, permission registry), and no other module reads a Notification table.
- AC-111: Given the provisioning path and the existing-tenant migration, When they run, Then no template, channel config or consent is seeded: a tenant starts with none, so every non-in-app channel fails closed (AC-24) until an admin configures one.

## Out of scope

- Any real provider driver (SMTP, SES-style mail, an SMS gateway, WhatsApp Business, push): follow-up rows that need the owner's credentials (D-2).
- `webhook` channel, `webhook_endpoints`, `webhook_deliveries` (D-1).
- `notification_rules` and the event-to-notification mapping (`stock.low`, `ar.invoice_overdue`, and the rest of G-18): a follow-up row. This task provides the contract the rules engine will call.
- Provider callbacks, delivery receipts, bounce handling and inbound opt-out (STOP, unsubscribe link): follow-up.
- Campaigns, bulk sends, scheduling a send for a future time, digest or batching, rate limiting per recipient.
- Automatic `customer_communications` logging on send (AC-102).
- Credential key rotation, a secrets-manager integration, per-tenant keys.
- Plugin-contributed drivers and templates through T-019's manifest and hooks (`notification_templates.plugin_name` ships as a nullable column only).
- Any frontend: inbox UI, template editor, channel settings screen.
- Raw address recipients, supplier recipients, group or role fan-out.
- Delivery of anything to a customer in this task: the log driver sends nothing.
- Legal consent evidence (double opt-in, IP and timestamp proof, jurisdiction rules), and regulatory unsubscribe wording: owner question 1.

## Non-functional requirements

- Security: secrets encrypted at rest, write-only and absent from every output (AC-19 to AC-21); variable escaping per channel (AC-41 to AC-45); header and line injection blocked (AC-42); no URL scheme other than `https` or a local path (AC-40, AC-85); the permission split keeps secrets, content and retry separately grantable.
- Privacy: bodies and addresses are personal data; the log driver masks and never logs a body (AC-8); the outbox carries only an id (AC-55); delivery content needs its own permission (AC-92); retention is 90 days (AC-94).
- Reliability: at-least-once delivery with idempotent consumers, a recovery command for lost events (AC-59), and a documented duplicate window (AC-78).
- Performance: the consumer handles one delivery per message; the dispatch and purge commands run in bounded batches; delivery list and counts use the documented indexes (AC-108); no unbounded select.
- Accessibility: not applicable (no UI).
- Observability: dispatch and purge commands report counts; the log driver line is structured.

## Open questions

None BLOCKING. Owner-visible questions with built-in defaults are collected in "Owner-visible questions" below.

## Assumptions

1. The branch for this task is on a stale base that has the event bus, plugin SDK, access and organization packages but no customers package (as T-032 found). AC-51, AC-62 to AC-69 and AC-96 to AC-102 need T-028 (customers, `customer_communications`) on the branch; the lead rebases or merges before development of Groups G and K. The rest is independent of customers.
2. T-018's outbox writer, relay, consumer groups and the stand-in bus are available as specified; T-019's plugin framework is not consumed by this task.
3. The audit writer, permission registry and per-tenant migration runner behave as in T-014, T-017 and T-028 (audit row in the caller's transaction; keys in a registry; forward-only migrations).
4. The customers module (or its contract) can supply a customer's email, phone, status and merged flag; the employee directory can supply an employee's liveness and status. Both through published contracts.
5. `en` exists as the platform default locale; the tenant has no locale setting yet.
6. All times are UTC; a tenant-timezone "day" for the daily limit is a later refinement (D-21).
7. The application already has server-side encryption configuration usable for the credential key; the architect chooses the mechanism.
8. Role grants for the new permission keys are made by the roles task, not here (T-032 precedent).

## Dependencies

- T-018 (outbox, bus, consumer idempotency, dead letter): required.
- T-028 (customers, `customer_communications`, contact data): required for Groups G and K.
- T-014/T-017 (access, audit writer, permission registry): required.
- Per-tenant migration runner and schema catalogue (T-008, T-009): required.
- T-019 (plugin framework): not required; extension points named only.
- Forward: rules engine, webhooks, real drivers, provider callbacks, sales/AR/payroll callers, frontend.

## Proposed follow-up rows

1. Real email driver (provider chosen by the owner): needs the owner's credentials and a provider decision.
2. Real SMS driver: same.
3. WhatsApp and push drivers: same.
4. Notification rules engine (G-18): event keys to templates, conditions and recipients; builds `notification_rules`.
5. Webhook endpoints and `webhook_deliveries` (the other half of §6.16).
6. Provider callbacks: delivered and bounced receipts, inbound STOP and unsubscribe, into the AC-71 transition and the consent store.
7. Automatic `customer_communications` logging when a customer delivery is sent.
8. Credential key rotation and optional secrets-manager integration.
9. Frontend: notification inbox, template editor, channel settings, delivery log.
10. Campaigns and bulk marketing sends with unsubscribe links (legal review first).
11. Plugin-contributed drivers and templates via T-019 manifest (links with FP-296).
12. Per-tenant timezone for the daily limit.

## Owner-visible questions

Each has a built-in default already applied; none blocks work. The owner may change any of them later; all are reversible.

1. Consent and legal evidence. Default applied: marketing needs an explicit recorded opt-in (fail closed); transactional is sent unless opted out; a staff member records consent with a source and note. Does the business need double opt-in, proof of consent (timestamp, IP, wording) or jurisdiction-specific rules (for example opt-in only in some countries)? The default is the safest, and the model can add columns later.
2. Which providers, and when. Default applied: none; only `log` and `null` exist. Which email and SMS providers should the first real drivers target, and who supplies the credentials?
3. Message retention. Default applied: 90 days for deliveries (the documented figure), including the stored subject and body snapshot; in-app notifications kept indefinitely. Should customer message bodies be kept at all, or only metadata?
4. Blocked and inactive customers. Default applied: they still receive transactional messages (a receipt, a statement) but never marketing. Is that right?
5. Daily limit day. Default applied: UTC day. Should it follow the tenant's local time?
6. Automatic communication log. Default applied: sending to a customer does not create a `customer_communications` row by itself. Should every customer message appear in the customer's communication history automatically?
7. Who may read message content. Default applied: separate permission `notifications.deliveries.view_content`, not granted by default. Should managers see bodies by default?
