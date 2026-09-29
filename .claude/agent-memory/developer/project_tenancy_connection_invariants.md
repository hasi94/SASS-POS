---
name: project-tenancy-connection-invariants
description: Hard-won framework facts behind FlexiPOS's master/tenant connection boundary - what leaks, which guard level catches it, and what must never be "simplified"
metadata:
  type: project
---

Facts measured (not read in docs) while building the `master` + runtime `tenant` connection
boundary in `packages/kernel/src/Tenancy/`. Each one changed a decision or caught a leak.

- **`DatabaseManager::build()` / `connectUsing()` are a cross-tenant leak, not a modern
  convenience.** `build()` stores the whole config (password included) in the private
  `$dynamicConnectionConfigurations`, `configuration()` **prefers** it over
  `config('database.connections.*')`, and `purge()` never clears it - no public API does.
  Introducing it makes three switch tests go red (measured), because entering tenant B then
  reads tenant A's retained config. Use `config()->set(...)` + `DB::purge(...)` only.
- **`DB::purge()` alone is not enough; the per-query guard is the load-bearing part.** The
  manager's reconnector is `fn ($connection) => $connection->setPdo($this->reconnect(...)->getRawPdo())`,
  so a `Connection` object held across a tenant switch **silently repoints itself at the
  currently configured tenant** via `reconnectIfMissingConnection()`. Measured: with the
  `beforeExecuting`/`beforeStartingTransaction` guard removed, an object obtained for tenant
  A returns **tenant B's** rows. Removing `purge()` instead turns the switch into a loud
  refusal rather than a leak - the two mitigations are not interchangeable.
- **`DB::extend($connectionName, ...)` is the right choke point, but it is not every route.**
  `makeConnection()` consults name-keyed extensions before driver-keyed ones and before the
  factory, so it catches `DB::table()`, raw queries and Eloquent alike. Register it from
  `$this->callAfterResolving('db', ...)` so a DB-free request never instantiates the manager.
  **`DatabaseManager::connectUsing()` — and `build()`, which delegates to it — call
  `$this->factory->make()` directly and never reach `makeConnection()`**, so a name-keyed
  extension is invisible to them (QA BUG-2: measured serving tenant B's rows inside tenant A's
  scope with the context still reporting A, no exception). The only hook that covers *every*
  construction route is the `ConnectionEstablished` event, which `connectUsing()` dispatches
  **before** caching the connection — so throwing from that listener also prevents the
  unguarded object being installed under the connection name. Keep the extension attachment
  too (an `Event::fake()` would otherwise disable the guard) and de-duplicate with a
  `WeakMap<Connection, true>`.
- **A credential object must carry no state at all — not a property, not a closure.** The
  framework installs its dumper during *its own* provider registration and captures **one
  `VarCloner` instance** in the handler closure (`CliDumper::register()`); a cloner copies
  `AbstractCloner::$defaultCasters` at construction, so a caster registered by an application
  provider — which runs later — never reaches the live handler. Registration order cannot be
  won. What works regardless is holding the value in a `WeakMap` keyed by the instance
  (see `CredentialValues`), so `(array) $object` is empty and every inspector has nothing to
  render but the virtual key `__debugInfo()` adds. Measured through the real `dump()` in a
  child process: private property leaked, captured closure leaked, no-state did not.
  Keep the caster too, for cloners built after boot, but do not rely on it.
  **Known and accepted:** `CredentialValues` is one static WeakMap holding every live
  credential, reachable by reflection. Reviewed and judged not a regression — anyone who can
  run reflection in-process could already read `config('database.connections.tenant')` or the
  environment, and the map is weak, so it holds only what is currently live. Worth knowing if
  FP-203 ever widens what can execute in-process.
- **`__debugInfo()` does not cover `dd()`/`dump()`.** Those use Symfony's `VarCloner`, and
  `Caster::castObject()` does `(array) $obj` **and then merges** `__debugInfo()` under separate
  virtual keys — so the real property renders right next to the redacted one. Storing the value
  in a closure does not help either: the cloner renders a closure's captured `use` bindings. The
  only mechanism that works is a **VarDumper caster**, whose return value *replaces* the cast
  array. Register with `AbstractCloner::addDefaultCasters([...])` — the public **static** entry
  point; `addCasters()` is an instance method and a freshly constructed `VarCloner` only reads
  `$defaultCasters`. `addDefaultCasters` spread-merges by key, so re-registration is idempotent.
