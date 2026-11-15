---
name: token-claims-and-selection-seams
description: The tenant token's three claim shapes, why the verifier closes the all-or-none hole, and the router's cached controller that silently inverts a re-mapping control.
metadata:
  type: project
---

**`TokenClaims` has three named shapes and the verifier enforces set equality, so composition closes holes a middleware cannot.**

**Why:** `RequireTenantToken`'s staleness check reads `employee` and `perm_version` and skips when either is absent - which reads like "absence folded into no guard" until you notice `VerifyAccessToken` refuses a tenant token whose claim names are not *exactly* `tenant` or `tenantWithEmployee`. A half-formed set never reaches a middleware. `EmployeeClaims` cannot be built partially, and `IssueTokenPair` takes the object rather than an employee id, so the shape is unreachable from the command too.

**How to apply:**
- A new token claim means editing `TokenClaims`, `allowedNames()`'s specimen, `TokenClaimsTest` (its subject *is* the exact sets) and `QaAuthHttpSurfaceTest` if a new auth exception type comes with it - that matrix is derived from the `Exceptions/` directory listing and reports an undriven type by name.
- Selection enters the tenant only for a membership naming an employee. `packages/auth` reaches `packages/access` through `Flexipos\Contracts\Access\*` only; a refusal is matched through the `AccessRefusal` interface, never the concrete `AccessRefused`.
- The kernel's serving refusals (`TenantSchemaBehind`, `TenantDatabaseNotActive`) are deliberately **not** caught in auth - they render as their own 503, same call `RequireTenantToken` makes.

**The trap that costs an hour: a `Route` memoises its controller instance.** It holds the handler it was built with, which holds a `Tenancy`, which holds a mapping repository built from `config('tenancy.databases')` *as it was then*. Re-mapping a tenant mid-test and calling the same endpoint again answers from the first request's fleet - measured twice, once as a 500 `TenantDatabaseNotMapped` and once as a servable tenant still refused 503. `forgetInstance(TenantDatabaseRepository::class)` and `forgetScopedInstances()` are not enough; also loop `Route::getRoutes()->getRoutes()` and call `flushController()`. Production builds a container per request, so this is a test-environment fact only.

See [[http-test-seams]], [[access-http-surface-seams]].
