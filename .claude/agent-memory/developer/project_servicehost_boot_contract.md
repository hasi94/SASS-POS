---
name: project-servicehost-boot-contract
description: Why FlexiPOS service packages boot via runtime PSR-4 registration instead of Composer's require graph, and what a service package must declare
metadata:
  type: project
---

`App\Support\ServiceHost\ServiceRegistry` boots services by obtaining Composer's
`ClassLoader` with `require base_path('vendor/autoload.php')` (never `require_once` —
that returns `true`, not the loader, after Laravel's bootstrap already required it;
Composer caches the instance in a static property so repeated `require`s return the same
object), calling `addPsr4()` at runtime, then `$app->register()` on the class named under
the package's own `extra.flexipos.provider` key.

Since T-002 the manifest is treated as untrusted input, with **no escape hatch and no
configuration switch**. In order: the `autoload.psr-4` map must hold **exactly one** pair
(`count($map) !== 1` is refused — "the first key is right" is not enough, or a manifest
smuggles `App\` into the second slot and a later refactor that iterates the map revives
the whole attack), that key must equal `Flexipos\Service\<Str::studly($name)>\` exactly,
`extra.flexipos.provider` must start with that same string, the class must satisfy
`is_a(..., Illuminate\Support\ServiceProvider::class, true)`, **and**
`(new ReflectionClass($provider))->isInstantiable()` — `is_a()` alone is happy with an
abstract subclass, and `$app->register()` then dies with a raw
`Cannot instantiate abstract class` that never names the service. Everything up to and
including the namespace/prefix checks runs *before* `addPsr4()`, so a rejected package
never leaves a prefix behind for another service's — or the host's own `App\` — namespace. A genuinely external integration belongs
in `plugins/*`, or behind a first-party `services/<name>/` wrapper that still declares its
own namespace.

**Why:** the acceptance criteria require that adding a service to `SERVICES` boots it with
zero edits to any file inside `services/host/`. A static `composer require` per service
would break that. The key is deliberately **not** `extra.laravel.providers`: Laravel's
package auto-discovery would then boot a service regardless of the `SERVICES` filter,
defeating the whole mechanism.

A service identifier must match `ServiceRegistry::NAME_PATTERN`
(`^[a-z][a-z0-9]*(-[a-z0-9]+)*$`, decision D-12). `SvcMakeCommand` reuses that constant
rather than restating the regex, and `ServiceRegistry` validates every `SERVICES` entry
against it at boot. Both ends must stay one constant: QA proved that a looser generator
rule lets `svc:make billing-` produce a Composer name Composer itself rejects and a
namespace that collides with `billing`, after which `/health/ready` reports a service
whose routes return 404. Directory existence is checked against a `scandir()` listing, not
`is_dir()`, so `SERVICES=Demo` behaves identically on Windows and on Linux.

`config:cache` is the one hazard guarded in code (`ServiceHostServiceProvider::
guardAgainstStaleCachedServices()`): it throws only when the configuration is cached AND
the ambient `SERVICES` is set AND it parses differently from the frozen value. The other
three caching constraints (`mergeConfigFrom` under cache, `route:cache`,
`--classmap-authoritative`) are **documentation only** by decision D-20 — do not add
runtime detection for them even though `ClassLoader::isClassMapAuthoritative()` exists.
All four are written up in `config/servicehost.php` and the root `README.md`.

**How to apply:** do not "simplify" this to a plain `composer require` or to Laravel
auto-discovery. If a service package will not boot, check that its `composer.json` has
exactly one `autoload.psr-4` pair and an `extra.flexipos.provider` class, not that it is
missing from the host's `require`. A service provider must never reference `App\*`
(Deptrac forbids service -> host dependencies), which is why generated controllers do not
extend the host's base `Controller`.

Related: [[project-backend-toolchain-constraints]].
