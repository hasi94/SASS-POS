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
  walking `vendor`, and `storage/` is a safe place for lint fixtures. Pint does **not**
  touch `*.stub` files, so a stub must be hand-written in the exact shape Pint wants for
  the generated `.php` output, or `composer lint` fails on the generated package.
- **PHPStan cannot see a Pest closure's real `$this`.** Pest annotates its own
  `it()`/`beforeEach()` with `@param-closure-this PHPUnit\Framework\TestCase`, so once a
  `tests/` directory is in `phpstan.neon.dist`'s `paths`, every `$this->get()` is
  `method.notFound` at level 6. The fix that keeps the level and adds no dependency is
  Pest's already-installed Laravel plugin: `use function Pest\Laravel\get;` (typed
  `TestResponse` return). Not an inline `@var`, not a baseline.
- **PHPUnit's duplicate-collection warning is emitted at collection time, before
  filtering**, so a child run with a filter that matches nothing still surfaces it
  (`... as it was already added to test suite "Host"`). That is how a test asserts the
  absence of the warning without shelling an unfiltered full run and recursing forever.
- **Under a cached config Laravel never loads `.env`**, so `Env::getRepository()->get()`
  then sees only the real process environment. `Symfony`/Laravel `Process::env([...=>
  false])` removes a variable from the child, which is how the "no ambient value" case is
  testable. `Env::getRepository()->get()` returns the raw string; `env()`/`Env::get()`
  coerce `"true"`/`"false"`/`"null"`/`"empty"`.
- **PHPStan level 6 does not flag an untyped *closure* parameter**, only functions and
  methods; a probe meant to prove a path is analysed must use something like a missing
  iterable value type or a wrong argument type.
- **Never `md5_file()` generated output against a checked-out tree.** `svc:make` always
  writes LF (`SvcMakeCommand::stub()` strips CRLF), but only `services/host/` has a
  `.gitattributes` and `core.autocrlf` is `true` on this machine, so a fresh clone or a
  branch switch materialises `services/demo` as CRLF and every raw-byte comparison goes
  red for someone who changed nothing. Hash
  `md5(str_replace("\r\n", "\n", file_get_contents(...)))` whenever the two sides came
  from different producers; raw `md5_file()` is only safe when comparing a tree against
  itself (before/after a move, a rollback, an AC-27 host fingerprint). A root
  `.gitattributes` is the durable fix and landed in T-004 as commit `643e220`.
  **Second half of the rule, and the part that is easy to get backwards: which hash is right
  depends on what the comparison decides, not only on where the two trees came from.** For an
  *assertion* that two trees match, normalise - a CRLF checkout must not fail the gate. For a
  *decision to delete* one of them, hash raw bytes even across different producers: raw
  equality is strictly stronger, so a false negative merely keeps a redundant copy while a
  false positive destroys the only one. T-004 QA BUG-4 was exactly that - a teardown comparing
  normalised content under a comment claiming it compared bytes.

- **`tests/Helpers.php` is loaded before `tests/Pest.php`, and both run before the
  application exists.** Pest's `BootFiles` bootstrapper walks a fixed `STRUCTURE`
  (`Expectations`, `Expectations.php`, `Helpers`, `Helpers.php`, `Pest.php`) on every
  command, including a single-file run — so a helper `Pest.php` itself calls belongs in
  `Helpers.php`, and it must **not** use `base_path()`/`storage_path()`. Derive paths from
  `__DIR__` instead. Same trap in Pest's `beforeAll`/`afterAll`: they run outside the
  application lifecycle, so the `File` facade and every path helper are unavailable there —
  memoise the path on the first `beforeEach` or use native `glob`/`unlink`/`rmdir`.
- **`pest --list-tests` and `--list-groups` exit 0 and perform full collection without
  executing anything.** That is how a group's membership can be proved from inside the suite
  for ~1 s per child, instead of re-running the group (≈50 s) or parsing a JUnit log that
  only records tests that actually ran. Class names come back as `P\<namespace>\<Basename>`.
  Pest also **rejects `--no-ansi`** ("Unknown option") — the flag is `--colors=never`, which
  is what the existing child-run helpers use.
- **`git add --renormalize .` re-adds every tracked file matching the pathspec**, so it also
  stages whatever unrelated edit another role has in flight (it staged the lead's
  `docs/backlog.md` change). Check `git status --porcelain` afterwards and unstage anything
  that is not a line-ending change before the commit. It also aborts partway with `unable to
  stat` if a tracked file is deleted in the working tree but not yet staged. And
  `git clone --local` from `D:` into a `C:` temp directory fails with `Improper link` —
  `--no-hardlinks` is required for any cross-drive clone on this machine.

**Why:** each was found by running the tool, not by reading its docs; four of them changed
a decision that had been written down differently.

**How to apply:** check these before "fixing" the corresponding config, and before
proposing a glob, a `.dist` rename, a version-field removal or a `Pest.php` `->in()`
addition. Related: [[project-servicehost-boot-contract]].
