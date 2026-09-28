---
name: project-backend-toolchain-constraints
description: Non-obvious constraints of the FlexiPOS backend toolchain (Deptrac, PHPStan, PHPUnit, Pint, Pest, Composer path repos) that shaped services/host and will bite again
metadata:
  type: project
---

Toolchain facts discovered while building and then QA-fixing the `services/host` skeleton
(T-001). They are not visible from reading the config files, so they get relitigated
otherwise.

- **Globbing is per-tool, not a project style choice.** `phpunit.xml` `<directory>`
  **does** expand `../*/tests`; PHPStan `paths` and Deptrac `paths` **reject** wildcards
  outright (`Path ...\services\*\src does not exist` / `InvalidPathException`). Tested on
  PHPStan 2.2 and Deptrac 2.0.4. So `phpunit.xml` is self-maintaining and the other two
  stay literal, guarded by `QaToolchainRegistryCoverageTest`. A Deptrac layer also cannot
  be globbed by design - it is a named, ruleset-addressable unit.
- **A `../*/tests` glob also matches `../host/tests`.** PHPUnit de-duplicates the files but
  emits one WARN line per file while doing it; add `<exclude>../host/tests</exclude>` to
  keep gate output readable.
- **PHPUnit's `<env force="true">` cannot pin a variable for Laravel.** It writes `putenv()`
  and `$_ENV` but never `$_SERVER`, and Laravel's Env repository reads `$_SERVER` first, so
  an ambient shell variable still wins and kills the suite in `setUp()`. Pin it in
  `TestCase::setUp()` by copying `$_ENV` over `$_SERVER` before `parent::setUp()`.
- **Deptrac cannot read a `.dist` config file.** Only a `.yaml`/`.yml` extension loads;
  built-in default is `deptrac.yaml`. PHPStan has no such problem, hence
  `phpstan.neon.dist` but plain `deptrac.yaml`.
- **Composer path packages need an explicit `version` field.** Without one Composer derives
  `dev-<current git branch>`, which fails `minimum-stability: stable` and makes
  `composer.lock` branch-dependent. Everything under `packages/*` and `services/*` pins
  `"version": "0.1.0"`.
- **Pest refuses to bind the same TestCase to one file twice.** A service package's test
  binds `Tests\TestCase` itself, so `tests/Pest.php` must NOT list that directory in
  `->in()`.
- **Pint already excludes `vendor`, `storage` and `bootstrap/cache`** when given a
  directory, so `pint --test .. ../../packages` covers every service and package without
  walking `vendor`, and `storage/` is a safe place for lint fixtures.

**Why:** each was found by running the tool, not by reading its docs; four of them changed
a decision that had been written down differently.

**How to apply:** check these before "fixing" the corresponding config, and before
proposing a glob, a `.dist` rename, a version-field removal or a `Pest.php` `->in()`
addition. Related: [[project-servicehost-boot-contract]].
