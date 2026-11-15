---
name: sqlite-rebuild-measured-t014
description: Measured 2026-10-22 - Laravel's SQLite table rebuild is safe for all nine T-014 contract keys; the transaction trap is real
metadata:
  type: project
---

T-014 step 1 spike (throwaway, deleted): on a private in-memory SQLite connection with `foreign_key_constraints => true`, nine `Schema::table(...)->foreign()` rebuilds (`number_sequences`, `employee_roles`, `employee_permissions`, `employee_branches` onto `branches`; `branches.default_location_id`; `terminals.current_device_id`; `locations.created_by`, `locations.updated_by`; `terminal_credentials.revoked_by`) kept: rows byte-identical, every stored generated column (`branch_id_key` COALESCE sentinel, `default_employee_id` CASE column) correct for old and new rows, existing keys, indexes and the composite primary key, the sentinel's null-duplicate refusal and the second-default refusal. After rebuilding the parents (`branches`, `locations`, `terminals`) `pragma foreign_key_check` is empty and their children's keys still refuse unknown ids. Rebuilding `branches` inside `DB::transaction()` fails with `FOREIGN KEY constraint failed ... drop table "branches"`, so a contract migration must never run in a transaction on SQLite.

**Why:** the plan made step 1 the gate for the contract migration; this is the evidence, so later steps need not re-derive it.
**How to apply:** the permanent form is `BranchForeignKeysMigrationTest` (plan step 6). The spike source survives only in the session scratchpad; rebuild it from this note if needed.
