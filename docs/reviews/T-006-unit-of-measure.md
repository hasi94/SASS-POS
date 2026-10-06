# Code review — T-006 the unit-of-measure model

| | |
|---|---|
| Verdict | **CHANGES REQUESTED** (round 1) — four Criticals, all in `docs/database.md`, all one-sentence fixes, three of them in §6.5 |
| Date | 2026-10-06 |
| Reviewer | `code-reviewer` (read-only — nothing edited, staged or committed; `git status` clean before and after both gate runs) |
| Scope | `git diff 47e226d..HEAD` — 25 commits, **7 files, all under `docs/`**, +1842/−70. Verified: `git diff --name-only 47e226d..HEAD \| grep -v '^docs/'` → **empty** |
| Gate, measured by the reviewer twice | **exit 0** — cleanup ran, PHPStan `[OK] No errors`, Deptrac `Violations 0 / Skipped 0`, Pest **314 passed (1435 assertions)**. 66.95s and 74.94s on the same tree. Identical to T-005's final run, which is correct for a documents-only change |
| QA | run 1 FAIL (2 criteria, 1 major + 7 minor), run 2 **PASS 67/67**, BUG-9 filed as pre-existing |

**There was no `dev` on this task.** The deliverable is `docs/database.md` and `docs/architecture.md` taken from v0.4 to v0.5, plus a spec, a plan, a decisions file and backlog rows. Review therefore means: does the design hold, is it internally consistent, are the claims true, is anything load-bearing missing.

**The verdict in one line:** the design holds, the currency analogy holds, and the documents are a large improvement on v0.4 in the places that cost the most later. The four Criticals are on four sentences, not on the model.

---

## The design, as the reviewer restated it in order to attack it

The design already solved conversion for **money**: a document carries an amount in its own currency, the rate snapshotted on the document, and the base amount beside it; the ledger is base-only. T-006's claim is that **units are the second dimension of that identical shape and the pattern had never been applied to them.**

The reviewer's judgement: **sound, and the strongest thing in the task.** The three properties it needs all hold — base quantities are additive like base amounts; the line is the date-stamped fact, so the snapshot really is the effective-dating `tax_rates` needed a whole table for; and a replayed projection is deterministic. It is also *not merely an analogy*: §7 now states both axes in one sentence with the same failure mode — *"a posting that reads `quantity` instead of `base_quantity` is the same class of bug as one that reads `debit` instead of `base_debit`"* — which is the form that makes it usable rather than decorative.

The answer to "a currency rate is a property of a date, a conversion factor is a property of an editable definition" is the snapshot, **and the snapshot was two-thirds complete**: it covered immutability of the past and determinism of a rebuild, and not reversal against the past. That gap is R-3.

---

## Critical findings

### R-1 — `product_id` left the ledger key with no argument, and three ordinary cases now collide

The new key was `unique (reference_line_id, type, batch_key, serial_key)`. The text argues the removal of `reference_type` and then says `product_id` and `batch_id` "demote to ordinary indexed columns" — `batch_id`'s demotion is covered because `batch_key` replaces it; **`product_id`'s is not argued anywhere.** The key assumes one movement per line, and the document says otherwise three times:

- **Composites and recipes.** One `sale_items` line of a composite writes one `sale` movement **per component** — same `reference_line_id`, same `type`, both sentinel keys `'-'` for quantity-tracked components. **Unique violation; the sale cannot be saved.** That is the task's own major defect reintroduced for every restaurant recipe and every kit, and **the v0.4 key handled it**, because it carried `product_id`.
- **Modifiers.** `linked_quantity`/`linked_unit_id` were added *in this task* so "depletes stock" is implementable. Each depleting modifier is another movement against another product on the same line.
- **Opening stock.** The text classes `opening` as header-level, but `stock_adjustments.type` includes `opening` **with item lines**. An opening load of 500 products through one header writes 500 movements on one key — one of which can exist.

**This was a regression T-006 introduced.** Fixed by restoring the column — `unique (reference_line_id, product_id, type, batch_key, serial_key)` — plus the rule the rest needs: *a line writes at most one movement per `(product_id, batch_id, serial_id, type)`*, so a component or modifier appearing twice on one line is aggregated into one movement, which is what makes the key a statement about the world rather than a constraint the writer has to dodge.

