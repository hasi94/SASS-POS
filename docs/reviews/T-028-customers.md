# Code Review: T-028 Customers

## Round 1 — Verdict: CHANGES REQUESTED

One Critical. It is in the merge (D-20), the part explicitly asked to be checked hardest. The rest of the ledger work is sound: one writer per ledger, the balance read under `FOR UPDATE`, the calculator run on the locked values, insert and maintained-column update in one transaction, no `version` bump, and no update or delete on either ledger table. There are 6 Warnings and some Suggestions.

What was run: `pest ../../packages/customers/tests tests/Feature/QaCustomersSourceRulesTest.php` from `modules/host` gave **210 passed (2585 assertions), 49.66 s**. The full `composer check` was not independently re-run; relied on QA's two independent runs.

## Critical (must fix)

**R-1 | `packages/customers/src/Features/MergeCustomers/MergeCustomersHandler.php:173-201` (and `:118-123`)**

**Problem:** the merge throws away the `replayed` flag that `CreditLedgerWriter::post()` returns, and never checks that the source's balance actually reached zero before it soft-deletes the source. D-20's "zero by construction" therefore depends on the writer really inserting a row. It does not have to:
- The writer's replay key is `(reference_type, reference_id, type)`.
- `reference_type` is free text on both public entry points (`RecordCreditLedgerEntryHandler` and the `CustomerCreditLedger` contract).
- So anyone can post an `adjustment` with reference `customer_merge_out` / `<source id>`.

Reproduction (pure handler logic, provable on SQLite today):
1. Customer S (LKR) has a `credit_sale` of 200, so its balance is 200.
2. `RecordCreditLedgerEntry`: type `adjustment`, reference_type `customer_merge_out`, reference_id = S, credit 100. S's balance is now 100.
3. `MergeCustomers(S, T)`:
   - the source balance is 100, so the handler posts adjustment credit 100 with key (`customer_merge_out`, S, adjustment);
   - the pre-check finds the row from step 2 with the same customer, amounts and currency, so it returns `replayed = true` and **writes nothing**;
   - `merge_in` then debits T by 100;
   - S is marked merged and soft-deleted **while still holding a balance of 100**.

Result: 100 of receivable now sits on both S and T. S is hidden from every list, AC-10's guard is bypassed, and the invariant D-20 exists to protect is false. The mirror case, a pre-existing (`customer_merge_in`, S, adjustment) row on T, makes the target's entry replay instead, and the money disappears. The loyalty pair is not affected, because it posts with `id = null`.

**Fix:**
```php
$out = $this->creditWriter->post(/* ...customer_merge_out... */);
$in  = $this->creditWriter->post(/* ...customer_merge_in... */);
if ($out->replayed || $in->replayed) {
    throw CustomersRefused::validation("A merge entry for [{$sourceId}] already exists; refusing to merge.");
}
```
Also add a postcondition before the soft-delete at line 118. Re-read the source under the lock, and throw if `credit_balance` or `loyalty_points` is not zero. That turns "zero by construction" from an assumption into a check. Also reserve the internal reference types: refuse `customer_merge_out`, `customer_merge_in` and `customer` (the opening entry) in `RecordCreditLedgerEntryHandler` and `ContractCreditLedger`, so only internal callers can write them.

Tests to add:
- a merge refusal for each of the two pre-seeded-key cases, writing nothing;
- a mutation check: removing the `replayed` check makes the new test go red.

## Warnings (should fix)

**W-1. The replay backstop cannot see the winning row on real MySQL.**
- **Where:** `packages/customers/src/Ledger/CreditLedgerWriter.php:133-145`, and the same pattern at `packages/customers/src/Loyalty/LoyaltyWriter.php:144-152`.
- **Problem:** the replay pre-check (line 72) is a plain, non-locking read, and it is the first read in the transaction. Under InnoDB REPEATABLE READ, that read fixes the transaction's snapshot. Walk through the race AC-21 describes: writers A and B both miss the pre-check; B waits on the customer lock while A commits; B's insert hits the unique key; the catch block re-reads with a plain `first()`, which still uses the old snapshot, gets `null`, and throws `replay_conflict` with "could not be read back". So an identical concurrent retry — the exact offline-sync case AC-21's backstop is there for — comes back as a conflict instead of `replayed = true`. No money is double-posted (the failure is safe), but AC-21's stated behaviour does not hold on the engine. The SQLite backstop test cannot tell the difference.
- **Fix:** do the catch-block re-read as a locking read (`->lockForUpdate()->first()`) in both writers. Add a two-connection case to the engine test.