- **`#[\SensitiveParameter]` alone cannot close the debug-trace hole; a `Stringable` does.**
  Measured with `HtmlErrorRenderer(true)`: the frame that prints the whole stanza is the
  framework's own `call_user_func($this->extensions[$name], $config, $name)` inside
  `makeConnection()` — an internal variadic, so no attribute of ours marks it. The fix
  (architect ruling) is to store the stanza password as a `ConnectionPassword` value object:
  Symfony's flattener records an object as `['object', get_debug_type($value)]` and discards
  the value, while the weak-typed connector still coerces it via `__toString()` for
  `new PDO(...)`. Keep **both** mitigations — the attribute covers our frame, the object
  covers the framework's, and `TenantConnectionFailed`'s `previous: null` covers the
  connector chain. They are not redundant; they cover different frames.
- **`ConnectionPassword` must never be merged into `Secret`.** `Secret::__toString()` returns
  `[redacted]` (that is what makes an accidental interpolation harmless);
  `ConnectionPassword::__toString()` returns the real value (that is its whole purpose).
  `Tenancy` is the only code converting one to the other, so `Secret::reveal()` stays the
  single audited call site. `var_export()` does still show the value — hence no
  `__set_state()`, and never export a stanza holding one. `config:cache` is safe only because
  no tenant is ever entered in a config-caching process. Treat any "X is the *only* surface
  that leaks" claim as unproven until it has been measured; the last one was wrong.
- **The coercion dependency is acceptable because it fails closed.** If the framework adds
  `strict_types` or types `$password`, `new PDO(...)` raises a `TypeError` and nothing
  connects — loud on the first `composer update`, versus a dependency that quietly leaks.
  `TenancyFrameworkContractTest` names that contract so the failure reads as *the framework
  changed*, not *tenancy is broken*; reverting the one-line fix proves the separation (the
  tenancy catchers go red, the contract test stays green).
- **The transaction guard fires before any reconnect.** `Connection::beginTransaction()` runs
  the `beforeStartingTransaction` hooks and only *then* calls `createTransaction()`, which is
  where `reconnectIfMissingConnection()` lives — so a stale connection is refused while its PDO
  is still null and no handle is reopened. Pinned by an assertion, not just a comment
  (`getRawPdo()` is still null after the refusal).
  **This bullet previously said the opposite**, from reading `createTransaction()` in isolation
  and assuming the hooks were inside it; a defensive `disconnect()` was added to a test for a
  Windows file lock that never actually occurred. Re-measured for review R-4: removing it
  changes nothing. Read the *caller* before concluding what order two framework steps run in.
- **Scope everything tenant-shaped, and resolve the context *inside* the guard closure.** A
  plain singleton holding `TenantContext` survives `forgetScopedInstances()` and would keep
  matching a leaked config after a queue worker resets its scope.
- **Holding a `Secret`'s value in a closure rather than a property empties `var_export()` and a
  reflection walk — and nothing more.** It is one layer, not the guarantee: Symfony's
  `VarCloner` renders a closure's captured `use` bindings, so this design leaked straight
  through `dd()`/`dump()` until a caster was registered (QA BUG-1). Do not reason from "no
  property holds it" to "no dumper can show it"; that inference is what the defect was.
- **PHP 8.2's `print_r()`/`var_dump()` render a closure's captured `[static]` variables.** A
  credential-leak test that passes work through a helper closure capturing the needle reports
  a leak that has nothing to do with the value under test. Call the subject directly, or the
  harness confounds the result — this cost one false red.

**Why:** this is a security boundary - a mistake is one shop reading another's sales, not a
failing test. Every bullet was verified by breaking the code and watching a named test go red.

**How to apply:** before "simplifying" any of this, re-read the bullet that names the API you
are reaching for. Related: [[project-backend-toolchain-constraints]],
[[project-servicehost-boot-contract]], [[feedback-windows-line-endings-in-scripts]].
