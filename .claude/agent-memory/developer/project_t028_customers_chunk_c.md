---
name: t028-customers-chunk-c
description: T-028 Customers chunk C (steps 8-11) - groups/customers CRUD, the credit ledger writer, replay semantics, and the AuditCatalogueTest gap a new DeclaresAuditActions provider causes
metadata:
  type: project
---

Measured 2026-11-10, one `dev` session, continuing from [[t028_customers_chunk_b]].
Shipped steps 8-11: four group slices (Create/Update/Delete/List), four customer
slices (Create/Update/Delete/List), `Ledger\CreditLedgerWriter` (the one real
database-backed writer, built for the first time this chunk), `Ledger\
ContractCreditLedger` (binds `CustomerCreditLedger`), `RecordCreditLedgerEntry`/
`ListCreditLedgerEntries`, and the opening-balance write on `CreateCustomer`
(AC-7a). Gate: 13 failed (exact pre-existing baseline) / 31 skipped / 3408
passed, `test` step 1020.29s (7.64% of the 7800s limit - no growth concern,
actually lower than chunk B's 1702.58s, most likely less machine contention
this run).

**A second predicted-red gap outside `packages/customers` the plan's section 9
table did not name, same shape as chunk A's `IdempotencyKeysSchemaTest` gap**:
`packages/access/tests/Support/AuditCatalogueTest.php` hardcodes the *complete,
sorted* list of modules every `DeclaresAuditActions` provider has registered
('access', 'catalog', 'organization', [+'fixture' in one dataset]) in two
separate `toBe()` assertions. Any new package implementing `DeclaresAuditActions`
(this task's `CustomersServiceProvider`) turns both red with a diff showing the
new module slotted in alphabetically. Fixed by adding `'customers'` to both
lists in their correct alphabetical position. This is a `dev`-owned file (not
`Qa*`-prefixed), the identical T-027 precedent of adding `'catalog'` the same
way when that package's provider first declared actions.

**How to apply:** on any future task whose service provider implements
`Flexipos\Contracts\Audit\DeclaresAuditActions` for the first time, grep
`AuditCatalogueTest.php`'s two `toBe(['access', ...])` module-list assertions
before trusting the plan's own predicted-red list to be complete - this is now
the second known repeat offender alongside `IdempotencyKeysSchemaTest`. Related:
[[gate_reads_live_filesystem_never_edit_during_it]],
[[t028_customers_chunk_a]] (names the first offender).

**A subtlety in the writer/test seam**: SQLite's NUMERIC column affinity
silently reformats a string like `"100.0000"` written through `insert()` -
reading it back can give a float or a trimmed-zero string. `CreditLedgerWriter::
resolveReplay()` must re-canonicalize every value read back off the row
(`Decimal::canonical(BigDecimal::of((string) $existing->debit))`) before either
comparing it or handing it to `CreditLedgerPosting`'s constructor, which enforces
an exact 4-decimal-place regex and throws `InvalidArgumentException` on anything
looser. Comparing stored vs. computed amounts must go through `BigDecimal::
compareTo()` (or re-canonicalized-string equality), never raw string equality
against whatever SQLite handed back - this bit the `CreditLedgerInvariantTest`
during authoring until switched to `compareTo() === 0`.

**One reusable-helper decision**: `AccessSeed::branch()`/`AccessSeed::employee()`
(packages/access/tests/Support) were reused as-is for the one `RecordCreditLedgerEntry`
branch/employee-lookup test, rather than building a second copy in `CustomersSeed`
- `Package_Customers_Tests` is already granted `Package_Access` in deptrac.yaml
for exactly this (the fixture tenant it builds on is access's).

**One deviation from the plan's literal Ruling 10 wording, reported**: Ruling 10
says "the contract adapters ... build the command and call `handle()`," which
would make `RecordCreditLedgerEntryHandler` the sole implementation the contract
adapter calls into. Ruling 6 is more specific and names the real call graph
explicitly: "the contract adapter (`ContractCreditLedger`), `CreateCustomer` ...
and `MergeCustomers` all go through it [`CreditLedgerWriter`]" - `RecordCreditLedgerEntryHandler`
is not in that list. Followed Ruling 6 literally: both `ContractCreditLedger` and
`RecordCreditLedgerEntryHandler` call `CreditLedgerWriter::post()` directly and
independently, rather than one calling the other. Functionally equivalent (one
writer, one source of truth either way); flagged as a deviation from Ruling 10's
generic template sentence, not from Ruling 6's specific, authoritative one.