**W-2. The engine test will fail, or prove nothing, the day ENGINE_HOLD lifts.**
- **Where:** `modules/host/tests/Engine/CustomersSchemaEngineTest.php:163, 232, 311` and `:297-340`.
- **Problem 1:** `insertGetId(..., 'id')` on a CHAR(26) ULID primary key returns `0` on MySQL (`MySqlProcessor::processInsertGetId` casts `lastInsertId()` to int; the table has no AUTO_INCREMENT). Every ledger insert in the CHECK, unique-key and lock tests would fail its foreign key or lock a nonexistent row.
- **Problem 2:** the lock test never calls `CreditLedgerWriter` — it runs a raw `lockForUpdate()` on both connections, testing MySQL, not this code. Plan step 17 says connection B "calls the writer".
- **Fix:** build the row first, insert it, use its real `id`. Have connection B call `CreditLedgerWriter::post()` on its own connection, assert it times out with no row written, and that the retry's `balance_after` includes A's write.

**W-3. The one-writer scan's exemptions cannot tell a read from a write, and the append-only scan is narrower than the plan.**
- **Where:** `modules/host/tests/Feature/QaCustomersSourceRulesTest.php:100-104` and `:332`.
- **Problem 1:** the exemption is per file and per token (real narrowing), but within an exempt file any use of the token passes. `DeleteCustomerHandler`/`MergeCustomersHandler` are described as read-only, yet `'credit_balance' => $x` written inside `MergeCustomersHandler` would still pass. `CreateCustomerHandler` could likewise start writing a non-zero `credit_balance` without the scan noticing.
- **Fix:** make the exemption shape-specific — Delete/Merge accept only property reads (`/->credit_balance\b/`) and still flag the string-literal form; Create accepts only the exact zero-default literals.
- **Problem 2:** the append-only scan reads only `packages/customers/src`; plan step 16 says "anywhere".
- **Fix:** scan the same set as the one-writer scan (`packages/*/src`, `modules/*/src`, `modules/host/app`); consider adding `upsert(`/`updateOrInsert(`/`truncate(` to the pattern.

**W-4. Create and update read the customer group without a lock.** Same class as T-027's W-1/W-6 and FP-429/430.
- **Where:** `CreateCustomerHandler.php:91`, `UpdateCustomerHandler.php:78`.
- **Problem:** `DeleteCustomerGroup` locks the group and counts live customers, while `CreateCustomer` concurrently reads the group without a lock and copies its credit defaults — the insert's FK check waits for the delete to commit then succeeds (soft-deleted row still exists), leaving a live customer in a soft-deleted group, breaking AC-3.
- **Fix:** `Lookup::live($connection, 'customer_groups', $groupId, 'group', lock: true)` in both places.

**W-5. `UpdateCustomer` edits dead records.**
- **Where:** `UpdateCustomerHandler.php:62`.
- **Problem:** no `whereNull('deleted_at')` on the read — a soft-deleted or merged customer's status, credit limit, `possible_duplicate_of` etc. can still be edited.
- **Fix:** add `->whereNull('deleted_at')`, refuse `notFound` or `notLive`/`alreadyMerged`.

