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
- **The one real choke point is a decoration of `db.factory`, not `DB::extend()` and not an
  event.** MEASURED on v12.69.2: every construction route funnels into
  `ConnectionFactory::make()` - `connection()` → `makeConnection()`, `connectUsing()` directly,
  `build()` → `connectUsing()`, a reconnect after `purge()`, and the read/write split as make()'s
  own branch. `DatabaseManager::__construct($app, ConnectionFactory $factory)` takes the factory
  by injection, so the decorator **must** subclass `ConnectionFactory` and **must** be registered
  in `register()` before anything resolves `db`. Delegate to the inner factory, never
  `parent::make()`, or another extender of `db.factory` is silently discarded. Residual, measured:
  `makeConnection()` still consults `$extensions[$name]` in its first branch, *before* the
  factory call, so a name-keyed extension would bypass it - pinned by a standing test asserting
  none is registered under the tenant name. Cite that by symbol: the line was :195 when this was
  written and :192 on v12.69.2, and no test checks a line number.
- **Binding a stanza to its own content is the only thing that detects a forged one.** MEASURED
  (T-007 R1, both designs run side by side): a sentinel object in a private `WeakMap` *accepts*
  the "copy every value, change the database" forgery, because an array copy carries the same
  object instance - identity is not authority. A random token stored only in the stanza fails the
  same way. What works is a per-`enter()` token recorded against a digest of the whole stanza.
  Two keys must be excluded from that digest and no others: the token itself, and **`name`** -
  `DatabaseManager::build()` writes `name` into the stanza **whether the caller supplied one or
  not** (`$config['name'] ??= static::calculateDynamicConnectionName($config)`, which invents
  `dynamic_<md5>` when none is given) and `ConnectionFactory::parseConfig()` adds it again inside
  `make()`, so with `name` in the digest a legitimate `DB::build()` is refused either way - named
  or unnamed. **The false version of that sentence - "build() makes the caller supply it" - had
  to be corrected three times on one task**: the authority's docblock, this file, and the forgery
  test that is the mechanism's published proof. Correcting a framework claim in the class does
  not finish the job; sweep every copy with whitespace collapsed, agent memory included.
  Excluding it is what creates the accepted residual "a genuine stanza rebuilt under another
  connection name", which the per-query guard still contains.
- **`Event::fake()` retains every `Connection` its test opened.** The fake records all dispatched
  events and `ConnectionEstablished` carries the connection object, inside a reference cycle with
  the application - so SQLite file handles are still open when a fixture's `afterAll` unlinks its
  files, and on Windows that is a warning plus a directory left behind. `gc_collect_cycles()`
  before the deletion clears it. Worth knowing before blaming the fixture.
- **HISTORICAL, and the reason the bullet above exists: `DB::extend($connectionName, ...)` plus a
  `ConnectionEstablished` listener was the previous design, and both are gone (T-007 FP-160).**
  Do not go looking for them in the code; do read this before proposing either again.
  `makeConnection()` consults name-keyed extensions before driver-keyed ones and before the
  factory, so an extension catches `DB::table()`, raw queries and Eloquent alike — but
  **`DatabaseManager::connectUsing()`, and `build()` which delegates to it, call
  `$this->factory->make()` directly and never reach `makeConnection()`**, so a name-keyed
  extension is invisible to them (QA BUG-2: measured serving tenant B's rows inside tenant A's
  scope with the context still reporting A, no exception). The listener was added to cover those
  two, because `connectUsing()` dispatches the event *before* caching the connection — and then
  `Event::fake()` swaps the `events` binding the manager resolves per dispatch, which silently
  disabled a security guard (T-007 AC-27, measured returning tenant A's own row inside tenant B's
  scope). Keeping both attachments needed a static `WeakMap<Connection, true>` to de-duplicate,
  and neither covered a connection built straight from `app('db.factory')`. One factory decoration
  replaces all of it with no event dependency and nothing to de-duplicate.
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
[[project-modulehost-boot-contract]], [[feedback-windows-line-endings-in-scripts]].
