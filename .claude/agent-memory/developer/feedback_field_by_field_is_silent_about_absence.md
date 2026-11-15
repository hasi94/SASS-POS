---
name: field-by-field-is-silent-about-absence
description: AC-70's `ip` shipped hard-coded to null through fourteen handlers and a green sweep, because the assertions named five fields and not the row.
metadata:
  type: feedback
---

**When a criterion enumerates the fields of a row, compare the whole row against an expected array.** A field-by-field assertion is silent about every field it omits.

**Why:** `AuditWriter` wrote `'ip' => null` and `write()` took no address at all. Fourteen handlers reached it, the sweep asserted five fields each time, and `audit_logs.ip` was asserted nowhere in either test tree. Shape 9's sibling: a count loose enough to pass a duplicate, beside a field assertion loose enough to pass an absence.

**How to apply:**
- Read the column list **from the table** (`getSchemaBuilder()->getColumnListing()`), not from a literal, so a later migration's column enters the comparison on the day it is created.
- Name the fields the test cannot know (`id`, `created_at`) explicitly and assert their shape; anything missing from the expected array is then reported.
- Extract the comparison so it can be run twice: green on the written row, throwing on the same row with one field knocked out - and show the old field-by-field assertions passing the knocked-out row, which is what makes the change evidence rather than tidying.
- A probe whose shipped resolver answers null outside a request (`ClientIp`) needs a liveness case through the real HTTP stack: in a package test null is also what the defect wrote.

See [[mutation-must-move-the-observable]], [[record-the-gap-dont-assert-zero]].