### R-2 — "one event per line per type" was still assumed for two more types

D-71 had found and fixed exactly this for the reservation pair. Still live for `transfer_in` on an instalment receipt, and for a repeated `correction` where the document's own rule ("a correction is a new row, never an `UPDATE`") positively invites more than one. Fixed with **a row for every one of the eighteen movement types**, stating what `reference_line_id` names, with three marked as rulings rather than readings.

### R-3 — the snapshot protected the past document and not the document that reverses it

`grep` found **zero** occurrences of `unit_factor` in any sentence about a return or a refund. Walked through with the document's own numbers: a receipt takes 10 cases at factor 24 → 240 base; someone edits the conversion to 12; returning those same 10 physical cases resolves factor 12 and removes **120 base**. **120 units remain on hand with no document able to remove them, no error, and no invariant that can see it** — check 1 compares the level to the ledger, both wrong together, and check 3 covers only receipts.

Fixed with one sentence, cited from both rows: a line referencing an earlier line inherits that line's `unit_factor` when the unit matches, and otherwise converts base-to-base, never re-resolving the ladder.

**The reviewer's note on this is the one worth keeping: D-58 already derives exactly this rule for the harder case.** One `unit_id` and one `unit_factor` on a transfer line means the receiving event necessarily converts at the sending event's snapshot, so a factor edit between dispatch and receipt cannot make a transfer gain or lose stock. The rule was got right where it was hardest and never asked on the easy case.

### R-4 — the valuation formula averaged a different cost from the one the ledger debits

The formula averaged `unit_cost` while the general ledger posts **Dr Inventory (landed cost)**, and `total_value` is declared the balance-sheet figure. They diverge by the apportioned charges on **every receipt, permanently**, with no reconciliation stated. Secondary: `landed_cost` had no stated unit, and D-57 exists precisely because one column name with two readings is how the 24× error gets written — it ruled on `unit_cost` across four tables and left un-ruled the column that actually values inventory.

Fixed by averaging `inventory_cost` (landed cost on a receipt, unit cost where there is no apportionment), declaring `landed_cost` per line unit as D-72, and stating the reconciliation between `total_value` and the inventory account.

---

## Warnings — eleven, all closed

**W-1** nightly check 3 would have cried wolf again for the reason the architect itself gave: `free_quantity` had no type, no `NOT NULL` and no default, and `base + ROUND(NULL × f, 4)` is NULL, so the check would fail on **every** receipt with a line carrying no bonus. Its own cry-wolf argument applied verbatim to its own cry-wolf fix. **W-2** Q-9's ≥ 1 guard was specified on `unit_conversions` while ladder step 3 resolves from the `units` chain, which has no product in it — a legitimate gram→kilogram row yields 0.001 and no per-product validation can see it. The enforcement point is the **resolver's output**, and U-2 makes mass the normal path. **W-3** `min_qty` had the same "first hit wins is meaningless" problem `unit_key` fixed. **W-4** four human-entered reorder quantities said nothing in their rows, and a buyer types a minimum order in **cases** because that is how the supplier quotes it — the 24× error in the reorder engine. **W-5** §3.3's "three sites" would let a reader of the conventions section conclude the schema is clean. **W-6** the sentinel convention was stated generally and justified **ULID-only**; the length argument holds at all four current sites and evaporates at the first non-ULID one, where `'-'` is a legal value. **W-7, W-8** the lead's: a stale index figure inside the row whose subject is stale figures, and the T-006 row outside the table it belonged to. **W-9** the dead-schema audit found the pair it was looking for and not `grn_return`, one enum member away, in the section that presents itself as the list of dead schema. **W-10** the free-goods question was declared open while the formula closed it by default through an unspecified variable. **W-11** one asymmetry with currency the snapshot does not cover: money forces conversion residue to surface through double entry and gives it a Rounding account; quantity has no balancing constraint, and **all three new invariants compare a number against a copy of itself derived by the same expression**, so they are blind to residue exactly as the batch-sum check is blind to conversion.

---

## The five judgement calls

**On the currency analogy:** sound, and not merely an analogy. The two gaps found (R-3's reversal, W-11's residue) are *in the same shape* as the ones the task closed, which is why they were cheap now.

