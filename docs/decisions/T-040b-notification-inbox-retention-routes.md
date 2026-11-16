# T-040b decisions: notification inbox, retention purge, owed column, routes

Autonomous team-mode decisions by `ba` for `docs/specs/T-040b-notification-inbox-retention-routes.md`. Format: `D-n | Question | Decision | Reason | Reversible`. T-040's decisions (`docs/decisions/T-040-notification-channels.md`) are cited as "T-040 D-n" and still apply. No escalation trigger was hit.

D-1 | Why a second task? | The lead accepted the architect's split: T-040 keeps the send path, customers and consent; T-040b takes the inbox reads, the purge, the owed column and the routes (T-040 D-35). Moved criteria carry their old numbers in brackets in the spec. | The routes need six existing sweeps updated and the owed column edits customers tests; neither is needed for the send path to be safe. | Reversible: yes.

D-2 | Which operation gets `delivery_id`? | The customers slice `LogCustomerCommunication` (no published contract, no permission check today). Exactly two existing tests change: the schema test asserting absence becomes presence, and the whole-row audit expectation gains `delivery_id` (AC-B15). | Architect correction 8; the T-032 F-2 precedent for a bounded, named test edit. | Reversible: yes.

D-3 | How does customers validate a delivery without reading `deliveries`? | A published contract `DeliveryLinks`, implemented by the Notification package, called inside the customers transaction with a shared lock on the delivery (AC-B14). | Module boundary: customers may use only a published contract; the lock stops a purge or retry racing the link. | Reversible: yes.

D-4 | What does `delivery_expired` mean (T-040 AC-79)? | A `failed` delivery whose `error_code` is `expired`, set by this purge's first phase, cannot be manually retried. | Architect correction 20: AC-79 was underspecified; the purge is the only writer of `expired`, so the rule belongs to both tasks. | Reversible: yes.

D-5 | Purge audit action. | `notifications_deliveries_purged`, declared by T-040b, not T-040, with module `notifications` (T-040 D-37). | The writer refuses an undeclared action; T-040 should declare only what it uses. | Reversible: yes.

D-6 | Purge cannot run in production yet. | Accepted and surfaced: FP-319 (no scheduler). The command is built, scheduled in code and testable; it runs by hand until a scheduler exists. | The same limitation already governs the dispatcher (T-040 D-45); building a scheduler is outside this row. | Reversible: yes.

D-7 | `ON DELETE SET NULL` on `customer_communications.delivery_id`. | Kept (T-040 D-27, D-50): a first in this database, justified because the purge deletes deliveries and a communication keeps its own content. The contract-step migration is separate and runs a data-clean pre-check. | Retention must not be blocked and must not delete communications. | Reversible: yes, a later migration changes the action.
