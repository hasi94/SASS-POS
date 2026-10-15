---
name: name-an-exception-for-its-throw-site
description: Name a refusal after the place that throws it, never after the criterion it serves - a criterion-named exception gets written where the criterion is read and ends up thrown nowhere.
metadata:
  type: feedback
---

Name a refusal for the site that throws it (`<Site>Refused`, with a named
constructor for the condition), not for the criterion it serves.

**Why:** T-008's plan asked for `TenantSchemaAhead`, after AC-55's wording. It
was first written into the serving gate, where AC-55 is *read* - and the gate
has no site that throws it, because serving an ahead tenant is explicitly
allowed. It was dead code with a misleading name and the architect struck it.
Shipped as `SchemaRepairRefused::mirrorAheadOfTenant()` it has exactly one
throw site, in the repair handler, and the name says where to look.

**How to apply:** before writing an exception class, name the method that will
throw it. If you cannot, the class is premature - file a row instead. The
codebase's existing convention agrees: `MigrationRunRefused`,
`CataloguePublishRefused`, `MigrationSetRefused`, `RunnerLoopRefused` are all
sites. A corollary worth keeping: a refusal whose effect matters (stopping a
tenant serving) writes that effect **before** it throws, so the guarantee does
not depend on anyone catching the exception.
