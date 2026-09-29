---
name: tenancy-boundary-leak-surfaces
description: Framework facts that decide whether the FlexiPOS master/tenant connection boundary actually holds - which construction paths skip the guard, which dumpers show a redacted credential anyway, and what survives a purge. Found by executing them in T-003 QA.
metadata:
  type: project
---

The `packages/kernel/src/Tenancy/` boundary is enforced at three levels (placeholder stanza →
`DB::extend('tenant')` → per-query `beforeExecuting`). Level 3 is the load-bearing one. These are
the things that *decide* whether it holds, all measured in T-003 QA (2026-09-29), none visible in
a passing suite.

**`DB::build()` / `DB::connectUsing()` skip the name-keyed extension entirely.** `connectUsing()`
calls `$this->factory->make($config, $name)` **directly**, not `makeConnection()`, so neither
level 2 nor level 3 runs. Measured before the fix: `DB::build(['name' => 'tenant', …tenant B's
config])` inside tenant A's scope served **B's rows** with `TenantContext` reporting A, and the
object was then cached under the `tenant` name so every later `DB::connection('tenant')` was
unguarded too. T-003 BUG-2, fixed by listening for `ConnectionEstablished` (which `connectUsing()`
*does* dispatch, **before** it caches, so throwing there also stops the install) and attaching
level 3 from there. Three things learned checking that fix:
- **Keep the extension as well as the listener.** `Event::fake()` swaps the `events` binding and
  `DatabaseManager` resolves it per dispatch, so the listener stops firing; listener-only would
  let a faked dispatcher silently disable level 3 on the ordinary route. Residual, accepted: the
  `build()` route *is* unguarded under `Event::fake()` — test-only, and the divergence runs the
  safe way (production stricter than the faked test).
- **`build()` poisons the name even when refused.** It writes
  `$dynamicConnectionConfigurations[$name]` *before* delegating, `purge()` never clears it, and
  `configuration()` prefers it over `config()`. So a refused `build()` bricks the tenant
  connection for the whole process — fail-closed and loud, worth pinning as such.
- A `WeakMap` keyed by `Connection` de-duplicates the now-double attachment safely: the guard
  closures capture `$app`/`$name`/`$tenantId`, never the connection, so entries free normally.
  Measure it **relatively** (baseline, N switches, drop, compare) — the map is static and outlives
  individual tests.

Checking only that *our own* resolver avoids a forbidden API is not the same claim as "no second
working path".

**Symfony's VarDumper - i.e. `dd()` and `dump()` - is not `var_dump()`, and `__debugInfo()` does
not hide anything from it.** `Caster::castObject()` does `(array) $obj` **and then** merges
`__debugInfo()` under separate *virtual* keys, so a redacting `__debugInfo()` adds a line rather
than replacing one: the real private property is printed next to `[redacted]`. Holding the value
in a closure instead of a property does not help either - VarDumper renders a closure's captured
`use` bindings, so it is *worse*. T-003 BUG-1. A caster works (its return value *replaces* the cast array) but only for cloners built
after it is registered, which is NOT the dump() helper's - see [[qa-negative-control-every-guard]].
What actually closes it is giving the object no state to show: T-003 ended up holding the
plaintext in a `WeakMap` keyed by the holder, so the object has no properties at all. That also
closed the `var_export()` residual, which no hook can reach — and it must be registered through the **static**
`AbstractCloner::addDefaultCasters()`, not the instance `addCasters()`: a freshly constructed
`VarCloner`, which is the only kind `dump()`/`dd()` ever build, copies the static defaults and
never sees an instance registration. Verify with a brand-new `VarCloner` after boot, and check
`reveal()`/`__toString()` still return the real value — a caster that broke the coercion would
fail closed and look like a tenancy bug. Clean on the same sweep, so the blast radius is exact:
PHP's own `var_dump()`, `print_r()`, `json_encode()`, Monolog's `LineFormatter`/`JsonFormatter`,
`config:show`, `about`, `serialize()` (throws) and both exception renderers.

**`config:cache` cannot see a runtime stanza, and swaps the container while proving it.**
`ConfigCacheCommand::getFreshConfiguration()` boots a **second `Application`** and caches *that*
one's config, so the `var_export()` residual on a credential value object is unreachable through
the framework however deep in a tenant scope the caller is. Verified by running it inside a
resolved scope: the cached file holds the placeholder stanza. Side effect that costs an hour if
you do not know it: `Application::__construct()` calls `Container::setInstance($this)` and the
command never restores the original, so after `Artisan::call('config:cache')` `app()` and every
facade point at the throwaway app - a tenant scope entered beforehand can no longer be left, and
the fixture file stays locked on Windows. Capture `Container::getInstance()` before the call and
restore it after.

**`tenant::read` / `tenant::write` used to survive `DB::purge('tenant')`.** `DatabaseManager`
caches them under those exact keys and `purge()` unsets only the name it is given, so such a
connection outlived its tenant scope *inside the manager*. Fixed in T-003 (review R-2): teardown
now purges every `tenant`/`tenant::*` key. Do not write a test that depends on the old survival -
I did, and it blocked the fix; see [[qa-assert-contract-not-symptom]]. The contract is "nothing
tenant-shaped outlives the scope, and an object held across a switch is refused".

**How to apply.** When re-testing this boundary: (1) probe every *construction* route, not just
`DB::connection()`; (2) probe leak surfaces by running them, never by reasoning about which magic
method each honours - the T-003 plan's residual list was assembled by reasoning and missed
`dd()`; (3) prove a stale-connection refusal is evidence by doing the same moves on an unguarded
connection name first, where the held object silently repoints itself at the new database and
returns its row. Full evidence: `docs/qa/T-003-tenant-connections.md`. Related:
[[qa-negative-control-every-guard]], [[service-host-fragile-areas]].