**W-6. Some inputs reach the database without a range/format check** — a raw `QueryException` (500) on strict MySQL, silent clamping on non-strict.
- **Where:** `expires_at` in `RecordLoyaltyTransactionHandler.php:59` (unvalidated up to 29 chars into DATETIME(3)); `Fields::decimal` (`Fields.php:173-200`, no integer-digit cap — `credit_limit`/`default_credit_limit`/`opening_balance` accept >14 integer digits); `discount_percent`/`loyalty_multiplier` can exceed DECIMAL(9,4)'s 99999.9999.
- **Fix:** validate `expires_at` as `Y-m-d H:i:s[.v]` or null; add a `maxIntegerDigits` arg to `Fields::decimal` (14 for (18,4) columns, 5 for (9,4) columns).

## Suggestions (optional, filed as follow-ups)

- `CreateCustomerHandler.php:156-157`: opening-balance sign test uses `(float)` against Ruling 3's "a float never appears" — use `BigDecimal::of(...)->isZero()`/`isNegative()` instead.
- `MergeCustomersHandler.php:216-219`: "most recent loyalty transaction" ordered by `id` — F-15 allows caller-supplied offline ids, so use `orderByDesc('created_at')->orderByDesc('id')`.
- `opening` ledger entries: the public writer accepts `opening` any number of times with any reference, skipping the credit-limit/status check — effectively unlimited credit under another type name. AC-24 allows this as written; consider restricting `opening` to the customer's own reference and log the decision with `ba`.
- Merge chains (A→S→T) leave A's `merged_into_id` pointing at a dead record; note for FP-240/FP-264 that history readers must follow it transitively.
- BUG-1 follow-on: the fix is correct, but `UpdateCustomerHandler.php:154` always parses `before->status` through the enum even when the patch doesn't touch status — a future out-of-enum value (e.g. from Sync) would fail every update to that row. Only parse when the patch touches status/blocked_reason. Message nit at `CustomerRules.php:28`: reads "A inactive" (should be "An inactive").
- `CreateCustomerGroupHandler.php:89-90`: a `default_marker` unique violation on concurrent default-group creates is reported as "code already in use" — tell the indexes apart.
- `LoyaltyWriter::resolveReplay` compares customer/program/type/points/value but not `expires_at`/`employee_id`/`notes` — document this as the replay fingerprint.
- Notes/communications on a merged-away source (`AddCustomerNoteHandler:48`, `LogCustomerCommunicationHandler:67` via `Lookup::any`) stay on the source and never appear on the target — not a spec breach (AC-34/35 only say "existing") but worth noting.

## Done well

- D-20 followed faithfully — ledger/loyalty rows never move (append-only scan confirms), only addresses/notes/communications reassign. Both customer rows locked in ascending id order; the writers' second lock on the same row is a no-op. Overlapping-pair merges (A→B with B→A, A→B with B→C, A→B with A→C) and merge-against-writer races on either side all serialise correctly.
- The read-then-write races T-027 had are mostly gone: balance/limit/status/currency all read under `FOR UPDATE` inside the writer; `UpdateCustomer` locks the row; `DeleteCustomer` locks before its balance guard.
- Calculators are well built: pure, BigDecimal with `RoundingMode::Unnecessary`, fixed check order, limit tested with `>`, hard side/sign rules enforced. Test data covers M1-M10.
- Replay design matches D-21/F-4: a different payload is refused, never silently dropped (W-1 is only the engine-snapshot detail of the backstop).
- Maintained-column refusal rejects the whole request before any read; `[SYNC]` columns are genuinely inert.

Files: `packages/customers/src/Features/MergeCustomers/MergeCustomersHandler.php` (R-1); `packages/customers/src/Ledger/CreditLedgerWriter.php`, `packages/customers/src/Loyalty/LoyaltyWriter.php` (W-1); `modules/host/tests/Engine/CustomersSchemaEngineTest.php` (W-2); `modules/host/tests/Feature/QaCustomersSourceRulesTest.php` (W-3); `packages/customers/src/Features/CreateCustomer/CreateCustomerHandler.php`, `packages/customers/src/Features/UpdateCustomer/UpdateCustomerHandler.php` (W-4, W-5); `packages/customers/src/Support/Fields.php`, `packages/customers/src/Features/RecordLoyaltyTransaction/RecordLoyaltyTransactionHandler.php` (W-6).
