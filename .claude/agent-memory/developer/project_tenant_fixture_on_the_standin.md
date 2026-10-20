---
name: tenant-fixture-on-the-standin
description: Four measured facts about running tests against a Tenancy-entered SQLite tenant - foreign keys are OFF, fsync dominates, VACUUM INTO builds a template in 1s, and two service providers of one class collapse into one.
metadata:
  type: project
---

Measured 2026-10-20 building `packages/access/tests/Support/AccessTenantFixture.php`.

**A fixture tenant entered through `Tenancy::enter()` does not enforce foreign
keys.** `Tenancy::writeTenantConnection()` builds the `tenant` stanza wholesale
and carries no `foreign_key_constraints` key - it has no reason to, because
MySQL and MariaDB enforce regardless. SQLite parses every `REFERENCES` clause
in the tenant tree and then ignores it. Found by accident: a mutation that
deleted an orphaned `permissions` row succeeded although a `role_permissions`
row referenced it. Any slice test built on a tenant fixture is otherwise
proving its handler's rules against a database laxer than production. The cure
is `pragma foreign_keys = on` on the connection **Tenancy built** (not one the
fixture makes, which would break T-009 AC-52).

**Three costs, each measured after the one before it was fixed:**

- migrating the tenant tree into a **file** database costs ~12.6 s. Migrating
  into `:memory:` and writing the result out with one `VACUUM INTO` costs ~1 s,
  and the product is the same database;
- creating a one-row table in each copied tenant file afterwards cost **0.7 s
  per tenant per test** - 7 s of a 10.5 s file. Bake per-tenant differences
  into per-tenant templates instead and let `boot()` be two file copies;
- `pragma synchronous = off` on the fixture connection: the write-heaviest
  file went 10.19 / 11.50 / 14.33 s to 3.46 / 1.98 / 6.35 s. Durability is
  worthless to a database deleted after each test.

**`Application::markAsRegistered()` keys `$serviceProviders` by class name.** A
second instance of one provider class **replaces** the first, with `force: true`
as well as without. A case needing two providers that disagree needs two
provider *classes*; the first draft passed for the wrong reason because the
container held one.

**How to apply:** when a package test needs a real tenant database, build on
the kernel's `tenancy-fixture.php`, add a per-tenant template and the two
pragmas, and never measure a timing on this machine without listing the other
`php.exe` processes first. Related: [[sqlite-standin-vs-engine]],
[[sqlite-fixture-costs-and-pest-properties]], [[gate-timing-spread]].
