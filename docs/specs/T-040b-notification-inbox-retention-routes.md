# T-040b: Notification inbox, retention purge, owed delivery column and HTTP routes

Follow-on of FP-295 (backlog row to be registered by the lead). Written autonomously (team mode); decisions in `docs/decisions/T-040b-notification-inbox-retention-routes.md` (D-n) and in T-040's decisions file (cited as "T-040 D-n"). This task builds on T-040 (`docs/specs/T-040-notification-channels.md`), which must be merged first: the five notification tables, the send path, consent, the ten permission keys and the audit catalogue exist there. T-040b changes no T-040 table; its only schema change is one column on `customer_communications`.

HARD CONSTRAINT carried over: nothing in this task sends a real email or SMS, calls a third-party API or needs a credential. The routes expose no way to send an arbitrary message (T-040 AC-105).

## Problem / goal

T-040 writes in-app notifications and delivery rows, but (1) employees cannot read their notifications, (2) deliveries are never purged although `docs/database.md` §6.16 documents 90-day retention, (3) `customer_communications.delivery_id`, owed since T-028 (AC-36, D-6), does not exist so a logged customer message cannot point at the attempt that carried it, and (4) none of the admin operations is reachable over HTTP. Goal: build those four things.

## Users affected

- Employee: lists and reads own in-app notifications.
- Tenant admin / manager: uses the admin operations over HTTP; sees purged history disappear after the retention period.
- Customer-team staff: log a communication with its delivery attached.
- Developers of the frontend task: consume the routes.

## Current behaviour

After T-040: in-app rows exist and nobody can read them; deliveries accumulate forever; `customer_communications` has no `delivery_id` (its migration defers it, and the customers schema test asserts the column is absent); the operation that logs a communication is the customers slice `LogCustomerCommunication` (no published contract, no permission check today); the merge slice moves communications by customer id only; no notification route exists, and the existing route sweeps (access surface, guard isolation, cross-tenant, OpenAPI completeness, route caching) know none.

## User stories

- As an employee, I want to list my notifications and mark them read, so that I see alerts without email.
- As a tenant admin, I want old deliveries purged on a schedule, so that personal data in message snapshots is not kept forever.
- As the customer team, I want `customer_communications.delivery_id` linked to `deliveries`, so that a logged message points at the attempt that carried it.
- As a frontend developer, I want authenticated routes for every notification admin operation and the inbox, so that screens can be built.

## Acceptance criteria

Conventions as in T-040 (whole-request refusals naming the field, stable snake_case codes, injectable UTC clock, `[ENGINE]` criteria recorded NOT VERIFIED until a real engine runs them). Each criterion shows its revision-1 number in brackets for traceability.

### A. Employee inbox

- AC-B1 [was AC-87]: Given an employee holding `notifications.inbox.read`, When they list their notifications, Then only their own are returned, newest first, with paging (default 25, maximum 100; a limit of 0, 101 or non-integer is refused `page_size_invalid`), filterable by `unread`, and the response includes the total unread count; another employee's notification id is answered `not_found` (never "forbidden", so existence is not leaked). Without the key: `not_permitted`.
- AC-B2 [was AC-88]: Given an own unread notification, When it is marked read, Then `read_at = now` is set once; marking an already read notification leaves `read_at` unchanged and succeeds without a second write (no audit row is written for reads). A bulk "mark all read" sets `read_at` only on that employee's unread rows and returns how many it changed.
- AC-B3 [was AC-90]: Given an in-app row, When retention is applied, Then in-app rows are never purged by this task (§6.16 sets no retention for `notifications`); the link to a purged delivery is set to null (AC-B5).

### B. Retention purge

