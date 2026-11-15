---
name: access-http-surface-seams
description: How T-010's tenant routes declare their permission, and the four seams a host test needs to drive them (mapping by two keys, per-declaration actors, global function names, Pest toContain)
metadata:
  type: project
---

`packages/access/routes/api.php` mounts nineteen tenant routes. Each carries
`RequireTenantToken` plus `RequireTenantPermission::class.':'.AccessPermissions::<CONST>`
- the declaration is a **middleware parameter**, because `QaAccessHttpSurfaceTest`
reads it back out of `Route::getRoutes()` with `gatherMiddleware()`. A declaration a
machine cannot find goes stale. `authenticated_only` is a recognised value of the same
parameter, not a second middleware class.

The PIN route carries a third, `RefusePinInTheUrl`, **between** the two others: after
the token (which enters the tenant) and before the guard (which reads the grant tables
and can write an `access_log` row).

**Four seams a host test driving these routes needs.**

- The token names the master's tenant ULID; the fixture tenant is keyed `tenant-a`.
  Map **both** keys to the same record (`config('tenancy.databases')`), so the request
  resolves by id and the test can still read the same database back through
  `AccessTenantFixture::switchTo('tenant-a')`. Forget `TenantDatabaseRepository` and
  `TenantMigrationTargetRepository` after the edit.
- `tenancy()->leave()` before every HTTP request: `Tenancy::enter()` refuses a second
  tenant while one is resolved.
- Seed **one actor per declaration, not per route** - four routes declare
  `roles.manage`, and `employees.code` is unique.
- Host test files share one global function and constant namespace. `accessHttpWorld()`
  in one file and `qaPinWorld()` in another is not style; a repeated name is a fatal
  redeclare on a full run and green on a filtered one.

**`expect($array)->toContain($needle, $message)` is variadic** - the second argument is
a *second needle*, not a message, so the explanation is silently asserted as a value and
the case fails while the code is right. Use
`expect(in_array($x, $a, true))->toBeTrue($message)`. `toBe()` and `toHaveCount()` do
take a message.

See [[http-test-seams]], [[tenant-fixture-on-the-standin]].
