---
name: t010-mutation-survivors-and-harness
description: T-010 QA - which kinds of mutant survived a green, well-commented dev suite (so look there first next time), the python mutation driver, and the two gate-run hazards that cost time
metadata:
  type: project
---

**What survived the dev suite on T-010 (1497 tests green, every file self-describing its controls).** Run mutants across the whole feature package before trusting any "control named in text":

- **An ordering the criterion states but the dataset omits** (AC-42/44: `unknown_permission` must beat `employee_unavailable`). Moving the subject check ahead left 10/10 resolver tests green. Look for "the table is printed" criteria and diff the printed rows against the rows the criterion text names.
- **A log level** (D-26 "log at error"): nothing asserts levels. Downgrade `error` to `debug` and see.
- **The secret that is ATTEMPTED, not SET.** The sink harness made the sentinel the PIN being set; a failed attempt leaking the attempted value into `access_log.detail` was only caught by the slice test. Ask which value the harness scans for versus which value the secret-bearing operation receives.
- **A sweep over freshly created subjects** (AC-53): `DeleteEmployee` deleted an employee the sweep had just created (holds nothing, no cell moves) and `SetRolePermissions` edited a role nobody held, so removing either bump left the sweep green. A sweep needs subjects that already hold something, per operation.
- **The unhappy input of a refusal's own rule** (AC-51): only the correct PIN was tried during a lock; a wrong PIN that counts and extends the lock survived 22/22.
- **A field only the HTTP/auth seam writes** (`login_history.tenant_id` on the SELECT path): 95 tests green with it null. Per-cause, per-endpoint tests are needed where the handler tests stop at the exception.
- **A route re-declared with the permissive marker** (`authenticated_only`): every dev HTTP loop skips such routes, so nothing noticed. Pin the skip-list by name.
- **Indirect reach** (AC-49): a text scan of the controller file misses a handler that depends on the forbidden handler. Walk the constructor-dependency graph instead and give it direct and indirect scratch controllers as liveness.
- **Shared-reader forks:** the resolver filters soft-deleted roles, `PermHash::contributionsFor` (claims, hash, my-permissions) does not. Where two paths read "what a grant covers", test them against one fixture of the odd state.

**Mutation driver (works, Pest-safe, no production edit).** `scratchpad/mutlib.py` `run_mutant(name, src, fqcn, old, new, tests)`: copies the file, replaces exactly one occurrence (errors on 0 or >1), writes `phpunit.qamut.xml` whose `bootstrap=` is a scratch file that requires vendor/autoload.php and `spl_autoload_register(..., true, true)` serves the copy for env `QA_MUTANT_CLASS`; prints `served=yes|NO` from a shutdown hook; deletes the xml in `finally`. Drive it from a python list, not bash (shell quoting of `$`, backslashes and newlines produced `served=NO` and a meaningless green twice). Non-class files (routes, migrations, `.env.example`) are edited in place and restored with `git checkout`, then `git diff --stat` on production.
- Once a deliberately red QA test exists in the package, every package-wide mutation run reports "N failed" with that one included: subtract it, and read the FAILED names.
- Package-wide run of `packages/access/tests` is ~45 s here; a host Qa* file is 2-10 s.

**Never overlap runs.** `composer check` started with `&` from the Bash tool returns at once but keeps running; a second `composer check` (or any pest run) at the same time collides on the shared fixtures. Measured: the second gate failed Pint with "SplFileObject::fwrite Permission denied" and then 1105 tests failed because a concurrent run had deleted `modules/demo` (the module-regeneration tests rebuild it); `git checkout -- modules/demo` restored it. One gate/pest process at a time, wait for the notification.

**Pest/PHPStan traps met again:** Pint rewrites FQCNs and reorders imports in new tests (run `pint` on them); PHPStan flags `?->` on a non-null property and undefined properties on an anonymous `AbstractLogger` subclass (use a named final class); `array` params need `@param array<string,string>`. Laravel's `TrimStrings` trims a JSON body value before a PIN policy sees it, so `"7391 "` is accepted as `7391`.

Related: [[t009-auth-cross-cutting-proofs]], [[t007-measurement-and-test-traps]], [[qa-negative-control-every-guard]].