- AC-B4 [was AC-94]: Given the retention period (default 90 days, configuration integer 7 to 3,650; outside that range the command fails to start with a named error), When `notifications:purge-deliveries` runs (for one tenant or `--tenant=*`, one tenant's failure not stopping the others), Then in bounded batches (default 500) it first marks any still-`queued` delivery older than the period as `failed` with `error_code = expired` (so it stays visible for one more cycle, T-040 D-30), then deletes deliveries in a terminal status (`delivered`, `failed`, `bounced`, `suppressed`, or `sent`) whose `created_at` is older than the period. It writes one audit row with the counts (no per-row audit), and a run with nothing to purge changes nothing and does not error. A `failed` delivery with `error_code = expired` cannot be manually retried (T-040 AC-79, `delivery_expired`). No scheduler runs in production (FP-319), so the purge runs only when invoked by hand until one exists.
- AC-B5 [was AC-95]: Given `customer_communications.delivery_id` and `notifications.delivery_id` reference a purged delivery, When the purge deletes it, Then both foreign keys are `ON DELETE SET NULL`, so the purge is never blocked by a referencing row and no communication or in-app row is deleted. (The `notifications` key is built by T-040, T-040 AC-117; the `customer_communications` key by AC-B7.) `[ENGINE]` for the foreign-key behaviour.

### C. The owed column `customer_communications.delivery_id` (T-028 D-6, AC-36)

- AC-B6 [was AC-96]: Given the expand step, When the migrations run on every tenant, Then `customer_communications` gains `delivery_id BIGINT UNSIGNED NULL`, with no default, no backfill, no foreign key in this step, and an index. It is one migration file, forward-only, run by the per-tenant runner against every tenant database, and ordered after T-040's migration that creates `deliveries`. A test shows the column exists on a tenant that already held communication rows, with every existing row's value null.
- AC-B7 [was AC-97]: Given the contract step, When it runs, Then a separate, later migration file adds the foreign key `customer_communications.delivery_id` to `deliveries.id` with `ON DELETE SET NULL`, after a pre-check that any non-null `delivery_id` has a matching delivery; a non-matching value stops the migration with a named error rather than deleting data. The two steps are never combined in one file (migration source rules, T-027 D-3). `ON DELETE SET NULL` is a first in this database's migrations (T-040 D-50). `[ENGINE]` for the foreign key itself.
- AC-B8 [was AC-98]: Given the types, When the foreign key is created, Then `deliveries.id` is `BIGINT UNSIGNED AUTO_INCREMENT` (not signed and not a ULID) and `delivery_id` is `BIGINT UNSIGNED`, so the two match exactly; a test asserts both declarations are unsigned. `[ENGINE]` for the engine rejection of a mismatch; the declaration check is provable on the stand-in.
- AC-B9 [was AC-99]: Given the customers operation `LogCustomerCommunication`, When `delivery_id` is supplied, Then it must be an integer of 1 or more that exists in `deliveries`; the delivery's `recipient_type` must be `customer` and its `recipient_id` must equal the communication's `customer_id` (`delivery_customer_mismatch`); the communication's `direction` must be `out`; and its `channel` must correspond to the delivery's channel (`email` to `email`, `sms` to `sms`, `whatsapp` to `whatsapp`; any other pair refused `delivery_channel_mismatch`). It is checked through the published contract of AC-B14, never by reading `deliveries` from the customers package, and inside the operation's own transaction. A delivery id that does not exist is refused `delivery_not_found`. A `delivery_id` that is not an integer (string, float, zero, negative) is refused naming the field. The operation's recorded audit row includes `delivery_id`.
- AC-B10 [was AC-100]: Given a delivery, When two different communications try to reference it, Then both are allowed (a delivery may be logged more than once); this task adds no unique index on `delivery_id`.
- AC-B11 [was AC-101]: Given T-028's customer merge, When communications are reassigned to the target customer, Then their `delivery_id` is preserved unchanged and AC-B9's customer check is not re-run (it applies at create only). The merge as built moves communications by customer id only and needs no change.
- AC-B12 [was AC-102]: Given this task, When a customer delivery reaches `sent`, Then no `customer_communications` row is written automatically (T-040 D-23 as corrected by T-040 D-36); automatic logging is a follow-up row. A caller that wants one creates it through `LogCustomerCommunication` with the delivery id.

### D. HTTP routes

- AC-B13 [was AC-106]: Given a tenant-authenticated route for each admin operation of T-040 (channel configs: create, update, activate, deactivate, delete, list, get; templates: create, update, activate, deactivate, list, get; consents: record, list; deliveries: list, get, counts, retry) and for the inbox operations of AC-B1 and AC-B2, When it is called without a tenant token, Then it is refused by the existing token middleware before the handler; with a token and without the permission, the handler's `not_permitted` applies. Input is a JSON body; every refusal names the field and a stable code. There is no template delete route (T-040 AC-38) and no route that sends a message (T-040 AC-105). The existing route sweeps (access surface, guard isolation, cross-tenant, OpenAPI completeness, route caching) are extended to cover each new route, and the committed OpenAPI document gains them. Credential values and ciphertext never appear in any response (T-040 AC-20), re-proved over HTTP with the sentinel test.

### E. Contracts, test edits, audit, documents

- AC-B14 [new]: Given the published contract `DeliveryLinks` (in the shared contracts namespace, `assertLinkable(delivery id, customer id, communication channel)`), When it is called, Then it must run inside the caller's transaction (a call outside one is a programming error), takes a shared lock on the delivery, and refuses with `delivery_not_found`, `delivery_customer_mismatch` or `delivery_channel_mismatch` (a dedicated refusal type). It is implemented in the Notification package and bound there; the customers package resolves only the contract.
- AC-B15 [new]: Given the customers tests as built, When AC-B6 and AC-B9 land, Then exactly these existing tests change and no other: the customers schema test that asserts the **absence** of `delivery_id` now asserts its presence; and the `LogCustomerCommunication` test whose whole-row audit expectations gains `delivery_id`. Any other red test is a defect to stop on (T-032 F-2 precedent).
- AC-B16 [new, was part of AC-103]: Given the retention purge writes an audit row, When it commits, Then the action is `notifications_deliveries_purged` under module `notifications`, declared in the module's audit catalogue by this task (T-040 declares the other twelve; the writer refuses an undeclared action). The row carries the counts of expired and deleted deliveries and never a delivery's content.
- AC-B17 [new, was part of AC-109 and AC-110]: Given the branch is delivered, When documents and the gate are checked, Then `docs/database.md` §6.9 notes that `customer_communications.delivery_id` is delivered (BIGINT UNSIGNED, expand then contract, `ON DELETE SET NULL`), amended by the architect, and `composer check` exits 0 with no new suppression, the boundary rules showing that the customers package uses only the `DeliveryLinks` contract and never a Notification internal.

## Out of scope

- Any change to T-040's tables, send path, consent, drivers, templates or permissions (the ten keys exist already).
- Automatic `customer_communications` logging; a unique index on `delivery_id`.
- Frontend (inbox UI and all screens); real-time push of in-app notifications.
- A scheduler for the commands (FP-319).
- Purge of in-app notifications; per-tenant retention settings.
- A route that sends a message.

## Non-functional requirements

- Privacy: the purge removes subject and body snapshots after the retention period (AC-B4); inbox reads never leak another employee's rows (AC-B1).
- Performance: the purge runs in bounded batches over the documented indexes; inbox lists page and use the documented `(recipient_employee_id, created_at)` and `(recipient_employee_id, read_at)` indexes.
- Security: routes enforce the token middleware and the handler's permission; no response carries a credential.
- Operability: the purge command prints its counts.

## Open questions

None BLOCKING.

## Assumptions

1. T-040 is merged first; the five notification tables and the ten permission keys exist.
2. The customers package and `customer_communications` are on the base (T-040 Assumption 1). `LogCustomerCommunication` carries no permission check today; adding one is not in scope.
3. No scheduler runs in production (FP-319): the purge does not run on its own until one exists.
4. Migrations sort after T-040's (higher date prefix); version literals in catalogue tests are regenerated at merge, never hand-merged.
5. `ON DELETE SET NULL` needs the `docs/database.md` §3.3 clause that T-040 delivers (T-040 AC-109).

## Dependencies

- T-040 (required, first).
- T-028 customers (present).
- T-018 outbox, bus (via T-040).
- Forward: frontend inbox, automatic communication logging, a scheduler (FP-319).

## Owner-visible questions

1. Retention. Default: 90 days for deliveries including stored subject and body, in-app rows never purged (T-040 owner questions 3).
2. Until FP-319 delivers a scheduler, the purge does not run unattended (T-040 owner question 8).
3. Whether routes should ship before a frontend consumes them (default: yes, the lead decided the split).
