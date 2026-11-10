# Plan: T-028 Customers: groups, credit ledger and loyalty

Spec: `docs/specs/T-028-customers.md` (AC-1 to AC-41 plus AC-6a and AC-7a, groups A to G). Decisions: `docs/decisions/T-028-customers.md` (D-1 to D-30), as corrected in `f116c1e`.
Design authority: `docs/database.md` §3.1 to §3.3, §6.7 (`approval_requests`), §6.9, §6.16 (`deliveries`), §6.18, §12.1 and §12.2. Also `Refference/ARCH.md` §5 (CUSTOMER module) and §12, and the T-027 plan's Rulings 1, 5 and 10 to 13, which carry over unchanged unless restated here.
Backlog: FP-232. Branch: `ai/T-028-customers`. Gate directory: `modules/host`.

**Revision 2.** `ba` folded F-1 to F-19 (section 0.2) into the spec in `f116c1e`, as D-18 to D-30 plus AC-6a and AC-7a. Each correction matches the resolution this plan proposed; the table below maps each finding to where it now lives. Wherever the original text of section 0.2 offered a "fallback" or an "if `ba` declines" alternative, that alternative is now **void**: the behaviour is required by the spec. No step's design changed between revisions. Only the AC and decision references were updated.

| Finding | Now in the spec as | Decision |
|---|---|---|
| F-1 | "Current behaviour", Out of scope | D-18 |
| F-2 | "Current behaviour", AC-15, AC-25, AC-33, AC-36, Out of scope | D-19 |
| F-3 | **AC-13** (rewritten), AC-14 | **D-20** (overturns D-10's merge) |
| F-4 | **AC-21** | D-21 |
| F-5 | **AC-26**, AC-32 | D-22 |
| F-6 | **AC-28** | D-23 |
| F-7 | **AC-40** (complete map) | D-24 |
| F-8 | **AC-6a**, AC-13, AC-20, AC-7a, NFR "single-currency" | D-25 |
| F-9 | **AC-20**, AC-23, AC-30, NFR "no inferred sign" | D-26 |
| F-10 | **AC-7** | D-27 |
| F-11 | **AC-7a**, AC-8 | D-28 |
| F-12 | **AC-8**, AC-12 | D-29 |
| F-13 | **AC-20**, AC-28 | D-30 |
| F-14, F-15, F-16, F-17, F-19 | AC-6, AC-33, Assumptions, AC-2 | informational, accepted |
| F-18 | AC-32 (no edit needed) | confirmed |

**Two stale lines in the revised spec, neither of which changes this plan** (informational, for `ba` or `lead`; non-blocking):
- The spec's header still says "Decisions D-1 to D-17"; the file now runs to D-30.
- "Current behaviour" says T-027 put per-tenant migrations "under the package's own `database/migrations-tenant/` directory". **As built, T-027's twelve migrations live in `modules/host/database/migrations-tenant/`** (measured). This plan follows what was built: section 3 puts T-028's eight there.

**Gate baseline (not re-measured by `architect`).** T-027's QA re-run recorded `composer check` at **13 failed (pre-existing) / 3258 passed / 31 skipped, `test` step 2408.65 s**, against the 7800 s limit. Step 0 measures it again. This task adds roughly 220 cases, most of them pure. Ruling 12 applies.

---

## 0. Verification and rulings

### 0.1 `ba`'s findings, re-read in source

| Claim | Verdict | Evidence |
|---|---|---|
| §6.9 groups eight tables | **Holds** | `docs/database.md` lines 1224 to 1235: `customer_groups`, `customers`, `customer_addresses`, `customer_ledger`, `loyalty_programs`, `loyalty_transactions`, `customer_notes`, `customer_communications`. The spec leaves out several documented columns: `[SYNC]` on `customers` and `loyalty_transactions`, `customers.currency`, `opening_balance`, `client_updated_at`, `customer_ledger.currency`, `loyalty_transactions.value` and `expires_at`. They are handled in F-8, F-11 and F-14, and section 4 lists them all |
| No customer table, no `packages/customers`, no `Contracts\Customers` | **Holds** | The tenant tree's 43 files end at `…002712_create_product_modifier_groups_table.php`. `packages/` holds no `customers`. `packages/contracts/src/` has no `Customers/` |
| `branches` and `employees` exist | **Holds** | `…000501_create_branches_table.php`, `…000304_create_employees_table.php` |
| `custom_field_definitions` exists (T-008/T-011), so `[CF]` can validate | **False (F-1)** | No migration creates it. T-027 F-1 found the same thing, and `…002704_create_products_table.php` says in its docblock that `custom_fields` is not built for exactly this reason |
| D-6: the four deferred columns reference tables that do not exist | **Holds, but the stated mechanism is wrong (F-2)** | No migration creates `accounts`, `ar_documents`, `sales` or `deliveries`. §6.16 makes `deliveries.id` a **BIGINT AUTO** key, so `delivery_id` will be `BIGINT UNSIGNED`, not a ULID |
| D-1/D-7: one task, all eight tables | **Holds** | See 0.3 |
| D-11: no lifetime column, so the tier comes from the current balance | **Holds for today, imprecisely stated, and blocked by F-5** | There is no lifetime-*points* column. `customers.lifetime_value` (lifetime *spend*) **does** exist, but nothing maintains it until FP-233 (AC-11), so it cannot drive a tier today. The current balance is the only basis that works now. The real problem is that AC-26 leaves `tier_rules` unstructured, so AC-32 has nothing it can evaluate (F-5) |
| D-12: no override path to wire up now | **Holds** | The only documented credit override is `approval_requests.type = credit_limit` (§6.7: in-till, `decision_method pin_local`, offline-capable). It belongs to Sales and is not built. T-020's `WorkflowEntityType` has no customer or credit case. One consequence for FP-233 is in section 10 |
| D-14: `database.md` gives no integer type for points | **Holds (only "points signed")**, **but `DECIMAL(18,2)` is wrong (F-7)** | §3.2 documents four precision pairs, and `TenantDecimalPrecisionTest` allows exactly those four. (18,2) would turn it red |
| D-15: `customer_ledger` gets the `journal_lines`-shape CHECK | **Holds** | The kernel's `CheckConstraint` takes integers only, so this needs one new method (step 2) |
| D-16: `customer_addresses` has no `[SOFT]`, so delete is a real delete | **Holds** | Line 1230: `[ID] [TS]` |
| Spec assumption: `restaurant_tables.version` and `payment_methods.version` are codebase precedents | **False (F-16)** | Neither table exists. The precedent is `products.version` (T-027) |

### 0.2 Findings routed to `ba`: all resolved in `f116c1e`

The findings follow unchanged, as the record of what was routed. The resolution in each is now the spec's own text (see the mapping table above), and every "fallback" or "if `ba` declines" clause below is void.

**Spec or decision edits needed (F-1 to F-13):**

- **F-1 (correction): `custom_field_definitions` does not exist.**
  - **Plan:** `customers.custom_fields` is not built, and a `custom_fields` field in a command is refused as unknown. This is T-027 AC-27a and D-12 exactly.
  - **Spec edit:** the "Current behaviour" bullet, plus an Out of scope line.
- **F-2 (correction to D-6, AC-33, AC-36): the deferral is right, the mechanism is misstated.**
  - D-6 says "each owning task adds the column and the constraint together", and AC-33 says "together with the FK". `SourceRules` AC-34 makes a foreign key onto an existing table a **contract**-phase migration, so each owner owes an expand migration (the column) **plus** a contract migration (the key). AC-15 and AC-25 already say this. This is T-027 F-2 repeated.
  - For FP-233, the documented `unique (sale_id, type) where sale_id not null` needs no partial index: NULLs are distinct in a unique index on both engines, so a plain `unique(sale_id, type)` is already exactly that.
  - Smaller text fixes:
    - D-6 says "Eight columns" and then names four;
    - D-10 cites "(D-13)" where it means AC-14;
    - `delivery_id` becomes `BIGINT UNSIGNED` (§6.16), which matters for FP-295.
- **F-3 (correction to AC-13 and D-10): the merge must not rewrite ledger rows.**
  - Reassigning `customer_ledger.customer_id` or `loyalty_transactions.customer_id` **updates append-only financial rows**. That breaks this spec's own AC-19 and AC-27 and §3.3 ("Financial rows are immutable after posting. A correction is a new row"). It also breaks every moved row's `balance_after` chain: a moved row's `balance_after` describes the *source's* running balance.
  - **Plan (Ruling 9): move the balance with compensating entries, through the same writers every other entry uses.**
    - Credit: an `adjustment` pair. The source side carries reference `customer_merge_out`, the target side `customer_merge_in`, and both use the source's id as `reference_id`. That pair is unique because a source can only be merged once.
    - Loyalty: a `transfer` pair. That enum value exists for exactly this.
    - The non-financial children move by `UPDATE`: addresses, notes and communications.
    - After that the source's balances are **zero by the ledger itself**, so AC-10's guard is satisfied rather than bypassed, and nothing is "recomputed by a fresh sum".
  - Also: the **target** must be live and not merged itself, otherwise merges could chain onto a dead record. A moved address keeps `is_default` only if the target has no default of that `type`.
  - **If `ba` keeps reassign-and-recompute:** step 15 changes, and the plan has to accept rows whose `balance_after` no longer matches their own customer's running balance. `architect` advises against it.
- **F-4 (correction to AC-21): a replay with a different payload must be refused.**
  - "A no-op that returns the original row, never an error" means a second `payment` with the same `(reference_type, reference_id, type)` and a **different amount** is silently dropped.
  - T-021's precedent, `RequireIdempotencyKey`, refuses a reused key whose fingerprint differs (`IDEMPOTENCY_KEY_REUSED`).
  - **Plan:** if `customer_id`, `debit`, `credit` and `currency` all match, it is a replay: return the original, with `replayed = true`, no write and no audit row. If any differs, refuse with `replay_conflict` and name the existing row's id.
- **F-5 (correction: AC-32 conflicts with AC-26): `tier_rules` must have a structure.**
  - AC-26 stores `tier_rules` as opaque JSON, and AC-32 has to evaluate it. As written, neither can be implemented without breaking the other.
  - **Plan:** one minimal shape, validated on write: `null`, or a non-empty list of `{"tier": <non-blank string ≤ 50>, "min_points": <decimal string ≥ 0, ≤ 4 dp>}`, with tier names and `min_points` values each unique.
    - The tier is the entry with the **greatest `min_points` ≤ the new balance**.
    - No such entry, or `null` rules, gives tier `null`.
    - `earn_rule` and `redeem_rule` stay opaque. Nothing in this task evaluates them, because the caller supplies `points` (AC-28).
  - **If `ba` prefers to defer tiering:** AC-32 moves to Out of scope, `loyalty_tier` stays `null`, and `TierRules` is not built.
- **F-6 (correction to AC-28): `loyalty_programs` has no `[SOFT]`.**
  - §6.9 gives it `[ID] [TS]` only, so "non-soft-deleted" has nothing to read.
  - **Plan:**
    - there is no delete slice; a program is retired with `is_active = false`;
    - an inactive program **refuses `earn` and `redeem`** (`program_inactive`);
    - `adjust`, `expire` and `transfer` stay allowed, so corrections and merges still work.
- **F-7 (correction to D-14 and AC-40): points are `DECIMAL(18,4)`, not (18,2).**
  - (18,2) is not one of §3.2's pairs, and `TenantDecimalPrecisionTest`'s allow-list `[[18,4],[18,6],[9,4],[18,8]]` would go red.
  - D-14's own argument (fractional accrual is legitimate) supports §3.2's "quantity `DECIMAL(18,4)`" just as well. It needs no change to §3.2 or to the static test, and it gives both calculators one scale.
  - AC-40's list is also incomplete. It omits:
    - `customers.loyalty_points` and `customer_groups.default_credit_limit` (18,4);
    - the two rates, `customer_groups.discount_percent` and `loyalty_multiplier`, which are (9,4) under §3.2's rate/percentage rule.
  - Section 4 is the complete map.
- **F-8 (gap): currency.**
  - `customers.currency` and `customer_ledger.currency` are both documented, and one `credit_balance` cannot add up two currencies. Nothing in the tenant tree provides a company currency: `company_profile` is not built.
  - **Plan:**
    - an entry's currency defaults to the customer's; a different one is refused (`currency_mismatch`);
    - a customer whose `currency` is `null` cannot take a ledger entry (`currency_required`);
    - `UpdateCustomer` refuses a currency change once any ledger row exists;
    - `MergeCustomers` refuses when the currencies differ and the source's balance is not zero.
- **F-9 (gap in AC-23 and AC-30): debit/credit side and points sign must be hard rules.**
  - The spec says "normally". A safety calculator needs a rule.
  - **Plan:**
    - **debit only:** `credit_sale`, `debit_note`;
    - **credit only:** `payment`, `refund`, `credit_note`, `write_off`;
    - **either side:** `opening`, because a customer can start in credit, and `adjustment`;
    - loyalty: `earn` > 0; `redeem` < 0; `expire` < 0; `adjust` and `transfer` either sign;
    - a breach is refused as `wrong_side` or `wrong_sign`.
  - If `ba` reads `refund` as either-side (a cash refund of an overpayment), that is one dataset row and one table cell.
- **F-10 (gap in AC-7): inherit `credit_allowed` too, and the limit is never null.**
  - The group carries `credit_allowed` as a default, but AC-7 copies only the limit and terms. A customer in a credit group would then be refused credit until someone set the flag by hand.
  - **Plan:**
    - `credit_allowed` is copied from the group like the other two, and defaults to `false` when there is no group;
    - `customers.credit_limit` is `NOT NULL DEFAULT 0`;
    - "no limit" is not representable, so a customer marked `credit_allowed` with limit 0 is refused every credit sale. That is the safe direction;
    - `balance_after > credit_limit` is refused, and equal to the limit is accepted.
- **F-11 (gap): `customers.opening_balance` is documented but not addressed.**
  - A free-standing `opening_balance` next to a ledger-maintained `credit_balance` is the two-sources-of-truth problem D-2 exists to prevent.
  - **Plan:**
    - `CreateCustomer` with a non-zero `opening_balance` posts the `opening` entry through the writer, reference `customer` / the new id, in the same transaction. A currency is then required;
    - `opening_balance` is immutable afterwards, and an update is refused whole.
  - **Fallback:** plain data that nothing reads, documented as such.
- **F-12 (correction to AC-12): `merged_into_id` is written only by `MergeCustomers`.**
  - Setting it by hand would mark a merge that moved nothing.
  - **Plan:** create and update refuse it, as AC-8 does for the maintained columns. `possible_duplicate_of` stays manual, and it must be another existing customer, never the row itself.
- **F-13 (gap): which customers may take an entry.**
  - **Plan:**
    - every ledger and loyalty write requires a **live, unmerged** customer (`customer_not_live`);
    - `credit_sale` and `debit_note` also require `status = active` (`customer_not_active`);
    - a merge's own source-side entries are written before the source is soft-deleted.

**Informational. The plan applies these, and `ba` may confirm or change them (F-14 to F-19):**

- **F-14:** `[SYNC]` is built inert on `customers` and `loyalty_transactions`: `origin` default `online`, `sync_status` default `synced`, everything else `NULL`, and **no FKs**, because §3.1's tag states none. `client_updated_at` is a nullable `DATETIME(3)`. D-6's test for deferring a column was "its target table is missing", and these columns have no target. Deferring them is equally clean if `ba` prefers that.
- **F-15:** §3.1 `[ID]` lets the POS generate the ULID for a `[SYNC]` entity. `RecordLoyaltyTransaction` therefore accepts an optional caller `id`:
  - the same id with the same payload is a replay;
  - the same id with a different payload is `replay_conflict`.
  - That gives loyalty a real replay key today, and AC-33's "known limitation" gets smaller. Drop the field if `ba` declines.
- **F-16:** correct the `version` precedent cited in Assumptions (see 0.1).
- **F-17:** §6.9 does not mark `customer_groups.code` unique (it does mark `customers.code`). AC-1 makes it unique, which is T-027 F-12's shape. Accepted.
- **F-18:** there is one `loyalty_points` balance and possibly many programs. AC-32 as written evaluates the tier against **the program of the transaction just written**, and the plan follows that. A "one active program" rule would be a new business rule, so it is not invented here.
- **F-19:** soft-deleting the default group clears its `is_default` in the same transaction, so AC-2's backstop never holds a deleted default.

### 0.3 Second look at the scoping (D-1 and D-7, as `lead` asked)

- **D-1 holds.** The credit ledger and loyalty share one mechanism:
  - lock the customer row;
  - a pure calculator produces `balance_after`;
  - insert the append-only row;
  - update the maintained column.

  Steps 6 and 7 are the same shape at the same scale (F-7), and steps 10 and 12 share it as well. `barcode_rules` was split out for a different reason: a second engine in a second language, with a parity suite. Nothing here is like that.
- **D-7 holds.** Notes and communications are leaf CRUD (5 slices) with no dependency beyond `customers`, and §6.9 groups them together. Moving them out would add a task with no mechanism of its own. `Refference/ARCH.md` §5 lists `customers`, `customer_groups`, `loyalty` and `customer_transactions` under the core CUSTOMER module. Its §12 "Loyalty Plugin" is an example of plugin enablement, not a placement ruling, and `docs/architecture.md` §3 puts loyalty in Customers. Loyalty therefore belongs in core.

### 0.4 Rulings

**Ruling 1: placement.** A new path package `packages/customers` (`flexipos/customers`, namespace `Flexipos\Customers\`), on T-027 Ruling 1's reasoning: Sales, Finance, the till and the pricing engine all read customers.
- Other code reaches it only through `Flexipos\Contracts\Customers\*` (AC-38).
- Its `Support/` helpers (`TenantConnection`, `Fields`, `Identifier`, `Lookup`) are the **eighth** copy (FP-331), because deptrac forbids importing catalog's.

**Ruling 2: the published contract** (`packages/contracts/src/Customers/`, plain PHP with no vendor imports):

| Symbol | Shape |
|---|---|
| `CustomerCreditLedger` | `record(CreditLedgerEntry $entry): CreditLedgerPosting`, which throws `CreditLedgerRefused`. Its docblock says: the **only** way to change `customers.credit_balance`; callable inside the caller's open transaction on the tenant connection (Laravel nests it as a savepoint); never sum `customer_ledger` yourself |
| `CreditLedgerEntry` | readonly request: `customerId`, `CreditEntryType $type`, `referenceType` (≤ 50), `referenceId` (ULID), `int\|string $debit`, `int\|string $credit`, `?currency` (3 letters), `?branchId`, `?employeeId`, `?notes`, `?actorId`. The constructor checks **shape only** (ULIDs, lengths). Amounts are checked by the calculator |
| `CreditEntryType` | backed enum, the eight §6.9 values |
| `CreditLedgerPosting` | readonly: `entryId`, `customerId`, `type`, `debit`, `credit`, `balanceAfter` (canonical 4-decimal strings), `currency`, `bool $replayed` |
| `CreditLedgerRefused` / `CreditRefusal` | `RuntimeException` with `public readonly CreditRefusal $reason` and named constructors. The reasons: `unknown_customer`, `customer_not_live`, `customer_not_active`, `invalid_amount`, `zero_amount`, `both_sides`, `wrong_side`, `out_of_range`, `credit_not_allowed`, `over_limit`, `currency_required`, `currency_mismatch`, `unknown_branch`, `unknown_employee`, `replay_conflict` |
| `LoyaltyLedger` | `record(LoyaltyTransaction $tx): LoyaltyPosting`, which throws `LoyaltyLedgerRefused`. The docblock is the same as the credit one |
| `LoyaltyTransaction` | readonly: `?id` (F-15), `customerId`, `programId`, `LoyaltyTransactionType $type`, `int\|string $points` (signed), `int\|string\|null $value`, `?expiresAt`, `?employeeId`, `?notes`, `?actorId` |
| `LoyaltyTransactionType` | the five §6.9 values |
| `LoyaltyPosting` | `transactionId`, `customerId`, `programId`, `type`, `points`, `balanceAfter`, `?tier`, `bool $replayed` |
| `LoyaltyLedgerRefused` / `LoyaltyRefusal` | `unknown_customer`, `customer_not_live`, `unknown_program`, `program_inactive`, `invalid_points`, `zero_points`, `wrong_sign`, `insufficient_points`, `out_of_range`, `invalid_value`, `unknown_employee`, `replay_conflict` |

There are 12 files. Nothing else is published: no customer reader, because nothing consumes one yet ("bindings arrive with the slices that earn them").

**Ruling 3: arithmetic and rounding.** This rule covers both calculators. `brick/math` `BigDecimal` is used throughout, and a float never appears (T-027 Ruling 5; it is already locked at 0.14.8 as a direct requirement).
1. **Input form:** an `int`, or a string matching `^\d+(\.\d{1,4})?$`. That means unsigned for `debit` and `credit`, and `^-?\d+(\.\d{1,4})?$` for signed `points`. A float, an exponent, a 5th decimal place or a blank is refused (`invalid_amount` / `invalid_points`).
2. **Never round.** Every operand has a scale of 4 or less, and the only operations are addition and subtraction, so every result is exact. Results are put to scale 4 with **`RoundingMode::Unnecessary`**, which makes any accidental need to round throw rather than pass silently.
3. **Range:** an operand or result with |x| > `99999999999999.9999` (14 integer digits, `DECIMAL(18,4)`) is refused as `out_of_range`.
4. **Canonical output:** a plain 4-decimal string (`"0.0000"`, `"-12.5000"`). A zero is never negative.
5. **For the future earn engine (FP-233 or FP-231), recorded here:** converting money to points (`amount × rate`) *does* need rounding. That caller rounds to 4 decimals `HalfUp` (§3.2's MySQL `ROUND()` convention) **before** calling `LoyaltyLedger`. These calculators never multiply.

**Ruling 4: `Customers\Ledger\CreditLedgerCalculator`** (pure, AC-23, AC-24, AC-41).
`apply(CreditPosition $p, CreditEntryType $t, int|string $debit, int|string $credit): CreditOutcome`. `CreditPosition` holds `balance`, `creditAllowed`, `creditLimit` and `active`. Checks run in this **fixed order**, so a test can pin which reason comes first:
1. parse `debit` and `credit` (Ruling 3.1 and 3.3);
2. both zero gives `zero_amount`; both non-zero gives `both_sides`;
3. side check per F-9 gives `wrong_side`;
4. `new = balance + debit − credit`, then the range check;
5. only for `credit_sale` and `debit_note`, in this order:
   - not `active` gives `customer_not_active`;
   - not `creditAllowed` gives `credit_not_allowed`, whatever the amount;
   - `new > creditLimit` gives `over_limit`.

   The check is on the **resulting balance**, never on the amount alone: a customer at 80 with a limit of 100 is refused a sale of 30. Every other type skips step 5.

`fold(list<array{debit, credit}>, string $opening = '0'): list<string>` returns the running balances by calling the same addition. It is used **only** by the invariant tests, as the oracle that the stored chain is compared against, and no handler calls it (section 7).

**Ruling 5: `Customers\Loyalty\LoyaltyCalculator` and `TierRules`** (pure, AC-30 to AC-32, AC-41).
`apply(string $balance, LoyaltyTransactionType $t, int|string $points): LoyaltyOutcome` checks, in order:
1. parse;
2. zero gives `zero_points`;
3. sign per F-9 gives `wrong_sign`;
4. `new = balance + points`, then the range check;
5. **only for `redeem`**, `new < 0` gives `insufficient_points`. Redeeming down to exactly 0 is accepted.

`expire`, `adjust` and `transfer` may take the balance below zero. D-13 says nothing about them, and the plan does not invent a rule.

`TierRules`:
- `parse(mixed $json): ?list<{tier, min_points}>` validates F-5's shape (used by the program create and update slices);
- `tierFor(?list $rules, string $balance): ?string` returns the greatest `min_points` ≤ balance, compared with `BigDecimal::compareTo`;
- tier names are stored as given, at most 50 characters.

**Ruling 6: the write path and the row lock** (AC-19 to AC-22, AC-27 to AC-29; §12.1's shape). There is **one** internal writer per ledger: `Ledger\CreditLedgerWriter::post()` and `Loyalty\LoyaltyWriter::post()`.
- The contract adapter (`ContractCreditLedger` / `ContractLoyaltyLedger`, which builds the slice's command), `CreateCustomer` (the opening entry, F-11) and `MergeCustomers` all go through it. Nothing else writes either table or either maintained column, and `QaCustomersSourceRulesTest` checks that.

Inside `$connection->transaction()` (a savepoint when the caller already has one open):
1. **Before the lock:** check the shape of the ids, the amounts (Ruling 3.1) and the enum values. A rejection belongs before the lock (§12.1). The calculator's parse runs again under the lock, at no extra cost.
2. **Replay pre-check** by key: `(reference_type, reference_id, type)` for credit, or the caller `id` for loyalty (F-15). A hit is compared under F-4: if it matches, return the original with `replayed`; if not, `replay_conflict`.
3. **`SELECT … FROM customers WHERE id = ? FOR UPDATE`** (`lockForUpdate()`), then the live, unmerged and currency checks (F-8, F-13), then the branch, employee and program checks.
4. The calculator runs on the **locked** row's values.
5. `INSERT` the ledger row with `balance_after`.
6. `UPDATE customers SET credit_balance = :new, updated_at = now WHERE id = ?`. For loyalty, `loyalty_points` and, when it changed, `loyalty_tier` (AC-32).
   - **`version` is not incremented.** `version` guards admin edits, and those can never write these columns (AC-8). Incrementing it would make an admin's save fail as stale every time a sale went through.
7. One `audit_logs` row (T-027 Ruling 11).

Further rules:
- **Concurrent replays.** Two writers can both miss the pre-check. The second `INSERT` then hits the unique index, `UniqueConstraintViolationException` is caught, and the code re-reads the row and runs step 2's comparison. The unique index is the backstop, the `AddProductBarcode` shape.
- **Lock order.**
  - A writer locks exactly one `customers` row.
  - `MergeCustomers` locks both rows in **ascending id order** before calling either writer. The writers' own re-lock in the same transaction is a no-op.
  - Every admin update (`UpdateCustomer`) reads its row with `lockForUpdate()`, so a `credit_limit` change and a credit sale are serialised.
  - §12.1 takes `stock_levels` locks before `customers`. FP-233 must keep that order (section 10).
- **`[ENGINE]`:** that the lock is held against a concurrent writer is NOT VERIFIED until G-1 (AC-22, AC-29). Step 17 writes the two-connection test for that day.

**Ruling 7: groups and customers** (AC-1 to AC-12, AC-15).
- **Codes:** trimmed. The duplicate pre-check compares `lower()`, and the unique index is the backstop (T-027 Ruling 12).
- **Optimistic concurrency** (AC-4, on groups and customers):
  - an update **requires** `version` (an int);
  - the row is read with `lockForUpdate()`;
  - a mismatch is `stale`, naming the current version;
  - a success increments it.
- **One default group** (AC-2): setting `is_default` clears the old default first, in the same transaction. The backstop is a stored generated `default_marker = CASE WHEN is_default THEN 1 END` with a plain unique index (the `primary_product_id` shape).
- **The guard on soft-deleting a group** (AC-3) names the first blocking customer's id or code, and a count.
- **AC-8:** the maintained fields are refused **whole**, before any read. They are `credit_balance`, `loyalty_points` and `loyalty_tier`, plus `merged_into_id` (F-12) and, on update, `opening_balance` (F-11). They live in `Ledger\MaintainedColumns`.
- **AC-9:** the `status` and `blocked_reason` consistency rule is checked against the **merged** state of the stored row and the patch.
- **AC-10:** a soft-delete is refused while `credit_balance` is not zero, compared as a decimal and not as a string. The refusal names the balance.
- **AC-7 and F-10:** the copy from the group happens at create only.
- Lengths:
  - `name` and `company_name` 150; `code` 50; `phone` 30; `email` 191; `tax_no` 50; `gender` VARCHAR(30) free text (no values are documented); `loyalty_tier` 50; `blocked_reason` 255; `notes` TEXT;
  - `dob` must be a valid `Y-m-d` date;
  - `address` is any JSON.

**Ruling 8: child tables** (AC-16 to AC-18, AC-34 to AC-37).
- **One default address per `(customer_id, type)`** (AC-17): clear-then-set in one transaction. The backstop is a stored generated `default_customer_id = CASE WHEN is_default THEN customer_id END` with `unique(default_customer_id, type)`.
- **`RemoveCustomerAddress`** is a hard `DELETE`. An unknown id is refused, naming it (AC-18).
- **Notes:** `pinned` defaults to false; `follow_up_date` is a valid date or null.
- **Communications:** `occurred_at` defaults to now, using a `DATETIME(3)` UTC string that matches `RunnerClock::PRECISION`. A communication has no update, because it is a log of what happened.

**Ruling 9: `MergeCustomers`** (AC-13, AC-14; D-20, D-25, D-29, D-30). This matches D-20's final wording point for point: reference names `customer_merge_out` and `customer_merge_in`, both with the source's id as `reference_id`; the loyalty transfer under the program of the source's latest loyalty row; the target required to be live and unmerged. One transaction:
1. Fold both ids. `source = target` gives `self_merge`.
2. Lock both rows in ascending id order.
3. Each row must exist (`notFound`), and must be live and unmerged (`alreadyMerged` / `notLive`).
4. If the source's `credit_balance` is not zero:
   - the currencies must be equal (F-8);
   - `CreditLedgerWriter::post` writes the source `adjustment`, which zeroes it, with reference `customer_merge_out` / `source_id`;
   - then it writes the target's mirror entry, reference `customer_merge_in` / `source_id`.
   - These are `adjustment` entries, so they are never refused by the limit rule.
5. If the source's `loyalty_points` is not zero: a `LoyaltyWriter::post` `transfer` pair (−balance, then +balance), under the program of the source's most recent loyalty row. A non-zero balance implies such a row exists. Both tiers are recomputed.
6. `UPDATE` `customer_addresses`, `customer_notes` and `customer_communications` to `customer_id = target`.
   - Before the address move, the source's `is_default` is cleared on each `type` the target already has a default for.
   - The source's ledger and loyalty rows **stay on the source** (F-3).
7. Source: `merged_into_id = target` and `deleted_at = now`. Its balances are already zero.
8. One `customers_merged` audit row, plus the writers' own rows.

The `customer_ledger` and `loyalty_transactions` of the two customers together are the customer's full history: the target's entries, plus the source's entries up to and including the `merge_out` row. Reading them as one statement belongs to FP-240 or FP-264 (section 10).

**Ruling 10: the slices** (24). Each is `Features/<X>/{X.php, XHandler.php}` plus `XResult.php` where it returns an id, with a mirrored `tests/Features/<X>/`. Commands take `array $fields` and `?string $actorId`, and an unknown field is refused (`Fields::only`).

| Area | Slices | ACs |
|---|---|---|
| Groups | `CreateCustomerGroup`, `UpdateCustomerGroup`, `DeleteCustomerGroup`, `ListCustomerGroups` | 1–5 |
| Customers | `CreateCustomer`, `UpdateCustomer`, `DeleteCustomer`, `ListCustomers`, `MergeCustomers` | 6–15 |
| Addresses | `AddCustomerAddress`, `UpdateCustomerAddress`, `RemoveCustomerAddress`, `ListCustomerAddresses` | 16–18 |
| Credit ledger | `RecordCreditLedgerEntry`, `ListCreditLedgerEntries` | 19–25 |
| Loyalty | `CreateLoyaltyProgram`, `UpdateLoyaltyProgram`, `RecordLoyaltyTransaction`, `ListLoyaltyTransactions` | 26–33 |
| Notes and communications | `AddCustomerNote`, `UpdateCustomerNote`, `ListCustomerNotes`, `LogCustomerCommunication`, `ListCustomerCommunications` | 34–37 |

The two `Record*` commands wrap the contract request. The contract adapters in `Ledger/` and `Loyalty/` build the command and call `handle()`, so the slice is the single implementation and the contract is a door onto it.

Not built:
- **Delete for notes or communications:** no AC asks for it.
- **A `ListLoyaltyPrograms` slice:** no AC asks for it either. FP-240 owns it.
- **`GetCustomer`:** FP-240.

**Ruling 11: pagination** (AC-37, D-17, every list).
- A keyset cursor on the ULID `id`, newest first: `where id < :before_id order by id desc limit size + 1`. That is `SearchAuditLogHandler`'s `before_id` shape.
- The page size goes through `Kernel\Audit\PageSize::clamp` with `config/customers.php`: `page_size` 50, `max_page_size` 100 (the T-014 D-28 numbers). The page size is never refused, only clamped.
- The result is `Support\Page { rows, ?nextBeforeId }`. A malformed `before_id` is refused.
- No extra index is needed: InnoDB's secondary index on `customer_id` carries the primary key.
- `ListCustomers` excludes soft-deleted rows and filters by optional `group_id` and `status`. `ListCustomerGroups` excludes soft-deleted rows. The child lists take a required `customer_id`.

**Ruling 12: test cost.** This is T-027 Ruling 13 unchanged.
- Calculators, `TierRules`, the slice rules and the shape tests are pure dataset tests.
- Each slice gets one fixture-backed file: refusals write nothing (no row and no audit row); a success writes one audit row, compared whole.
- The package's own tests run within each step. `composer check` runs at each chunk boundary.
- If a chunk adds more than 10 % to the `test` step, stop and report to `lead` (FP-328).

**Ruling 13: the vocabulary sweep** (T-027 Ruling 14). Before step 5, `dev` greps `QaVocabularyRenameSweepTest`'s retired spellings against every enum value in this task. `transfer`, `adjust` and `campaign` look safe, but they are checked, not assumed. Enum values are referenced as `Enum::Case` and never used as dataset keys.

---

## 1. Approach summary

1. A new `packages/customers`, reached from outside only through `Flexipos\Contracts\Customers\*`: two ledger interfaces, with their request and posting DTOs, enums and exceptions.
2. Eight forward-only tenant migrations (43 → 51) implement §6.9 column for column, minus the four D-6 columns and `custom_fields`. The schema adds one engine-only `CHECK` on `customer_ledger` and two generated "one default" backstops.
3. Two pure calculators hold all the balance arithmetic:
   - exact `BigDecimal`, scale 4, never rounding (`Unnecessary`);
   - a fixed check order;
   - the credit limit tested on the *resulting* balance;
   - a redeem may never go negative;
   - tiers come from a minimal validated `tier_rules`.
4. There is one writer per ledger, and the same transaction does: replay check, `FOR UPDATE` on the customer, calculate, insert, update the maintained column. A replay with a different payload is refused (F-4).
5. 24 CRUD slices, with named refusals and one audit row per write. The merge moves balances by **compensating entries**, never by rewriting history (F-3). Every list is keyset-paged.
6. A source scan proves "one writer, append-only, no surface". Static precision covers AC-40. An engine test (CHECK, lock contention, decimals) is written now and skipped under `ENGINE_HOLD`.

## 2. Alternatives rejected

- **Merge by reassigning and re-summing** (the spec as written): it breaks append-only and the `balance_after` chains (F-3).
- **Recomputing `credit_balance` by `SUM()` on each write:** a second arithmetic path, and an O(n) read inside the lock. The maintained column plus `fold()` as a test oracle gives the same guarantee.
- **`UPDATE customers SET credit_balance = credit_balance + ?`** without reading the row: it cannot apply the limit check against the resulting balance, and it skips the calculator.
- **`DECIMAL(18,2)` points:** F-7.
- **Integer points:** D-14's own reasoning.
- **Incrementing `customers.version` on ledger writes:** it would make admin edits fail as stale under normal sales traffic (Ruling 6).
- **Defaulting a missing currency silently:** no company currency exists yet (F-8).
- **A `modules/customers` leaf module:** T-027 Ruling 1.
- **Putting the arithmetic in `packages/contracts`:** it would be that package's first vendor dependency (T-027 Ruling 4).

## 3. Files to create

`dev` writes every file. `Qa*` files are `qa`-accountable.

### `packages/contracts/src/Customers/` (new)
The 12 files of Ruling 2.

### `packages/kernel`
- `src/Schema/Support/CheckConstraint.php`: add `oneSided(string $table, string $debit, string $credit)`. It issues ``ALTER TABLE `t` ADD CONSTRAINT `t_debit_credit_one_sided` CHECK ((`debit` = 0 OR `credit` = 0) AND `debit` >= 0 AND `credit` >= 0)``, on MySQL and MariaDB only. It takes identifiers only (the existing `assertIdentifier`), plus a pure `oneSidedStatement()`.
- `tests/Schema/CheckConstraintTest.php`: the exact text; a no-op on SQLite; a bad identifier refused.

### `packages/customers` (all new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/customers` 0.1.0, requiring `flexipos/contracts` and `brick/math: ^0.14` |
| *(host)* `modules/host/config/customers.php` | `page_size` 50, `max_page_size` 100. It lives in host `config/`, next to `organization.php` and `access.php`, because no package ships its own config directory |
| `src/CustomersServiceProvider.php` | binds `CustomerCreditLedger` to `Ledger\ContractCreditLedger` and `LoyaltyLedger` to `Loyalty\ContractLoyaltyLedger`; `implements DeclaresAuditActions`. No routes and no commands |
| `src/Ledger/{CreditLedgerCalculator,CreditPosition,CreditOutcome,CreditLedgerWriter,ContractCreditLedger,MaintainedColumns,EntrySide}.php` | Rulings 4 and 6, F-9 |
| `src/Loyalty/{LoyaltyCalculator,LoyaltyOutcome,TierRules,LoyaltyWriter,ContractLoyaltyLedger}.php` | Rulings 5 and 6 |
| `src/Customers/{CustomerStatus,CustomerRules}.php` | AC-9's pure rule, status enum |
| `src/Support/{TenantConnection,Fields,Identifier,Lookup,Decimal,Page}.php` | the eighth copy (FP-331); `Decimal` is Ruling 3.1's parser, shared by both calculators |
| `src/Exceptions/CustomersRefused.php` | `validation`, `notFound`, `duplicate`, `inUse`, `stale`, `maintained`, `balanceOutstanding`, `selfMerge`, `alreadyMerged`, `notLive`, `currencyMismatch` |
| `src/Registry/CustomersAuditActions.php` | built from each handler's `ACTION` |
| `src/Features/<24 slices>/…` | Ruling 10 |
| `tests/Support/{CustomersSeed.php,customers-fixture.php}` | raw-insert seeds (group, customer with currency, program with tiers, branch, employee). `customersEnterTenant()` only enters and never calls `boot()` |
| `tests/Ledger/{CreditLedgerCalculatorTest,CreditLedgerInvariantTest}.php`, `tests/Loyalty/{LoyaltyCalculatorTest,TierRulesTest}.php` | steps 6, 7 and 10 |
| `tests/Contracts/{CreditLedgerEntryShapeTest,LoyaltyTransactionShapeTest,CustomersEnumsTest}.php` | step 5 |
| `tests/Schema/CustomersTenantSchemaTest.php` | step 3 |
| `tests/Support/CustomersProviderTest.php` | step 1 |
| `tests/Features/<X>/<X>Test.php` | one per slice |

### `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/2026_11_10_002801_create_customer_groups_table.php` … `002808_…` | in this order: customer_groups, customers, customer_addresses, customer_ledger, loyalty_programs, loyalty_transactions, customer_notes, customer_communications. Each is `@phase expand` with no `down()`, uses `IdentifierColumn`, `dateTime(…, 3)`, VARCHAR(30) enums and full decimal arguments, and has a docblock naming its deferred columns |
| `tests/Feature/QaCustomersSourceRulesTest.php` | step 16 |
| `tests/Engine/CustomersSchemaEngineTest.php` | step 17 |

## 4. Data / API changes

**Schema:** eight tenant tables. No master change, and no existing table is altered.

| Table | Columns as built | Keys and indexes |
|---|---|---|
| `customer_groups` | `id`, TS, `deleted_at`, `code` VARCHAR(50), `name` VARCHAR(150), `discount_percent` DECIMAL(9,4) NULL, `credit_allowed` default false, `default_credit_limit` DECIMAL(18,4) NULL, `default_payment_terms_days` SMALLINT UNSIGNED NULL, `is_default` default false, `default_marker` TINYINT UNSIGNED NULL stored `CASE WHEN is_default THEN 1 END`, `loyalty_multiplier` DECIMAL(9,4) NULL, `version` BIGINT UNSIGNED default 1 | unique `code`; unique `default_marker` |
| `customers` | `id`, TS, `deleted_at`, `created_by`, `updated_by`, **[SYNC]**: `origin` VARCHAR(30) default `online`, `terminal_id`, `device_id` (ULID NULL, no FK), `client_seq` BIGINT NULL, `client_created_at` DATETIME(3) NULL, `sync_status` VARCHAR(30) default `synced`, `synced_at` NULL; `group_id` NULL, `code` VARCHAR(50) NULL, `name` VARCHAR(150), `phone` VARCHAR(30) NULL, `email` VARCHAR(191) NULL, `address` JSON NULL, `dob` DATE NULL, `gender` VARCHAR(30) NULL, `tax_no` VARCHAR(50) NULL, `company_name` VARCHAR(150) NULL, `currency` CHAR(3) NULL, `credit_allowed` default false, `credit_limit` DECIMAL(18,4) default 0, `credit_balance` DECIMAL(18,4) default 0, `payment_terms_days` SMALLINT UNSIGNED NULL, `loyalty_points` DECIMAL(18,4) default 0, `loyalty_tier` VARCHAR(50) NULL, `opening_balance` DECIMAL(18,4) default 0, `status` VARCHAR(30) default `active`, `blocked_reason` VARCHAR(255) NULL, `possible_duplicate_of` NULL, `merged_into_id` NULL, `first_sale_at`, `last_sale_at` DATETIME(3) NULL, `lifetime_value` DECIMAL(18,4) default 0, `notes` TEXT NULL, `client_updated_at` DATETIME(3) NULL, `version` BIGINT UNSIGNED default 1 | unique `code`; index `phone`, `email`, `name`, `group_id`; FKs `group_id` to customer_groups, `possible_duplicate_of` and `merged_into_id` to customers, `created_by` and `updated_by` to employees; `JsonValidity` on `address` |
| `customer_addresses` | `id`, TS, `customer_id`, `type` VARCHAR(30), `label` VARCHAR(100) NULL, `address` JSON NULL, `is_default` default false, `default_customer_id` (ULID NULL, stored `CASE WHEN is_default THEN customer_id END`), `geo` JSON NULL | unique (`default_customer_id`, `type`); FK `customer_id` **cascade**; `JsonValidity` on `address` and `geo` |
| `customer_ledger` | `id`, `customer_id`, `branch_id` NULL, `type` VARCHAR(30), `reference_type` VARCHAR(50), `reference_id` (ULID), `debit`, `credit` DECIMAL(18,4) default 0, `balance_after` DECIMAL(18,4), `currency` CHAR(3), `employee_id` NULL, `notes` TEXT NULL, `created_at` DATETIME(3) | **unique (`reference_type`, `reference_id`, `type`)**; index (`customer_id`, `created_at`); FKs `customer_id` (RESTRICT), `branch_id`, `employee_id`. No FK on the reference pair (§3.3). Engine-only `CheckConstraint::oneSided` |
| `loyalty_programs` | `id`, TS, `name` VARCHAR(150), `earn_rule`, `redeem_rule`, `tier_rules` JSON NULL, `expiry_months` SMALLINT UNSIGNED NULL, `is_active` default true | `JsonValidity` on the three |
| `loyalty_transactions` | `id`, **[SYNC]** (as on customers), `customer_id`, `program_id`, `type` VARCHAR(30), `points` DECIMAL(18,4), `value` DECIMAL(18,4) NULL, `balance_after` DECIMAL(18,4), `expires_at` DATETIME(3) NULL, `employee_id` NULL, `notes` TEXT NULL, `created_at` DATETIME(3) | index (`customer_id`, `created_at`); FKs `customer_id`, `program_id`, `employee_id` |
| `customer_notes` | `id`, TS, `created_by`, `updated_by`, `customer_id`, `note` TEXT, `pinned` default false, `follow_up_date` DATE NULL | FK `customer_id` **cascade**; `created_by` and `updated_by` to employees |
| `customer_communications` | `id`, TS, `customer_id`, `channel` VARCHAR(30), `direction` VARCHAR(30), `subject` VARCHAR(255) NULL, `body` TEXT NULL, `campaign_ref` VARCHAR(100) NULL, `employee_id` NULL, `occurred_at` DATETIME(3) | index (`customer_id`, `occurred_at`); FKs `customer_id` (RESTRICT, §6.9 says no cascade), `employee_id` |

Notes on the schema:
- **Foreign keys total 17** (customers 5, addresses 1, ledger 3, loyalty 3, notes 3, communications 2). Every one belongs to a **newly created** table, so `SourceRules` AC-34 holds.
- `RESTRICT` everywhere except §6.9's two `CASCADE`s. Customers are never hard-deleted, so neither cascade fires in practice.
- **Not built:**
  - `receivable_account_id`, `ar_document_id`, `sale_id` (with its unique key) and `delivery_id` (D-6, F-2);
  - `custom_fields` (F-1).
- **Complete precision map** for `TenantDecimalPrecisionTest` (AC-40, D-24; identical to the spec's revised list):
  - (18,4): `credit_limit`, `credit_balance`, `opening_balance`, `lifetime_value`, `loyalty_points`, `default_credit_limit`, `debit`, `credit`, both `balance_after`, `points`, `value`;
  - (9,4): `discount_percent`, `loyalty_multiplier`.
- `APP_REQUIRED_SCHEMA_VERSION` goes from 43 to 51, and the catalogue is republished (`php artisan schema:publish --write-fixture`).

**Contracts:** Ruling 2. **HTTP, console, jobs, cache, hooks, outbox events:** none. **Config:** `customers.php` (Ruling 11). **Dependencies:** the `flexipos/customers` path package; `brick/math` is already locked. The `composer.lock` diff must add only the path package entry.

## 5. Boundary impact

- **`deptrac.yaml`**, the T-027 shape exactly, with no exemption:
  - **Paths:** `../../packages/customers/src` and `tests`.
  - **Layers:** `Package_Customers` = `^Flexipos\\Customers\\(?!Tests\\).*` and `Package_Customers_Tests`.
  - **Rulesets:**
    - `Package_Customers: [Package_Kernel, Package_Contracts]`;
    - the tests layer gets those plus `Package_Customers` and `Package_Access` (for the fixture);
    - `HostApp` gains `Package_Customers`;
    - `HostTests` gains both layers.
  - **`Module_Demo` is not granted `Package_Customers`. That is AC-38's instrument.**
  - `Package_Customers` is **not** granted `Package_Catalog`, because nothing here needs it.
- **PHPStan:** both paths. **Composer** (host): `require` gains `flexipos/customers: *`, and `autoload-dev` gains the tests namespace. Then `composer update flexipos/customers` (never `--lock`) and `composer validate --no-check-publish`.
- **`bootstrap/providers.php`:** `CustomersServiceProvider` after `CatalogServiceProvider`.

## 6. Implementation steps

The rules carried over from T-027 §6 are unchanged:
- red-first, with a fixture or by leaving something out, and never by stubbing a guard to allow;
- the **red-list rule** after steps 1, 3 and 4 (record it, compare it with section 9, and stop on any file section 9 does not name);
- no child processes;
- unique test basenames;
- list foreign `php.exe` processes before each gate run.

Mutations are run once, reverted, and recorded in `dev`'s hand-back with the red-then-green counts.

### Chunk A: plumbing, schema, static instruments

- [x] **Step 0: baseline.**
  - `composer check`: record passed, failed and skipped, the `test` seconds, and the process list.
  - The spec revision is already committed (`f116c1e`), and every F-1 to F-19 resolution was accepted, so no fallback applies.
  - *(No AC.)*
- [x] **Step 1: package plumbing.**
  - `composer.json`, an empty provider, the config file, and the section 5 edits. Add `'customers'` to `QaToolchainRegistryCoverageTest`'s literal.
  - **`CustomersProviderTest`:** the provider is loaded and `BigDecimal` autoloads.
  - **Negative control:** a scratch class in `modules/demo/src` importing `Flexipos\Customers\Ledger\CreditLedgerCalculator` fails `deptrac`, and the same class importing `Flexipos\Contracts\Customers\CustomerCreditLedger` passes. Delete both.
  - *(AC-38 instrument.)*
- [x] **Step 2: `CheckConstraint::oneSided`.**
  - The exact-text test; the SQLite no-op; a bad identifier refused.
  - **Mutation K1:** remove the driver guard, and the no-op test throws.
  - *(Groundwork for AC-20 and D-15.)*
- [x] **Step 3: the eight migrations, with `CustomersTenantSchemaTest`** (its own SQLite stand-in, the T-027 shape).
  - Each table's sorted column list equals section 4 **exactly**, with nullability. That **proves the absence** of the four D-6 columns, `custom_fields` and `sale_id` (AC-15, AC-25, AC-33, AC-36, F-1).
  - Every unique key, by raw double insert:
    - the ledger triple;
    - `customers.code`: two NULLs accepted, a duplicate refused;
    - `customer_groups.code`;
    - **`default_marker`:** a second default refused, two non-defaults accepted;
    - **`(default_customer_id, type)`:** a second default billing address for one customer refused; one default billing plus one default delivery accepted; another customer's default accepted.
  - The `pragma foreign_key_list` set equals the 17 keys, and the two cascades delete their children.
  - `SourceRules::inspect()` reports nothing, and no file declares `down`.
  - **Predicted red:** section 9's schema list. Anything else means stop.
  - *(The schema halves of AC-2, AC-6, AC-17, AC-21 and AC-40, and AC-15, AC-25, AC-33 and AC-36 by absence.)*
- [x] **Step 4: AC-40 static precision.**
  - Extend `TenantDecimalPrecisionTest` with T-028's exact column map (section 4) under a T-028 comment. The allow-list stays at four pairs.
  - **Red-first:** a fixture `decimal('points', 18, 2)` is reported. This is the F-7 point, proven.
  - **Gate: `composer check` (end of chunk A).**
  - *(AC-40.)*

### Chunk B: the arithmetic core (safety-critical)

- [x] **Step 5: contracts.** Ruling 2's 12 files.
  - **`CreditLedgerEntryShapeTest` and `LoyaltyTransactionShapeTest`:** well-formed instances are accepted; a malformed ULID, a `reference_type` of 51 characters, a 4-letter currency and a malformed caller `id` are each refused by name.
  - **`CustomersEnumsTest`:** `CreditEntryType` is exactly the eight values, `LoyaltyTransactionType` exactly the five, and the two refusal enums exactly Ruling 2's lists.
  - *(Groundwork for AC-20, AC-28 and AC-41.)*
- [x] **Step 6: `CreditLedgerCalculator` (pure).** One dataset row per case, each with the exact expected string or reason.
  - **Arithmetic:**
    - 0 + debit 100 gives `100.0000`;
    - 100 − credit 30.5 gives `69.5000`;
    - −20 + debit 20 gives `0.0000` (never `-0.0000`);
    - `0.0001` steps are exact;
    - `99999999999999.9999` + `0.0001` gives `out_of_range`.
  - **Input:**
    - a float gives `invalid_amount`;
    - `"1e3"`, `"-5"` as a debit, `"1.00001"` (5 decimals) and `""` each give `invalid_amount`;
    - `5` (int) is accepted.
  - **Both sides:** both zero gives `zero_amount`; both non-zero gives `both_sides`.
  - **Sides (F-9):** each of the eight types with its wrong side gives `wrong_side`. `adjustment` and `opening` are accepted on either side.
  - **Limit (AC-24):**
    - limit 100: balance 80 plus a `credit_sale` of 20 is accepted (equal to the limit); a sale of 20.0001 gives `over_limit`;
    - **balance 80, limit 100, sale 30 gives `over_limit`**, the case that separates "resulting balance" from "amount alone";
    - `credit_allowed = false` gives `credit_not_allowed`, even for 0.0001 and even with a limit of 1000;
    - an inactive customer gives `customer_not_active`;
    - `debit_note` follows the same rules;
    - **control:** `opening`, `payment`, `refund`, `credit_note`, `adjustment` (debit) and `write_off` taking the balance past the limit are all accepted.
  - **Order:** a `credit_sale` that is both `wrong_side` and over the limit reports `wrong_side` (Ruling 4's order).
  - **`fold`:** a ten-entry sequence gives the expected running list.
  - **Mutations:**
    - **M1:** `balance − debit + credit`. The arithmetic rows go red;
    - **M2:** delete the `credit_allowed` check. That row goes red;
    - **M3:** `>=` for `>`. The equal-to-limit row goes red;
    - **M4:** compare the amount instead of the result. The 80/100/30 row goes red;
    - **M5:** round 5 decimals with `HalfUp` instead of refusing. The 5-decimal row goes red;
    - **M6:** apply the limit check to `adjustment`. The control row goes red.
  - *(AC-23, AC-24, AC-41.)*
- [x] **Step 7: `LoyaltyCalculator` and `TierRules` (pure).**
  - **Arithmetic:** 10 + earn 2.5 gives `12.5000`; redeem −12.5 gives `0.0000`, accepted; redeem −12.5001 gives `insufficient_points`.
  - **Control:** `expire` and `adjust` going below zero are accepted.
  - **Signs (F-9):** a negative `earn`, a positive `redeem` and a positive `expire` each give `wrong_sign`. `transfer` and `adjust` take either sign. Zero gives `zero_points`. Five decimals give `invalid_points`.
  - **`TierRules::parse`:** valid; `null`; an empty list refused; a duplicate tier refused; a duplicate `min_points` refused; a negative `min_points` refused; a missing key refused; a non-list refused.
  - **`tierFor`:** with bronze 0, silver 100 and gold 500:
    - 99.9999 gives bronze; 100 gives silver; 499.9999 gives silver; 500 gives gold;
    - unsorted input gives the same results;
    - with no bronze entry, 50 gives `null`;
    - `null` rules give `null`.
  - **Mutations:**
    - **M7:** delete the redeem check. That row goes red;
    - **M8:** `<= 0` for `< 0`. The redeem-to-zero row goes red;
    - **M9:** `tierFor` picks the lowest qualifying tier. The gold row goes red;
    - **M10:** compare tier thresholds as strings. With `"99"` against `"100"`, a row goes red.
  - **Gate: `composer check` (end of chunk B).**
  - *(AC-30 to AC-32 logic, AC-41.)*

### Chunk C: groups, customers, the credit ledger

- [x] **Step 8: groups** (four slices; Ruling 7).
  - **AC-1:** a blank code, a duplicate code, a duplicate by case, and a negative value for each of the four numerics are each refused, naming the field; the defaults hold.
  - **AC-2:** create A as default, then set B as default. Exactly one default remains, and it is B. Create, update, and soft delete of the default (F-19) each behave the same way.
  - **AC-3:** a delete is refused while a live customer references the group, naming that customer. After that customer is soft-deleted, the delete is accepted.
  - **AC-4:** a missing `version` is refused; a stale one is refused, the row unchanged; a success increments it.
  - **AC-5:** `discount_percent` and `loyalty_multiplier` round-trip. The source scan in step 16 proves no `price_list` read.
  - The list is paged (Ruling 11): 3 rows at size 2 give 2 rows plus a cursor, then 1 row and no cursor.
  - **M11:** skip the default-clearing. The backstop refuses, and the AC-2 test goes red.
  - *(AC-1 to AC-5.)*
- [x] **Step 9: customers CRUD** (`CreateCustomer`, `UpdateCustomer`, `DeleteCustomer`, `ListCustomers`). The opening entry comes in step 11.
  - **AC-6:** blank name; a duplicate code; two NULL codes accepted; an unknown group; a soft-deleted group; a bad status.
  - **AC-7 and F-10:** limit, terms and `credit_allowed` are copied from the group when not supplied, and explicit values win. A later change to the group leaves the customer unchanged.
  - **AC-8 and F-12:** each of `credit_balance`, `loyalty_points`, `loyalty_tier` and `merged_into_id` on create and on update is refused **whole**. The row is byte-identical afterwards, including a `name` sent in the same request and `version`, and no audit row is written. `opening_balance` on update is refused the same way.
  - **AC-9:** `blocked` without a reason is refused; `active` with a reason is refused; a patch setting `status = active` on a blocked row without clearing the reason is refused; clearing both together is accepted.
  - **AC-10:** with a seeded balance of 0.0001, the delete is refused, naming it. At 0 the delete is accepted even with history rows seeded.
  - **AC-11:** the three sale fields are null or 0, and **a command field naming one is refused as unknown**.
  - **AC-12:** `possible_duplicate_of` is set by update; pointing it at the row itself, or at an unknown id, is refused.
  - **AC-6a (D-25):** a currency change is refused once a ledger row exists. **Control:** before any ledger row, it is accepted.
  - **AC-6 (F-14):** the `[SYNC]` defaults are `origin = online` and `sync_status = synced`, and the rest are null. A command field naming any `[SYNC]` column is refused as unknown.
  - AC-4 for customers. The list is paged and filtered.
  - **M12:** let `credit_balance` through on update. The AC-8 test goes red.
  - *(AC-4, AC-6, AC-6a, AC-7 to AC-12; D-27, D-29.)*
- [x] **Step 10: `RecordCreditLedgerEntry`, the writer, the contract adapter and `ListCreditLedgerEntries`** (Ruling 6).
  - **AC-20:** an unknown customer; a missing reference; an unknown branch; an unknown employee; a soft-deleted or merged customer (F-13); currency null, and currency mismatch (F-8).
  - **AC-23:** after an entry, `customer_ledger.balance_after` = `customers.credit_balance`, written in one transaction.
  - **AC-24 end to end:** an over-limit sale writes nothing, with no row, no balance change and no audit row, and is refused with `over_limit`.
  - **AC-21 and F-4:**
    - the same key and same payload return the **same entry id** with `replayed = true`, the balance unchanged, one row, and one audit row in total;
    - the same key with a different `credit` gives `replay_conflict`, naming the existing id;
    - **the backstop:** seed the row with a raw insert, so the pre-check is bypassed in a test seam, then call with the same payload. The catch path returns the replay.
  - **AC-19:** the slice set has no update or delete for the ledger. The source scan in step 16 asserts no update or delete statement on the table anywhere.
  - **`CreditLedgerInvariantTest`:** a fixed 40-entry mixed sequence, seeded deterministically, through the **contract**. After every write:
    - `customers.credit_balance` = the last `balance_after` = `CreditLedgerCalculator::fold(all rows by id)`;
    - each row's `balance_after` equals its running fold.
  - **Contract seam:** resolving `CustomerCreditLedger` from the container gives the adapter. A call made inside an outer `transaction()` that the caller then rolls back leaves nothing behind (savepoint semantics).
  - **Lock:** the handler issues `lockForUpdate` on `customers`, checked through a query listener that sees the `for update` clause on MySQL grammar, or by inspecting the builder in a seam. The lock **holding** is `[ENGINE]` (step 17).
  - The list is paged per customer.
  - **Mutations:**
    - **M13:** skip the `customers` update. The invariant test goes red;
    - **M14:** return the original without comparing the payload. The conflict test goes red;
    - **M15:** remove both the pre-check and the catch. The backstop test goes red.
  - *(AC-19 to AC-24; AC-22 apart from its `[ENGINE]` clause.)*
- [x] **Step 11: the opening balance on `CreateCustomer`** (AC-7a, D-28).
  - Non-zero `opening_balance` gives one `opening` row through the writer, reference `customer`/id. `credit_balance` = `opening_balance`, set in the create transaction.
  - A negative opening is a credit entry.
  - No currency gives `currency_required`, and nothing is written, **including no customer row**.
  - Zero gives no ledger row.
  - An update supplying `opening_balance` is refused whole (covered in step 9, AC-8).
  - **Gate: `composer check` (end of chunk C).**
  - *(AC-7a.)*

### Chunk D: loyalty, addresses, notes and communications, the merge

- [ ] **Step 12: loyalty** (`CreateLoyaltyProgram`, `UpdateLoyaltyProgram`, `RecordLoyaltyTransaction`, `ListLoyaltyTransactions`).
  - **AC-26:** a missing name; `earn_rule` and `redeem_rule` round-trip any JSON; invalid `tier_rules` refused (F-5); `is_active` defaults to true.
  - **AC-28:** an unknown program; zero points; an unknown employee. **F-6:** `earn` or `redeem` on an inactive program gives `program_inactive`, while `adjust` on it is accepted.
  - **AC-30:** `loyalty_points` = `balance_after`.
  - **AC-31:** redeeming more than the balance writes nothing.
  - **AC-32:** earn across the silver threshold sets `loyalty_tier = silver` in the same transaction, and a redeem back below it reverts the tier.
  - **F-15:** a caller `id` replay gives the same id with `replayed`; a different payload gives `replay_conflict`.
  - **AC-27:** there is no update or delete slice (step 16 scan).
  - **AC-33:** the column list has no `sale_id` (step 3).
  - The list is paged.
  - **M16:** skip the tier update. The AC-32 test goes red.
  - *(AC-26 to AC-33; AC-29 apart from its `[ENGINE]` clause.)*
- [ ] **Step 13: addresses** (four slices).
  - **AC-16:** an unknown or soft-deleted customer; a bad type; JSON round-trips.
  - **AC-17:** a default billing plus a default delivery coexist; a second default billing clears the first, by add and by update; another customer's default is unaffected.
  - **AC-18:** remove is a real delete (the row is gone); an unknown id is refused, naming it.
  - **M17:** skip the clearing. The backstop refuses, and the test goes red.
  - *(AC-16 to AC-18.)*
- [ ] **Step 14: notes and communications** (five slices).
  - **AC-34:** a blank note; the `pinned` default; a bad date.
  - **AC-35:** each enum checked; `occurred_at` defaults to now when absent, and a supplied value is kept; an unknown employee is refused.
  - **AC-36:** there is no `delivery_id` (step 3).
  - **AC-37:** both lists are paged, with the same 3-at-2 boundary case and a malformed cursor refused.
  - *(AC-34 to AC-37.)*
- [ ] **Step 15: `MergeCustomers`** (Ruling 9, which is AC-13 and D-20 as now written: compensating `adjustment` and `transfer` pairs; ledger rows never move; only addresses, notes and communications are reassigned).
  - **Refusals:** self; an unknown id on either side; a source already merged; a target merged or soft-deleted; currency mismatch with a non-zero balance. Each writes nothing.
  - **Success**, with a source holding balance 150 and 40 points plus two addresses (one a default billing), a note and a communication, and a target holding balance 50, a default billing address and 10 points:
    - the source's old ledger and loyalty rows **still** carry `customer_id = source`, byte-identical;
    - the source gains a `merge_out` adjustment, and its balance is 0;
    - the target gains a `merge_in` adjustment, and its balance is 200;
    - the transfer pair moves the points, and the target holds 50 points with its tier recomputed;
    - the addresses, the note and the communication now belong to the target;
    - the moved billing address is no longer the default, and the target's own default still is;
    - the source has `merged_into_id` set and is soft-deleted;
    - **`CreditLedgerInvariantTest`'s oracle holds for both customers.**
  - A source with a negative balance produces a debit-side `merge_out`.
  - A source with zero balances posts no ledger rows.
  - **AC-14:** nothing touches any table outside the five (step 16 scan names the tables).
  - **Mutations:**
    - **M18:** add the source's balance straight onto the target with no entries. The oracle goes red for both;
    - **M19:** reassign the ledger rows. The "byte-identical" assertion goes red.
  - **Gate: `composer check` (end of chunk D).**
  - *(AC-10, AC-13, AC-14; D-20, D-25, D-30.)*

### Chunk E: scans, engine, documents

- [ ] **Step 16: `QaCustomersSourceRulesTest` (host).** All checks read code only, through `SourceRules::codeOnly`.
  - **One writer** (the NFR, AC-41):
    - outside `packages/customers/src/Ledger/` and `src/Loyalty/`, no file under `packages/*/src`, `modules/*/src` or `modules/host/app` contains the code tokens `credit_balance`, `loyalty_points`, `loyalty_tier` or `balance_after`;
    - migrations are excluded by path;
    - `MaintainedColumns` lives under `Ledger/` by design.
  - **Append-only (AC-19, AC-27):** no code statement chains `table('customer_ledger')` or `table('loyalty_transactions')` into `update(`, `delete(`, `increment(` or `decrement(` anywhere.
  - **No pricing read (AC-5):** `price_list` appears nowhere in `packages/customers/src`.
  - **No surface:** no `routes/` directory, `Route::`, `Cache::`, `dispatch(`, `ShouldQueue` or `HookGateway`, so T-022's sweeps need no entry (the NFR).
  - **Contracts purity:** `packages/contracts/src/Customers` imports nothing outside contracts and PHP core.
  - **Red-first:**
    - a scratch file in `packages/organization/src` writing `credit_balance` is reported;
    - a scratch `table('customer_ledger')->update(` is reported;
    - delete both.
  - *(AC-5, AC-19, AC-27, AC-38, AC-41.)*
- [ ] **Step 17: `CustomersSchemaEngineTest`** (database `flexipos_t8test_t028_tenant`, created and dropped by **exact name** through `EngineDatabase`, never a `LIKE` sweep).
  - Under the hold, only the "refuses to reach the engine" case runs.
  - Written for G-1:
    - `information_schema` decimals equal section 4;
    - **the CHECK refuses** `debit = 1, credit = 1` and `debit = -1` (AC-20, D-15);
    - JSON validity on `tier_rules`;
    - the two generated-column keys refuse on MySQL;
    - a case-variant duplicate code is refused by the unique index;
    - **lock contention (AC-22, AC-29):** connection A runs `SELECT … FOR UPDATE` on a customer inside an open transaction; connection B, with `innodb_lock_wait_timeout = 1`, calls the writer and times out, writing nothing; after A commits, B's retry succeeds and its `balance_after` reflects A's write;
    - migrating twice is a no-op.
  - *(The `[ENGINE]` clauses of AC-20, AC-22 and AC-29, which stay NOT VERIFIED under `ENGINE_HOLD`, as AC-39 states.)*
- [ ] **Step 18: documents.** Each is written to describe, never to quote retired vocabulary (memory note).
  - **`docs/database.md` §6.9, "As built (T-028)":**
    - the deferred columns, with the expand-plus-contract mechanism, and `delivery_id` as BIGINT;
    - points at (18,4);
    - Ruling 3's never-round rule;
    - the F-9 side and sign table;
    - the `tier_rules` shape;
    - the merge-by-compensation rule and its reference names;
    - the replay semantics;
    - the inert `[SYNC]` columns;
    - the two generated backstops.
  - **Backlog notes** (section 10).
- [ ] **Step 19: final gate.** `composer check` from `modules/host`, with counts and `test` seconds compared against step 0, then `composer test:fast` once.
  - *(AC-38, AC-39.)*

## 7. Test strategy

- **Unit (pure):**
  - `CreditLedgerCalculatorTest`;
  - `LoyaltyCalculatorTest` and `TierRulesTest`;
  - `CustomerRules` (AC-9);
  - the contract shape and enum tests;
  - `CheckConstraintTest`.

  These carry most of the cases, through datasets.
- **Integration (SQLite stand-in through `AccessTenantFixture`):**
  - one file per slice: refusals write nothing, with no audit row; a success writes one audit row, compared whole;
  - `CustomersTenantSchemaTest`;
  - **`CreditLedgerInvariantTest`**, which is the oracle test. The pure `fold()` is an independent check of the stored chain. It is not a second production path: no handler calls it, and step 16 does not exempt it from the token scan because `fold()` lives under `Ledger/`.
- **Static (host):** `QaCustomersSourceRulesTest`, `TenantDecimalPrecisionTest` (AC-40), and deptrac with the demo negative control (AC-38).
- **Engine:** `CustomersSchemaEngineTest`, skipped under the hold.
- **Mutations:** K1 and M1 to M19, covering:
  - the sign, the limit (allowed flag, boundary, result against amount, which types it applies to) and never-round;
  - the redeem check, its boundary, tier selection and the numeric compare;
  - default clearing (two places);
  - maintained-field immutability;
  - the maintained-column update;
  - replay comparison and the backstop;
  - tier persistence;
  - merge by compensation, and append-only under merge.

| ACs | Proved by |
|---|---|
| 1–5 | step 8 (AC-5 also step 16) |
| 6, 6a, 7–12 | step 9 |
| 7a | step 11 (the update refusal is in step 9) |
| 13, 14 | step 15, plus step 16 |
| 15, 25, 33, 36 | step 3 exact column lists, plus step 18 |
| 16–18 | step 13 |
| 19 | steps 10 and 16 |
| 20 | step 10; the CHECK is `[ENGINE]` (step 17) |
| 21 | step 10 |
| 22, 29 | steps 10 and 12; the lock holding is `[ENGINE]` (step 17) |
| 23, 24 | steps 6 and 10 |
| 26–28, 30–32 | steps 7 and 12 |
| 27 | steps 12 and 16 |
| 34–37 | step 14 |
| 38 | steps 1, 16 and 19 |
| 39 | step 19 (every criterion except the three `[ENGINE]` clauses) |
| 40 | steps 4 and 17 |
| 41 | steps 6, 7 and 16 |

## 8. Risks & rollback

1. **Gate time (highest).** `test` was 2408 s at T-027's close, and about 220 cases are added. Mitigations: pure-first design (Ruling 12) and measurement at each chunk boundary. If a chunk adds more than 10 %, stop and report to `lead`.
2. **The spec revision is settled** (`f116c1e`, D-18 to D-30). The residual risk is drift: `dev` must read AC-13, AC-20, AC-21, AC-26 and AC-28 as revised, not the original draft quoted in `1d9a34a`.
3. **Stand-in and engine divergence.** The row lock, the CHECK, JSON validity and case-insensitive collation are proven only on the engine. On SQLite, `lockForUpdate()` is a no-op, so **no stand-in test can catch a writer that reads the balance before locking**. That is why Ruling 6 fixes the order in code, M13 to M15 cover the arithmetic consequences, and step 17 holds the real proof for G-1.
4. **Merge history reads.** A merged customer's statement now spans two customer ids. This is correct, but FP-240 and FP-264 must follow `merged_into_id` (section 10). Until they do, a list for the target shows only the `merge_in` line for the source's history.
5. **Offline credit sales (FP-233, FP-237).** §12.2 records an offline sale "because it already happened". An offline `credit_sale` arriving over the limit will be refused by AC-24 as written. FP-233 needs a recorded-anyway path, which is the `approval_requests` override or a conflict row. The contract is designed so that an additive `?override` argument is enough (section 10).
6. **`composer.lock`:** only the path package entry may change.

**Rollback:** every file is new except the section 9 edits. The migrations are forward-only and no tenant outside this branch has run them, so rollback is to abandon the branch.

## 9. Edits to existing files (the predicted-red list)

| File | Edit | Step |
|---|---|---|
| `modules/host/composer.json`, `composer.lock` | require and autoload-dev; the path package entry | 1 |
| `modules/host/deptrac.yaml`, `phpstan.neon.dist`, `bootstrap/providers.php` | section 5 | 1 |
| `modules/host/config/customers.php` *(new)* | Ruling 11 | 1 |
| `tests/Feature/QaToolchainRegistryCoverageTest.php` | `'customers'` in the shared-package literal | 1 |
| `packages/kernel/src/Schema/Support/CheckConstraint.php` and its test | `oneSided` | 2 |
| `database/catalogue/tenant_schema_versions.json` | republished | 3 |
| `.env.example` | `APP_REQUIRED_SCHEMA_VERSION` 43 → 51 | 3 |
| `tests/Helpers.php` `tenantTreeTables()` | the eight names, under a T-028 comment | 3 |
| `tests/Feature/TenantBaselineBuildsTest.php` | the FK inventory gains the 17 keys; the branch-referencing list gains `customer_ledger` | 3 |
| `tests/Feature/QaAccessCloseOutSweepsTest.php`, `QaAccessSchemaShapeTest.php` | a `$t028` list | 3 |
| `tests/Feature/MigrationTreeDisciplineTest.php` | the table count 41 → 49 (it carries a literal, per its T-027 comment) | 3 |
| `SchemaCataloguePublishTest` | fixed by the republish | 3 |
| `tests/Feature/TenantDecimalPrecisionTest.php` | the T-028 map | 4 |
| `docs/database.md` | step 18 | 18 |

Anything red that is not in this table means stop and report to `architect`.

## 10. Follow-ups for `lead` (backlog rows or notes, not built here)

- **FP-260:** `customers.receivable_account_id`, as an expand migration plus a contract migration (F-2).
- **FP-264:**
  - `customer_ledger.ar_document_id`, the same way;
  - the customer statement must include merged sources through `merged_into_id` (risk 4).
- **FP-233:**
  - `loyalty_transactions.sale_id` plus a plain `unique(sale_id, type)` (F-2);
  - maintaining `first_sale_at`, `last_sale_at` and `lifetime_value` (AC-11);
  - reassigning `sales.customer_id` and the other future references on merge (AC-14);
  - the credit-limit override through `approval_requests` and the offline recorded-anyway path, as an additive contract argument (risk 5);
  - lock `stock_levels` before `customers` (§12.1);
  - round points to 4 decimals `HalfUp` before calling `LoyaltyLedger` (Ruling 3.5).
- **FP-295:** `customer_communications.delivery_id` as `BIGINT UNSIGNED` onto `deliveries`, as expand plus contract.
- **FP-237 (Sync):** the inert `[SYNC]` columns on `customers` and `loyalty_transactions` are ready, and the `customer` and `customer_group` replica entity types (§6.18).
- **FP-240:** `GetCustomer`, `ListLoyaltyPrograms`, delete for notes, permission keys, and the merged-history view.
- **A custom-fields task:** `customers.custom_fields` (F-1).
- **A lifetime-points or lifetime-spend tier basis**, if the owner wants it (D-11). It is additive.
- **FP-331:** the eighth copy of the support helpers.

## Questions for the human

None. F-1 to F-19 were resolved by `ba` in `f116c1e` (D-18 to D-30). Two stale lines remain in the spec, both informational (see the head of this plan). `lead` may still want to tell the product owner about D-20: it is the one decision that changes what a user sees, because a merged customer's history spans two records until FP-240 or FP-264 builds the combined statement.