**On §3.3 as the home for the sentinel pattern:** *"the best single decision in the fix round."* Not §3.1, because this is a key-design rule rather than a column set, and not a note on one row, which is the failure mode that produced the defect. The reviewer verified all four sentinel columns, verified the **mirror** case specifically (`active_prefix GENERATED AS (IF(is_active, prefix, NULL))` gives exactly "any number of retired rules may share a prefix, one live rule may claim it"), confirmed the ULID-length argument holds at every current site under PAD SPACE on both engines, and said it would **adopt the architect's stated lesson verbatim as a project convention**.

**On the ledger key:** it fixes both bugs it claims, and keeping `type` and the sentinel keys earns more than the NULL fix alone — it also separates a line fulfilled from two batches. D-71 is *"a genuinely elegant answer and the right place to settle it."* But it introduced R-1 and R-2.

**On shipping without an answer to Q-9:** **yes, ship, and do not wait.** The document already takes the cheap direction as its default, so the asymmetry protects rather than exposes — if the owner later says no, a validation relaxes and no data migrates. What should *not* ship is the sentence saying where the guard lives, because it was wrong (W-2) and would have made Q-9's "yes" unenforceable while everyone believed it was enforced.

**On anything else load-bearing:** nothing. FP-177 to FP-180 are correctly scoped, honestly attributed, and all four say where they bite. The partitioning constraint is stated at the point of use rather than resolved, with the reason, which is the right call.

---

## Rulings on process

**The retroactive spec was worth writing, and it is measurable rather than arguable.** The test for theatre is whether criteria written after an artefact can fail against it. They did: two failed on run 1, and the spec raised four defects in a text `ba` was reading as the source of its own criteria — including one `ba` ruled a defect rather than softening. *A spec fitted to its artefact passes 67/67 on the first run. This one did not.* The reviewer asked that the "likely-wrong" section become a standing part of every spec, retroactive or not.

**`ba` without a shell was handled correctly and the failure mode is documented.** Saying so twice, marking five criteria for QA to check (all five held), and logging the limitation is the right behaviour. The cost was one wrong inference about a document's past state, stated precisely by `ba` itself. The remedy is in the role, not the agent: a criterion about what *changed* cannot be written by a role that cannot read history.

**The lead's capacity-fix call was right.** A pre-existing error *inside the argument you are editing* is not the same as one elsewhere in the file; it was the only number a reader uses to size a machine; being wrong by 100× in the direction of more disk makes a VPS look adequate when it is not; and it was isolated so the cost of a wrong scope call is one revert.

---

## The convention question — this is a third thing and a fourth, not FP-176

Applying the T-005 test (do the trigger and the check differ) separates T-006's twelve-plus defects into four shapes, of which the existing rows cover two.

**FP-176 proper** — the claim is wider than the mechanism. Two instances. **FP-181, the derivable number** — seven-plus instances across all five roles. Not FP-171, because there is no clock and no outside world. Not FP-176, because there is no mechanism and no falsifying input, only an enumeration. Self-detectable from the artefact in hand, and it **multiplies**. **FP-182, the unfetched evidence** — five instances across four roles. Trigger: a claim that names its own evidence. Check: open the thing it names. Already proven on this project, since AC-52 *is* that check and it found a defect on the first pass over ~60 cross-references.

**The fourth shape gets no row.** Nothing was overclaimed and no number was wrong — a true paragraph simply did not travel one section. Its check, "enumerate the other sites this reasoning reaches", is FP-181's pointed at keys instead of counts, *which is how BUG-9's eleven were found.* Recorded as the rationale beside FP-176.

The distribution argument is the same as after T-005 and stronger: **twelve-plus instances, five roles, zero self-caught** — which makes all of them cross-reading obligations rather than anyone's personal discipline.

---

## Done well

**The organising idea is a diagnosis, not a reframing**, and the measurement behind it is what makes it one: a unit on exactly four line tables, no base-unit statement anywhere in 1251 lines, and a grep for "base" returning only currency hits.

**The boundary is designed as an absence and then given something that checks the absence** — seven tables named, the same seven in §14, a static test specified against them, and the obvious alternative rejected on the count of conversion sites rather than on elegance.

