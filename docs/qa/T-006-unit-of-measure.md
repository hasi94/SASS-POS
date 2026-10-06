# QA Report: T-006 — the unit-of-measure model (documents only)

Spec `docs/specs/T-006-unit-of-measure.md` (AC-1 to AC-67) · Plan `docs/plans/T-006-unit-of-measure.md` · Decisions D-57 to D-69.
Branch `ai/T-006-unit-of-measure`, 17 commits over base `47e226d`, nothing pushed. Measured on this machine on **2026-10-06**.
No code, test or fixture was written: the task is documents, and nothing here needed a new test.

## Verdict: PASS (run 2; run 1 was FAIL, kept below as measured)

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
