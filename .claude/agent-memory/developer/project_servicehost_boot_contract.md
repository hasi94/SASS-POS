---
name: project-servicehost-boot-contract
description: Why FlexiPOS service packages boot via runtime PSR-4 registration instead of Composer's require graph, and what a service package must declare
metadata:
  type: project
---

`App\Support\ServiceHost\ServiceRegistry` boots services by finding Composer's
`ClassLoader` through `spl_autoload_functions()`, calling `addPsr4()` at runtime, then
`$app->register()` on the class named under the package's own
`extra.flexipos.provider` key.

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

**How to apply:** do not "simplify" this to a plain `composer require` or to Laravel
auto-discovery. If a service package will not boot, check that its `composer.json` has
exactly one `autoload.psr-4` pair and an `extra.flexipos.provider` class, not that it is
missing from the host's `require`. A service provider must never reference `App\*`
(Deptrac forbids service -> host dependencies), which is why generated controllers do not
extend the host's base `Controller`.

Related: [[project-backend-toolchain-constraints]].
