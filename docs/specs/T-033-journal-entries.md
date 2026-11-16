# T-033: Journal entries and lines, posted through one service

**Revision 3.** Resolves the architect's findings F-15 to F-21 (plan section 0.5) as D-32 to D-39. Changed criteria: AC-6 (reversal refused), AC-45 (combined CHECK), AC-55 (dimensions not re-checked), AC-68 (`entry_not_found` type), AC-73a (actor check order), AC-81 (widened); the ordering note in AC-69; Assumption 2 and 6 corrected. New criterion: AC-6a.

**Revision 2.** Resolves the architect's findings F-1 to F-14 (plan section 0.2) under the lead's rulings; see D-24 to D-31 in the decision file. Changed criteria: AC-14, AC-15, AC-16, AC-33b, AC-34, AC-35, AC-41, AC-45, AC-51, AC-63, AC-64, AC-65, AC-67, AC-73, AC-75, AC-76, AC-82. New criteria (suffix ids, numbering of the rest unchanged): AC-15a, AC-16a, AC-73a. Merge preconditions are in Assumption 1.

Backlog row FP-261 (Phase 3, Finance). Spec-ahead: documents only; no code and no gate run until T-032 is DONE and merged into this branch. Written autonomously (team mode): every question that would have blocked is resolved in `docs/decisions/T-033-journal-entries.md` as D-n and cited here. Sources read: `docs/architecture.md`, `docs/database.md` section 6.10 and section 7, `docs/patterns.md` sections 4 and 5, the FP-260 to FP-269 backlog rows, the T-032 spec and decisions, and the contracts already in `packages/contracts/src/Finance` (`PeriodGate`, `PeriodClearance`, `PeriodRefusal`, `ChartOfAccounts`, `AccountSnapshot`, `AccountRefusal`, `AccountUsage`, `FiscalYearUsage`, `PeriodCloseBlockers`, `PeriodBlocker`).

## Problem / goal

The ledger has accounts and fiscal periods (T-032) but nowhere to write a posting. Every later finance task (posting rules, cash and bank, receivables, payables, reports) needs one place that turns a business document into balanced, dated, numbered, immutable ledger rows, and nothing else may write them. T-032 also left three ports that answer "none" until the journal exists, so its guards (no recoding an account that has postings, no deleting a year that has entries, no closing a period with draft entries) are inert today.

Goal: a published `PostingService` contract that every module uses; an entry that cannot exist unbalanced, in a closed period, or on a non-postable account; exact decimal amounts; one document number per entry; posting that is idempotent by source document; correction only by reversal; one audit row per entry; and the three T-032 ports answering from the journal.

## Users affected

- Developers of FP-262 to FP-268: consume the contract; they are the only callers of the service.
- Accountant / finance role: reads entries, posts manual entries, reverses entries.
- Shop owner / admin: sees nothing new by itself (no screens, no routes).
- Cashiers: not affected; they never call the journal directly.
- Auditor: relies on append-only rows, numbers without gaps, and audit rows.

## Current behaviour

