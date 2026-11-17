---
name: t029-pricing-chunk-c
description: T-029 chunk C (step 10a, 21 CRUD slices, TaxGroupDirectory, catalog AC-3) - numbers and traps
metadata:
  type: project
---

Gate after chunk C: 12 failed (same classes as baseline, QaHealthProbeResilienceTest 1 not 2) / 31 skipped / 3984 passed, test step 991s (+2.3%).

- Large bash heredocs with apostrophes fail; use Write/Edit. Python `\U`/`\P` in strings bite: use Edit for PHP namespaces.
- VerticalSliceLayoutTest needs `<X>.php` + `<X>Handler.php` per slice and a mirrored tests/Features/<X>/*Test.php.
- A new DeclaresAuditActions provider edits packages/access/tests/Support/AuditCatalogueTest.php (two literals).
- The backfill seeds a default STANDARD tax group in every migrated tenant; tests start with one default.
- phpstan: an anonymous double returning a constant non-null from a `?string` method fails return.unusedType; type the property `?string`.
- Pest `app(AuditCatalogue::class)` needs AccessTenantFixture boot first.