**D-58 is better than it looks**, and is the one place R-3's hazard was already closed, for the harder case, correctly, before anyone asked.

**The §14 completeness test is the model for how to specify a static test** — it names what would fail, says how many and why, splits the total, enumerates the renamed columns one by one, and writes down why the exemption list lives in the document: *"an exemption list that is not in the document is an exemption list the implementer invents."* The sharpest sentence produced on this task, and it came from `ba` refusing to soften its own criterion.

**The repack design is right on the two things that are easy to get wrong** — one table with a role column rather than two, and the last output line absorbing the rounding residual so value in equals value out *exactly*, which is the only form of that sentence that produces a trial balance that ties.

**The negative-on-hand guard is a better fix than the bug report asked for.** QA raised a zero denominator; the answer explains why zero is the less likely case, names the decision that makes negative stock legal, follows it to a negative inventory balance on the balance sheet, then gives the one-line rule.

**The replica blast-radius paragraph is the best risk statement in either document** — one sentence tying a missing entity type to an unrejectable document, and the reason the new entities are in the *first* payload rather than added later.

**Four process things the reviewer rated above any individual finding:** the architect reported five corrections to its own brief with measurements attached, and then reported that **its own explanation of one of them did not survive being checked** — a self-correction of a self-correction, written as a rule rather than an apology. It refused to cite a plan it could not source. QA's key sweep is the single highest-value piece of work on the task: it took a convention that had just been written and tested it against all ~50 keys in the document, finding two correctness defects in a schema nobody was reviewing. And every role's errors are attributed to their own author by name.

---

## On the merge

Two approved tasks are now stacked behind one acknowledgement: T-005 is DONE and unmerged, gated on the human acknowledging the `CLAUDE.md` correction, and T-006 is branched from that unmerged tip. `CLAUDE.md` still cites v0.4 of two documents that are at v0.5 — the same hazard the T-005 review named, one task deeper.

**FP-174** ("give `CLAUDE.md` a standing accuracy assertion — with the task that next edits it") is the row that closes this family permanently, and the task that edits that line is the one that should carry it.

---

# Round 2 — re-check

| | |
|---|---|
| Verdict | **CHANGES REQUESTED** — two Criticals, both one-table fixes, both in the artefact that changed most |
| Gate, measured by the reviewer | exit 0 — PHPStan no errors, Deptrac 0 violations, Pest **314 passed (1435 assertions)**, 89.98s |
| Not re-litigated | the design, the currency analogy, the boundary, the tag census, the index audit. Round 1 approved those on measurement |

Both Criticals were **the same defect in two artefacts**: a corrected rule that landed in one place and not in the others stating the same rule. One was the fix to round 1's own R-1, live in the prose and absent from the table row an implementer writes the migration from.

## Two corrections to the lead's brief

**The diff range given to the reviewer did not contain the fix to three of its four Criticals.** The fix commit sits two commits before the range's base, so "re-check the changed items" over that range would have shown one Critical's re-fixes and none of the other three. The reviewer re-derived from the current text instead, and flagged it as *the same mechanism by which R2-1 survived six QA runs: the diff that was re-read did not contain the change being verified.*

**And "no header-level movement wording remains" was wrong** — it remained in the spec's AC-36, which the §6.5 table now overturns. It remained in the design document nowhere, which is presumably what had been measured.

## R2-1 — the key stated seven times, four of them stale

The five-column key was correct in the SQL block, the plan and the decisions file, and **stale in the `stock_movements` table row, in §11's idempotency list, in the partitioning paragraph, and in AC-36.**

The row is the one that matters: **every other key in the document is declared in its table row, so that row is the normative declaration and it is what the migration gets written from.** So the Critical raised in round 1 was still live at both sites where it would actually be implemented.

**AC-36 compounded it rather than catching it:** the criterion certified the *superseded* key, so a QA pass on AC-36 became **evidence against** the fix. Run 6 passed 69/69 with AC-36 satisfied by two stale rows.

Fixed by **deleting three statements rather than correcting four** — the columns now appear once and the other sites name them. The plan's copy is labelled as a copy with the normative site named, on the grounds that *a copy which does not declare itself is the defect.*

## R2-2 — an implementer following the document exactly produced the error

