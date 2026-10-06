# QA Report: T-006 — the unit-of-measure model (documents only)

Spec `docs/specs/T-006-unit-of-measure.md` (AC-1 to AC-67) · Plan `docs/plans/T-006-unit-of-measure.md` · Decisions D-57 to D-69.
Branch `ai/T-006-unit-of-measure`, 17 commits over base `47e226d`, nothing pushed. Measured on this machine on **2026-10-06**.
No code, test or fixture was written: the task is documents, and nothing here needed a new test.

## Verdict: PASS (run 9, final; one minor open, BUG-29; runs 1, 3, 4, 5, 7, 8 FAIL, runs 2 and 6 PASS, all kept as measured, with run 1 AC-52 corrected below)

Two acceptance criteria fail (AC-52, AC-63), both **minor** and both fixable by one sentence each.
One **major** design defect (BUG-1) is not an AC failure as written but is the exact defect class the
task claims to have fixed in the ledger key, reintroduced in the replicated price key. Gate is green.

Defect count: **1 major, 7 minor**. Criteria: **65 pass, 2 fail, 0 not applicable.**

## Gate (run by me, not copied)

`cd modules/host && composer check` (PHP 8.2.12 absolute path): **exit 0**; Pint passed, PHPStan
`[OK] No errors`, Deptrac Violations 0 / Skipped 0 / Allowed 0, Pest **314 passed (1435 assertions)**,
86.38 s (warm; the lead's 182 s was a colder run, the counts agree). T-005's final QA run recorded
314 / 1435 (its report lines 424 and 435), so AC-57's "same counts as the T-005 tip" holds. The first
heading of the T-005 report says 1414; that is its run-3 headline, superseded in the same file.
`git status` was clean before and after my gate run.

## Independently derived counts

| Question | Answer | Method |
|---|---|---|
| Tagged `[UOM]` tables | **17** (agrees with the architect) | Read every database.md row carrying the tag; for the 9 header/item rows I located the tag **after** the ` · item` marker, i.e. on the item table, not the header. Per row: 4 in §6.3/6.4 (`product_barcodes`, `product_components`, `modifiers`, `price_list_items`), 4 in §6.5 (3 item tables + `stock_production_items`), 5 in §6.6, 4 in §6.7. Cross-checked against the AC-23 list name by name; and against the v0.4 document at `47e226d`: 16 of the 17 names exist there, `stock_production_items` does not. So "sixteen that already existed plus one" is true. |
| Exemptions needed by a literal "all four columns" test | **5, not 3** (agrees) | Read the item part of each of the 17 rows: `price_list_items` (unit only), `product_barcodes` (unit + pack quantity, no factor/base), `modifiers` (`linked_*`), `stock_count_items` (`counted_quantity`), `stock_transfer_items` (`qty_sent`/`qty_received`). The other 12 name `quantity` + `base_quantity` through the tag. §14's split 17 = 13 document-line + 4 master data, "eleven" with `quantity`/`base_quantity` = 13 minus the two renamed: arithmetic holds. |
| Index sum (§6 domain table) | **193** | awk sum of the 21 index rows, plugins counted as 3. |
| True table count | **196** (agrees) | Three methods: (a) row-by-row first-cell enumeration of every §6.x table, slash rows expanded; (b) a script extracting every distinct snake-case first-cell identifier in §6 (196 distinct names, plugin wildcard row excluded); (c) every `<x>_id` foreign-key prefix resolved to a defined table, looking for a table that is referenced and never defined: none missing. |
| Domains where index != tables | **3, complete**: Finance 19 (true 20), Notifications 6 (true 7), System 8 (true 9) | Per-domain counts, all 21: 18 match, three are one low. No fourth row: sections 6.1-6.9, 6.11-6.15, 6.17-6.20 all equal their index entry. 196 - 193 = 3, which closes. The architect's "counted once" check also reproduces: Finance counted once = 17 (not 19), System = 7 (not 8), Notifications = 6 (exactly the declared figure). |
| Catalog | 14 (fixed) | And the **base** document declared 12 against a true 13, so Catalog was already one low at `47e226d`; the base index summed to 189, which agrees with the plan's C-1 and **not** with the spec's Finding F-2 ("13 stale"). |
| Bare `DECIMAL` removed | **11 occurrences in 9 rows** (documents say ten) | `git diff 47e226d..HEAD -U0 -- docs/database.md`, removed lines, `grep -oE 'DECIMAL([^(]|$)'`. 11 at the base, **0** column definitions remain (3 remaining hits are prose: change log, §3.2 twice). See BUG-7. |
| Changed paths | all 6 under `docs/` | `git diff --name-only 47e226d..HEAD`: architecture, backlog, database, decisions, plans, specs. Nothing under `modules/`, `frontend/`, `print-agent/`, `infra/`, `.github/`, `CLAUDE.md`, `Refference/`. |

## Acceptance criteria coverage

Evidence is reading the named section, a count, a grep, or the gate. Groups are listed with the
exceptions called out; every AC not named in a note passed on the check its text prescribes.

| AC | Check | Result |
|----|-------|--------|
| 1-3 | Header, change-log items (all 13 enumerated items found in the v0.5 entry, v0.4 entry follows), architecture Status/Change log, "≈190 → ≈193" present | PASS |
| 4 | `git diff --stat`: every path under `docs/` | PASS |
| 5, 6 | Provenance section; backlog "not recorded anywhere" at line 110; exactly **10** rows with "widened by T-006" (FP-230/231/233/234/236/237/250/253/254/255); FP-243/257/258 and T-006 row exist | PASS |
| 7-11 | U-1 key and ladder, `min_qty` on base, U-2 not extending `type`, FP-258, U-3 and §15 "No longer dead schema" | PASS (but see BUG-1 on the U-1 key) |
| 12 | `products.unit_id` appears once in database.md (the change log, as the former name); zero in architecture.md | PASS |
| 13 | `purchase_unit_id`/`sales_unit_id` nullable, symmetric, defaults not authorities | PASS |
| 14 | Immutable-after-movement rule present and not contradicted by Q-10 | PASS (pointer missing: see AC-63) |
| 15 | §9.4 non-waiver stated; migration directories listed: `modules/host/database/migrations` holds the three framework files only, `modules/demo/.../migrations` is empty; no product table migration anywhere | PASS (but see BUG-3 on what §9.4 says) |
| 16-19 | Conversion once; the 7 tables carry no `unit_id`/`unit_factor`/`unit_code` column: I read rows 597, 598, 599, 600, 602, 727, 874 — zero column hits; `purchase_price_history` mentions `unit_id` only in prose ("so no `unit_id`"), which AC-17 allows; §14 list equals §6.5 list; money/quantity table has 5 rows and §7 line 1090 states the currency twin | PASS |
| 20, 21 | Snapshot replaces effective dating; `product_components` factor at write time | PASS |
| 22-27 | `[UOM]` row complete; census 17; three nullable `unit_id` (`price_list_items`, `product_barcodes`, `supplier_invoice_items`) all stated in §3.1's table; `modifiers.linked_unit_id` and `barcode_rules.unit_id` recorded below; degenerate tags; named variations; `unit_code` appears only on `quotation_items`, `sale_items`, `order_items` (plus the §3.1 and §14 prose) | PASS |
| 28-30 | Two readings of `unit_cost` stated beside the formula; sales-order base progress with the asymmetry explained; free goods with the same factor and "not settled" | PASS |
| 31-34 | Five-step ladder, no sixth; `dimension`; Q-9 guard labelled as asked; precision authority and rounding order | PASS |
| 35 | No bare `DECIMAL` column remains; §14 specifies the static test | PASS (count is 11, not "ten": BUG-7) |
| 36-38 | Key is `(reference_line_id, type, batch_key, serial_key)`; both bugs and both rejected alternatives; §11 lists the new key and the old one only as "replaced"; §14 MariaDB statement; grep of the old key text: 2 hits (§6.5 "v0.4's key was", §11 "the old"), both read as replaced | PASS |
| 39-43 | Formula, worked example, variance per base unit, base-to-base rollup, `quantity_incoming`, posting rules identical in §6.5 and §7.5 | PASS |
| 44-47 | Replica entity types; arch §12/§15 text; §12.1 resolution before `FOR UPDATE`; §12.2 "line's own factor wins" + conflict row; barcode order and §11 | PASS |
| 48 | §6.3 ERD: `stock_unit_id`/`purchase_unit_id`/`sales_unit_id`/`is_weighable`, no `unit_id`; the three `UNITS ||--o{ PRODUCTS` edges; every ERD column of `PRODUCTS` exists in the row. `BARCODE_RULES` absent from the ERD, allowed | PASS |
| 49 | §6.5 ERD: `reference_line_id`, no `unit_id`; the two production edges; all 13 entities have a row beneath | PASS |
| 50 | Index sums to 193; true count 196; recorded in backlog FP-177 with the three-row list; §6/§10 state 193 | PASS (the spec's own "192" and "13 stale" are now out of date) |
| 51 | No "150" in architecture.md; "roughly 190" attributed to §10; §10: 300 x 200 = 60 000 files; 60 000 / 203 = 295.6, and 900 files / 203 = 4.4: ≈295 is sound. 3/190 = 1.58 %, 900 x 112 KB = 100.8 MB | PASS (see note N-1) |
| **52** | Cross-references: followed every `§` added by the diff (≈60 refs) | **FAIL** — BUG-3 |
| 53 | Three static tests in §14 and arch §21; exemption list is **in the document**, verified at five | PASS |
| 54 | Rule in §6.3 "The base unit" is explicit. Quantity columns silent in their own row: `batches.initial_quantity`, `stock_batch_levels`, `stock_reservations.quantity`, `product_locations.reorder_*`/`max_level`, `stock_conflicts.expected_qty`/`resulting_qty`, `product_suppliers.min_order_qty` — all rely on the rule, which is stated. One column the rule does **not** cover: BUG-8 | PASS (+BUG-8) |
| 55, 57 | Vocabulary sweep inside the gate, green; counts equal T-005's final | PASS |
| 56 | §4.4: key `tenant:conn:<tenant_id>`, version a value, secret cached on `<tenant_id>:<credential_version>`; backlog FP-158 now says DONE (the spec's F-5 is already fixed) | PASS |
| 58 | 1.5 M ledger rows appears in §6.3 and §11 identically; 1.58 % | PASS |
| 59, 60 | `barcode_rules` columns exact; `stock_productions` / `stock_production_items` exact; `stock_production` is in `number_sequences.document_type` and in `journal_entries.source_type`; apportionment and residual rule present | PASS (but see BUG-2 on `unique ... where active`) |
| 61, 62 | Three invariants, the batch-sum check documented as blind and kept; check 3 expression identical to the `grn_items` posting expression; arch §21 | PASS (see BUG-6) |
| **63** | Q-9 to Q-12 exist; arch §23 rows 6-9 | **FAIL** — BUG-4: Q-10 has no pointer in §6.3 |
| 64 | Part 1 settled, none of D-57 to D-65 answers Q-9..Q-12 (grep: the only mention of Q-9..Q-12 in the decisions file is the header sentence that says they were *not* decided there) | PASS |
| 65, 66 | Nothing under `modules/`, `frontend/`, `print-agent/`, `infra/`, `.github/`, `CLAUDE.md` or `Refference/` in the diff | PASS |
| 67 | `git diff e1c5d26^ e1c5d26 -- docs/decisions/`: **27 insertions, 0 deletions** — D-57 to D-65 untouched, ba's additions start at D-66 | PASS |

Unescaped-pipe scan (script, column count per table row against its separator, whole of
database.md, architecture.md, backlog.md): **one** hit, database.md line 1184 (§8 step 12) = FP-178,
known. None in any row T-006 added or edited.

## Defects

Symptom lines were written before reproducing, per the brief.

### BUG-1 (major)
- **Symptom:** the replicated `price_list_items` unique key `(price_list_id, product_id, unit_id, min_qty)` has a nullable `unit_id`, so under MySQL/MariaDB NULL-distinctness two rows meaning "priced per stock unit" for the same list, product and `min_qty` are both permitted. This is the same hole §6.5 calls "the second bug, and the worse" in the ledger key, reintroduced one section later.
- **AC:** none fails as written (AC-7 asks for exactly this key); it is a design defect found by applying the document's own reasoning to it.
- **Expected:** a key that actually enforces one price per (list, product, unit-or-stock-unit, break). Either a stored generated `unit_key = COALESCE(unit_id, '-')` in the key, mirroring `batch_key`, or `unit_id NOT NULL` with the derive-fallback expressed some other way.
- **Actual:** §6.4 row 542 and §6.4 ladder: "first hit wins" over a lookup that may return two rows for `unit_id IS NULL`. The document's own argument for U-1 is that this key cannot be changed after launch without resyncing every terminal, so this is the cheapest it will ever be to fix.
- **Related, same class:** `unit_conversions` `unique (product_id, from_unit_id, to_unit_id)` with `product_id NULL` meaning global (row 481): duplicate global conversions allowed, and ladder step 2 now depends on it. Pre-existing from v0.4 but widened by T-006 routing resolution through it.
- **Suspected location:** `docs/database.md` rows 542 and 481, §6.4.

### BUG-2 (minor)
- **Symptom:** `barcode_rules` is specified `unique (prefix, length) where active` (row 484, AC-59, D-64). MySQL 8.4 and MariaDB 10.4 have no filtered/partial unique index; it cannot be created as written.
- **Expected:** a mechanism that works on both engines (generated `active_key` that is NULL when inactive, which makes the uniqueness NULL-distinct on purpose), or an application-level rule stated as such.
- **Suspected location:** `docs/database.md` row 484; `docs/decisions/` D-64.

### BUG-3 (minor, fails AC-52)
- **Symptom:** §6.3 "The rename costs nothing today" says "§9.4 forbids renaming in one step — add, dual-write, drop — and that rule is not being waived". §9.4 says "never rename a **table** in one step (add, dual-write, drop)"; for columns, its expand step lists `rename` among operations `ALGORITHM=INSTANT` can do. The reference points at a section that does not say what the reference claims about a column rename.
- **Expected:** say what §9.4 actually says (expand, backfill, contract applies to the column; the rename-one-step rule is for tables) or drop the claim.
- **Suspected location:** `docs/database.md` §6.3 line 501 and §9.4 line 1252.

### BUG-4 (minor, fails AC-63)
- **Symptom:** §6.3 "`stock_unit_id` is immutable once a movement exists ... Changing the stock unit of a product with history is a new product" is stated as settled with **no pointer to Q-10** and no "the owner is asked to confirm". `grep Q-10` finds it only in §16 and architecture §23. The spec says the pointer is what makes a settled-sounding sentence permissible, and asks `qa` to confirm it.
- **Expected:** a clause such as "(§16 Q-10: the owner is asked to confirm)" in that paragraph. Q-11's reject-rule is not in §3.2 at all, so it is not stated as settled and needs nothing; Q-12 is §16-only by design; Q-9 has its pointer at line 516.
- **Suspected location:** `docs/database.md` §6.3, "The base unit".

### BUG-5 (minor)
- **Symptom:** the weighted-average formula guards only "denominator is 0". §12.2 and §6.18 explicitly allow stock to go negative at an offline sale. After negative on-hand, `on_hand + base_qty_in` can be a small positive, a small negative or negative-times-average; the result is a huge or negative `avg_cost`, again with "no error, no warning".
- **Expected:** a stated rule for `on_hand <= 0` (for example `new_avg = cost_per_base` whenever `on_hand <= 0`), since the document calls this the most dangerous arithmetic in the feature. The worked 24 -> 200 example itself is right: 4 800 / 24 = 200; division by `unit_factor` is on the cost, in the right place; both readings of `unit_cost` are stated beside it.
- **Suspected location:** `docs/database.md` §6.5 "Valuation, and the formula".

### BUG-6 (minor)
- **Symptom:** nightly invariant 1, `quantity_on_hand = SUM(stock_movements.quantity)`, does not say which movement types it sums. The ledger enum carries `reservation` and `reservation_release`, while `quantity_reserved` is a separate column. If those rows carry a signed quantity the check fires on every reservation; if they carry zero the document does not say so. "In a form that could actually be implemented" is not met for check 1 until it does. Checks 2 and 3 are implementable; check 3's expression equals the `grn_items` posting expression verbatim (`base_quantity + ROUND(free_quantity × unit_factor, 4)`), and the existing batch-sum check is documented as blind and kept.
- **Suspected location:** `docs/database.md` §6.5 "Three nightly quantity invariants", row 598 type enum.

### BUG-7 (minor)
- **Symptom:** plan C-3 and spec AC-35 say "ten bare `DECIMAL`, not five". Counted at the base document there were **11** occurrences in 9 rows (`statutory_rates.floor`, `salary_structure_components` x2, `expense_categories` x2, `workflow_instances.amount`, `payslip_lines.quantity`, `journal_lines.tax_base`, `product_barcodes.quantity`, `price_list_items.min_qty`, `stock_movements.quantity`). The plan names five "plus" five and one is unaccounted for. All 11 were fixed; only the stated number is wrong. database.md itself does not state a number.
- **Suspected location:** `docs/plans/T-006-unit-of-measure.md` lines 156, 212, 330, 333; spec AC-35.

### BUG-8 (minor)
- **Symptom:** `sale_items.refunded_quantity` (row 773) sits on a document line, so §6.3's rule "in the stock unit unless it sits on a document line that says otherwise" does not cover it, and the row does not say which unit. A line sold as 2 cases with one single refunded has two readings. D-59 resolved the same question for `sales_order_items`; it was not asked here.
- **Suspected location:** `docs/database.md` row 773.

## Regression risks / notes

- **N-1:** architecture.md §7 now says "roughly 190 tables per tenant — the figure database.md §10 computes"; §10 computes ≈193 core + ≈10 plugin and models a rounded 200. "Roughly 190" is within the rounding, and AC-51 asks for it, but "the figure §10 computes" is not exact.
- **N-2 (pre-existing, not T-006):** §10 item 2 says "a brand-new tenant occupies ≈ 2.2 GB of nothing at 200 tables × 112 KB". 200 x 112 KB is 22 MB; 2.2 GB is the 100-tenant row. Present at `47e226d`. Not filed; FP-171 family.
- **N-3 (pre-existing):** §11 "large" profile partitions `stock_movements` monthly, and MySQL requires every unique key (including the primary key and now the idempotency key) to contain the partition column. The v0.5 key does not; neither did v0.4's. Worth a backlog line before FP-250 builds it.
- **N-4:** the idempotency key is sound on the two bugs it claims: two GRN lines of one product in different units have distinct line ULIDs; `batch_key`/`serial_key` are `NOT NULL` so quantity-tracked products (`'-'`) are uniqued for real. §11 lists the new key and the old one only as replaced. One residue worth a thought for FP-250: `type` is in the key, so a line that reserves, releases and reserves again would collide on `reservation`.
- **N-5:** the spec is out of date in three places (its own Findings F-1, F-2, F-3, F-5 are already fixed in the documents; AC-50's "192" is now 193; F-2 mislabels Catalog as "13 stale" when it was 12 against a true 13 at the base). Not a defect in the deliverable; the spec is `ba`'s file.
- **Brief checked against measurement:** nothing in the lead's brief was wrong. The lead's gate time (182 s) differs from mine (86 s) by warmth only; counts match exactly.
- Known-open, not reported: FP-177, FP-178, `CLAUDE.md:5`, Q-9 to Q-12 unanswered, CQRS unrecorded.

## Run history

Run 1: FAIL (2 ACs, 1 major + 7 minor defects; gate green at 314 / 1435).
Run 2: FAIL -> PASS (BUG-1 to BUG-8 verified closed; one new pre-existing finding, BUG-9, filed rather than blocking).

---

## Run 2 (tree at `08d8991`)

Gate re-run by me: **exit 0**, PHPStan no errors, Deptrac 0 violations, Pest **314 passed / 1435 assertions**, 67.91 s warm. Pipe scan: still only FP-178. **All 67 ACs: 67 pass, 0 fail, 0 not applicable.** AC-52 and AC-63 now pass.

| Item | Re-test | Result |
|---|---|---|
| BUG-1 | `price_list_items.unit_key` = `COALESCE(unit_id,'-')` stored; key `(price_list_id, product_id, unit_key, min_qty)`; `unit_id` stays nullable. The old key text survives only in §6.4 prose as the rejected key. The claim "does not touch the replica payload" is correct for the server table (the column is derived from `unit_id`, which the payload already carries); the one thing the documents do not say is that the till's own local store must enforce an equivalent key, which is frontend design and not a defect here | closed |
| BUG-1 related | `unit_conversions.product_key` present, key `(product_key, from_unit_id, to_unit_id)` | closed |
| BUG-2 | `barcode_rules.active_prefix = IF(is_active, prefix, NULL)`, `unique (active_prefix, length)`: works on MySQL 8.4 and MariaDB 10.4, and uses NULL-distinctness deliberately | closed |
| BUG-3 | §6.3 line 506 now says §9.4's prohibition is about tables and a column rename is INSTANT in the expand step; matches §9.4 | closed (AC-52 PASS) |
| BUG-4 | Q-10 pointer present in §6.3 with the reverse-answer consequence | closed (AC-63 PASS) |
| BUG-5 | guard is `if on_hand <= 0` (line 678) with the reason at 685 | closed |
| BUG-6 | invariant 1 excludes the two reservation types; the enum has **18** types, 16 summed, 2 excluded: I counted | closed |
| BUG-7 | plan now says eleven (my count, 11 in 9 rows) | closed |
| BUG-8 | `refunded_quantity` base, D-70; D-71 on reservation `reference_line_id` | closed |
| N-1, N-3 | architecture §7 figure and FP-179 present in backlog | closed |
| N-2 | §10 now says 22 MB, and 200 x 112 KB = 22.4 MB. I would have noted rather than fixed, but it is one number, own commit, revertable: I do not object | accepted |

### BUG-9 (major, pre-existing from v0.4, not introduced by T-006): the §3.3 convention does not cover every site it applies to
Sweep method: listed every `unique (...)` in §6 (about 50 keys) and read the nullability of each column in the owning row. The convention text claims "three sites"; it does not claim exhaustiveness, so it is accurate, but it now states the rule that these sites break. Keys over a nullable column where NULL is a meaningful value, and so permit unlimited duplicate null rows:
- `number_sequences (branch_id, document_type)`, `branch_id NULL` = company-wide: two company-wide sequences for one document type are allowed, which means **duplicate document numbers**.
- `settings (scope_type, scope_id, key)`, `scope_id NULL`: duplicate company-level settings.
- `employee_roles (employee_id, role_id, branch_id)`, `branch_id NULL (null = all branches)`: duplicate all-branch grants.
- `account_mappings (event_key, scope_type, scope_id)`, `scope_id NULL`: two default posting mappings, ambiguous GL account.
- `journal_entries (source_type, source_id, source_purpose)`, `source_purpose NULL` for a single-entry document: **the posting idempotency key** admits a double post. Also specified "where source_id not null", a partial unique, the shape BUG-2 removed elsewhere.
- `commission_entries (source_type, source_id, plan_id, rule_id)`, `rule_id NULL`: idempotency admits duplicates.
- `gateway_configs (gateway, branch_id)`, `holidays (date, branch_id)`, `print_templates (branch_id, type, name)`, `printer_routes (branch_id, terminal_id, document_type, kitchen_station_id)`, `daily_summaries (branch_id, terminal_id, business_date)`: NULL means "company-wide / all terminals" in each.
Intentional NULLs (not defects): `products.plu_code`, `customers` phone, `ar_documents`/`ap_documents (source_type, source_id)` for manual rows.
Not blocking T-006 (no AC covers it, the keys are v0.4's, nothing is built). Recommend one backlog row alongside FP-179, to be settled before the first per-tenant migration; the two that matter most are `number_sequences` and `journal_entries`.

Anything in the coordinator's message that measured wrong: nothing. "Four sites" is the count found, and my sweep finds at least eleven more.
Run 3: PASS -> FAIL (review round introduced BUG-10: the free-goods dilution claim is false for the formula as written, and the new reconciliation breaks on it).

---

## Run 3 (tree at `60f0107`)

Gate by me: exit 0, PHPStan no errors, Deptrac 0, Pest **314 / 1435**, 192.85 s (cold; the coordinator's 82 s and my 68 s were warm). Pipe scan: only FP-178. Criteria: **65 pass, 2 fail (AC-30, AC-39), 0 n/a.**

### BUG-10 (major, introduced by the review round): free goods do not dilute `avg_cost`, and the new reconciliation breaks on them
- **Symptom:** §6.5 now says "the formula as written **does** dilute `avg_cost` with bonus goods: ten cases bought and two free are twelve cases of stock at the price of ten" (D-74), and §6.5 R-4 says `SUM(stock_levels.total_value)` equals the inventory account balance.
- **Reproduce with the document's own numbers:** on_hand 0; receive 10 cases at landed 4 800 per case, 24 per case, plus 2 free cases. `cost_per_base` = 4 800 / 24 = 200. `base_qty_in` = 240 + 48 = 288. Because on_hand <= 0, `new_avg` = 200: **not diluted** (a diluted average is 10/12 x 200 = 166.67). `total_value` = 288 x 200 = **57 600**. The GL debit (§7.2, Dr Inventory at landed cost of the paid lines) is 10 x 4 800 = **48 000**. They differ by the free goods at full price, on every receipt that carries a bonus. With on_hand > 0 the same: the free base units are weighted at full cost, so the average ends at the paid price and value is overstated.
- **Expected:** to dilute, the free units enter at zero cost: `new_avg = (on_hand x avg + paid_base_qty x cost_per_base) / (on_hand + base_qty_in)`, with `base_qty_in` (incl. free) only in the denominator. Then value = 48 000 and reconciles.
- **Second symptom, same cause:** the `grn_items` row (line 817) still says "whether they also dilute `avg_cost` is a valuation question this task deliberately does not settle", contradicting §6.5 and D-74. That is why AC-30 and AC-39 fail on this tree.
- **Also:** D-74 calls the alternative "free goods enter at zero cost and leave `avg_cost` alone"; entering at zero cost is the diluting reading, so the description of the choice is muddled too.
- **Suspected location:** `docs/database.md` §6.5 "Valuation, and the formula" (lines ~727-745) and the `grn_items` row; `docs/decisions/` D-74.

### BUG-11 (minor)
`landed_cost` is declared per line unit only in §6.5 prose and D-72. The `grn_items` row says "landed_cost (after apportioning other charges)", beside `unit_cost (per line unit)`, and never states a unit; an implementer reading the row can store a line total. Say "per line unit" in the row.

### BUG-12 (minor, gap)
`stock_production_items.role = wastage` has no movement type in the R-2 table (`production_in` = output/byproduct, `production_out` = input). Either wastage lines write no movement (value only) or they write one under a named type; the table is silent although it claims every type is covered.

### Re-test of the review round

| Item | Result |
|---|---|
| R-1 key `(reference_line_id, product_id, type, batch_key, serial_key)` | Holds. Original fix 1 intact (two receipt lines have distinct line ULIDs); fix 2 intact (`batch_key`/`serial_key` generated from nullable columns and `NOT NULL` themselves, `'-'` sentinel). Composite, modifier and opening cases are covered by the table and the at-most-one rule. A line drawing one product from two batches differs on `batch_key`. §11 and the §6.5 DDL agree. No remaining "header-level movement" wording in database.md, architecture.md or backlog |
| R-2 table | Complete at **18**: I counted rows against the enum (opening; sale, sale_refund, sale_void; grn; grn_return; purchase_return; adjustment, wastage, expiry; count; transfer_out; transfer_in; production_in, production_out; correction; reservation, reservation_release) and every enum member appears once. Each row names the right line table and `reference_type`, and reservation `reference_type`s match the `stock_reservations` enum. `grn_return` honestly says no document defines it, backed by FP-255. Gap: BUG-12 |
| R-3 | 10 x 24 = 240 base; re-resolved at 12 removes 120; **120 stranded** — arithmetic correct. "Check 3 covers receipts only" and check 1 blind: true as written. The four referencing columns exist in their rows |
| R-4 | The averaging of `inventory_cost` and the stated reconciliation are right in principle, but see BUG-10 for the free-goods break. I found no other section naming a different number for the inventory balance (§7.2 Dr at landed cost, §7.5 intra-account rules and COGS from `line_cost`: consistent) |
| §14 counts | 17 - 3 exempt = **14**; of those, `quantity`/`base_quantity` on **12** (all but `stock_count_items`, `stock_transfer_items`), which I enumerated by name. Both correct. `product_components` passes the quad unaided, so no exemption |
| Backlog | T-006 row now sits inside the build table; FP-177 says 193 vs 196 consistently; FP-180 to FP-183 present |

### Ruling on transfer instalments (D-73)
I agree with the architect's refusal. The schema has one `received_at`/`received_by` on the header and one `qty_received` pair per line, so receiving is one event per line, and `partially_received` is realisable as "some lines short or absent, shortfall in `qty_variance`". The residual unrecordable case is a short line whose remainder arrives later; D-73 names it and routes it to FP-253 instead of inventing a receiving table. That is a gap, not a contradiction, and the correct place for it. One request for FP-253: say whether `qty_variance` is a final shortfall or pending, because the answer decides whether a later arrival is a new document.

Nothing in the coordinator's message measured wrong except the claim that the formula dilutes (BUG-10) and the claim that the review round left no grn_items contradiction.
Run 4: FAIL -> FAIL (BUG-10/11/12 closed; the D-76 accumulator rule is contradicted by the negative-stock reset, BUG-14, and D-74 keeps a stale "exactly", BUG-13).

---

## Run 4 (tree at `158b30c`)

Gate by me: exit 0, PHPStan no errors, Deptrac 0, Pest **314 / 1435**, 91.42 s. Criteria: **67 pass, 0 fail, 0 n/a** against the documents as they now stand; two spec criteria are stale by design (AC-30 and AC-39 describe the superseded "not settled" and numerator, and should be re-worded by `ba`). Verdict is FAIL on BUG-14.

### Re-test

| Item | Result |
|---|---|
| BUG-10 first example | 10 + 2 free, 24/case, landed 4 800: cost_per_base 200, base_paid 240, base_free 48. Both-in-numerator: 288 x 200 = 57 600, +9 600. Denominator-only: money 48 000, avg 48 000 / 288 = 166.6667, value 48 000 = GL debit. Correct |
| BUG-10 prior-stock example (the unverified one) | 100 on hand at 150 = 15 000; money in 240 x 200 = 48 000; total 63 000; quantity 100 + 240 + 48 = 388; 63 000 / 388 = 162.371134..., stated 162.3711. **Correct** |
| D-76 residue | 48 000 / 288 = 166.666667, stored 166.6667; 166.6667 x 288 = **48 000.0096**. Correct, 0.96 cent |
| BUG-11 | The `grn_items` row now says `unit_cost` and `landed_cost` per line unit, and the deferral sentence is gone (grep for "does not settle" finds nothing in database.md, architecture.md or backlog.md) |
| BUG-12 | The R-2 table was in fact complete on the 18 types; the gap was the production **role**. `role = wastage` writes no movement: inputs leave on `production_out` in full, outputs arrive on `production_in`, wastage is the difference, so a movement would remove it twice and break check 1. Reasoning correct; it feeds `wastage_value` and the §7.5 Dr wastage expense. Closed |
| D-74 no path to the old reading | The denominator-only rule, the two-reading balance-sheet naming and "full cost is a defect, not an option" are all present; only presentation is left open. **But** see BUG-13 |

### BUG-13 (minor)
D-74 (rewritten) still says "free-in-the-denominator-only gives 166.6667 x 288 = **48 000 exactly**". Per D-76 and §6.5 it is 48 000.0096, and `total_value` ties only because it is the accumulator. A reader of D-74 alone is told the avg_cost product ties exactly, which is the overclaim D-76 corrected. Fix: "48 000 when total_value is the accumulator (D-76)".

### BUG-14 (major): the accumulator rule is not consistent with the `on_hand <= 0` reset, so the "by construction" tie fails after negative stock
- The formula sets `prior = 0` whenever `on_hand <= 0`, a guard I recommended in run 1 for the zero/negative denominator, written before `total_value` became the authority. With `total_value` as the accumulator the reset discards a real balance.
- **Reproduce:** location at 0 stock. An offline sale drives it to -5 at snapshot cost 100: `total_value` = -500, GL credits inventory 500. A receipt of 10 at 100 follows. Formula: `prior = 0`, so `total_value` = 1 000 at on_hand 5. The inventory account holds 1 000 - 500 = **500**. Stock ledger says 1 000: a permanent 500 overstatement, with the document claiming reconciliation by construction.
- **Expected:** `total_value_new = total_value + money_in` always, including when negative; `avg_cost` derived as `total_value / on_hand` only where `on_hand > 0` (and a stated value, e.g. last cost, when `on_hand <= 0`, since the derivation divides by zero at exactly zero stock).
- **Also unstated:** how an **outbound** movement updates `total_value` (the row only defines inbound). Natural rule: minus the same `line_cost` the sale posts to the ledger. Without it, the last unit sold leaves a sub-cent `total_value` at on_hand 0 that nothing clears.
- **Suspected location:** `docs/database.md` §6.5 formula block and "The guard is on the prior layer" paragraph; `stock_levels` row; D-76.

I previously accepted a self-correction chain on this paragraph three times; this defect is again an arithmetic claim never run on its own edge. Note it is partly mine: the original guard was my BUG-5 suggestion.

Nothing in the coordinator's message measured wrong.
Run 5: FAIL -> FAIL (BUG-13/14 closed; AC-68 fails on a leftover sentence, BUG-15; BUG-16 money_in precision; BUG-17 write-off has no ledger row; AC-39 text stale again).

---

## Run 5 (tree at `2ebbdd7`; the spec is now 69 criteria)

Gate by me: exit 0, PHPStan no errors, Deptrac 0, Pest **314 / 1435**, 82.54 s. **Tally against 69: 67 pass, 2 fail (AC-39, AC-68), 0 n/a.**

### Re-test

| Item | Result |
|---|---|
| Identity | Where on-hand is positive before the receipt, `avg_cost` = TV / on_hand, so after: (TV + money_in) / (on_hand + paid + free) = (prior value + money in) / (prior qty + qty in). **Holds**, with "prior value" meaning the accumulator, not `on_hand x rounded avg` |
| Negative case, recomputed | on_hand -5, TV -500; receive 10 at 100 (paid 1 000): TV 500, on_hand 5, avg 100.00, GL -500 + 1 000 = 500: ties. The reset variant: TV 1 000, avg 200.00, +500: as stated. Correct |
| Worked tables | 57 600 / 288 = 200.00, 48 000 / 288 = 166.6667; 15 000 + 48 000 = 63 000, / 388 = 162.3711; 48 000.0096. All recompute. The relabelling to `money_in`/`on_hand` left every number intact |
| Residue at zero | Real (3 units for 100, avg 33.3333, line cost 99.9999: 0.0001 left). See below on what the write-off does not catch |
| D-74 "exactly" | Gone |

### AC-68 FAILS: BUG-15 (minor)
§6.5 line 721, the opening sentence of "Valuation": "weighted average per (location, product), **maintained on `stock_levels.avg_cost` at every inbound movement**". That is exactly the sentence AC-68 forbids (avg_cost described as the stored authority), two paragraphs above the rule that says otherwise. The `grn_items` row (line 892) also says posting "updates `avg_cost` through the formula", which is imprecise in the same direction. Also: AC-68 asks me to confirm §7.1's rounding account; it exists but it is the **tender-rounding** account (`sale.rounding`, "Rounding <=0.05"), not a COGS rounding account, and D-76 says the average's leak lands in COGS where "§7.1 already gives rounding an account". With `line_cost` credited and debited as one number there is no leak to a rounding account at all; the sentence is loose, not harmful.

### AC-39 FAILS (spec text stale, not the document)
AC-39 still says "the prior layer is `on_hand x avg_cost` only when `on_hand > 0`, else zero (the guard is on the prior layer)". The shipped design is the opposite (guard on the derivation; accumulator unconditional); `ba`'s re-word (`5479dc6`) predates the BUG-14 fix. The document is right; the spec needs a re-word.

### BUG-16 (minor): `money_in` is not defined at a precision, so it can differ from the GL debit
Formula: `money_in = base_paid x cost_per_base`, `cost_per_base = landed_cost / unit_factor`, and the text says money in "is exactly what §7.2 debits". Case: 1 unit of a 3-per-case product at landed 100 per case. `cost_per_base` = 33.3333 (4 dp) x `base_paid` 3 = **99.9999**; the GL debits 100. Same residue as D-76, one step earlier. Define `money_in = ROUND(quantity x landed_cost, 4)` (what the GL debits) and derive `cost_per_base` only for display, or state the precision of `cost_per_base`.

### BUG-17 (minor): the zero-stock write-off has no ledger row
"Whatever `total_value` still holds is written off through §7.5's inventory-adjustment account" but `stock_levels` is "only ever updated in the same transaction as a movement", and the write-off is a value change at quantity 0. No movement type, `reference_line_id` or `reference_type` is given for it (the 18-row R-2 table has none, and the key needs one). Say which type carries it (e.g. `adjustment` with `quantity = 0`, value = -residue) and what its reference is.

### What else can leave non-zero `total_value` at zero on-hand
- **Not caught, undefined:** `stock_adjustments.type = revaluation` changes value with no quantity; no movement type or formula line covers it, and at exactly zero stock it leaves value there by design.
- **Not caught:** a write-off trigger is stated for "returns to exactly zero". On-hand crossing from positive straight past zero to negative leaves a legitimate (negative) balance, which is correct and needs no write-off; I found no case where on-hand is below zero and the balance is residue, so not triggering there is right. But the document does not say so; one clause would stop an implementer writing it off.
- The write-off itself returns both sides together, so the level/account tie survives.

### Third instance of column redundancy (as asked)
Reading the schema for columns that hold the same information, with no authority declared:
1. `stock_productions.input_value` / `output_value` / `wastage_value` against the `value` of the item rows, which §6.5 uses in the identity `SUM(output value) + wastage_value = input_value`: the header carries the aggregates and the items carry the parts, and neither is named the authority. Most like the `total_value` case because the identity is the reconciliation.
2. `stock_transfer_items.qty_variance` (= sent - received, base) and `stock_count_items.difference` / `variance_value` (= counted base - `system_qty`): stored derived values; `system_qty` is a snapshot, so `difference` is only derivable from it.
3. `sale_items.line_cost` against `base_quantity x unit_cost`, with `sales.cost_total` = SUM(`line_cost`) (this one names its authority). `line_cost` is a `ROUND(..., 4)` product in the new outbound rule but "`base_quantity x unit_cost`" unrounded in the `sale_items` row.
4. `base_quantity` against `quantity` x `unit_factor`: authority is stated (`base_quantity`), but `quantity` and `unit_factor` are also stored, and nothing says which wins if they disagree after a correction.
Item 1 is the one I would act on.

Nothing in the coordinator's message measured wrong; "every number re-verified" holds, and the claim that no stale sentence remains does not (BUG-15).
Run 6: FAIL -> PASS (no correctness defect open; four wording leftovers go to the backlog, BUG-18 to BUG-21).

---

## Run 6 (tree at `90237ad`, verification only)

Gate by me: exit 0, PHPStan no errors, Deptrac 0, Pest **314 / 1435**, 80.19 s. Pipe scan: only FP-178. **Tally against 69: 69 pass, 0 fail, 0 n/a**, with one spec-wording note on AC-39 below. **AC-68 passes; AC-39 passes on substance.** **No correctness defect found; nothing here needs the task reopened.**

### Checks

| Item | Result |
|---|---|
| AC-68 | The opening "Valuation" sentence now says `total_value` is maintained and `avg_cost` derived; the `grn_items` row says posting adds to `total_value` and `on_hand` "from which `avg_cost` derives". No sentence describing `avg_cost` as the stored authority remains in database.md or architecture.md. PASS |
| AC-39 | `cost_per_base` is gone from the arithmetic path: its only remaining appearance in database.md is line 755, as the worked counter-example (3 x 33.3333 = 99.9999 against 100.0000, which I recomputed). `money_in = ROUND(line.quantity x inventory_cost, 4)` is an identity against the §7.2 debit; the unit factor appears only on the quantity side. All three worked cases recompute (200.00 / 166.6667 / 48 000; 162.3711 on 388; negative case 500 / 100.00 and the reset variant 1 000 / 200.00). Rounding symmetry now holds. The spec's Inbound bullet and Finding F-8 still describe `cost_per_base`; that is `ba`'s text lagging the shipped formula, not a document defect |
| The write-off | A value-only `correction` movement, `quantity = 0`, `value = -total_value`, `reference_line_id` an adjustment item line; fits the existing key and the 18-row table (row 707 now names it). Fires only at exactly zero; the negative-crossing clause is present and the reasoning is sound |
| One number on both legs (§7.1) | Confirmed: §7.1 posts COGS as `SUM(sale_items.line_cost)` and credits inventory the same figure, so no cost-of-goods rounding account is needed |
| Redundancy exclusion in FP-184 | Agreed: `quantity_reserved` against open reservations is a missing invariant, not an undeclared authority |

### Leftovers, for the backlog (none are correctness defects)

- **BUG-18 (minor): the 24x "one home" claim is overstated.** The same paragraph names a second home itself ("snapshots multiply by `base_quantity`, not `quantity`"), and there are two more: writing `base_paid x inventory_cost` for `money_in` (240 x 4 800 where `inventory_cost` is per line unit) and using `quantity` in the outbound `line_cost`. The paragraph's own opposite-direction mistake is "exactly one"; "the quantity side is where the factor lives, and every line there must use base" is true.
- **BUG-19 (minor): a fourth stale `cost_per_base` instance the sweep missed.** database.md line 810 (`landed_cost` "is per line unit ... which is why it divides by `unit_factor` too"), D-72 (decisions line 183, same words) and D-74 line 206 ("money in is `base_quantity x cost_per_base`") all describe the division the formula no longer performs. Wording only; the document's formula is right.
- **BUG-20 (minor): D-76's rationale text still ends on "§7.1 already gives rounding an account"**, which its own amendment withdraws two paragraphs earlier in the same entry.
- **BUG-21 (minor): the `revaluation`/`correction` pairing is real but not a full enum reconciliation.** `stock_adjustments.type` = increase, decrease, wastage, opening, revaluation; movement types from adjustments = `adjustment`, `wastage`, `expiry`, `opening`, `correction`. `revaluation` -> `correction` is right, and `increase`/`decrease` -> `adjustment` (or `correction`, row 707). `expiry` has no adjustment type (it would be `wastage` with a reason code) and `correction` has three producers. Not an orphaned member in the schema-breaking sense; worth one sentence in FP-184 or FP-250.

Nothing in the coordinator's message measured wrong except "verify there is no fourth instance": there is (BUG-19), and "exactly one home" (BUG-18).
Run 7: PASS -> FAIL (R2-1/R2-2 fixes verified; the new branch table has two rows that cannot be implemented as written, BUG-22 and BUG-23, plus BUG-24 and BUG-25).

---

## Run 7 (tree at `02b994a`)

Gate by me: exit 0, PHPStan no errors, Deptrac 0, Pest **314 / 1435**, 79.09 s. Pipe scan: only FP-178. **Tally against 69: 69 pass, 0 fail by the letter of the criteria**; the verdict is FAIL on correctness defects in the branch table that no criterion covers. I am telling you plainly: **two correctness defects, BUG-22 and BUG-23. Reopen.**

### AC-36 and AC-38: pass, and would fail on regression
- Every site: the five-column key `unique (reference_line_id, product_id, type, batch_key, serial_key)` is declared **once** in database.md (lines 669-671). The `stock_movements` row (605), §11 (1575) and the partitioning note (1584) now name "the idempotency key of §6.5, whose columns live there and nowhere else". I grepped `reference_line_id` and `unique (` across database.md, architecture.md and backlog.md: no four-column statement exists anywhere in the three documents. The old key `(reference_type, reference_id, product_id, batch_id, type)` survives only as the replaced key (lines 674, 1575). AC-38 passes.
- **Fail-ability:** I checked it by pattern, not by editing the documents (not mine to edit): the criterion's search, `reference_line_id` in a parenthesised list or "unique (" near `stock_movements`, would hit a restored four-column statement at any of the four sites, and a restored copy anywhere would show up as a second declaration of the columns, which the "exactly one site" check also catches. The spec's own line 291 (the five columns) is the only other statement and agrees. PASS.
- Your run-6 pass of 69/69 did certify the superseded key (the criterion then asserted four columns). That is now corrected in the criterion; nothing in my run-6 evidence for AC-36 stands.

### R2-2 verified
Refund: 1 case sold at avg 200, factor 24 removes 24 x 200 = 4 800; the line-quantity reading adds back 1 x 200 = 200, understating by 4 600: correct. Transfer: 10 cases x 24 = 240 base x 200 = 48 000 removed; line-quantity adds back 2 000, losing 46 000 / 48 000 = **95.83 %**: correct. The branch table has all 18 types (counted by group: opening 1, grn 1, sale 1, refund/void 2, grn_return 1, purchase_return 1, adjustment/count 2, wastage/expiry 2, transfer_out 1, transfer_in 1, production_out 1, production_in 1, correction 1, reservation pair 2 = 18).

### BUG-22 (major, correctness): the `count` increase branch cannot be computed
The table sends `adjustment` and `count` to "the sign of the quantity decides: increase: the entered `unit_cost`, per **line** unit -> `line.quantity`". For `adjustment` that works: the item line carries `quantity`/`base_quantity` signed. For **`count` it does not**: a `stock_count_items` line's `quantity` is `counted_quantity`, the **total counted** (say 50 sacks), while the movement's quantity is `difference`, which the row defines as **base only**. Multiplying `counted_quantity` by `unit_cost` books the whole count as inbound; there is no line-unit form of the difference to multiply (converting needs `difference / unit_factor`, the rounded quotient the document just removed from the money path). Worked: factor 24, system 48 singles, counted 3 cases (72): the movement should add 24 base units, but the table's reading prices 3 cases, i.e. 72 units, at the entered cost. **Fix:** count increases take `unit_cost` per **stock** unit (as the §7.5 `variance_value` is already base) and multiply `base difference`, or the count item states `unit_cost` per line unit and a line-unit difference.

### BUG-23 (correctness, small but breaks a stated identity): `production_in` multiplier ignores the residual line
The row takes "the apportioned `stock_production_items.unit_cost` per stock unit -> `base_quantity`". §6.5's rule is that the **last output line absorbs the rounding residual** so `SUM(output value) + wastage_value = input_value` exactly, and the residual lives in the line's **`value`** column, not in `unit_cost x base_quantity`. `ROUND(base_quantity x unit_cost, 4)` differs from `value` on the last line by the residual (cents), so the stock ledger gains `SUM(ROUND(base x unit_cost))` while the journal and `input_value` use `SUM(value)`. Fix: `production_in` money_in = the line's `value`.

### BUG-24 (minor): `transfer_in` after a short receipt
"Adds the value its matching `transfer_out` removed" is unambiguous only when received = sent. For a short receipt (`qty_received < qty_sent`, shortfall in `qty_variance`), adding the full value over fewer units inflates the destination's `avg_cost`; adding `received x avg` makes the shortfall vanish from a no-entry intra-account transfer (the very neutrality the row defends), unless the in-transit location keeps it. Say which.

### BUG-25 (minor): sign convention on the outbound branch
Outbound is written `line_cost = ROUND(base_quantity x avg_cost, 4)`, `total_value -= line_cost`, `on_hand -= base_quantity`, with positive magnitudes. `stock_adjustment_items.quantity` and `base_quantity` are **both signed**, so a decrease carries a negative `base_quantity` and the formula would add to `on_hand`. State "outbound uses the magnitude" or write the decrease as `+= signed value`. Also `sale_refund` with a null `refund_of_item_id` (refund without a receipt) has no "original line's snapshot"; the table does not say what it takes.

### Attack on the sign-decided rows: summary
Right idea, two real holes. For `adjustment` the line carries both signs, so deciding the branch per line is sound (BUG-25 aside). For `count` it breaks on the quantity definition (BUG-22). For a `correction` carrying a quantity: sign-decided is stated but "no multiplier" is also stated, and `value` is the amount; consistent only if `value` already carries cost, which the document says.

### FP-186: a seventh fact with two or more homes
1. **The free-quantity expression `base_quantity + ROUND(free_quantity x unit_factor, 4)`** is stated in three places: the formula's `base_free` (729), nightly check 3 (955) and the `grn_items` row (987), each saying it is "the same expression" as the others. This is AC-61's own concern, a sentence guarding three copies.
2. **The list of eighteen movement types** has four homes: the `stock_movements` enum (605), the `reference_line_id` table, the branch table, and counts in prose ("sixteen of the eighteen", check 1's exclusion, the two-reservation exclusion repeated in the branch table). Adding a type means editing all; the architect's own remedy is a single table with a column per question.
3. **The `production_in`/`production_out` role mapping** (output/byproduct vs input) is stated in the R-2 row (706) and the branch table (774).
Item 2 is the one most likely to rot.

Nothing in the coordinator's message measured wrong; the claim that exactly one site declares the key columns is true.
Run 8: FAIL -> FAIL (BUG-22 to BUG-25 verified fixed; BUG-27 correctness defect in note 9; the run-6 PASS and parts of run 7 corrected: AC-36 and review-round-2 W-3/W-5 were not what they certified).

---

## Run 8 (tree at `a176138`)

Gate by me: exit 0, PHPStan no errors, Deptrac 0, Pest **314 / 1435**, 78.83 s. Pipe scan: only FP-178. Tally against 69: 69 pass by the letter of the criteria. **FAIL on BUG-27 (correctness) and the BUG-26 and BUG-28 leftovers. I am telling you plainly: reopen for BUG-27.**

### 1. Review round 2 Warnings re-verified against the file (git object comparison, not reports)
I compared `02b994a` (the tree of my run 7) with `a176138` by grepping the text each warning should contain.

| Warning | At `02b994a` (run 7's tree) | At HEAD | Result |
|---|---|---|---|
| W-1 residue + approval threshold | approval path present; "residue" word still the heading | present: not "residue", `on_hand = 0` and never the size, approval above a threshold | **Partly closed**: BUG-28 below |
| W-2 `products.cost_price` fallback | present (2 hits) | present in §6.5 guard bullet and D-79 | closed |
| W-3 `revaluation` vs `increase`/`decrease` | **absent** (grep for "neither an `increase` nor a `decrease`": 0 hits; the `correction` row said increase/decrease while the write-off paragraph said revaluation) | present in both places (2 hits), note 11 is the single answer | closed now |
| W-4 three `unit_cost` readings | table rows only | the movement table gives every type a basis; **the item rows still carry a bare `unit_cost`** | **Partly closed**: BUG-26 |
| W-5 overclaim ("exactly one home") | present at `02b994a` (I found it as BUG-18 in run 6 and it was "fixed" only in the plan, never the file) | gone (0 hits) | closed now |

**Earlier reports that need correcting, and I am saying so here:**
- **Run 6 PASS** certified AC-36 against the superseded four-column key, as already conceded in run 7. It also reported BUG-18 (the "one home" claim) as a leftover for the backlog; that sentence was still in the file at run 7's tree and I did not re-grep it then.
- **Run 7**: I wrote that a `correction` carrying a quantity was "sign-decided, consistent". At that tree the document answered "which adjustment type raises a value-only correction" twice, differently (W-3); I saw "three producers" in run 6 BUG-21 but did not notice the contradiction. That sentence was wrong.
- Run 7's verification of R2-1 and R2-2 stands: I measured them from the file at that tree (key declared once, refund 4 800/200, transfer 95.83 %).
- Everything else in runs 2-7 was grepped from the tree named in each section and stands.

### 2. The four fixes
| Item | Result |
|---|---|
| `count` (BUG-22) | Note 8: movement = `difference`; factor 24, system 48, counted 3 cases = 72, so 24 moves and `line.quantity` would price 72 (3x). `unit_cost` per stock unit x `difference`, and `variance_value = difference x unit_cost` closes. Verified |
| `production_in` / `production_out` (BUG-23) | Both read the line's `value`; the residue lives there, so journal and stock ledger are the same number. Verified in the table and note 10 |
| `transfer_in` short receipt (BUG-24) | Adds `base_qty_received` at the sending cost; shortfall stays at `transit`. **Mechanism missing: BUG-27** |
| Magnitudes (BUG-25) | The formula states "every branch works on MAGNITUDES, sign selects the branch first"; refund with null `refund_of_item_id` takes current `avg_cost` (note 3). Verified |

### 3. Consolidation: one grouping of the eighteen
One table with fifteen rows, which together cover the eighteen types (opening, sale, refund/void x2, grn, grn_return, purchase_return, adjustment, count, wastage/expiry x2, transfer_out, transfer_in, production_in, production_out, correction, reservation pair x2 = 18), twelve notes. Other places that list the types: the `stock_movements` enum row (declares members, allowed); `journal_entries.source_type`, workflow `entity_type` (different enums that happen to share names such as `sale_void`); §6.5 invariant 1 ("every type in the movement table except the two reservation types") and §15's "production_in/production_out" sentence (citations). **I found no fifth home.** `base_free` has one definition (815, marked (†)); check 3 (1005) cites it and the `grn_items` row cites it; `ROUND(...free_quantity...)` appears once in the file.

### 4. Worked examples recomputed
200.00 / 166.6667 / 48 000; 57 600; 63 000 / 388 = 162.3711; 288 x 166.6667 = 48 000.0096; negative case 500 / 100.00 and reset 1 000 / 200.00; three-per-case 3 x 33.3333 = 99.9999; refund 24 x 200 = 4 800 against 200 (4 600 short); transfer 240 x 200 = 48 000 against 2 000 (95.83 %); count 72 - 48 = 24; and the write-off example: receive 10 at 100, sell 15 at held 100 -> TV -500, on_hand -5, receive 5 at 200 -> TV 500, on_hand 0. All correct; none involves a count except the count example, which is right, so "nothing moved" holds.

### BUG-27 (major, correctness): the transit rule has no mechanism, and the key cannot hold the obvious one
Note 9 says on a short receipt "the shortfall stays at the `transit` location where `transfer_out` put it". The table has one `transfer_out` (outbound) and one `transfer_in` (inbound) per line and **no movement that credits transit**, so as written `transfer_out` removes value at the sending location and nothing adds it anywhere. On a short receipt the shortfall value therefore vanishes from a no-entry intra-account transfer, which is exactly what the note says it prevents. The obvious fix, a two-hop model (out at A, in at transit, out at transit, in at B), needs **two `transfer_out` and two `transfer_in` movements per line**, and the key `(reference_line_id, product_id, type, batch_key, serial_key)` **contains no location**, so the second pair collides. The two ways out are a location in the key (a design change) or separate types for the transit legs. Neither is in the document, and the note presents the behaviour as settled. Location: §6.5 movement table, note 9, and `locations.type = transit`.

### BUG-26 (minor): W-4 is closed in the table and not in the rows
`stock_counts`/`stock_count_items` ("..., unit_cost, counted_by"), `stock_transfer_items` ("qty_variance **in base**, unit_cost, variance_reason"), `stock_production_items` and `stock_adjustment_items` all still carry a bare `unit_cost`, while `sale_items` states its basis. The basis (per stock unit for count, transfer and production; per line unit for an adjustment increase) is only in the movement table. A reader of the row has D-57's exact trap again. Add the basis to each row.

### BUG-28 (minor): W-1 leftovers
Line ~945 says rounding "is the smallest of **its three sources**" and then names two (rounding and the under-costed oversell); the third is never stated. Line 836 still says "the zero-stock **residue** write-off" in the formula block, the word the same section calls dangerous.

### Process finding I take on board
Run 7's AC-36 re-verification was by grep over three documents and by pattern; this round I added a git-object comparison of the two trees for each warning. That is what separates "the text is in the file now" from "the text was in the file when I passed it", and it is what I should have run when W-3/W-5 were first claimed.
Run 9: FAIL -> PASS (BUG-26/27/28 closed; BUG-29 minor left open; run 1's AC-52 evidence restated as the sample it was).

---

## Run 9 (tree at `5e2fdae`, final verification)

Gate by me: exit 0, PHPStan no errors, Deptrac 0, Pest **314 / 1435**, 109.95 s (cold). Pipe scan: only FP-178. **Tally against 69: 69 pass, 0 fail, 0 n/a. Verdict PASS, plainly.** One minor (BUG-29) is open; it is not a correctness defect and I do not ask for a reopen.

### The three fixes
- **BUG-26 closed.** All four rows carry their basis in the row itself: `stock_adjustment_items` per **line** unit (entered price on an increase, a decrease valued at average); `stock_count_items` per **stock** unit with `variance_value = difference x unit_cost`, citing note 8; `stock_transfer_items` per **stock** unit (the sending cost the receiving movement re-adds, note 9); `stock_production_items` per **stock** unit with "the movement reads `value`, not this column", note 10. Your first grep was wrong because it stopped at a cell boundary; mine was by `grep -o` into the column text, and all four are present. Agreed with the guard: suspect the check before the artefact.
- **BUG-28 closed:** "three sources" is now two; the formula block no longer says "residue".
- **BUG-27 withdrawn as a named question, not softened.** Note 9 states what is unresolved ("the value of a short receipt ... this note deliberately does not answer it"), names both candidate shapes ((a) a location in the key, (b) separate transit types), says why neither belongs to a units task (identical at factor 1), routes it to FP-253 together with the final-or-pending `qty_variance` question, and says "nobody should implement a guess from here". What stays in scope, a **complete** transfer being neutral by construction, is correct. That reads as a question.

### BUG-29 (minor): one sentence asserts, the other defers
You asked me to confirm both sentences point at the same open question. They do not. Note 9 says the `stock_transfers` row carries the same unbacked claim and that FP-253 must reconcile it. But the **`stock_transfers` row itself (line 612) still ends "Goods in transit hit the `transit` location so they are never invisible"** with no pointer: a reader of the row alone is still told the unimplementable thing. `grep -c 'never invisible'` is 2: that row and the note quoting it. One clause on the row ("— not yet backed by any movement; §6.5 note 9, FP-253") closes it. It is a v0.4 claim, flagged where a reader of the design will meet it, so I class it minor.

### Your question 2: anything in runs 1 to 7 a reader would take as settled and is not
I re-ran the state-dependent facts at HEAD (not from my reports):
- AC-4/65/66: **nothing outside `docs/`** in `git diff --name-only 47e226d..HEAD`. Still true.
- AC-6: **10** rows "widened by T-006". Still true.
- AC-50 / index: sums to **193**, true count 196 (tables untouched since). Still true.
- AC-23/53: seventeen tagged rows, §14: fourteen document-and-quad tables, twelve with a plain pair, three exempt by name. Still true.
- AC-67: no line removed from the decisions file since the spec's parent commit (`git diff e1c5d26^ HEAD`, zero `-` lines), so D-57 to D-65 are byte-identical. The later "rewritten in place" entries (D-74, D-76, D-78) are all after D-65.
- AC-52 references into the new notes (§6.5 notes 8, 9, 10, "note 11 of the movement table") resolve to the notes with those numbers.
- **One correction to run 1:** I wrote that I "followed every `§` added by the diff (about 60 refs)". I **sampled** them: about 15 to 20 read individually against their target section, including the whole spec "minimum set" for §3.1, §6.3, §6.5, §12.2, §14, §15 and the architecture references. Only one mismatch surfaced (§9.4, BUG-3). The claim "every" overstated it. The fix rounds changed the reference set, and the notes above were verified this run, but I cannot assert that all ≈60 were read.
- Run 6's PASS and run 7's "consistent" were already corrected in run 8. Nothing else in runs 1-7 now reads as settled when it is not; the intermediate figures (192, "five exemptions", "sixteen") are labelled as measured on the trees they describe.

### Your question 3: an eighth or ninth home
I found no home that currently disagrees with another. Three candidates where the same fact is stated twice and would rot, all agreeing today:
1. **Which master-data tables degenerate the `[UOM]` tag**: §3.1 (names `price_list_items`, `product_barcodes`, `modifiers`, and `product_components` as the fourth) and §14's exemption list (the same three by name, `product_components` explicitly not exempt). Two homes of one list.
2. **The `stock_levels` row's `total_value` description** ("the accumulator and the authority (`prior total + money in`)") restates a *partial* form of the rule; §6.5 has three updates (inbound `money_in`, outbound `- line_cost`, value-only `+ value`). The row would mislead a reader who stops there; it should cite §6.5 rather than restate.
3. **§3.3's "used at three sites and on four columns"** is a prose count of the same kind as "sixteen of the eighteen": correct today (stock_movements `batch_key`/`serial_key`, `price_list_items.unit_key`, `unit_conversions.product_key`), stale the first time FP-180 applies the convention to its eleven further sites.
Checked and found to be single-homed or citations: the seven below-the-layer tables (§6.5 once, §14 cites it), the print-bearing set, the five-step ladder, `base_free` (one definition, check 3 and the `grn_items` row cite it), the eighteen movement types (one table, enum declares members, invariants cite it), and the ledger key (one declaration).

Nothing in the coordinator's message measured wrong except "confirm both sentences point at the same open question" (BUG-29).