Read from this worktree (T-032's spec, decisions, plan and published contracts are present; whether its handlers and migrations are built was not verified by `ba`, which has no shell):
- No `journal_entries` or `journal_lines` table exists, and nothing posts. `docs/database.md` documents both tables, the idempotency key, the balance rule and section 7's posting rules.
- `packages/contracts/src/Finance` publishes `PeriodGate`, `ChartOfAccounts` (with `postable`), `AccountUsage`, `FiscalYearUsage` and `PeriodCloseBlockers`; the last three have default implementations that answer "none" (their docblocks say FP-261 rebinds them).
- `number_sequences` exists as a table (company-wide or per branch, row-increment allocation). No journal sequence row is seeded.
- `fiscal_years.retained_earnings_entry_id` is a plain nullable column with no key (T-032 D-28); `fiscal_periods` has no inbound key from the journal.
- `docs/database.md` section 7 names the key `finance.post_closed_period`; T-032 built `finance.periods.post_adjustment` for that purpose and it supersedes the older name (D-10).

## User stories

- As a module developer, I want one published service to post a document's accounting, so that no module writes ledger rows and every posting gets the same checks.
- As an accountant, I want every entry to balance exactly, so that the trial balance can never be out by a cent.
- As an accountant, I want posting refused into a closed period and on an inactive or header account, so that finished months and the chart stay trustworthy.
- As an accountant, I want a mistake corrected by a reversal entry and never by an edit or delete, so that the ledger is an unalterable record.
- As a developer of a queued or offline document, I want a retry of the same document to return the same entry, and a retry with different content to be refused, so that a network replay can never double-post or silently change a posting.
- As an auditor, I want each entry numbered, dated, sourced and audited, so that I can trace any figure back to its document and its actor.
- As the T-032 maintainer, I want its three usage ports answered from the journal, so that its guards finally refuse what they were written to refuse.

## Acceptance criteria

Conventions. A refusal is whole-request: nothing is written (no entry, no line, no audit row, no number consumed) and the response names the offending field path and a stable snake_case reason code from the closed `PostingRefusal` vocabulary (or, for the period and account steps, carries the existing `PeriodRefusal` or `AccountRefusal` code unchanged). "Live" means `deleted_at IS NULL`. Criteria marked `[ENGINE]` need a real MySQL or MariaDB engine and, under ENGINE_HOLD, are written but not run and recorded NOT VERIFIED; everything else is provable on the SQLite stand-in. Money is a string everywhere (D-3).

### A. The published contract and its transaction rule

- AC-1: Given the contract package, When it is inspected, Then `PostingService` exists in `packages/contracts/src/Finance` with `post(PostingRequest): PostedEntry` and `reverse(ReversalRequest): PostedEntry`, together with the immutable value objects `PostingRequest`, `PostingLine`, `ReversalRequest`, `PostedEntry`, the `PostingRefusal` enum and the `PostingRefused` exception; and no model class, query builder or table name crosses the module boundary (the boundary check stays clean).
- AC-2: Given `post` or `reverse` is called with no database transaction open, When it runs, Then it throws `LogicException`, reads no table and writes nothing (same rule as `PeriodGate::clear`).
- AC-3: Given a document handler that opens a transaction, saves its document and then calls `post`, When `post` is refused or any later statement throws, Then the document row and the journal rows roll back together, so a document without its entry (or an entry without its document) cannot be committed by this service.
- AC-4: Given `PostedEntry`, When returned, Then it carries the entry id (26-character ULID, upper case), number, period id, entry date, `total_debit` and `total_credit` as four-decimal strings, status, and `replayed` (true only when AC-34 applies); nothing else.
- AC-5: Given any code outside the posting service, When it writes to `journal_entries` or `journal_lines`, Then a static test fails the build; the test names the directories it scans, and a planted-violation control file proves the scan detects a write (an instrument that cannot fail is a defect, D-17). The only permitted updates of an existing entry row are in AC-48.

### B. Request validation (shape)

Checks run in the order of D-21; the first failing field in request order (lines ascending) is reported.

- AC-6: Given `source_type`, When it is not exactly one value of the documented closed list (D-7), Then it is refused `invalid_source_type`. Case variants (`Sale`, `SALE`), padded values, an empty string, null and unknown values are all refused and never coerced.
- AC-6a: Given `post` is called with `source_type = reversal` (any source_id), When it runs, Then it is refused `invalid_source_type` and nothing is written; only `reverse` creates reversal entries, so the key (reversal, original_id) can never be taken by a module and a later `reverse` of that entry is never refused `already_reversed` because of it (D-32).
- AC-7: Given `source_id`, When the type is not `manual` and `source_id` is absent, null or not a 26-character ULID, Then it is refused `invalid_source_id`. For `manual` and `opening` it may be absent, but when present it must be a ULID. A ULID is folded to upper case before storage and comparison.
- AC-8: Given `source_purpose`, When supplied, Then it matches `^[a-z][a-z0-9_]{0,29}$` (1 to 30 characters), else it is refused `invalid_source_purpose`; an empty string is refused (absent means none). A purpose without a `source_id` is refused.
- AC-9: Given `entry_date`, When it is null, empty, not a real strict `Y-m-d` (`2026-02-30`, `2026-1-1`, a date-time, a string with spaces), Then it is refused `invalid_date` before any lookup; it never defaults to today.
- AC-10: Given `description`, When supplied, Then it is null or (after trimming, AC-14) 1 to 500 characters counted as characters, valid UTF-8, with no control characters, else refused `invalid_description`; over-length input is refused, never truncated.
- AC-11: Given `branch_id` on the entry, When supplied, Then it is a 26-character ULID (folded to upper case) or null, else refused `invalid_branch`.
- AC-12: Given `lines`, When the count is below 2 or above 200, Then it is refused `too_few_lines` or `too_many_lines`. A request with a non-list `lines` value is refused `invalid_lines`.
- AC-13: Given a line `account_id`, When it is not a 26-character ULID, Then it is refused `invalid_account_id` naming the line index.
- AC-14: Given a line `memo` or the entry `description`, When supplied, Then the value is trimmed; an empty or whitespace-only value is stored as null (not refused); otherwise it is 1 to 255 (memo) or 1 to 500 (description) characters after trimming, valid UTF-8, no control characters, else refused `invalid_memo` or `invalid_description`. Both are stored trimmed (D-30).
- AC-15: Given a line's dimensions (`branch_id`, `location_id`, `department_id`, `employee_id`, `customer_id`, `supplier_id`, `product_id`), When one is supplied, Then it is null or a 26-character ULID, else refused `invalid_dimension` naming the line index and the dimension; a dimension key outside this list, or any of `project_ref`, `tax_id`, `tax_base` (not built, D-16), is refused `unknown_field`, naming each.
- AC-15a: Given a well-formed `branch_id` (entry) or dimension id (line) that names no live row in a table that exists in the tenant schema, When the request is checked, Then it is refused `unknown_reference` with context naming the field path (and the line index for a dimension), after the account checks and before the number is allocated; nothing is written and no number is consumed, and a raw foreign-key database error is never surfaced (D-26). A dimension whose target table does not exist yet (D-16, F-13: `department_id`, and `supplier_id` until its table exists) is not checked.
- AC-16: Given any request body (entry or line), When it contains a key the contract does not define (for example `id`, `number`, `status`, `period_id`, `posted_at`, `posted_by`, `reversal_of_id`, `total_debit`, `is_auto`), Then the whole request is refused `unknown_field` naming every offending key in `fields`. Those values are always derived by the service, never supplied.
- AC-16a: Given the closed `PostingRefusal` vocabulary, When inspected, Then it contains every code named in this spec, including `unknown_field`, `invalid_actor` and `unknown_reference`; list filters of the read handlers reuse T-032's existing `validation`, `page_size_invalid` and `cursor_invalid`.

### C. Amounts and the balance rule

- AC-17: Given a line, When its `debit` or `credit` is not a string (integer, float, boolean, null where a value is required), Then it is refused `invalid_amount`; a PHP float is never accepted, never coerced, and never produced by the service.
- AC-18: Given an amount string, When it does not match `^(0|[1-9][0-9]{0,13})(\.[0-9]{1,4})?$`, Then it is refused `invalid_amount`. Refused: empty string, `-1`, `-0`, `+5`, `1e3`, `1,000.00`, ` 5`, `5 `, `05`, `.5`, `5.`, `1.00001` (more than four decimal places is refused, never rounded, D-3), a 15-digit integer part, a non-ASCII digit, a null byte.
- AC-19: Given a line, When both `debit` and `credit` are positive, Then it is refused `line_both_sides`; when both are zero (any spelling of zero) it is refused `line_zero`. Exactly one side is positive. An omitted side counts as `0`.
- AC-20: Given a request, When the exact sum of all line debits differs from the exact sum of all line credits (computed in exact decimal arithmetic, compared at four places), Then it is refused `entry_unbalanced` carrying both totals as strings, and nothing is written. Proven with sums such as `0.1 + 0.2` against `0.3` (balanced) and `0.0001` out of balance (refused), so no floating-point drift is possible.
- AC-21: Given a balanced request, When the sum of either side does not fit `DECIMAL(18,4)` (more than 14 integer digits), Then it is refused `total_out_of_range`; a request of 200 lines each at the maximum single-line amount proves it.
- AC-22: Given a balanced entry, When it is stored, Then `total_debit` and `total_credit` equal the exact sums of its lines, are equal to each other, and each line is stored at exactly four decimal places; reading it back returns the same strings that were submitted, normalised to four places (`"12.5"` is returned as `"12.5000"`).

### D. Accounts

- AC-23: Given every distinct `account_id` on the lines, When the entry is checked, Then each is resolved through `ChartOfAccounts::postable`, and the first refusal is returned unchanged (`account_not_found`, `account_not_live`, `account_inactive`, `account_not_postable`) with the offending line index; nothing is written. A header, an inactive account, a soft-deleted account and a nonexistent id are each proven.
- AC-24: Given the same account appears on several lines, When the entry is checked, Then it is accepted (no rule forbids it) and each line is stored separately; a line debiting and another crediting the same account in one entry is accepted.
- AC-25: Given accounts are locked for the check, When two postings touch the same accounts in different line orders, Then locks are taken in ascending account id order so they cannot deadlock. `[ENGINE]` two-connection proof.
- AC-26: Given an account that is deactivated, soft-deleted or turned into a header by a concurrent request after the check started, When the posting commits, Then it could not have committed ahead of the shared lock held by `postable`; the concurrent change waits or is refused, never a line on a dead account. `[ENGINE]`.

### E. The fiscal period

- AC-27: Given a request that passed shape, amount and balance checks and is not a replay, When the period is resolved, Then `PeriodGate::clear(entry_date, actor_id)` is called inside the same transaction, and the returned period id is stored as the entry's `period_id`. The entry's own date and the period's date range always agree.
- AC-28: Given the gate refuses, When `post` runs, Then the refusal is returned with its `PeriodRefusal` code (`invalid_date`, `no_fiscal_period`, `period_closed`, `period_soft_closed`) unchanged and nothing is written. A closed period refuses every actor, including finance; a soft-closed period refuses an actor without `finance.periods.post_adjustment` and clears one who holds it; a null actor (system posting) is refused in a soft-closed period.
- AC-29: Given the date is the first day, the last day, and the day after a period, When posted, Then the first two resolve to that period and the third to the next period or `no_fiscal_period` (the gate's AC-49 behaviour is not re-implemented). A tenant with no fiscal year refuses every post (`no_fiscal_period`).
- AC-30: Given a period closes while a posting is in flight, When both run, Then the close waits for the posting to commit, and a posting started after the close commits sees the closed status and is refused (shared lock, T-032 AC-50). `[ENGINE]` two-connection proof.
- AC-31: Given a document's date lies in a period that closed before its queued posting ran, When the service is called, Then it refuses with the gate's code and never substitutes another date (D-13); the caller owns what happens next.

### F. Document numbering

- AC-32: Given a successful post, When the entry is stored, Then it has a unique `number` of the form prefix plus zero-padded sequence (default `JE-000001`), allocated from the company-wide `journal_entry` sequence row inside the posting transaction. The migration seeds that row once (prefix `JE-`, padding 6, no reset, next number 1); running it twice leaves one row (D-8).
- AC-33: Given a refused request (any code), a replay (AC-34), or a posting whose transaction rolls back, When the sequence is read afterwards, Then it is unchanged: no number is consumed by anything that did not commit an entry. After N committed entries the numbers are exactly 1 to N, gap-free and strictly increasing in commit order.
- AC-33a: Given two concurrent posts, When both commit, Then they receive different numbers and neither fails on a duplicate-number error; the `number` column also carries a unique key that would refuse a duplicate. `[ENGINE]` for the two-connection proof.
- AC-33b: Given the sequence row is missing, When a post runs, Then the service itself never creates the row; the shared allocator (`DocumentNumbers`, T-030) recreates it inside the posting transaction with the same defaults as the seed (`JE-`, padding 6, no reset), so a recreated row never changes the number format, and the row commits or rolls back with the entry (numbering stays gap-free). The allocator's defaults carry `journal_entry` => `JE-` (D-25).

### G. Idempotent posting

- AC-34: Given an entry already exists for `(source_type, source_id, source_purpose)` and the new request has the same payload fingerprint (D-6), When `post` runs, Then it returns the stored entry with `replayed = true`, writes no row, writes no audit row, consumes no number and does not call the gate or the account check. The returned `PostedEntry` equals the first call's in every field except `replayed`. This holds for a sequential replay; the loser of a concurrent same-key race has already run the gate and account check, and its outcome is exactly AC-40's (D-31).
- AC-35: Given the same key and a different payload (any difference in entry date, branch, description, line count, line order, any line's account, numeric debit or credit value, memo or dimension; compared on the stored, normalised form, so `"12.5"` and `"12.5000"`, or `"x"` and `" x "`, are one payload, D-30), When `post` runs, Then it is refused `idempotency_conflict` and the stored entry is untouched.
- AC-36: Given the same key, the same payload and a different actor or a later clock, When `post` runs, Then it is still a replay (actor and clock are not part of the fingerprint).
- AC-37: Given a stored entry whose period has since closed, When the same document is replayed with the same payload, Then it returns the stored entry (replay is detected before the gate, D-6); a different payload is still `idempotency_conflict`; a brand-new key into that period is refused by the gate.
- AC-38: Given the stored entry has since been reversed, When the original document is replayed, Then it returns the original entry (status `reversed`, `replayed = true`); it never posts a second time.
- AC-39: Given `source_purpose` is null, When two entries are posted for the same `(source_type, source_id)` with null purpose, Then the second is a replay or a conflict, never a second row. Uniqueness for a null purpose is enforced by the stored key itself (not by a nullable column in a unique index, which both engines treat as distinct), and a one-row-per-key proof runs on the SQLite stand-in and `[ENGINE]`. Different purposes for one document (for example `revenue` and `cogs`) each get their own entry.
- AC-40: Given two simultaneous posts with the same key, When both run, Then exactly one entry exists afterwards; the loser returns the winner's entry as a replay (same payload) or `idempotency_conflict` (different payload), and a duplicate-key database error is never surfaced to the caller. `[ENGINE]`.
- AC-41: Given an entry of type `manual` or `opening` with no `source_id`, When the same request is posted twice, Then two entries are created (no key exists to match); this is stated behaviour, and the callers own their own double-submit control (D-29).

### H. What is stored, and append-only

- AC-42: Given a posted entry, When its row is read, Then it carries: ULID id, `number`, `entry_date`, `period_id`, `branch_id` (nullable), `source_type`, `source_id` (nullable), `source_purpose` (nullable) and its stored uniqueness key, `status` (`posted`), `total_debit`, `total_credit`, `description`, `reversal_of_id` and `reversed_by_id` (nullable), the payload fingerprint, `posted_at` (server clock, UTC), `posted_by` (the actor or null), `is_auto` (true when `posted_by` is null), `created_by`, `created_at`, `updated_at`. No `deleted_at`, no `tenant` column, no currency columns (D-4).
- AC-43: Given a posted entry, When its lines are read, Then each has a ULID id, `entry_id`, `line_no` (1 to N, in request order), `account_id`, `debit`, `credit` (each `DECIMAL(18,4)` default 0), `memo` and the seven dimension columns; the unique key `(entry_id, line_no)` exists; `created_at` is set; no line carries a unit, quantity or currency (`docs/database.md` section 7).
- AC-44: Given the stored decimal columns, When the schema is checked, Then every money column is `DECIMAL(18,4)`, and the existing decimal-precision test stays green.
- AC-45: Given the table definitions, When inspected, Then these checks are declared: `CHECK (debit = 0 OR credit = 0)` and `CHECK (debit >= 0 AND credit >= 0)` on lines (either as two checks or as the kernel's single combined `oneSided` check, provided its SQL text states both conditions, D-34), `CHECK (total_debit = total_credit)` on entries, through the kernel check-constraint helpers (including the approved additive `CheckConstraint::balanced`). On the SQLite stand-in only the exact SQL text is proved (the helpers are engine-only and the stand-in cannot add a CHECK after creation); enforcement, meaning a direct insert violating each is rejected, is `[ENGINE]` on MySQL 8.4 and MariaDB 10.4 and is NOT VERIFIED under ENGINE_HOLD. The service itself refuses every violating shape before any write (AC-17 to AC-20) (D-24).
- AC-46: Given an entry and its lines, When written, Then all lines are inserted in one statement in the same transaction as the entry, so no committed entry exists without all of its lines, and no committed line exists under an entry whose totals differ from the lines' sums. A forced failure on the line insert leaves neither.
- AC-47: Given the product, When searched, Then no update, delete or soft-delete operation exists for a line, and none for an entry beyond AC-48; no HTTP route, handler, command or console command edits or removes a posted row.
- AC-48: Given a posted entry, When anything changes it, Then only `status` (to `reversed`), `reversed_by_id` and `updated_at` may change, only inside `reverse` (D-9); the static test (AC-5) fails the build on any other update of a journal column.

### I. Reversal

- AC-49: Given a posted, non-reversal entry and a request carrying its id, a reversal `entry_date` and a `reason` (1 to 500 characters, not blank), When `reverse` runs, Then a new entry exists with `source_type = reversal`, `source_id` = the original id, `reversal_of_id` = the original, status `posted`, its own number, and for each original line one line on the same account with debit and credit swapped, the same dimensions and memo and the same `line_no`; its totals equal the original's totals.
- AC-50: Given a successful reversal, When the original is read, Then its status is `reversed` and `reversed_by_id` is the new entry; its amounts, lines, number, date and period are unchanged; both changes commit in one transaction with the new entry.
- AC-51: Given an entry already reversed, When `reverse` is called again with a different date or reason, Then it is refused `already_reversed`; given the identical request (same date and reason), Then it returns the same reversal entry as a replay. `reverse` never returns `idempotency_conflict` (D-23, corrected by D-28).
- AC-52: Given an entry whose `source_type` is `reversal`, When `reverse` is called, Then it is refused `not_reversible`. A draft entry is refused `not_reversible`. An unknown, malformed or other id is refused `entry_not_found`.
- AC-53: Given a reversal date, When it is missing, malformed or earlier than the original's `entry_date`, Then it is refused (`invalid_date`, or `reversal_before_original`); a missing date is never defaulted.
- AC-54: Given a reversal date, When it is in a closed period, or soft-closed for an actor without the adjustment permission, Then the gate's refusal is returned and the original stays `posted`. The original's own period status is irrelevant; only the reversal date is gated.
- AC-55: Given any account on the original lines is now inactive, a header or deleted, When `reverse` runs, Then it is refused with the account refusal and nothing is written (D-14). Branch and line dimensions are not re-checked for liveness: the reversal copies them as stored and raises no `unknown_reference` (D-35).
- AC-56: Given `reason`, When it is missing, blank or over 500 characters, Then it is refused `reason_required` or `invalid_reason`; it is stored in the audit row and in the reversal's `description`.
- AC-57: Given two simultaneous reversals of one entry, When both run, Then exactly one reversal exists and the other is refused `already_reversed` or returns the winner as a replay (same payload); the original row is locked for the check. `[ENGINE]`.
- AC-58: Given a document that is voided in FP-262, When it calls `reverse`, Then it needs no `finance.journal.*` permission (the service checks none, D-10); the same call through the human handler (AC-70) does.

### J. The three T-032 ports, answered from the journal

- AC-59: Given an account with at least one line, in an entry of any status, When `AccountUsage::hasPostings` is asked, Then it returns true; with no line it returns false; an unknown id returns false.
- AC-60: Given a fiscal year date range, When `FiscalYearUsage::hasEntries` is asked, Then it returns true when any entry has `entry_date` in the range inclusive (any status), false otherwise, and the first and last day of the range each count.
- AC-61: Given a period range, When `PeriodCloseBlockers::blockersFor` is asked, Then it returns one `PeriodBlocker('draft_journal_entries', n)` when `n > 0` entries in status `draft` are dated in the range inclusive, and an empty list when none (D-5, D-12). A posted or reversed entry is never a blocker.
- AC-62: Given the service container, When the application boots, Then the three interfaces resolve to the journal implementations and no longer to the "none" defaults; the defaults remain available to tests that need them. A test posts one entry, and then proves with the real bindings that T-032's guards refuse: recoding an account that has a line (`code_locked`), deleting or retyping it (`has_postings`, `type_change_refused`), deleting the year containing the entry (`fiscal_year_in_use`). The T-032 fake-bound tests stay green.
- AC-63: Given the three ports, When called, Then each reads only journal tables and returns primitives or `PeriodBlocker` values; the module boundary check stays clean. Read as (D-31): the accounts and periods code of Finance never names a journal table, and only the journal's own port implementations answer the three ports; a source scan enforces it.
- AC-64: Given the port answers sit on the posting path of T-032 guards, When there are 200 000 lines for one account, Then `hasPostings` uses an indexed existence read (`(account_id, entry_id)`) and returns without a full scan. On the stand-in this is asserted from the query plan using that index; the engine plan and the 10-line transaction time are written into an engine test and reported NOT VERIFIED under ENGINE_HOLD (D-31).

### K. Audit

- AC-65: Given a successful `post` or `reverse` that writes an entry, When it commits, Then exactly one audit row is written in the same transaction through the existing `AuditTrail` contract: module `finance`, action `journal_entry_posted` or `journal_entry_reversed` (house snake_case style, D-27), subject type `journal_entry`, subject id, actor id (the employee; null when `posted_by` is null, in which case `after.is_auto` is true; the audit contract has no actor-type parameter, so the row's actor type is not `system`, D-27), and in `after` the number, source type and id, source purpose, entry date, period id, totals and line count; for a reversal also the reason and the original id (D-19). The actions are declared through the existing audit-action declaration mechanism.
- AC-66: Given a refusal, a replay, a failed transaction or a read, When it completes, Then no audit row is written for it; a rolled-back posting leaves no audit row.
- AC-67: Given a reversal, When it commits, Then exactly one audit row exists for it (the `journal_entry_reversed` row for the new entry); the original's status flip writes no separate row and is identified by `after.original_id`.

### L. Permissions and reads

The service enforces no permission (D-10). Four thin handlers do, each checking first, before any transaction and before any read of the target, refusing `not_permitted`.

- AC-68: Given `finance.journal.view`, When a caller lacks it, Then `GetJournalEntry` (by id, or by `source_type`, `source_id`, `source_purpose`) and `ListJournalEntries` refuse `not_permitted`. With it, `GetJournalEntry` returns the entry with its lines ordered by `line_no`, amounts as four-decimal strings, and an unknown id is `entry_not_found`. The read handlers throw only `FinanceRefused`; `FinanceRefusal` carries `entry_not_found` with the same code string as `PostingRefusal` (D-37).
- AC-69: Given `ListJournalEntries`, When called, Then it pages from its first version (page size 1 to 200, default 50; out of range refused, as T-032 AC-61), filters by date range (`from`, `to`, strict `Y-m-d`, inclusive), `status`, `source_type`, `period_id`, `account_id`, `branch_id`, validates every filter case-sensitively (invalid refused), orders by `entry_date` descending then `number` descending (string order, correct while numbers keep equal width, that is below 1,000,000 entries and with an unchanged prefix, D-36) then `id`, and never returns lines (summary rows only).
- AC-70: Given `finance.journal.reverse`, When a caller lacks it, Then `ReverseJournalEntry` refuses `not_permitted` before any read; with it, the handler calls `PostingService::reverse` in its own transaction with the authenticated employee as actor.
- AC-71: Given `finance.journal.post_manual`, When a caller lacks it, Then `PostManualJournalEntry` refuses `not_permitted`; with it, the handler fixes `source_type = manual` (a body naming `source_type` is refused as an unknown key), posts with the authenticated employee as actor, and the entry's `is_auto` is false.
- AC-72: Given the permission keys, When the registry is synchronised, Then `finance.journal.view`, `finance.journal.post_manual` and `finance.journal.reverse` are declared through the existing permission declaration mechanism and appear in the permission catalogue; no role is seeded here (roles task).
- AC-73: Given a handler call by an employee who is not live, or whose id is not a ULID, When it runs, Then it is refused before any write; `posted_by` always references a live employee or is null.
- AC-73a: Given a direct service call (`post` or `reverse`) whose actor id is not null and is malformed or not a live employee, When it runs, Then it is refused `invalid_actor` and nothing is written. The check runs after the balance check and before the replay lookup (D-38), so an invalid actor never reads, replays or conflicts with a stored entry; so AC-73's rule holds for module calls as well.
- AC-74: Given a service call by a module with a null actor (system or offline replay), When it posts, Then `posted_by` is null and `is_auto` is true, and the soft-closed rule treats the actor as lacking the adjustment permission (AC-28).

### M. Migrations, multi-tenancy, nothing destructive

- AC-75: Given the schema change, When it runs, Then it is expand-and-contract: the expand migrations create `journal_entries` and `journal_lines` with their own indexes, uniques, CHECK constraints and all of their own foreign keys declared inside the create step (house precedent; the new tables are empty so nothing needs validating): `journal_entries.period_id` onto `fiscal_periods`, `journal_lines.account_id` onto `accounts` (`RESTRICT`), `journal_lines.entry_id` onto `journal_entries` (`CASCADE`, a pure child), `journal_entries.reversal_of_id` and `reversed_by_id` onto `journal_entries` (`RESTRICT`), `journal_entries.branch_id`, `posted_by` and `created_by` onto their tables where those exist, and the line dimension keys only where the target table exists (D-16); the expand also seeds the sequence row (backfill phase). A separate contract migration, ordered after the expands, adds the one key onto a pre-existing populated table: `fiscal_years.retained_earnings_entry_id` onto `journal_entries` (`RESTRICT`). Expand and contract are separate files; the contract file is one fleet run (D-24).
- AC-76: Given the contract migration (the single `fiscal_years` key), When it runs, Then it first checks that every non-NULL `fiscal_years.retained_earnings_entry_id` names an existing entry and refuses naming table, column and count otherwise; a fixture with one orphan proves the refusal and that no key was added. Every migration is forward-only (no `down()`).
- AC-77: Given the migration planner, When it classifies every T-033 migration, Then none is `is_destructive` (T-008 D-10): nothing drops, renames, narrows or tightens an existing column, no `NOT NULL` is added to an existing column, and new-tenant provisioning is not blocked when backups are unavailable.
- AC-78: Given the existing tenants, When the migrations run on each, Then the tables are created empty and one sequence row is added; nothing is backfilled and no existing row changes. Running the whole set twice is a no-op the second time.
- AC-79: Given the two new tables, When their columns are listed, Then neither has a `tenant_id` or any tenant-naming column (the database is the boundary); a test with two tenant databases posts in one and proves the other's journal, sequence and ports are unaffected.
- AC-80: Given the identifier columns (`id`, `entry_id`, `account_id`, `period_id`, the dimensions), When the schema and collation tests run, Then every ULID column is `CHAR(26)` through the shared identifier helper, no other collation is named, and the schema catalogue lists the two tables.
- AC-81: Given `fiscal_years.retained_earnings_entry_id` now has a key, When T-032's existing tests run, Then they still pass; the only edits to existing T-032, host and sales tests are assertions that pinned what T-033 legitimately changes (the default "none" port bindings, the exact key count, the foreign-key count, the absent key on that column, absolute `number_sequences` row counts), each listed in plan section 8. No test is deleted, skipped or loosened beyond that list (D-33).
- AC-82: Given the engine tests for this task (uniqueness under concurrency, CHECK enforcement, lock ordering, gate versus close, number allocation, reversal race), When the gate runs under ENGINE_HOLD, Then they are written, tagged `[ENGINE]`, skipped or excluded with a stated reason and reported NOT VERIFIED; none is reported passed without an engine run. CHECK enforcement (AC-45), the engine query plan (AC-64) and the unique-key and lock proofs belong to this set.

## Out of scope

- Posting rules per document type and `account_mappings`: FP-262. Cash and bank accounts and transfers: FP-263. AR and AP: FP-264, FP-265. Expenses: FP-266. Tax configuration (so `tax_id`, `tax_base`): FP-267. Reports and the trial balance: FP-268. Screens: FP-269.
- `account_balances`, any trial balance or P&L (D-11).
- A draft-creating door, an approval flow for manual entries, recurring or template entries (D-5).
- Multi-currency, `currencies`, `exchange_rates`, base amounts (D-4); `project_ref`, `tax_id`, `tax_base`, `plugin_name` (D-7, D-16).
- The year-end closing entry and the `closing` year state (T-032 D-5; still unowned).
- The nightly integrity re-check job and a database trigger (D-20); the outbox event for postings (D-18).
- HTTP routes and any API surface; the four handlers are called by tests and later layers.
- Offline sync of journal rows; archiving or partitioning `journal_lines`.
- Parking, alerting or re-dating a queued document whose period closed (D-13; FP-262).
- Wiring `journal_entry_id` columns onto sale, GRN, payment and other documents: the owning tasks.

## Non-functional requirements

- Integrity: one transaction per posting, with every lock and ordering rule above; the replays, conflicts, races and dead-record failure modes found in T-028 and T-032 reviews are each covered by a named criterion (AC-34 to AC-41, AC-25, AC-26, AC-30, AC-57).
- Security: no float in any money path (AC-17); closed vocabularies for every enum input; unknown keys refused; no permission-bypassing write path (AC-5, AC-47).
- Performance: posting a 10-line entry is a bounded number of statements independent of the size of the journal (no table scans, no per-line N+1 account queries beyond one lock per distinct account). The company-wide number allocation serialises all postings through one row until commit; at the design's busy-tenant figure (1 000 sales per day) this is acceptable and the plan states the measured transaction time. `hasPostings` and the date-range ports are indexed reads (AC-64). Reads page from the first version.
- Migration: forward-only, per tenant, expand before contract, never destructive (AC-75 to AC-78).
- Accessibility: not applicable (no UI).

## Questions for the owner

Each has a built default (see the decision file); none blocks work.

1. Number format and sequence: company-wide `JE-000001`, no yearly reset (D-8). Does the owner want a per-branch or per-fiscal-year numbering? Default: company-wide, gap-free.
2. Manual entries: `finance.journal.post_manual` is a separate key from the soft-closed allowance, so an accountant can post manual entries in open periods without being able to post into soft-closed ones (D-10). Default as stated.
3. Reversal may not be dated before the original (AC-53) and requires every account to be active again (AC-55, D-14). Default as stated.
4. The nightly integrity re-check job named in `docs/database.md` (D-20): which backlog row owns it? Default: not built here; the lead files a row.
5. The owner should still review the seeded chart codes and subtypes before this task's first posting ships (T-032 D-6, D-23): system codes freeze once data posts.

## Open questions

1. None blocking. All questions that would have blocked are answered as D-1 to D-23.

## Assumptions

1. Merge preconditions before any code (D-31): T-032 is DONE and merged into this branch, and T-030 (c354a0e or later, which carries T-029) is merged, for the shared `DocumentNumbers` allocator. The additive kernel helper `CheckConstraint::balanced()` and the one-line `journal_entry` => `JE-` default in the allocator are approved by the lead. Until then the contracts named here are read from this worktree's `packages/contracts/src/Finance`, and the sections that depend on built Finance code (AC-27 to AC-31, AC-59 to AC-64, AC-75, AC-81) are not buildable.
2. Journal code lives in the Finance package (`packages/finance`, slices in `src/Features/<Slice>/`, D-39), registered as T-032 D-24 says, so the ports can be rebound without a new boundary. The architect confirms the placement.
3. Numbers come from the shared `DocumentNumbers` allocator (T-030); no second allocator is built (D-25). Verified by the architect in plan section 0.
4. The employees, branches, locations, departments, customers and suppliers tables may not all exist when this task is built; each foreign key is added only where its target exists (D-16).
5. bcmath (or an equivalent exact decimal facility) is available in both runtime and CI; the XAMPP table lists it. No float arithmetic is used anywhere.
6. The soft-closed adjustment permission is T-032's built key `finance.periods.post_adjustment` (D-39); the older name `finance.post_closed_period` in `docs/database.md` section 7 is stale (D-10). The architect or lead corrects that documentation line.
7. The unique key for idempotency is a stored key built so a null purpose collides (D-6); the exact column design is the architect's. Whatever sentinel is used must not be a legal purpose value (AC-8 forbids the empty string and a leading non-letter, so a one-character `-` or empty string cannot collide).
8. No entry-date rule beyond the period gate exists: future-dated and backdated entries are accepted if the period is open (D-22).