The inbound branch asserted every inbound cost is per **line** unit. The document states in two other places that a refund's cost column is a copy of the average and therefore per **stock** unit.

Measured: a 1-case refund at average 200 and factor 24 added **200** against **4,800** removed — inventory understated by 4,600 on every multi-unit refund, permanently, breaking the reconciliation on the second transaction any shop performs. An intra-account transfer of 10 cases lost **95.83%** of the value moved **with no journal entry to notice**, because §7.5 posts none for a transfer within one account.

The reviewer's framing: **no sentence mapped the eighteen movement types to the two branches**, so for the five types that return or relocate already-valued stock the document did not say which applied. Both readings were available and neither was named. Unlike the earlier finding that an implementer *might reach for* the wrong column, here the document itself instructed the error.

Fixed with a branch table carrying each type's branch, cost column and unit basis. **Six rows were rulings rather than read-offs**, of which the sharpest: for `adjustment` and `count`, **the sign of the quantity decides the branch** — nothing had said a signed type could have one branch, and it cannot.

## Warnings

Five, all closed. The residue description invited a tolerance test that would have stranded a material balance (reproduced at 500 from a genuine cost mismatch, not a crumb); the factor guard was specified on a table the factor can bypass via the unit chain, which matters more now that weighable goods put mass in scope; the volume-break lookup had the same "first hit wins is meaningless" problem a sentinel had just fixed one dimension over; three human-entered reorder quantities had no stated basis, and a buyer types a minimum order in **cases**; and three cost columns had no reading at all.

## The reviewer's judgements

**On the fifth defect shape (FP-184):** the right row, and the architect's framing better than the lead's. Its sharpest observation — *an equals sign between two stored columns is not a direction* — is the diagnostic an implementer can actually apply, and is why v0.4 *looked* as though it had named an authority while having declared a formula. **The exclusion is right and the line is cleaner than the row stated it:** an undeclared authority produces two different answers to one question; a missing invariant produces no check of a single answer.

**On the sorting question:** endorsed, and for a reason beyond the stated one — it is **answerable without knowing the consequence**, which is what makes it survive contact with a tired reviewer at the end of a long task. "Is it correctness?" requires working the arithmetic first, which is exactly the step that fails when people are stopping. And it should be run one step further than it was: **the spec is a record someone implements from, because QA implements its pass/fail from it** — which is how AC-36 came to certify a superseded key through six runs.

**On the lead's scope line:** stopping was right and **the severity curve is the right instrument while the count is not** — 9, 4, 3, 2, 3, 3 looks flat, while what closed went from a reintroduced null key and an inventory overstatement to sentences. *A fix round that closes sentences at the cost of a round is a net loss, and a reviewer who demands a seventh round for wording is asking for the eighth.* But **the line was applied to three artefacts when there were five**, which is why R2-1 exists — itself the overclaim defect, in a count.

**On CQRS, an argument the architect had not claimed:** round 1 noted that the snapshotted factor is correct under all three flavours, which was luck. **The accumulator decision is not.** It makes the inventory total a maintained stateful accumulator and declares it authoritative — fine under code-level separation, a read model requiring rebuild-by-replay under projections, and not a column at all under event sourcing, where the authority question reopens with a different answer. **So the flavour now reaches into the one number the balance sheet is read from.**

## On the process facts

The lead's self-report was called *the most useful artefact produced this round*. The architect's measurement — **every arithmetic or counting claim it made that it did not independently re-derive was wrong, and none that it did re-derive was** — is, with that many trials, strong enough to act on, and is the empirical basis the convention rows had been missing when they were argued for after T-005. *They were filed on a taxonomy and are now filed on data.*

**The convention's author being its most frequent instance is the expected outcome, not an embarrassment**, because both rows exist on the premise that the defect is invisible to its author. What worked is that every correction came from an agent that re-derived rather than trusted — including one with no shell, which caught a proposal-presented-as-shipped by reading the live text and naming both candidate explanations rather than picking one.

**And the reviewer corrected its own round 1:** it had framed the transfer-instalment question as "either reading is a defect, and the ambiguity is the defect". The architect declined it with a measurement and QA ruled in the architect's favour. The reviewer's verdict on itself: *it is a missing capability, not a contradiction, which is a materially different thing and routes to a different row.*
