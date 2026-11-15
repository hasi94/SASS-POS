---
name: master-table-list-is-pinned-three-times
description: Adding a master table forces edits in four files, three of them another role's, because two counts are literals - and the suite reads SQLite types without lengths
metadata:
  type: project
---

**A new master table is not one file.** `modules/host/tests/Helpers.php`'s
`masterApplicationTables()` is the authority, and two Qa/discipline files pin
its size as a **literal**:

- `tests/Feature/MigrationTreeDisciplineTest.php` — `masterApplicationTables()`
  `->toHaveCount(n)` and an exact-equality tree sweep;
- `tests/Feature/QaSchemaSignatureInstrumentTest.php` — `toHaveCount(n + system
  tables)` over the signature set;
- `tests/Feature/MasterSchemaBuildsTest.php` reads the helper, so it needs no
  count edit, only a banner line that counted the migrations in prose.

**Why:** T-009 step 3 added four tables and the step's own "done when" named all
of those files as having to pass. There is no way to satisfy it without editing
three files `qa` owns; the counts are literals by design, so a reviewer sees the
diff. Declare it and list the lines in the commit message
([[feedback-defer-in-a-comment-or-file-a-row]], the T-007 step 4 precedent).

**How to apply:** before writing a migration, grep the repository for the
current count as a bare number. Also budget the assertion delta:
`MigrationSourceRulesTest` classifies **one path per migration file**, so N new
migrations silently add N assertions to the gate total — that is how an
otherwise unexplained delta is attributed.

**Reading a schema on the stand-in.** `Schema::connection('master')->getColumns()`
returns Laravel's SQLite shape, and the grammar emits **bare** types: `char(64)`
and `ulid` both come back as `type` = `varchar` with no length, `json` as `text`
(unless the stanza sets `use_native_json`), `binary` as `blob`. So assert
`type_name` and `nullable`, never `varchar(26)`. What the stand-in *can* say
precisely: `auto_increment` is true for `$table->id()`, and
`generation['type'] === 'stored'` with `generation['expression']` is readable
for a generated column. Related: [[project-sqlite-standin-vs-engine]].
