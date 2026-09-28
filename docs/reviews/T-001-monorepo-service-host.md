# Code review — T-001 monorepo, backend toolchain and service host skeleton

| | |
|---|---|
| Verdict | **APPROVE** |
| Date | 2026-09-28 |
| Reviewer | `code-reviewer` (read-only) |
| Branch | `ai/T-001-monorepo-service-host` |
| Gate at review time | `composer check` exit 0 — Pint passed, PHPStan level 6 no errors, Deptrac 0 violations, Pest 74 passed (222 assertions), 0 skipped |
| Preceding QA | run 1 FAIL (31/32, 4 defects) → run 2 **PASS** (32/32, 0 open defects) |

No Critical findings. The boot mechanism, the generator and the gate do what the spec and plan say, and the fixes for BUG-1 to BUG-5 are real fixes rather than green-washing. Nine Major findings are worth scheduling, several before the first real service is built on this skeleton, but none is a correctness, security or data-safety defect in the shipped behaviour and none violates the agreed design. Because there are no Critical findings, no `R-n` items were raised against `dev` and no `[FIX]` task was created.

---

## Critical

None.

---

## Major — schedule, do not block the commit

| # | Location | Problem | Fix |
|---|---|---|---|
| M-1 | `ServiceRegistry.php:122-126` | The provider named in the manifest is instantiated without checking it is a service provider. `class_exists()` is the only gate, so a manifest naming any autoloadable class gets it constructed with the container and then dies with a raw fatal that does not name the service — the opposite of D-1. QA covered "provider missing" but not "provider is the wrong kind of class". | Add an `is_a($provider, ServiceProvider::class, true)` check throwing `UnknownServiceException`. |
| M-2 | `ServiceRegistry.php:115-120` | The PSR-4 prefix from the manifest is trusted verbatim and never tied to the service name. A copy-pasted package keeping another service's namespace, or one declaring `App\`, silently adds a second source directory for someone else's namespace. `addPsr4()` also runs before the `class_exists()` gate, so a package that fails validation leaves its prefix registered for the rest of the process. | Require the declared namespace to equal `Flexipos\Service\<Studly>\` and the provider to start with it; move `addPsr4()` after validation. |
| M-3 | `ServiceRegistry.php:37` `NAME_PATTERN` | The pattern is bypassable with a trailing newline: PCRE's `$` matches before a final newline, verified on PHP 8.2.12. The registry is accidentally safe because `parse()` trims first; the generator is not, so a crafted name can create a directory the registry can never boot. Same defect class as BUG-2. | Add the `D` modifier. One character, inherited by both call sites through the shared constant. |
| M-4 | `SvcMakeCommand.php:86-96` | Generation is not atomic. A missing stub or a write failure part-way through the six-file loop leaves a half-made package, and the next attempt refuses with "already exists. Nothing was created or modified", which is then untrue. | Wrap the write loop and delete the target directory on any throwable, or render into a temp directory and move on success. |
| M-5 | `routes/web.php:10-11` | Health endpoints inherit the `web` middleware group, so liveness depends on the session store. With the shipped `SESSION_DRIVER=database`, `/health/live` opens a database connection and returns 500 when the database is down, so an orchestrator would kill a healthy process. Invisible to the suite only because the test config uses the array driver. Contradicts D-7. | Register the health routes outside the web group, and add a test that boots with a database session driver and no sessions table and still gets 200. |
| M-6 | `config/servicehost.php:20`, `ServiceHostServiceProvider.php:22-24` | `config:cache` silently freezes `SERVICES`, defeating the headline guarantee of the task: different stages running different service subsets from one image. `mergeConfigFrom()` is also a no-op under a cached config, so a service booted that way never contributes its connection. `route:cache` freezes routes the same way and currently fails outright because of a closure route. | Throw when the configuration is cached and the ambient `SERVICES` disagrees with the cached value. Document the rule. |
| M-7 | `phpunit.xml:8-13, 28-31` | The `<exclude>` is correct for what QA tested and hides nothing today, but with the host's tests excluded from the only globbed suite, a future host test directory would be collected by nothing and never run. That is BUG-1 relocated from services to the host. | Collapse the two literal host suites into one `tests` suite, or assert collection in the guard test. |
| M-8 | `README.md:55` | States "no file inside `services/host/` needs to change", which is true for booting and false for a real service: the static analysis and boundary configurations both live there and both must be edited. The README contradicts the reminder `svc:make` prints. | Name the two files and the layer plus ruleset entry, and point at the guard test. |
| M-9 | `ServiceRegistry.php:225-234` | The runtime-autoloading design is silently incompatible with `--classmap-authoritative`, where the loader returns nothing for anything outside the classmap. An image built that way fails every service with a baffling message. | Document the constraint and add it to the container work's checklist; consider detecting it and naming the flag in the error. |

---

## Minor

Test directories are excluded from both static analysis and the boundary check, so a service's test file could import another service with no violation. The coverage guard skips any package whose name *contains* `fixture`, which a legitimate future service could match. Tests generate real packages inside the working tree, so a killed run leaves residue and concurrent runs collide. Routes registered after the app has booted are not name-refreshed, harmless today because the stub uses URLs. The directory listing is re-scanned once per name and could be memoised. `servicePath()` is public and concatenates an unvalidated string. The generated connection falls back to the global or root database user, defeating the per-service grant model. The readiness endpoint is unauthenticated and enumerates internal topology, which the gateway work should restrict. Stock Laravel scaffolding remains, including a welcome view, a closure route that alone makes route caching throw, front-end build files, and an unmodified Laravel README whose MIT statement contradicts the proprietary licence in the manifest. The plan still names a `.dist` boundary config while the tree ships the real filename.

## Test honesty

The suite is genuinely good: it asserts observable behaviour through HTTP status and exact JSON, loaded-provider lists, route counts, exit codes, file checksums and child-process runs. Proving the formatter and boundary checker with committed fixtures that actually make the tools fail is the right approach, as is the checksum proof that the generator writes nothing into the host. Named exceptions: one test asserts only that the framework's string helper works and runs no project code; two stock Laravel example tests inflate the count; the shared-constant assertion is close to asserting the implementation back to itself and would be stronger driving both validators over one table of names; the five malformed-manifest tests assert only that the name appears in the message, so collapsing all five messages into one generic string would keep them green; and the generated stub's readiness test promises more than it asserts, which matters because it ships into every future service.

## Judgement calls

**Reaching into the autoloader stack to find Composer's class loader** is legitimate and widely used, and the not-found case is handled explicitly. Two things weaken it: it takes the first matching loader, which becomes ambiguous once the plugin system adds a second, and it duplicates something Composer already offers, since requiring the autoload file returns the same cached instance deterministically. That is a one-line change with no behaviour change. The real maintenance hazard is not the lookup but the three things around it: unvalidated provider type, unvalidated namespace, and the silent authoritative-classmap incompatibility.

**Manual static analysis and boundary registries guarded by a red test plus a printed reminder** is the right shape and sufficient. The constraint is permanent, not deferred, since neither tool expands wildcards. A red gate beats a reminder, and the guard is well built: it reads all three registries, resolves paths properly, derives the probe class from the package's own manifest rather than a naming convention, and fails safe if the configuration is reformatted. Anything heavier, such as a generator that rewrote those files, would reintroduce the coupling the zero-edit criterion exists to prevent. It needs two cheap hardening edits first: close the name-contains-fixture escape hatch, and fix the README that currently tells the developer the opposite of the reminder.

## Done well

Fail-fast carrying the offending name through every malformed-manifest path, so the decision is honoured in code rather than prose. Using a custom manifest key instead of the framework's auto-discovery key, which is the detail that stops a service booting when it was not asked for, with a docblock explaining why so it stays true. Making Windows give the Linux answer on directory casing, above what the spec required, killing a class of works-locally-breaks-in-a-container bugs before the first container exists. One name pattern shared by generator and registry so the two cannot drift. Proving the generator touches nothing in the host by checksum rather than by policy. Clearing the configuration cache before the test step, which is the one place the caching hazard could have poisoned the gate. Comments that explain why, which is what stops the next developer simplifying the sharp parts.

## Recommended follow-ups

Recorded in the backlog as FP-146 to FP-153. FP-143, the boundary-check package rename raised by the architect, remains valid.
