# Plan: T-010 Employees, roles and branch-scoped permissions

Spec: `docs/specs/T-010-employees-roles-permissions.md` (AC-1 to AC-74, with AC-12a, AC-12b and AC-52a; the spec header is the authority for the set and this plan states no total — FP-181). Decisions: `docs/decisions/T-010-employees-roles-permissions.md` (D-1 to D-35; the decisions file is the authority for the range, which has moved twice during execution). Backlog row FP-208, carrying FP-200's debt. Branch `ai/T-010-employees-roles-permissions`, cut from the T-009 tip.

**Gate baseline this plan is written against, measured 2026-10-20 on this branch before any change:** `composer check` **exit 0 · 1157 passed · 11 skipped · 6914 assertions · test step 184.68s against a 900s limit (ratio 4.87, source=manifest)**. Every step below is measured against the gate as it stood when that step began, not against this number, because each step moves it.

`ENGINE_HOLD` is on and G-1 is unmet. The `[ENGINE]` criteria the spec marks (AC-1's live listing, AC-4's `information_schema` half, AC-6's two-engine half, AC-8's concurrency, AC-37's, AC-51's and AC-52's concurrency halves) ship **NOT VERIFIED**. They are not skipped, not stubbed and not re-worded into something the stand-in can answer. Section 11 is the authority for the set and for what each owes when the engine arrives; this header states no total, because step 3b may remove AC-10 from it (FP-181).

---

## 0. The rulings the lead and `ba` asked for

### Ruling 1 — FP-180 does **not** block T-010, and nothing of FP-180's rides along. The lead's premise is false, measured.

**The lead's proposal was to fold FP-180's two tenant-baseline sites — `number_sequences` and `settings` — into T-010's migration pass, on the grounds that they are shipped defects and this is the last cheap moment. I opened the shipped files. Both are already fixed.**

- `modules/host/database/migrations-tenant/0001_01_01_000004_create_number_sequences_table.php` declares `IdentifierColumn::sentinel($table, 'branch_id', 'branch_id_key')` and `$table->unique(['branch_id_key', 'document_type'])`. Its docblock opens with *"This is the case the sentinel exists for, and it is a correctness bug rather than hygiene"* and spells out the duplicate-invoice-number consequence.
- `…_000003_create_settings_table.php` declares `IdentifierColumn::sentinel($table, 'scope_id', 'scope_id_key')` and `$table->unique(['scope_type', 'scope_id_key', 'key'])`.
- Both are already **behaviourally proved** on the stand-in: `modules/host/tests/Feature/TenantBaselineBuildsTest.php` asserts that a second company-wide `settings` row and a second company-wide `sale` sequence are each refused, naming the sentinel column in the expected exception.

**So FP-180's two correctness cases were never shipped as code defects. FP-180 is a *document* row.** Its detail block in `docs/backlog.md` says so in its own words — *"the document now states a rule that parts of the same document break"* — and the stale artefact is `docs/database.md` line 424, which still prints `unique (branch_id, document_type)` for `number_sequences`. The row's deadline phrase, *"settle before the first per-tenant migration, because every one of these becomes a unique-index rebuild across every tenant database once data exists"*, is a cost argument about **migrations**, and for these two tables that cost was already paid by T-008.

**Ruling, and it is a deliberate decision rather than an omission:**

1. **T-010 proceeds now. FP-180 is not a predecessor.** The lead's stated worry — bolting unrelated migrations onto a 75-criterion task — does not arise, because there are no unrelated migrations to bolt on.
2. **T-010 adds no edit to `number_sequences` or `settings` for FP-180's sake.** Not deferred for cost: *there is nothing to do*. Any future reader asking "did T-010 skip this?" should read this paragraph as "T-010 checked and it was already correct", which is a different fact from "T-010 chose not to".
3. **T-010's own two sites are built under the convention** — `employee_roles` (FP-180's named site) and `employee_permissions` (not on FP-180's list, and the same bug in its stronger primary-key form, D-10). They are step 3's work and step 4's proof, so FP-180 loses one of its eleven and gains none.
4. **FP-180's remaining work is editorial and belongs to the lead, not here.** I do not own `docs/backlog.md`. Recommended row text, for the lead: *"FP-180 is a `docs/database.md` row, not a code row. Its two correctness sites, `number_sequences` and `settings`, were built correctly by T-008 and are behaviourally proved in `TenantBaselineBuildsTest`; what is stale is §6.1's printed key definitions. `employee_roles` is closed by T-010. The deadline phrase 'before the first per-tenant migration' is satisfied and should be removed so it does not block another task."*
5. **`ba` should be told** (see §12): Open question 9 in the spec is answered and its premise should be corrected, because the spec currently leaves the lead a decision that the files have already made.

### Ruling 2 — T-008's AC-23 scans the **whole tenant tree**, AC-30 scans the **six baseline tables**. Both are edited, both edits are scoped, and here is the reason for each.

`ba` could not see the files. I opened them. The honest answer was *neither* of the two cases AC-12 then offered: one is whole-tree and one is baseline-scoped, and they need different handling. **`ba` acted on this at `06f2320` and the criterion is now three** — AC-12 (whole-tree, re-scoped), AC-12a (baseline-scoped, widened, with the shape-7 reason in the criterion text) and AC-12b (no other pre-existing test edited, save T-009 AC-39's narrowing, which D-5 already sanctions). The ruling below is unchanged; only the criterion numbers it answers have moved.

**AC-23 — whole-tree, and it is red the moment the eighth table lands. Three sites.**

| Site | What it does | Why it reddens |
|---|---|---|
| `modules/host/tests/Feature/MigrationTreeDisciplineTest.php:122-129` | `expect(migrationTreeTables(MigrationTree::Tenant))->toBe(tenantBaselineTables())->and(tenantBaselineTables())->toHaveCount(6)->and(migrationTreeSources(MigrationTree::Tenant))->toHaveCount(6)` | reads the **directory**, not a baseline subset |
| `modules/host/tests/Feature/TenantBaselineBuildsTest.php:120-135` | migrates `tenantMigrationPath()` — the whole tree — and asserts the live table list equals `tenantBaselineTables() + ['migrations']` | applies every file in the directory |
| `modules/host/tests/Feature/MigrationTreeDisciplineTest.php:131-147` | the master/tenant disjointness check, over `tenantBaselineTables()` | the intersection must be taken over the real tenant set |

**The precedent for the edit is T-009's own and it is exact:** T-009 added four master tables and extended `masterApplicationTables()` from 17 to 21, adjusting the `toHaveCount()` beside it. Nobody called that rewriting T-008's record, because the helper is a *declaration of what the tree holds*, not an assertion about T-008.

**The scoped edit, and it is deliberately not the lazy one.** Do **not** widen `tenantBaselineTables()` to fourteen entries: its name would then be false, and three other files read it (below) for a different fact. Instead:

- `tenantBaselineTables()` **keeps its six entries unchanged**, and becomes accurate to its own name for the first time.
- A new `tenantTreeTables()` in the same file returns the baseline six plus T-010's eight, sorted. The three whole-tree sites above move to it. Each gains a one-line comment naming T-010 as the task that split the two concepts and why.

**And one defect found while measuring, which must be fixed in the same step or it fails for a confusing reason.** Three files use `count(tenantBaselineTables())` as a stand-in for *the number of tenant migration files*:

- `packages/kernel/tests/Schema/Support/MigrationSetTest.php:186` — `$set->highestVersion()`
- `modules/host/tests/Feature/SchemaCataloguePublishTest.php:168,172` — the published row count and `range(1, …)`
- `modules/host/tests/Feature/FixtureMigrationSetsTest.php:146` — the version after the baseline

That equality holds only because T-008 shipped one table per file, and the identity is fragile either way: T-010 was planned to ship nine files and eight tables, and ships eight and eight after correction (c) withdrew the contract file. **The fix must not depend on which of those happened** — that is the point of deriving it. This is an FP-181 instance — one number with two homes — and the fix is to derive it from the source of truth: a new `tenantMigrationFileCount()` that counts `migrationTreeSources(MigrationTree::Tenant)`. The three sites move to it. **Recorded here so `qa` reports it as a scoped edit with a stated cause, not as drift.**

> **Amended after step 3 — I named three sites and there are twenty, and `dev` found the other seventeen. The amendment is owed and the overrun is approved.** The same number is written as the bare literal `6` (and `7` for "one above the baseline") across seven further pre-existing files: `MigrateTenantHandlerTest` (18), `PlanMigrationRunHandlerTest` (8), `ContractGuardBlockingSetTest` (4), `QaContractGuardNamedRunTest` (3), `RunTenantMigrationsHandlerTest` (2), `QaRealApplierOnStandInTest` (2), `DispatchMigrationRunTest` (1), `QaSchemaSignatureInstrumentTest` (1). All now derive from `tenantMigrationFileCount()`, no assertion weakened.
>
> **This is unavoidable rather than scope creep, and the test is simple: no tenant migration of any kind could ship without it.** Any task adding a file to that tree meets all twenty sites, so the work belongs to the first one that does, which is this one. **AC-12b requires every such edit to be stated, and this paragraph is that statement** — `qa` cites it rather than reporting twenty unexplained diffs.
>
> It is also the sharpest FP-181 instance the project has produced: a number with **twenty** homes, invisible for two tasks because the tree never changed size. My own "three sites" was the twenty-first home, and it was wrong within a day of being written.

**AC-30 — baseline-scoped, and it still has to change, for a different reason.** `TenantBaselineBuildsTest.php:266-298` builds its foreign-key inventory by looping `tenantBaselineTables()`, so narrowly read it would stay green forever while the tree grew around it. That is shape 7 in miniature: an instrument that discriminates perfectly over a domain that has stopped being the one that matters. Two things make an edit unavoidable anyway:

1. AC-10's contract migration adds `settings.updated_by → employees.id`, which is **inside** the six it already scans. The expected inventory `['settings.key -> settings_registry.key']` goes red the day that migration lands on an engine that honours it.
2. AC-9 asks for a live assertion that **no** foreign key in the new tables references a table named `branches`, which cannot be answered from a six-table loop.

So the sweep **widens to `tenantTreeTables()`** and declares the full expected inventory as an explicit sorted list — stronger than today, not weaker. Its liveness control stays (`settings.key` must still be in the list, and the unknown-settings-key insert must still be refused), and it gains the AC-9 assertions. The docblock records that this widening is T-010's, with this ruling as the reason.

**`qa`'s report should read, against the three criteria `ba` split this into at `06f2320`:** *AC-12 — AC-23's three sites scan the whole tree and were rescoped by splitting the helper. AC-12a — AC-30's site was baseline-scoped, was widened deliberately, and the reason is the contract FK plus AC-9. AC-12b — the only other edits to pre-existing tests are the twenty `tenantMigrationFileCount()` sites stated above and AC-59's one narrowing, which T-009 AC-39 already sanctions under D-5. Step 3a's planner-corpus change was built, measured and reverted in full, so it is not an edit.*

### Ruling 3 — `settings.updated_by`'s contract foreign key **fits inside this task**. AC-10 stands as written; the fallback is not taken.

`ba` worried that the runner's contract rule makes it impossible. I read the rule rather than its name.

`PlanMigrationRunHandler::assertContractStepsUnblocked()` refuses a plan containing a contract step at version V while `tenantsBelow(V - 1)` is non-empty, and `tenantsBelow()` reads `tenant_databases` excluding exactly one status, `provisioning`. Three measured consequences:

1. **The refusal is about *planning a run*, never about *shipping a file*.** `MigrationRunRefused::contractStepBlocked()` prints its own remedy — `tenant:migrate --all --to=<V-1>` first, then the run that includes the contract step. A task may therefore ship an expand set and a contract step together; what it may not do is promise they apply in one run.
2. **Today the blocking set is empty.** No tenant database exists: provisioning is FP-206 and is blocked behind the engine hold. So the step is plannable for the whole of this task's life. — **FALSE OF THE SUITE. Corrected below; read premise 2 only with the correction attached.**
3. **FP-206 is not harmed either.** A tenant being provisioned carries status `provisioning`, which is the single value `tenantsBelow()` excludes, so a fresh database can be migrated straight to the top in one run with the contract step included. The two-run requirement only ever bites a fleet that already holds servable tenants below V-1 — which is the case expand-and-contract exists for.

~~**Ruling: the contract migration ships in step 3, as the ninth and highest-versioned file.** AC-10 does **not** become a filed backlog row and its fallback clause is not exercised.~~ **REVERSED after step 3a. See correction (c): the migration does not ship in T-010 and AC-10's fallback *is* exercised.** The three numbered consequences above are still true of the planner; what was wrong was my judgement that this task could afford the corpus work they imply.

**One honest limit, and one claim of mine that `dev` measured and disproved.**

- **What *is* provable now, and must be, is the half that decides whether the migration is safe to run at all:** the data-clean pre-check. That is ordinary SQL (`settings` rows whose `updated_by` is non-null and matches no `employees.id`), runs identically on both engines, and is the thing that makes AC-10's "the migration fails for that tenant, `settings` is unchanged, other tenants proceed" testable today. It runs **first**, before anything engine-specific.

#### Correction after step 3 — Ruling 3's two defects, both mine, found by `dev` and by one measurement each

**(a) Premise 2 was true of production and false of the suite, and the error was in what I opened.** I wrote *"today the blocking set is empty, because no tenant database exists"*, reasoning about the **fleet**. The suite's fleet is not empty: `ThreeTenantFixture` and the migration-run fixtures map tenants at `schema_version = 0` **deliberately**, because *"a tenant that is behind"* is the input half the whole planner corpus is built on. With a contract step at the top of the shipped tree, those plans are now **correctly refused** and pre-existing kernel tests fail (**24**, across eight kernel files plus one literal in `MigrationTreeDisciplineTest` that is step 3's own; `dev` corrected its first figure of 38, and `ContractGuardBlockingSetTest` and `QaContractGuardNamedRunTest` pass untouched). The guard is right; the corpus collides with it.

This is an FP-182 instance in my own work — *a claim that names its own evidence is checked by opening what it names*. I opened `PlanMigrationRunHandler`, `tenantsBelow()` and `MigrationRunRefused`, and concluded correctly about all three. **I did not open the planner's tests**, which are the other half of "is the blocking set empty". The lesson is narrower than "measure more": when a guard's input is *the fleet*, the fleet has two populations — production and the fixtures — and a claim about one is not a claim about the other. `dev` was right to stop rather than force it. **At this point I believed the ruling survived and only its premise needed repair. That was also wrong — see (c).**

**(b) "SQLite has no `ALTER TABLE … ADD CONSTRAINT`" is true of raw SQL and false of the schema builder, which is what the migration actually calls.** Laravel 12's `SQLiteGrammar` lists `foreign` in its `$alterCommands` (line 36) and implements it by **rebuilding the table**, so the key is real and enforced. `dev` measured it three ways — `pragma foreign_key_list` reports it, `sqlite_master` shows it inside the recreated `CREATE TABLE`, and an insert naming an absent employee is refused with `FOREIGN KEY constraint failed`. I confirmed the grammar line independently. **The `JsonValidity` precedent does not transfer:** that helper gates because SQLite genuinely has no ALTER that adds a CHECK, which is a different fact about a different statement. I reasoned from the sibling rather than from the statement.

**`dev` kept the gate because the plan prescribed it and it does not change a design unilaterally. That was the correct call and it is also why this is cheap to fix now.** The ruling on whether the gate stays is in **step 3b**, and it is conditional, because dropping it has a hazard that the empty stand-in cannot show.

#### Correction (c), after step 3a was built and measured — **Ruling 3 is reversed. The contract migration does not ship in T-010, and AC-10's fallback is exercised.**

`dev` wired `highestPlannableVersion()` exactly as I specified, measured it, and reverted. **It made things worse: `PlanMigrationRunHandlerTest` went from 6 reds to 10**, and the four new reds are the cases whose subject *is* a contract step (FX-4, FX-5) — the helper strips their step and retargets them **below the guard they exist to exercise.** That is the hazard I named in step 3a's own negative control, *"retargeting the corpus into a set where the guard cannot fire, shape 7 arriving inside a fix"*, produced by the fix I prescribed. The control caught it, which is the one thing that worked as designed.

**`dev`'s diagnosis is better than my prescription and it is structural.** Two verified source facts:

- `runFixtureSet(n)` composes each FX directory **on top of the shipped tree**, so a shipped contract step at 15 puts FX-1 at 16 and the contract step **in the middle**. "The top minus any contract steps at the top" strips nothing and returns 16.
- `PlanMigrationRunHandler::appliedVersions()` is `range(lowestSelectedTenantVersion + 1, $target)`. A fleet at 0 and any target ≥ 15 therefore *includes* 15, and the guard fires. **The only plannable target for a fleet at 0 is ≤ 14 — below every FX migration, which is the corpus's entire subject matter.**

So the plannable ceiling is a function of the set **and the fleet's floor**, and a helper taking only the set cannot see the fleet. `dev` calls that my own lesson one level down and it is right: *when a guard's input is the fleet, a claim about the set is not a claim about the guard.* I wrote that sentence in correction (a) and then prescribed a set-only helper in the next paragraph. **And the decisive part is that no fleet-aware helper rescues it either:** with a contract step anywhere in the shipped tree and fixtures at 0, no plan can reach any FX migration at all.

**The three options, ruled.**

- **(b) `MigrationSet::baseline($path, $upToVersion)` — rejected.** It is a **kernel source change made to let tests pass**, and the `$upToVersion` it takes is a hand-maintained number that goes stale on the next contract step: the literal-in-disguise I rejected once already in step 3a, now promoted into production code. Worse than the problem.
- **(a) FP-304 now** — `runFixtureSet()` composes FX onto a fixture baseline. **Right end state, wrong task.** `dev` flags the real cost honestly: the planner corpus then stops measuring the shipped tree, which is part of what T-008's evidence base *is*. That loss is recoverable (one named case can keep it, see the row below) but recovering it properly is design work, not a sub-step.
- **(c) AC-10's own fallback — TAKEN.** The contract migration does not ship in T-010. AC-10 reads *"not done, and recorded"*, which is the wording `ba` wrote into the criterion for exactly this outcome, and it is a deliberate deferral rather than a silent drop.

**Why (c) and not (a), stated as the asymmetry it is.** Deferring AC-10 costs **nothing operational**: `settings.updated_by` has been unguarded since T-008, there are zero tenant databases, and FP-209 already owes three contract foreign keys in the same area and can carry this fourth. Taking (a) now puts **the corpus carrying T-008's evidence base** through a redesign, mid-T-010, inside a task already at 77 criteria, where it would be reviewed as item 3a of an employees feature. The costs are not comparable and the cheap side is obvious.

**What changed my mind versus my earlier deferral reasoning, because it is the opposite direction and should not read as drift.** In step 3a I deferred FP-304 on the grounds that *"a three-line derived helper closes it today"*. The helper does not close it — `dev` measured that — and `dev`'s second finding kills the other half too: **FP-304 arrives the day any contract step ships, not when FP-209 lands.** So the "it can wait" argument is gone. But the **risk** argument never depended on timing, and it is the one still standing. Those are two different arguments and only one of them was refuted.

**FP-304 is now a blocking predecessor for the next contract migration by anybody.** That is the lead's to sequence; recommended row text is in step 3a.

**Step 3b's measurement is not wasted and must not be re-derived.** All five held (see step 3b), so whoever ships this foreign key starts from a proved position and AC-10's enforcement half is `[ENGINE]`-free before it is written.

### Ruling 4 — Open question 6: confirmed. Surrogate `id` on `employee_permissions`, null-safe key on both tables, and the stand-in's lie is designed around rather than relied on.

`docs/database.md` 6.2 prints `employee_permissions` PK `(employee_id, permission_key, branch_id)` with `branch_id` NULL meaning all branches. MySQL and MariaDB make every primary-key column `NOT NULL`, so the row the key exists for is unrepresentable. D-10's replacement — surrogate `id`, plus a unique key over the stored null-safe sentinel — is **confirmed**.

**And the stand-in does not reproduce the real behaviour, which is why AC-6's control is shaped the way it is.** SQLite accepts NULL in a non-integer primary key. If the test merely asserted "the null-branch row stores", it would pass against the broken design too. The discriminating control is the one `ba` wrote: build a fixture table with a **plain** unique key over the nullable column, run the identical inserts, and show it **accepts** the duplicate that the real table refuses. That is a difference the stand-in *can* see, on both engines, and it is what makes the green mean something. The two-engine comparison itself stays `[ENGINE]`.

### Ruling 5 — no resolver cache, no per-request memo. One read per question.

`docs/patterns.md` §5: a read model is added on a **measurement**, and there is none. The spec's performance NFR invents no figure and neither do I. AC-45 and AC-46 are therefore satisfied structurally — there is no cache to mis-key and no memo to go stale — and both criteria keep their fixture doubles: a deliberately mis-keyed cache fixture and a version-blind cache fixture, each shown to produce the wrong answer, so the criteria stay falsifiable instead of vacuous. If a later task measures a problem, the answer is a decision with a stated staleness bound, not a refactor.

**Added after step 12 — the connection name is read in one place, and the fold is observable or it is nothing.** `dev` created `Support/TenantConnection.php` as the single home for the connection-name lookup and, correctly, did not reach into step 6's committed `SyncPermissionRegistryHandler` unasked. **It is now asked: the handler folds onto `TenantConnection`.** Two copies of one config lookup is one fact with two homes, which is the defect class this project has filed twice (FP-181, FP-184) and measured once — T-006's closing finding was *"where this task used a single source, nothing went stale; every one of the six shapes happened in a fact that had two homes."* `docs/patterns.md` §3 points the same way rather than against it: *"shared code earns its place by being used twice"* is satisfied the moment the second caller exists, so the three-use rule offers the duplicate no shelter.

**The fold carries step 6's moved-value control, and without it the change is unobservable:** point `TenantConnection` at a different connection name and show the **sync follows it**, with an unused second value as the negative control. That is what separates *"the sync reads the authority"* from *"the sync happens to agree with it"*, and it is the only thing that stops the duplicate being quietly reintroduced. No behaviour changes, so the gate moves by zero.

### Ruling 6 — `perm_hash`'s canonical form, documented here because the till must reproduce it (AC-54).

```
line   := ("allow" | "deny") "\t" <permission_key> "\t" <scope>
scope  := <branch ULID, 26 chars>  |  "*"   (* = the null, all-branches scope)
body   := implode("\n", sort(array_unique(lines)))      // sort() = byte-wise ascending, after collapsing
input  := "flexipos.perm.v1" "\n" <perm_version decimal, no padding> "\n" body
hash   := bin2hex(sha256(input))                         // 64 lower-case hex characters, never truncated
```

Contributions are: one `allow` line per (key, scope) reachable through a role assignment, and one line per `employee_permissions` row with its own `allowed` flag. An employee with no contributions has an empty `body` and still a defined hash (AC-55). The `flexipos.perm.v1` tag exists so a format change is detectable rather than silent. **Not claimed, per D-23:** that two configurations which resolve identically hash equally.

### Ruling 7 — the tenant connection is laxer than production on the stand-in, and that is now a **standing property of this task**, not a fixture detail

`dev` found it the only way it could be found: a mutation passed a foreign key it should have hit. **The stanza `Tenancy` writes carries no `foreign_key_constraints` key**, and SQLite leaves foreign keys *off* by default, so every `REFERENCES` clause in the tenant tree was being ignored on any connection built by `Tenancy::enter()`. Without the fix, every slice test from step 6 onward would have been proving handler rules **against a database laxer than production** — and passing.

**I checked whether anything T-008 or T-009 proved rests on the same laxness. Nothing does, and the check is cheap to repeat:** every pre-existing test that asserts a foreign key sets `foreign_key_constraints => true` on its *own* private stand-in connection — `TenantBaselineBuildsTest:76`, `TenantAccessKeysTest:65`, `QaTenantBaselineStandInShapeTest:29`, `QaSchemaSignatureInstrumentTest:157` — and the master stanza carries it at `config/database.php:98`. The gap exists only in the stanza `Tenancy` builds, and `packages/kernel/tests/Support/master-mapping-fixture.php:22` **documents the absence knowingly**. It was never load-bearing because no foreign-keyed tenant table existed until this task. **T-010 is the first task to reach a tenant foreign key through `Tenancy::enter()`, which is exactly why it is the task that found it.**

**Ruling, in three parts.**

1. **`AccessTenantFixture::enter()` keeps `dev`'s implementation** — `pragma foreign_keys = on` issued on the connection **`Tenancy` built**, never a connection this task builds, so T-009 AC-52 is untouched. Both halves stay pinned as `dev` wrote them: the refusal with its liveness control, and **the laxness of a plain `tenancy()->enter()` asserted rather than described**. That second assertion is the valuable one — it makes the gap a measured fact that cannot silently close or widen, and it is the difference between a fixture detail and a standing property.
2. **It is binding on every test in this task that touches a tenant foreign key**, and step 24's sweep asserts it: any such test reaches the database through `AccessTenantFixture::enter()`. A test that builds its own lax connection and asserts a foreign key is proving nothing, and that is the failure mode this would otherwise repeat forever.
3. **The real fix is the stanza, and it is a filed row rather than T-010's work.** Recommended text for the lead: *"The tenant connection stanza omits `foreign_key_constraints`, so foreign keys are unenforced on every SQLite-backed tenant connection built by `Tenancy`. Harmless on MySQL and MariaDB, where keys are always enforced; on the stand-in it makes the test database laxer than production. Found by T-010 when a mutation passed a key it should have hit; T-010 works around it in its own fixture and pins the gap. The fix belongs in the stanza so no future fixture can forget it."* **Not taken here** because it is a change to the connection factory that every tenant test in the repository would inherit mid-task, and the fixture-level fix buys the same correctness for this task's evidence with none of that blast radius.

### Ruling 8 — the resolver's refusal order: a question-level refusal precedes a subject-level one. Confirmed as `dev` shipped it, and it became a criterion

The order is **`unknown_permission` → `branch_required` → `employee_unavailable` → `not_permitted`**. `dev` flagged it rather than settling it, because **both orderings satisfied every criterion as written** — which is the right reason to escalate, since `qa` grades the spec and could have asserted the opposite and been right.

**`dev`'s argument is decisive on its own: a diagnostic that fires only for some askers is not a diagnostic.** `unknown_permission` is a server defect that D-26 logs at error level; put it after the subject check and a typo'd guard on a route only non-acting employees reach **denies everyone forever and logs nothing** — silent, permanent, and invisible to the one mechanism built to catch it.

**The principle, which is what makes this a decision rather than a preference.** `unknown_permission` and `branch_required` are properties of **the question**; `employee_unavailable` is a property of **the subject**. A malformed question has no answer about any subject, so question-validity precedes subject-judgement. It is the rule the HTTP layer already follows one level up (422 before 401/403), and T-009's plan states the handler-level form — *"validate → resolve key → judge"*.

> **A citation check, recorded because the checking is the point and because I overstated its result.** T-009's plan attributes that ordering to **D-36**, so I opened D-36 before leaning on it. D-36 is the `login_history` narrowing and does not *prescribe* the ordering — but, as the lead then verified against the text I had only half-read, its reasoning **rests on** it (*"an operational failure **before that point** writes no row"*) and it does support the clause the plan cites it for. **So the citation is looser than ideal, not wrong, and my "it says no such thing" was itself the overstatement.** Two lessons, and the second is the one worth keeping: checking a citation cost nothing and was right; and **the check has to be read as carefully as the claim** — FP-182's move is "open what it names", not "open it and skim". The ruling never depended on the precedent, which is why it survived my getting the precedent wrong in both directions.

**The asymmetry, which is how every other call on this task has been settled.** Wrong in `dev`'s direction: a non-acting employee can distinguish a registered key from an unregistered one — a key list that ships in the application's own source, through an in-process resolver that is not a public oracle. Wrong the other way: a permanently silent server defect. Not comparable. **AC-43 is untouched either way** — *"regardless of grants"* is about grants, and no contribution is read for an employee who cannot act under either order.

**This needed a spec change and not only a plan line, for the reason `dev`'s escalation implied:** a plan line binds `dev` and does nothing about the real risk, which was `qa` writing against the ambiguity. Done at `f5e3ec8` — AC-42 states the order with the question-level / subject-level principle, D-33 carries the ruling and cites the principle rather than D-36, and AC-44 gained the row I called the criterion **plus one `ba` added that the principle covers and neither of us asked for**: a branch-scoped key with no branch, asked about a non-acting employee, returns `branch_required`. That second row is the better test of the principle, because it is the case where the question-level refusal is not a server defect and the ordering still has to hold.

---

## 1. Approach summary

T-010 ships as **one new path package, `packages/access`**, holding the eight tenant tables' migrations, ~24 vertical slices, the resolver, the registry, the PIN policy and the audit writer; plus **the first symbols ever placed in `packages/contracts/src`** — the resolver interface, the refusal-reason enum, the claim bundle and the permission-declaration interface — so that `packages/auth` and every later module can ask the question without touching the access tables. Deptrac already grants `Package_Auth → Package_Contracts`, so **authentication gains no new allowed edge**, and `Package_Access` is deliberately withheld from `Module_Demo`: a module reaching into the access internals is a reportable violation on day one, which is AC-11's instrument.

The HTTP surface follows T-009 exactly: a route file in the package loaded by `AccessServiceProvider`, thin invokable controllers in `modules/host/app/Http/Controllers/Access/`, and the guard as **one new middleware** beside `RequireTenantToken`. D-7's staleness check goes **inside** `RequireTenantToken`, not beside it, for the reason that class's own docblock already gives: a route must not be able to be authenticated without also being gated, and the tenant connection the check needs is opened by that class and by nothing else.

Nine tenant migrations: eight `expand` creating one table each, and one `contract` adding `settings.updated_by → employees`. The catalogue republishes to version 15 and `.env.example`'s `APP_REQUIRED_SCHEMA_VERSION` moves 6 → 15 in the same step, because T-008's AC-21 pins them equal.

Time is `Flexipos\Kernel\Schema\Support\RunnerClock`, already bound as a singleton by T-009 — **not a second clock**. Argon2id reuses T-009's `config/hashing.php` and its reduced test work factors; the PIN hasher is the framework hasher, not a new one.

Everything that enumerates — the registry sweep, the route sweep, the bump sweep, the PIN sink scan, the resolver truth table — **publishes its domain** (D-27, shape 7): it prints what it examined, asserts the list is non-empty, and names a mutation including an item nobody listed.

---

## 2. Alternatives rejected

- **`modules/employees`, a real module.** Rejected: a module may not depend on another module, so every later module that wants to ask "may this employee do K?" would be reaching across a forbidden edge. Authorization is cross-cutting infrastructure, like `packages/auth`.
- **Folding it into `packages/auth`.** Tempting — `auth`'s own composer description already says "guards and permission resolution". Rejected: auth is master-side identity and T-010 is tenant-local authorization, and AC-73 ("nothing master-side is written") is far easier to prove when the two live in different packages with different connections.
- **Two packages, `access` and `employees`.** Rejected: the tables are foreign-keyed to each other, every operation touches both, and §0's volume argument says one folder per feature matters more than one package per noun.
- **Putting the resolver interface in `packages/kernel`.** Rejected: the kernel is infrastructure every package already depends on; promoting a domain contract there is a three-use-rule violation and `packages/contracts` exists for exactly this.
- **A resolver cache, or a per-request memo.** Rejected — Ruling 5.
- **Enumerating branches to populate `branch_ids` for an owner.** Rejected by D-2 and it is not reopened: there is no `branches` table, and defining `[]` as "all" would redefine the claim when FP-209 lands.
- **A `branches` existence check at write time.** Rejected: there is nothing to check against (D-30). Shape only.
- **A partial unique index for AC-8's one-default rule.** Rejected: neither MySQL nor MariaDB has one — the exact shape T-006 removed from `barcode_rules`. A stored generated column is used instead (§4).
- **Widening `tenantBaselineTables()` to fourteen.** Rejected — Ruling 2.
- **A separate status check per request alongside the version check.** Rejected by D-7, and it would be a second source for one fact (FP-184).

---

## 3. Files to change / create

Paths are repository-relative. `[new]` unless marked *(change)*.

**This table is indicative, not exhaustive, and that is deliberate.** It names the files whose *existence* is a design decision — the boundary, the contracts, the migrations, the one-writer classes. It does not try to predict every `Support/` class, because a class that becomes the single home for something two slices decide is `docs/patterns.md` §3 **working**, not drift, and a plan that pre-named them would be guessing at which facts turn out to have two homes. `dev` added six on this basis — `RoleKey`, `RoleLookup`, `RoleView`, `AssignedRole`, `EmployeeView`, `EmployeeLookup`, plus `TenantConnection` (Ruling 5) — and the pay-off is measured rather than asserted: **neutering the system-role guard inside `RoleLookup::requireCustom()` reddens exactly AC-21's three operations**, which is precisely what reading `is_system` in one place buys and what six copies of the check could not have given. The standing test for a new one is that measurement: if moving the rule in one place moves the result everywhere it applies, it earned its file. A `Support/` class that no mutation can reach through is the one to question.

### `packages/contracts` — the published surface (the first code this package has ever held)

| Path | What and why |
|---|---|
| `src/Access/PermissionResolver.php` | `decide(string $employeeId, string $permissionKey, ?string $branchId): AccessDecision`. The one question every later module asks |
| `src/Access/AccessDecision.php` | allowed flag + `RefusalReason` + the finer `detail.reason` (`not_granted`, `explicit_deny`, `out_of_scope`) that never reaches an HTTP body (D-26) |
| `src/Access/RefusalReason.php` | the closed set of fourteen, as a backed enum. **One place**, so a criterion can assert the cause rather than the status class |
| `src/Access/EmployeeClaims.php` | the five employee-derived claims as one value object: `employee`, `branchIds`, `allBranches`, `permVersion`, `permHash`. All-or-none (AC-58) is a property of the type, not of three call sites |
| `src/Access/EmployeeClaimSource.php` | what `packages/auth` calls at mint: `claimsFor(string $employeeId, string $userId): EmployeeClaims` or a refusal carrying D-6's four causes |
| `src/Access/EmployeeStalenessCheck.php` | what `RequireTenantToken` calls per request: current version vs the claim |
| `src/Access/DeclaresPermissions.php` | the interface a module's provider implements to declare its keys |
| `src/Access/PermissionDeclaration.php` | key, module, group, description, `isBranchScoped` — validated at construction (AC-14's format rule lives here, once) |

### `packages/access` — the implementation

| Path | What and why |
|---|---|
| `composer.json` | `flexipos/access`, psr-4 `Flexipos\Access\`, requires `flexipos/contracts` |
| `src/AccessServiceProvider.php` | binds the resolver, the registry, the claim source, the staleness check, the PIN policy, the audit writer; loads `routes/api.php`; registers this package's own declarations. Registered in `modules/host/bootstrap/providers.php` |
| `routes/api.php` | the tenant routes of sections C to G, each carrying exactly one declaration (AC-63) |
| `src/Registry/PermissionRegistry.php` | collects every `DeclaresPermissions` provider's keys. **Publishes its domain**: `declarations()` returns the keys *and* the providers that supplied them |
| `src/Registry/AccessPermissions.php` | this task's six declarations (`employees.view/manage/set_pin`, `roles.view/manage`, `access.assign`), all `is_branch_scoped = false` (D-13) |
| `src/Resolver/DatabasePermissionResolver.php` | D-8 (a) to (f) in one class. No cache (Ruling 5) |
| `src/Support/EmployeeStatus.php` | the six statuses and `acting()` = {active, probation}. **One place** (D-15); AC-27's moved-value proof points here |
| `src/Support/PermVersion.php` | the single atomic `UPDATE … SET perm_version = perm_version + 1 WHERE …` and the fan-out over a role's holders. Never read-then-write |
| `src/Support/PermHash.php` | Ruling 6's canonical form, in one function |
| `src/Support/PinPolicy.php` | 4-to-8 digits, the weak-PIN list, the lockout figures — **one list in one place**, numbers from `config('access.*')` |
| `src/Support/AccessLogWriter.php` | the only writer of `access_log`; refuses a `type` outside {`pin_login`, `pin_failed`, `permission_denied`} at construction (D-21) |
| `src/Support/AuditWriter.php` | D-20's minimal single writer of `audit_logs`. Replaced by FP-211 later |
| `src/Support/ScopeKey.php` | the `'*'`/null-branch marker used by the hash and the resolver, so the marker is one symbol |
| `src/Support/TenantConnection.php` | **the single authority for the connection name** (Ruling 5). `SyncPermissionRegistryHandler` folds onto it, with the moved-value control |
| `src/Support/{RoleKey,RoleLookup,RoleView,AssignedRole,EmployeeView,EmployeeLookup}.php` | added by `dev` at steps 11 and 12 under the two-use rule, each the one place something two or more slices decide. `RoleLookup` holds the `is_system` reading, which is why one mutation there reddens exactly AC-21's three operations |
| `src/Models/*.php` | eight `TenantModel` subclasses. No `tenant_id`, no global scope by tenant |
| `src/Exceptions/AccessRefused.php` | carries a `RefusalReason`; one constructor per reason, so "refused" is never constructible without a cause |
| `src/Features/SyncPermissionRegistry/` | AC-14 to AC-18, the owner role and its bump |
| `src/Features/{CreateRole,RenameRole,SetRolePermissions,DeleteRole,ListRoles,GetRole}/` | section C |
| `src/Features/{CreateEmployee,UpdateEmployee,ChangeEmployeeStatus,DeleteEmployee,ListEmployees,GetEmployee}/` | section D |
| `src/Features/{AssignRole,RemoveRoleAssignment,SetEmployeePermission,ClearEmployeePermission,AttachBranch,DetachBranch}/` | section E |
| `src/Features/ResolvePermission/` | the slice wrapping the resolver, so AC-53's enumeration sees a folder like every other |
| `src/Features/{SetPin,VerifyPin}/` | section G. `VerifyPin` has **no route** (D-16, AC-49) |
| `src/Features/BuildEmployeeClaims/` | D-6's four checks and the five claims; the `EmployeeClaimSource` implementation |
| `src/Features/GetMyPermissions/` | AC-60 |
| `tests/**` | the mirror tree. **Namespaced `Flexipos\Access\Tests\…` — see step 1** |

### Migrations — `modules/host/database/migrations-tenant/`

| Path | Phase | What |
|---|---|---|
| `2026_10_20_000301_create_permissions_table.php` | expand | `key` VARCHAR(80) PK, module, group, description, `is_branch_scoped` |
| `…000302_create_roles_table.php` | expand | `IdentifierColumn::primary`, `key` unique, name, description, `is_system`, `[TS]`, `deleted_at` |
| `…000303_create_role_permissions_table.php` | expand | `role_id` FK cascade, `permission_key` FK; PK both |
| `…000304_create_employees_table.php` | expand | §4's column list. Self-referencing `created_by`/`updated_by` are declared **inside the same `Schema::create`**, which `SourceRules::foreignKeysOnlyInContract()` permits (a table the same migration created is not an existing table) |
| `…000305_create_employee_roles_table.php` | expand | the null-safe key (D-10) |
| `…000306_create_employee_permissions_table.php` | expand | surrogate `id` + the null-safe key (Ruling 4) |
| `…000307_create_employee_branches_table.php` | expand | PK (employee_id, branch_id) + the one-default generated column (§4) |
| `…000308_create_access_log_table.php` | expand | `$table->id()`, `detail` JSON with `JsonValidity::enforce`, `ip` binary(16) |
| ~~`…000309_add_employees_foreign_key_to_settings_table.php`~~ | ~~contract~~ | **WITHDRAWN from T-010 by Ruling 3 correction (c)**, pending FP-304. When it is written: the data-clean pre-check first, then the foreign key with **no driver gate** (step 3b measured all five properties green on the stand-in, including the rebuild of a populated table). The file exists, Pint- and PHPStan-clean, and should be recovered rather than rewritten |
| `modules/host/database/catalogue/tenant_schema_versions.json` *(change)* | — | republished, 6 rows → 15 |

### The host

| Path | What and why |
|---|---|
| `app/Http/Controllers/Access/*.php` | thin invokable controllers, one per routed operation. Validate, call a handler, render |
| `app/Http/Middleware/RequireTenantPermission.php` | the guard: resolves, 403 `not_permitted`, writes the one `access_log` row (AC-64). The `authenticated_only` marker is a value it recognises, not a second class |
| `app/Http/Middleware/RequireTenantToken.php` *(change)* | after `enter()`, D-7's staleness check → 401 `permissions_changed`, no handler, no `access_log` |
| `bootstrap/providers.php` *(change)* | add `AccessServiceProvider` |
| `composer.json` *(change)* | require `flexipos/access` |
| `config/access.php` | PIN min 4 / max 8, lockout 5 / 15 min, employee page size and its clamp. **Numbers only** — no status list, no key list, no reason list |
| `deptrac.yaml` *(change)* | `../../packages/access/src` and `/tests` paths; `Package_Access` layer; `Package_Access: [Package_Kernel, Package_Contracts]`; `HostApp` gains `Package_Access`. `Module_Demo` deliberately does **not** |
| `phpstan.neon.dist` *(change)* | the same two paths |
| `.env.example` *(change)* | `APP_REQUIRED_SCHEMA_VERSION=6` → `15` |
| `composer.lock` *(change)* | the new path package |
| `tests/Helpers.php` *(change)* | Ruling 2: `tenantTreeTables()`, `tenantMigrationFileCount()`; `tenantBaselineTables()` unchanged |
| `tests/Feature/MigrationTreeDisciplineTest.php` · `TenantBaselineBuildsTest.php` *(change)* | Ruling 2's scoped edits |
| `tests/Feature/{SchemaCataloguePublishTest,FixtureMigrationSetsTest}.php` · `packages/kernel/tests/Schema/Support/MigrationSetTest.php` *(change)* | the file-count fix |
| `packages/auth/src/Tokens/TokenClaims.php` · `Features/SelectTenant/*` · `Features/RefreshToken/*` *(change)* | the five claims, through the `EmployeeClaimSource` contract |
| `packages/auth/tests/Features/SelectTenant/SelectTenantTest.php` *(change)* | AC-59's **one sanctioned narrowing**, to the null-employee case. Narrowed, never deleted |

### Test files (`qa`-owned unless a slice's own mirror)

`packages/access/tests/Features/<X>/<X>Test.php` (one per slice, `dev`, mirror rule) · `packages/access/tests/Support/AccessTenantFixture.php` (step 5) · `modules/host/tests/Feature/QaAccessHttpSurfaceTest.php` · `QaPermissionSweepTest.php` (AC-18, AC-53, AC-63) · `QaPinSinkCaptureTest.php` (AC-48) · `QaTenantWriteBoundaryTest.php` (AC-2, AC-73).

---

## 4. Data / API changes

**Schema.** Eight new tenant tables, exactly the "Data this task stores" column lists and nothing more (AC-3 forbids the design's wider HR row arriving by accident). Every ULID column through `IdentifierColumn`; every instant `DATETIME(3)`; every enumeration `VARCHAR(30)` with an application enum; no charset, collation or engine literal. The master gains nothing (AC-1, AC-73).

**Two keys that are not plain unique indexes, and the reasons differ — a reviewer must not file either as the other.**

1. **The null-safe keys on `employee_roles` and `employee_permissions`** (D-10). `IdentifierColumn::sentinel($table, 'branch_id', 'branch_id_key')`, unique over `(employee_id, role_id, branch_id_key)` and `(employee_id, permission_key, branch_id_key)`. Here NULL is a **meaningful value** ("all branches"), so the sentinel is required: this is `docs/database.md` §3.3 applied.

2. **The one-default rule on `employee_branches`** (AC-8). A unique index on `(employee_id, is_default)` would also refuse a second *non*-default row, and a partial index does not exist on either engine. So a stored generated column:

   ```php
   // Not §3.3's sentinel and deliberately not routed through
   // IdentifierColumn::sentinel(), whose docblock says in terms that the next
   // person needing one on a different shape writes their own and says why.
   // Here NULL is NOT meaningful - it means "no constraint applies to this
   // row" - which is the one case a nullable unique key is correct for. The
   // inverse of §3.3, and recorded as such so FP-180 does not collect it.
   $table->ulid('default_employee_id')
         ->nullable()
         ->storedAs('CASE WHEN is_default THEN employee_id END');
   $table->unique('default_employee_id');
   ```

   `employees.user_id` (AC-7) and `employees.code` (unique including soft-deleted rows) are the same legitimate case and need no sentinel.

**Migration run shape.** The catalogue's highest version becomes **14** and `.env.example` follows it. **Every shipped step is `expand`**, so no contract guard fires and nothing about the run shape changes for anybody — correction (c) withdrew the one contract step. When the `settings.updated_by` key is eventually shipped at version 15, the guard applies as Ruling 3 describes: a fleet holding servable tenants below 14 runs `tenant:migrate --all --to=14` first, the refusal prints that command itself, and a tenant in status `provisioning` does not block, so FP-206 still provisions to the top in one run.

**Endpoints** (`api/v1`, the `api` group, invokable controllers — T-009's shape; FP-215's envelope is still not built). Every one carries `RequireTenantToken` plus exactly one declaration (AC-63).

| Method | Path | Declaration |
|---|---|---|
| GET/POST | `/api/v1/access/roles`, `/roles/{role}` | `roles.view` / `roles.manage` |
| PUT/DELETE | `/api/v1/access/roles/{role}`, `/roles/{role}/permissions` | `roles.manage` |
| GET/POST | `/api/v1/employees`, `/employees/{employee}` | `employees.view` / `employees.manage` |
| PATCH | `/api/v1/employees/{employee}`, `/employees/{employee}/status` | `employees.manage` |
| DELETE | `/api/v1/employees/{employee}` | `employees.manage` |
| POST/DELETE | `/api/v1/employees/{employee}/roles`, `/permissions`, `/branches` | `access.assign` |
| POST | `/api/v1/employees/{employee}/pin` | `employees.set_pin` |
| GET | `/api/v1/me/permissions` | `authenticated_only` |

**No route verifies a PIN** (D-16, AC-49), and AC-49's control is the route list itself, printed.

**Refusal codes to a caller:** 422 `validation`; 403 with one of `not_permitted`, `branch_required`, `unknown_permission`, `employee_unavailable`, `last_owner`, `self_change`, `no_escalation`, `role_in_use`, `system_role`, `scope_not_applicable`, `not_attached`, `has_scoped_grants`; 401 `permissions_changed`. The finer `not_granted` / `explicit_deny` / `out_of_scope` live only in `access_log.detail.reason`.

**Config keys added:** `access.pin.min_digits` (4), `access.pin.max_digits` (8), `access.pin.lockout.threshold` (5), `access.pin.lockout.minutes` (15), `access.employees.page_size` (50), `access.employees.max_page_size` (100).

**No data migration.** No tenant database exists. No escalation trigger is met.

---

## 5. Implementation steps

Each step ends green on `composer check` from `modules/host`, with a **zero-delta-or-explained** comparison against the gate as it stood when the step began. Owner is `dev` unless stated. Criteria closed are in brackets. **Every step names a negative control, and a control that cannot be shown red is not a control** — the T-009 standing rule applies: before citing an instrument, open the file and confirm what it scans.

**Red-first rule, binding and structural.** A T-008 red phase destroyed sixteen of the owner's databases because a guard was stubbed permissively while the real effect stayed wired. On this task, red is proved by **omission** (the mechanism does not exist and the call throws), **against a fixture** (a scratch table or a scratch declaration), or **with an in-memory double**. No guard, resolver, policy or middleware is ever stubbed to allow. `DatabasePermissionResolver` in particular is never written to `return true` at any point, not even for a minute.

- [x] **Step 1 — the two packages and the boundary.** Create `packages/contracts/src/Access/` (the eight symbols of §3, bodies only as far as step 1 needs: the enums and the value objects are real, the three interfaces are declarations) and the `packages/access` skeleton with an empty-boot provider. Register: host `composer.json` require, `bootstrap/providers.php`, `deptrac.yaml` (two paths, the `Package_Access` layer, the ruleset entry, `HostApp` gains it, `Module_Demo` does not), `phpstan.neon.dist` (two paths). `composer update flexipos/access flexipos/contracts` — **never with `--lock`**, which Composer refuses beside a package selection (T-009 step 4a measured this).

  **The one thing that will bite and is cheap now:** `QaToolchainRegistryCoverageTest` reports `"no deptrac.yaml layer collects any namespace in <tree>"` for any registered `tests` directory whose files are all global-namespaced, and the only escape is `qaGlobalNamespaceTestTrees()`, an FP-303 list that is self-liquidating by design. **`packages/access/tests` ships namespaced `Flexipos\Access\Tests\…` and adds no entry to that list.** Shared helpers go in a `Support` class with static methods, not in file-scope functions, because a namespaced file-scope function is not callable from a sibling the way the existing trees rely on. **If a namespaced Pest file turns out not to be collected by `modules/host/phpunit.xml`, stop and report to `architect`** — the documented fallback is to ship global and add `'packages/access/tests' => 'FP-303'`, which is a loss to be recorded, not a thing to do quietly.

  > **Corrected after execution — "the first tree that is genuinely Deptrac-collected" was wrong on the day, right about the direction.** `dev` measured it: **Pest names its generated class after the file path, not the declared namespace**, and a Pest file declares no class of its own, so Deptrac collects **no token** from the four new test files today. The namespace is nonetheless doing real work, and `dev` proved it as a pair rather than asserting it — a class declared in `packages/access/tests` reaching `Flexipos\Module\Demo\DemoServiceProvider` gives **1 violation, exit 1**; the identical class global-namespaced gives **0 violations, exit 0**. So the tree becomes genuinely collected **the moment it declares a class**, which step 5's `Support` helper does, and no FP-303 entry was needed.
  >
  > Worth keeping because it is the registry-coverage defect one level down: a *registered*, *namespaced* tree can still be inert if nothing in it declares a class. The honest claim is **"collected for everything that declares a class, which from step 5 is the helpers"**, not "collected".

  **`dev`'s three step-1 extensions, all approved, recorded because AC-12b requires every edit to be stated.** (i) `HostTests` gains `Package_Access` — steps 19 to 24's `qa`-owned host tests would otherwise be unwritable, and **`Module_Demo`'s omission is untouched**, which is the half that carries AC-11. (ii) `modules/host/composer.json` gains an `autoload-dev` psr-4 pair, because a path package's own `autoload-dev` is inert when it is installed as a dependency — a real property of Composer, not a workaround. (iii) `QaToolchainRegistryCoverageTest:437`'s shared-package literal gains `'access'`; that literal **is the guard's published domain**, so extending it when a package joins is the guard working as designed rather than an exemption. None of the three weakens an assertion.

  **Negative control, a discriminating pair (shape 3 — both are "a reference"; only one is forbidden):** a scratch class in `modules/demo/src` importing `Flexipos\Access\Features\CreateEmployee\CreateEmployeeHandler` must make `composer deptrac` exit non-zero; the same class importing `Flexipos\Contracts\Access\PermissionResolver` must **not**. Both scratch files deleted before the step closes. **Measured clean, including one case I would not have thought to check and `dev` did:** demo importing a class that **does not exist yet** still gives 1 violation, because Deptrac classifies unresolved names by the layer regex. So the control above is valid as written, before the handler it names is built — which is also why it could be run in step 1 at all. *(AC-11 in part, AC-13.)*

- [x] **Step 2 — the stand-in spikes, before anything rests on them.** Throwaway, all deleted at the end; what survives is one sentence in steps 3, 4 and 15. Measure on the SQLite stand-in: (a) `IdentifierColumn::sentinel()` inside `Schema::create` plus a unique key over it refuses a second null-branch row and accepts a distinct branch id; (b) the `CASE WHEN is_default THEN employee_id END` stored column builds through Laravel's SQLite grammar and gives exactly-one-default; (c) a non-integer primary key accepts NULL — **confirm the stand-in lies as D-10 says**, so AC-6's control is written knowingly rather than hopefully; (d) ~~`ALTER TABLE … ADD CONSTRAINT` is unavailable, so step 3's contract migration must gate on the driver like `JsonValidity` does.~~ **(d) is withdrawn — see Ruling 3's correction (b) and step 3b.** It is true of raw SQL and false of the schema builder the migration actually calls, and the spike as written would have confirmed the wrong half. **If (a) or (b) fails, stop and report to `architect`** — do not improvise a different key. *(No AC; it protects steps 3, 4 and 15.)*

- [x] **Step 3 — the eight migrations, the catalogue, and Ruling 2's scoped edits.** *(Closed. The ninth — AC-10's contract file — is **withdrawn from T-010** by Ruling 3's correction (c), not held; the tree is at 14 files, catalogue 14, `.env.example` 14, and that is the shipped state.)* The eight expand files, one table each, in the order of §3. Republish the catalogue; move `.env.example`. Apply Ruling 2:

  > **One number, now that the ninth file is withdrawn.** The tree holds **14** files, the catalogue holds 14 rows and `.env.example` is 14. None of the three is written by hand: the catalogue is republished and `.env.example` is pinned equal to it by T-008 AC-21. **When the contract foreign key is eventually shipped — after FP-304 — all three move to 15 together, the contract step is version 15, and the remedy the guard prints is `tenant:migrate --all --to=14`.** split `tenantBaselineTables()` / `tenantTreeTables()`, add `tenantMigrationFileCount()`, retarget the three whole-tree sites and the three file-count sites, widen AC-30's inventory sweep with its liveness control intact.

  **Red first, structurally:** the schema assertions are written in the same commit and are red *because the tables cannot be described*, not because anything is stubbed.

  **Negative control — and it is `MigrationTreeDisciplineTest`, not `MigrationSourceRulesTest`.** Measured on T-009: a scratch tenth migration with a `timestamp` column and a bare `decimal` left `MigrationSourceRulesTest` green at 20 passed and reddened `MigrationTreeDisciplineTest`. **I re-opened both files rather than repeat the claim:** `MigrationSourceRulesTest`'s only use of `migrationRuleTreeSources()` is at line 315, in a case titled *"finds the migration trees it will be swept over"*, which asserts the master tree is non-empty and that every path classifies to the tree it was asked for — it never runs `SourceRules::inspect()` over a shipped file. Every `inspect()` call in that file takes a fixture from `tests/Fixtures/MigrationSourceRules/`. The file proves the rule *engine*; it says nothing about the shipped tree. Any claim here of the form "the shipped tree obeys the source rules" cites the second file. **Second control:** the catalogue fixture one version higher and one lower each turn the equality test red (AC-5).

  **Done when:** `MigrationTreeDisciplineTest`, `TenantBaselineBuildsTest`, `MigrationSourceRulesTest`, `SchemaCataloguePublishTest`, `FixtureMigrationSetsTest`, `MigrationSetTest`, `QaDatetimePrecisionSourceTest` and `QaSchemaSignatureInstrumentTest` all pass **with no new exemption**. *(AC-1 stand-in half, AC-2 source half, AC-3, AC-4 source half, AC-5, AC-9, AC-12, AC-12a.)*

- [x] **Step 3a — CLOSED AS WITHDRAWN. The planner corpus cannot be retargeted inside this task, and the fix I prescribed made it worse.** `dev` built it, measured 6 reds becoming 10, and reverted everything; the tree is back at 14 files and the gate is green. The reasoning is Ruling 3's correction (c) and is not repeated here. **What `dev` did that the plan should copy:** it shipped the specified fix, ran the specified negative control, let the control fail the fix, and reverted — rather than adjusting the control until the fix passed. That sequence is the whole value of naming a control in advance, and it is the first time on this project a prescribed fix has been killed by its own prescribed control.

  **The row the lead files, and it is a blocking predecessor rather than a tidy-up.** *"FP-304 — give the migration-planner corpus its own fixture baseline tree. `runFixtureSet()` composes the FX directories onto `config('schema.tenant_migration_path')`, so the planner's tests take the **product's** tree as their input; the day any contract step ships, `appliedVersions()` includes it for every target above it and no plan from a fleet at 0 can reach any FX migration, which is the corpus's entire subject matter. Measured on T-010: 24 reds across eight kernel files. **Blocks the next contract migration by anybody**, including T-010's deferred `settings.updated_by` key and FP-209's three branch keys. The FX directories already compose onto any baseline, so the mechanism exists. Keep one named case planning the **shipped** tree — refused for a fleet at 0, with the test running the remedy command the refusal prints, and succeeding for a fleet at the version below the contract step — so the real-tree coverage the corpus has today becomes named rather than incidental, which is the one thing this change must not lose."* *(AC-10 moves to "not done, and recorded"; AC-12b's statement shrinks accordingly — the twenty `tenantMigrationFileCount()` sites remain, the planner-corpus change does not happen.)*

- [x] **Step 3b — the driver gate: all five measurements hold, so the gate goes. CLOSED, and the result outlives the deferral.** `dev` measured against a **populated** `settings` table (three rows, both scope kinds): the rows are byte-identical before and after; `scope_id_key` is still `-` for the null-scope rows and the branch id for the scoped one, so **the stored generated column survived its own table being rebuilt**; the `settings_registry` key is still reported by `pragma foreign_key_list`; the new `updated_by → employees.id` key is reported; and **the null-scope duplicate is still refused**, so the §3.3 sentinel's behaviour survives the rebuild. Enforcement was proved with its liveness half: a row naming no employee refused with `FOREIGN KEY constraint failed`, the same row with a real employee accepted.

  **Three consequences, and the first is the one to carry forward.** (i) **AC-10's enforcement half is not `[ENGINE]`** — Laravel 12's SQLite grammar rebuilds the table and the key is real, so whoever ships this foreign key inherits a proved position and must not re-derive it or re-add a driver gate. (ii) My "SQLite has no `ADD CONSTRAINT`" is struck from the plan wherever it appeared, and the migration docblock carries the measurement instead of a claim about what SQLite "cannot" do. (iii) Because correction (c) withdrew the migration, AC-10 is NOT VERIFIED **for the reason "deferred, recorded", not for the reason "[ENGINE]"** — two different facts that would otherwise be reported as one, which is why §11 now separates them.

- [x] **Step 4 — the keys, proved by direct insert.** AC-6, AC-7, AC-8 against the migrated stand-in. **Every one carries Ruling 4's discriminating control:** the identical inserts against a fixture table built with a plain unique key over the nullable column are shown to **accept** the duplicate. Without that, a green here is equally consistent with the broken design. AC-7's positive control (two null `user_id` rows both accepted) is in the same test as its refusal. *(AC-6 stand-in half, AC-7, AC-8 stand-in half.)*

- [x] **Step 5 — the tenant fixture with the tree applied.** `packages/access/tests/Support/AccessTenantFixture.php`, built **on** `packages/kernel/tests/Support/tenancy-fixture.php` rather than beside it: boot that fixture, apply `tenantMigrationPath()` to the `tenant-a` and `tenant-b` SQLite files, set their mapping `schema_version` to the catalogue top so the existing serving gate admits them. Nothing here builds a tenant connection; everything goes through `Tenancy::enter()` (T-009 AC-52 still holds). **Negative control:** with `schema_version` left at 0 the existing gate must refuse with `TenantSchemaBehind`, and with it at the top the same request must succeed — the pair, because "refused" alone is also what a broken fixture produces. **And it issues `pragma foreign_keys = on` on the connection `Tenancy` built — Ruling 7, which is binding on every later step.** Without it the tenant tree's `REFERENCES` clauses are ignored and every slice test from step 6 on proves its rules against a database laxer than production. Both halves are pinned: the refusal with its liveness control, and the laxness of a plain `tenancy()->enter()` **asserted rather than described**. **Watch the clock:** the gate's test step is at 184.68s of 900s; file-backed SQLite per test is the expensive shape, so fixtures that do not need `Tenancy` use a private in-memory connection as `TenantBaselineBuildsTest` does. *(No AC; it is the vehicle for AC-2's cross-tenant control, AC-45, and everything from step 6 on.)*

- [x] **Step 6 — the registry: declaration, format, sync.** `PermissionDeclaration` validating the key format at construction; `PermissionRegistry` collecting from providers and **publishing its domain** (the keys *and* the providers that supplied them); the sync slice with its `inserted / updated / unchanged / orphaned` report; the `is_branch_scoped` flip refusal; orphans never deleted. **Negative controls:** a fixture declaring each defect (malformed key, over-length, duplicate-with-different-attributes) fails once per defect and writes nothing; a key inserted directly into the table but never declared is reported `orphaned`; and **a scratch declared key that appears in no list in any test is picked up by the sweep** — that last one is AC-18's shape-7 control and is mandatory. The sweep's domain statement must say in terms: *"this enumerates the declarations of the providers booted under `MODULES=demo` plus `bootstrap/providers.php`; a module not booted in this environment is outside it"*, and assert the booted provider list is non-empty. *(AC-14, AC-15, AC-16, AC-18.)*

- [x] **Step 7 — the owner role.** Seeded by the sync with `is_system = true`, holding every registered key by data. A newly declared key joins `owner` and bumps every holder **in the same transaction**; a second sync bumps nothing. **Negative control:** an employee holding only a custom role is **not** bumped — the pair (holder bumped, non-holder not) is what makes the bump attributable to `owner` rather than to the sync running at all. *(AC-17, AC-52's sync clause in part.)*

- [x] **Step 8 — `PermVersion` and `PermHash`.** The single atomic increment (one `UPDATE … SET perm_version = perm_version + 1`, never read-then-write — the `[ENGINE]` half of AC-52 rests on the shape, and the shape is reviewable in the diff) and Ruling 6's canonical hash. **Negative controls:** a fixture reordering the contributing rows, their ids and their creation order leaves the hash unchanged; flipping one `allowed` flag changes it; two employees with identical contributions and identical versions hash identically; an employee with no contributions still has a defined hash. **Do not** assert "equal hash iff equal rights" — D-23 names that as the natural overclaim. *(AC-54, AC-55.)*

- [x] **Step 9 — the resolver.** D-8 (a) to (f) in `DatabasePermissionResolver`. No cache, no memo (Ruling 5). **Red first by omission:** AC-38 to AC-43 are written first and fail because the class does not exist.

  **The mandatory mutation controls, each run by `dev` and re-run by `qa`:** (i) **AC-40** — reversing the precedence so allow beats deny must turn the deny cases red; (ii) **AC-39's shape-6 fixture** — the diverging case is an employee whose *only* allowing contribution is scoped to branch Y, asked about branch B; an employee with a null-scope grant cannot discriminate the rule because both readings allow; (iii) **AC-41** — a role holding a non-scoped key assigned at a specific branch must **not** confer it, with the same role at a null scope as the positive control; (iv) **AC-42** — `branch_required` and `unknown_permission` must each be distinguishable from `not_permitted`, so a resolver collapsing them to one reason reddens.

  **AC-44 publishes the truth table**: the dataset is printed, asserted non-empty, asserted to contain at least one row for each of the four reasons the resolver can produce and both allowed and refused rows — so a resolver that always refuses, or always allows, fails. *(AC-38 to AC-44, AC-45, AC-46.)*

- [x] **Step 10 — AC-45 and AC-46's falsifiability.** With fixture A and B seeded with the **same employee id** and different grants, answer for A, then B, then A again. Then the two fixture doubles that keep the criteria meaningful in the absence of a cache: a resolver decorated with a cache keyed on employee id alone **must** return A's answer for B and the test must report it; one keyed without the version **must** return the stale answer after a bump. Both are test-local doubles stated in the file as proof about the criterion and not about the shipped resolver. *(AC-45, AC-46 closed with their controls.)*

- [x] **Step 11 — roles.** The six slices of section C. AC-21's system-role refusals with the custom-role positive control for each of the three operations; AC-22's `role_in_use` with the no-assignment control; AC-19's key reservation across soft deletion. *(AC-19 to AC-23.)*

- [x] **Step 12 — employees.** The six slices of section D. AC-26 states the architect's choice explicitly: a request including `code`, `user_id`, `pin_hash`, `perm_version` or `status` in an update is **refused with `validation`**, not silently ignored — a silent ignore is indistinguishable from a field that was never wired, and the refusal is the version a test can tell apart. AC-29's page size is **clamped**, not refused, and the clamp is asserted at the boundary value. AC-27's acting set is read from `EmployeeStatus::acting()` and proved by the moved-value shape: change the constant, watch the crossing-the-boundary bumps follow, with an unused second set as the negative control. *(AC-24 to AC-29, AC-52's status and soft-delete clauses.)*

- [ ] **Step 13 — assigning access.** The six slices of section E, with D-11's attachment rule in both directions (`not_attached` at write, `has_scoped_grants` at detach). AC-31's acceptance of a well-formed id naming no branch is asserted **positively**, with the file saying in terms that branch existence is not validated (D-30), so `qa` cannot read it as a gap. AC-33's "removing a row that does not exist is `validation`, and bumps nothing" needs the bump-absence asserted, not inferred. *(AC-30 to AC-33, AC-35, AC-52's assignment clauses.)*

- [ ] **Step 14 — the three safeguards.** `self_change` (AC-34), `no_escalation` (AC-36), `last_owner` (AC-37). **Each refusal carries the paired shape AC-64 established and the spec demands everywhere:** the actor lacking exactly one of the role's keys is refused, and granting them that one key makes the identical request succeed. A test asserting only "403" here proves that *something* refused, and three guards can refuse in this step alone. AC-34's control is the same change made by a different actor; AC-37's is a second owner. *(AC-34, AC-36, AC-37 stand-in half.)*

- [x] **Step 15 — WITHDRAWN with AC-10, by Ruling 3 correction (c).** This step existed to exercise the contract migration, which does not ship in T-010. **Nothing is lost that has to be re-derived:** step 3b already measured the whole of AC-10's engine-facing half green on a populated table, and the migration file itself is written and clean. What the step owed and now moves with FP-304 is the *isolation* behaviour — a fixture tenant with a dangling `updated_by` failing **that tenant alone** while others proceed, with the pre-check removed as the negative control. `qa` records AC-10 as **"not done, and recorded"**, the wording `ba` put in the criterion for this outcome, and **not** as `[ENGINE]`. *(AC-10 deferred, recorded.)*

- [ ] **Step 16 — audit and the transaction.** `AuditWriter`, one row per successful administrative operation, none on a refusal; `pin_set` recorded with no hash and no PIN. AC-71's forced mid-way failure leaves neither the change, nor the bump, nor the audit row. **Negative control for AC-71 — and it is the shape-5 lesson from T-009 step 7, which cost a real control:** the probe observes the **property**, not a consequence. "A throw after the write still leaves the row" survives the very wrapper the criterion is about. Use a `DB::connection('tenant')->listen()` probe recording `transactionLevel()` during each statement, asserted non-zero for the three writes — **and give the probe its own liveness control**, because a probe that is reading nothing reports the same thing as a probe that is reading a clean run. *(AC-70, AC-71.)*

- [ ] **Step 17 — setting a PIN.** `PinPolicy`'s one list; Argon2id through the existing hasher and T-009's reduced test factors; `pin_set_at`, counter zeroed, lock cleared; `perm_version` unchanged; an actor may set their own. **Negative control:** moving one entry out of the weak-PIN list must make exactly that PIN accepted — one list in one place is only true if moving it moves the result. `7391` and an eight-digit PIN accepted; `0000`, `1234`, `4321`, `1111` refused. *(AC-47.)*

- [ ] **Step 18 — verifying a PIN, and the lockout.** In-process only. AC-50's single refusal value across all six causes, with the cause surviving **only** in `access_log.detail.reason` — the sibling-guard remedy, exactly as T-009 D-12 required of `login_history.failure_reason`. The hasher is invoked **exactly once in every case**, including unknown code and no-PIN, against a fixed dummy hash, counted by a double; **the double that counts zero for the unknown-code case is the named negative control and must turn AC-50 red**. AC-51's boundary is asserted on both sides in one dataset: at `pin_locked_until` minus one second refused, at exactly `pin_locked_until` accepted. AC-49's control is the **printed route list**, asserted non-empty and asserted to contain no route reaching `VerifyPin` — a domain published, because an empty route list would otherwise pass it. *(AC-49, AC-50, AC-51 stand-in half, AC-69's PIN half.)*

- [ ] **Step 19 — the PIN sink harness (AC-48), `qa`-owned.** Runtime capture, never a textual search of source. Six sink kinds, each with its own positive control in the same test: (1) every column of every table in **both** databases, enumerated from the live schema at test time, printing the table and column counts it scanned; (2) the application log at every level; (3) exception messages and rendered error pages; (4) every response body and header; (5) token claims; (6) the ingress channel — the URL, query string, path and `Referer` as the application records them, with a `pin` or `pin_hash` in the query string or path refused `validation` **before any read or write**.

  **The three mandatory controls, and the second is the one that makes a digit secret testable at all:** (i) each of the six sink kinds, deliberately fed the sentinel, is reported as found; (ii) a **baseline run with no sentinel** is captured first and the sentinel string asserted absent from it, with the sentinel at least eight characters and formed so it cannot be a substring of any baseline capture; (iii) the scan also looks for URL-encoded, JSON-escaped and reversed forms.

  **Shape 7 is the live hazard here and it is why sink 6 exists:** T-009's harness enumerated four sinks, the request URL was in nobody's list, passwords were readable from the query string, and 1145 tests stayed green. The harness must therefore **publish its domain** — the six kinds, the table and column counts, the header names — and the report must state the residual: the application cannot erase what a reverse proxy logs, and a four-digit PIN colliding with unrelated digits is mitigated by the sentinel, not removed. AC-52a is recorded as a stated limit, not tested. *(AC-48, AC-52a.)*

- [ ] **Step 20 — the HTTP surface, the guard, and the route sweep.** The controllers, `routes/api.php`, `RequireTenantPermission`, and AC-63's sweep enumerating **from the router**. AC-64's pair is binding on **every** guarded route: an employee holding every registered key **except** the route's own is refused 403 `not_permitted` with the handler spy never called and the route's tables unchanged; an employee holding **only** that key passes and the spy is called once. **Mutation controls for AC-63, all three mandatory:** a scratch route with no declaration, a scratch route naming an unregistered key, and **a scratch route registered by a module the test does not mention** are each reported. `authenticated_only` routes are printed so a reviewer sees which routes skip the check. *(AC-63, AC-64, AC-65, AC-69's denial half.)*

- [ ] **Step 21 — the staleness check.** Inside `RequireTenantToken`, after `enter()`. 401 `permissions_changed`, no handler (spy), **no** `access_log` row. A missing or soft-deleted employee row gets the same 401. A token with no `employee` claim performs no check and reaches only `authenticated_only` routes. **Controls:** before the change the same token reaches the handler **once** (the liveness half — an uncalled spy is also what a broken test produces); after refreshing, the new token reaches it. AC-68's ordering is proved on the **diverging input** (shape 6): a tenant whose mapping is *not servable* **and** whose token is stale — the gate must refuse first and the permission check must never run, which a servable fixture cannot discriminate. *(AC-66, AC-67, AC-68.)*

- [ ] **Step 22 — the claims at selection.** `BuildEmployeeClaims` behind `EmployeeClaimSource`; `TokenClaims` gains the tenant-with-employee shape as a **third named set**, so "exactly" stays a value and not a literal in two handlers. AC-57's four employees are built as four distinct cases so a constant implementation fails. AC-61's four causes each get their own positive control. AC-59's **one sanctioned narrowing** of the T-009 test lands here: the sentence pinned to "selection does not open the tenant database" is narrowed to the null-employee case, never deleted, and the diff shows it. **Negative control:** a token minted in the test with one extra claim is rejected by the same set-equality comparison, and the **platform** token's claim set is asserted unchanged from T-009 AC-28. *(AC-56 to AC-59, AC-61.)*

- [ ] **Step 23 — tenant refresh, my permissions, platform staff.** AC-62's re-read (never copied from the old token), with the refresh token **not consumed** on an `employee_unavailable` refusal and the liveness control D-24 required: the same token succeeds on a later legitimate refresh, because a token that was never valid also has a null `used_at`. AC-60's live read. AC-72's pair: staff with no employee refused on every guarded route, staff whose membership links an owner employee passing the same route. AC-74: the T-009 operations unchanged and green. *(AC-60, AC-62, AC-72, AC-74.)*

- [ ] **Step 24 — the sweeps and the close-out.** AC-53's bump sweep, enumerating the operations **from the `Features/` directory**, not from a list in the test, computing every (employee, key, scope) cell before and after, printing the operations examined and the cell count. **Its three mutation controls are mandatory:** (a) deleting the bump from one operation reddens the sweep **naming that operation**; (b) a scratch slice that writes `employee_roles` and is listed nowhere is found by the enumeration and fails; (c) a bump added to an operation that changes nothing is **not** required — the sweep asserts that a change implies a bump, never the converse. Then AC-2's query-log scan (no tenant predicate on any new table, with the fixture `where tenant_id = ?` as its liveness control) and AC-73's master-side scan with the same control shape. **And Ruling 7's sweep:** every test in this task that asserts a tenant foreign key reaches the database through `AccessTenantFixture::enter()`, enumerated from the test tree and printed, because a test that builds its own lax connection and asserts a foreign key proves nothing. Finally `composer check`, and the NOT VERIFIED list of §11 written into the QA report. *(AC-2, AC-11's query-log half, AC-13, AC-44's print, AC-52, AC-53, AC-73.)*

---

## 6. The seven mask shapes, cumulative from T-009 §7, and where each bites here

1. **An earlier gate absorbs it.** Validation sits in front of every rule in sections C to E, and the tenant gate and the staleness check sit in front of every permission check. A refusal test whose input is *also* invalid, or whose tenant is *also* unservable, proves nothing. *Remedy — change the input:* every refusal fixture in steps 11 to 14 uses an input that is valid in every respect but the one under test, and step 21 names the diverging input for the ordering.
2. **A key or constraint absorbs it.** `UNIQUE(code)`, the two null-safe keys and the one-default column will each refuse a duplicate whether or not the handler's own rule exists. *Remedy — pin something only the mechanism produces:* AC-30's "assign the same role twice" asserts the `validation` reason **and** that `perm_version` did not move, not merely that an error occurred.
3. **A sibling guard refuses with the same class — the big one here, and the spec designs it.** Fourteen reasons, and `not_granted` / `explicit_deny` / `out_of_scope` all surface to a caller as one `not_permitted`. *Remedy, and it is AC-64's pair:* an employee holding **every key except K** is refused and an employee holding **only K** passes. Every refusal criterion in steps 11, 13, 14, 20 and 21 carries that pair, and `access_log.detail.reason` is the one place the cause survives — the same role `login_history.failure_reason` played on T-009.
4. **The read that feeds a statement duplicates its own terms.** `AttachBranch` reads the employee and then bumps; `DetachBranch` reads the scoped grants and then deletes. If the write re-filters on the terms the read already applied, a broken read is invisible — the write simply affects nothing and the refusal is right for the wrong reason. *Remedy:* the bump and the delete are written unconditionally against the row the read returned, and the proof is a case where the read says *allowed* and the row is nonetheless stale.
5. **The control observes a consequence instead of the property, and an absent thing reports the same as an absent observer.** Every criterion phrased as an absence — "no tenant predicate", "no master write", "the handler is never called", "no `access_log` row", "the refresh token is not consumed", "nothing written" — is proved by a probe observing the property, and **every such probe owes a liveness control**, because zero is what a probe reading nothing reports. Binding on: step 16 (`transactionLevel()`, not "the row survived a throw"), step 20 (the spy called once by a valid request), step 21 (the handler reached once before the bump), step 23 (the refresh token used successfully afterwards), step 24 (the query logs must contain a query they are certain to see).
6. **A valid input cannot discriminate an ordering whose branches both succeed.** Three orderings here: gate-before-permission-check (step 21), permission-check-before-handler (step 20), and bump-inside-the-transaction (step 16). Each needs a fixture containing the **diverging** case, and the mutation shown red *on that case*. A servable tenant with a fresh token cannot tell you the gate runs first.
7. **An instrument that discriminates perfectly over the wrong domain — invisible to mutation by construction.** This is the one that cost T-009 a real defect, and this task has **five** enumerating instruments: AC-18 (registry keys), AC-48 (PIN sinks), AC-49 (routes reaching verify), AC-53 (bumping operations), AC-63 (tenant routes). For each, write down the set it enumerates and the set the criterion is about, and justify the difference **in the file**. Each prints what it examined and asserts it non-empty; each names a mutation that is an **item nobody listed** — a scratch declaration, a scratch route, a scratch slice. `ba` has already built that property into all five; the steps above keep it rather than quietly narrowing it. The question to ask is not *"does this instrument discriminate?"* but **"is the set it covers the set that matters?"**

**Two standing rules this task must not break.** No proof that is a textual search — AC-48 and AC-2 say so in terms. A printed instruction must be runnable — step 15's and Ruling 3's `tenant:migrate --all --to=14` is printed by `MigrationRunRefused` itself, and the test asserting it must run the command it names, not match its text.

---

## 7. Test strategy

**Unit (inside a slice, `dev`-owned).** `RefusalReason` completeness against D-26's fourteen. `EmployeeStatus::acting()`. `PinPolicy` over the weak-PIN table. `PermHash` order independence and sensitivity. `EmployeeClaims` all-or-none. `PermissionDeclaration`'s format rule. `ScopeKey`'s marker.

**Slice tests (`dev`-owned, mirrored under `packages/access/tests/Features/<X>/`).** One per slice, against step 5's migrated stand-in tenant with the clock fixed. These carry the bulk of AC-14 to AC-55.

**Integration / HTTP (`qa`-owned, `modules/host/tests/Feature/`).** The endpoints end to end; the two middleware; AC-56 to AC-74; the AC-48 sink harness; AC-2 / AC-73's query-log scans; AC-53 and AC-63's sweeps.

**Which tests prove which ACs** is a table in the QA report. Every claim carries a grade:

- **Independently proven** — runs on the stand-in and would fail if the behaviour were absent: AC-2, AC-3, AC-5, AC-7, AC-9, AC-11 to AC-36, AC-38 to AC-50, AC-53 to AC-74.
- **Corroborating** — true here, but the engine is the real subject: AC-1's and AC-4's stand-in halves, AC-6's and AC-8's stand-in halves, AC-10's pre-check half, AC-51's non-concurrent half.
- **Covered by reading** — AC-52's atomic-statement shape (diff review while the engine is held), AC-52a (a stated limit), AC-37's and AC-51's concurrency halves.

---

## 8. Risks and rollback

| Risk | Size | Mitigation |
|---|---|---|
| **Gate duration.** 184.68s of a 900s limit at the plan baseline, ratio 4.87; **3.60 at the narrowest point after steps 1 to 4** (1214 passed). Twenty-four slices with file-backed SQLite tenants could multiply it | the one that could actually stop the task | In-memory private connections wherever `Tenancy` is not the subject; file-backed fixtures reused across a file, as `tenancy-fixture.php` already does with its `afterAll`. **`dev` reports the test-step elapsed at every step**, and if the ratio falls below 2.0 it is an escalation to the lead, not a quiet slide. The slide from 4.87 to 3.60 over four steps is the shape to watch, not the absolute number |
| ~~A namespaced Pest tree is a first for this repository~~ **CLOSED at step 1** | — | Measured: Pest collects it, Deptrac collects anything in it that declares a class (step 5's helper onward), no FP-303 entry needed |
| ~~A contract step collides with the planner corpus~~ **REALISED, 24 tests; AC-10 deferred under its own fallback** | the one that cost a step and a reversal | FP-304 filed as a **blocking predecessor for the next contract migration by anybody**, including FP-209's three. The corpus is untouched and the shipped tree is back at 14 files |
| ~~Dropping the driver gate rebuilds a populated `settings` table~~ **CLOSED at step 3b, all five measurements held** | — | Recorded so the next owner of this foreign key starts proved and does not re-add a gate |
| **The tenant connection is laxer than production on the stand-in** (Ruling 7) | found late, would have invalidated every later slice test | `AccessTenantFixture::enter()` turns keys on and **pins the gap in both directions**; step 24 sweeps that every foreign-key test goes through it; the stanza fix is a filed row |
| **AC-64's pair needs an employee holding every key but one, per route** | medium | One fixture builder parameterised by the excluded key; the registry supplies the key list, so a new key is covered automatically |
| **The `[ENGINE]` criteria ship NOT VERIFIED** | accepted, pre-existing | §11's list, carried into the QA report verbatim. None is re-worded into something the stand-in can answer |
| **The contract step's two-run requirement is a real operational constraint forever** | small today, zero tenants | Ruling 3; the refusal prints its own remedy; `provisioning` does not block, so FP-206 is unaffected |
| **A branch id naming no branch is storable** | accepted, D-30 | Stated in the migration docblocks and in the test files; FP-209 closes it with a contract FK after a data-clean proof |
| **`perm_version` fan-out on a role edit touches every holder** | small | Bounded by one shop's staff, in one transaction (D-28) |

**Rollback.** Everything is on `ai/T-010-employees-roles-permissions` and nothing is pushed or merged. No tenant database exists, so no migration has run anywhere outside a test. The only edits outside the two new packages are additive registrations, Ruling 2's twenty `tenantMigrationFileCount()` sites and AC-59's one narrowing (step 3a's planner-corpus change was built, measured, and reverted in full) — each small, each named in §3, each reversible by reverting the step that made it.

---

## 9. What I judge unrealistic in the spec — nothing is blocking, and every item raised is now closed

The spec is unusually well-formed at this size and I am rejecting none of it. Items 1 to 3 went to `ba` and were taken at `06f2320`; items 4 to 6 arose during execution and were taken at `f5e3ec8`. All are kept here as the record of what moved and why. **Six spec changes across a task this size, every one of them a gap found by building rather than by reading, is the number I would want to see** — a spec that needed none would mean the building was not testing it.

1. **Open question 9's premise was false** (Ruling 1). **Closed** — reworded to "answered, nothing owed", naming the sentinel columns and `TenantBaselineBuildsTest`. `ba` left **D-10 untouched deliberately**, because that decision cites FP-180 as a *name for the defect class*, which Ruling 1 confirms rather than undermines. That is a finer distinction than I drew and it is the right one: the class is real, and only the claim that those two files were unfixed was stale. A reader of Ruling 1 will be tempted to make the over-correction `ba` avoided.
2. **AC-12 offered two cases and the truth was both** (Ruling 2). **Closed** — split into AC-12, AC-12a and AC-12b, with AC-12a carrying the shape-7 reason in the criterion text itself, and AC-12b new, so that "no other pre-existing test is edited" does not contradict the narrowing D-5 already sanctions.
3. **AC-26's "ignored or refused (architect's choice, stated)"** is settled as **refused with `validation`** (step 12). **Closed** — and `ba` added the control the reason implies and I had not asked for: the same request *without* those fields succeeds, so the refusal is attributable to the field rather than to the request shape.

4. **The resolver's refusal order was underdetermined** — both orderings satisfied every criterion, so `qa` could have asserted either. **Closed** (Ruling 8): AC-42 states the order and its principle, D-33 carries the ruling, AC-44 gained two rows including one `ba` derived from the principle itself.
5. **"The role-key format" is named by AC-19 and defined by no document.** `dev` read it as `/^[a-z][a-z0-9_]*$/`, max 60 — the narrowest shape consistent with `owner`, with widening as the reversible direction. **Closed** as D-34, and the substance of that decision is the part I would have under-weighted: it is deliberately **not** the dotted permission-key pattern, because a role key and a permission key are different namespaces and one shared validator would make them one fact with two meanings — FP-184 arriving through a regex.
6. **Four smaller calls `dev` made and stated rather than hid** — a status set to the value it already holds is a no-op rather than an invented refusal; setting a role's permissions to the set it already holds still bumps. **Closed** as D-35. On the second, the asymmetry is the argument and the criterion already sanctions it: **AC-53 asserts change-implies-bump and explicitly not the converse**, so a spare bump is inside the criterion and a missed one is not. The lead verified that against AC-53's text rather than against my description of it, which was the right instinct — the description was mine and unverified when I gave it.

Three further things I checked and am *not* raising, recorded so nobody re-checks them:

- **`Refference/ARCH.md` contradicts nothing here.** Roles and Permissions are Phase 0; Employees are Phase 4 (HR). D-14's trimming of the `employees` row to identity and access is therefore *aligned* with the owner's document, not a liberty taken against it.
- **`docs/architecture.md` §165 and §307** ("authorization is evaluated against the tenant database"; "per branch, least privilege, no bypass flag") support D-4, D-8 and AC-72 directly.
- **`docs/patterns.md` §5** is why there is no cache (Ruling 5), and §4.1 is why `access` is a package and not a module.

---

## 10. Questions for the human

None. Every open item is `ba`'s five owner-facing questions, which are non-blocking by their own terms, and the two architect questions answered in §0. No escalation trigger is met: nothing here deletes or migrates existing data, no payment, message, paid API or credential is involved, and no legal or security-policy choice is made that the safest conventional default does not already cover.

---

## 11. The `[ENGINE]` criteria, and exactly what each owes when G-1 is met

| Criterion | What ships now | What the engine owes |
|---|---|---|
| AC-1 | the stand-in table listing | the live `information_schema` listing on MySQL 8.4 and MariaDB 10.4 |
| AC-4 | the source-rule pass | the `information_schema` half: `CHAR(26) ascii_bin`, `DATETIME(3)`, no native enum |
| AC-6 | the stand-in refusals and the plain-key control | the same three cases on both engines, where a non-integer PK cannot hold NULL |
| AC-8 | the single-writer refusal | two concurrent writers, one default |
| AC-37 | the guard and its controls | two concurrent removals each seeing the other as the remaining owner |
| AC-51 | the counter, the lock, both sides of the boundary | simultaneous wrong PINs incrementing atomically, the lock applied once |
| AC-52 | the single-statement shape, by diff review | two simultaneous qualifying changes raising the version by two, never one |

**AC-10 is deliberately not in this table, and the distinction matters to the report.** It is NOT VERIFIED for the reason **"deferred, recorded"** — Ruling 3's correction (c) withdrew the contract migration from T-010 pending FP-304 — and **not** for the reason "[ENGINE]". Step 3b measured its enforcement half green on the stand-in, so it is the one criterion here that the engine hold is *not* what stands between it and a proof. Reporting the two reasons as one would hide both facts.

None is skipped, none is stubbed, and none is re-worded. `qa` records every row of this table as NOT VERIFIED, with the table as the reason — **and the row count is this table, not a number in this sentence** (FP-181), because step 3b may remove AC-10 from it.

---

## 12. Messages

**`architect` → `ba`, round 1 — all three taken at `06f2320`, see §9. Nothing outstanding from that round.**

**`architect` → `ba`, round 3 — taken at `f5e3ec8` before I could send it, and `ba` went past what was asked.** The refusal order (Ruling 8, AC-42 and D-33), the role-key format (D-34) and the four smaller calls (D-35) are all recorded. The item worth noting is AC-44's **second** new row, which `ba` derived from the principle rather than from the request — a branch-scoped key with no branch, asked about a non-acting employee — and which is the better test, because there the question-level refusal is **not** a server defect and the ordering still has to hold. Nothing outstanding.

**`architect` → `ba`, round 2 — two items, both settled by measurement.**

**(i) AC-10 is deferred under its own fallback clause and now reads "not done, and recorded"** (Ruling 3 correction (c)). This is the first time that clause has been exercised, and `ba` wrote it for exactly this case. The cause is FP-304, not the engine hold — worth stating in the criterion, because "deferred" and "[ENGINE]" are different facts and the report must not merge them.

**(ii) The marker, which still applies to whoever ships the key later.** AC-10's *enforcement* half stops being `[ENGINE]`: Laravel 12's SQLite grammar implements `foreign` by rebuilding the table, so the key is real and enforced on the stand-in, and the driver gate the plan prescribed was my error rather than the engine's limitation. The marker should come off and §11's AC-10 row should narrow to the two-engine comparison. **A criterion becoming *more* provable is rare enough that it should be a stated change rather than a silent upgrade** — and if any of step 3b's five measurements fails, nothing changes and the reason is recorded instead.

**`architect` → lead.** Three backlog rows, none mine to file, and the second is a sequencing decision rather than a row:

1. **FP-180's row** needs its deadline phrase removed and its scope corrected to "a `docs/database.md` row"; recommended text is in Ruling 1 item 4.
2. **FP-304 — the migration-planner corpus** gets its own fixture baseline tree; full text in step 3a. **It is a blocking predecessor for the next contract migration by anybody** — T-010's deferred `settings.updated_by` key and FP-209's three branch keys all wait behind it. That ordering is yours to set, and it is the one thing from this round that must not be left implicit.
3. **The tenant connection stanza** omits `foreign_key_constraints`; text in Ruling 7 item 3. Harmless on MySQL, and on the stand-in it makes every SQLite-backed tenant connection laxer than production.

**`architect` → `dev`.** Steps 3a and 3b are closed and nothing in the migration area is owed. **From step 13: take the `TenantConnection` fold with its moved-value control (Ruling 5), and the refusal order is confirmed as you shipped it and is now AC-42 and D-33 — you were right to flag it rather than settle it, because both orders satisfied the criterion and `qa` grades the criterion.** **Two things from this round that are binding on every step after it:** Ruling 7's foreign-key property — any test asserting a tenant foreign key goes through `AccessTenantFixture::enter()`, and step 24 sweeps for it — and the step-3a sequence itself, which is the model: **you shipped the prescribed fix, ran the prescribed control, let the control fail the fix, and reverted, rather than adjusting the control until the fix passed.** That is the first time on this project a prescribed fix has been killed by its own prescribed control, and it is worth more than the step would have been. Your structural read — that a helper taking only the set cannot see the fleet — is better than my prescription and is now the reasoning in the plan.

**`architect` → `dev`.** Read §0 before step 1, §6 before writing any control, and §5's red-first rule before writing any guard. The three things most likely to cost you a day: the namespaced test tree in step 1, the `count(tenantBaselineTables())` file-count defect in step 3, and the liveness control every absence-shaped assertion owes in §6 shape 5. Any plan problem comes to me; never switch design silently.
