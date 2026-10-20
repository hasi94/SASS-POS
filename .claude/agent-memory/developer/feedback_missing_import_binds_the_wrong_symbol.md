---
name: missing-import-binds-the-wrong-symbol
description: A ::class with no import resolves in the current namespace, so a container binding silently registers a class that does not exist and the error names the right symbol
metadata:
  type: feedback
---

Before believing a `BindingResolutionException` that says *"Target [X] is not
instantiable"*, check that the **binding site imported X**. `Foo::class` with no
`use` resolves to `<current namespace>\Foo`, so
`$this->app->singleton(PermissionResolver::class, Impl::class)` written in
`Flexipos\Access` without the import binds `Flexipos\Access\PermissionResolver`
- a class nobody has ever declared - and the caller asking for
`Flexipos\Contracts\Access\PermissionResolver` gets "not instantiable" for the
**interface it correctly named**. Nothing in the message mentions the namespace
that was actually bound, and `::class` never fails for a name that does not
exist.

**Why:** the error points at the consumer while the defect is at the producer,
which is three minutes of reading the consumer. PHPStan did not catch it either:
the bound name was a valid string expression.

**How to apply:** when a binding "does not take", `grep -n '^use' <the provider>`
before anything else; and when adding a binding to a provider, add the import in
the same edit rather than relying on an earlier one being there. The same trap
holds for `::class` in a Deptrac/PHPStan config string, a listener map and an
`instanceof`. Related: [[name-an-exception-for-its-throw-site]].
