---
name: t009-auth-cross-cutting-proofs
description: T-009 QA techniques that worked and traps measured - sink-capture harness through real writers, mutating prod via a phpunit bootstrap autoloader, Pest rewriting exception file/line, router-cached controllers, logger recorder blind spots
metadata:
  type: project
---

**Mutating production without editing it (works, Pest-safe).** `-d auto_prepend_file` that requires vendor/autoload.php makes Pest print NOTHING and exit 1. What works: copy `phpunit.xml` to `modules/host/phpunit.qamut.xml` with `bootstrap=` pointing at a scratch file that `require`s vendor/autoload.php, then `spl_autoload_register(fn, true, true)` serving a mutated copy of ONE class chosen by env `QA_MUTANT`; run `pest --configuration=phpunit.qamut.xml`. Always run a byte-identical mutant first (must stay green) and print `served=yes` from a shutdown function (an unserved mutant is a green lie). Delete the xml before the gate. Scratch copies live in the session scratchpad, not the repo.

**A controller is a blind spot for slice tests.** Mutating `SelectTenantController` to read `tenant_id` as the code left all 31 slice tests green; only an HTTP test sending the extras reddened. Thin controllers need their own wire-level test for every "ignored field" criterion (AC-10/38/43). Likewise deleting `LoginController`'s duplicate validation leaves every test green.

**Traps measured on T-009.**
- `authFixtureRecordingLogger()` records only constructor-injected `LoggerInterface`; `Log::`, `logger()` and `app('log')` bypass it (Container::instance drops the alias). Its doc comment claims otherwise. For a sink scan, redirect `logging.default` to a `single` file channel and read the bytes back; it sees all routes and the formatter's JSON escaping and traces.
- A scan for a raw sentinel misses JSON-escaped forms. Make the sentinel contain `/` and `"` and scan raw + json_encode (slashes escaped and not) + url-encoded; prove it with a control that goes through a real writer (`security_events.detail`, `Log::error` context).
- Pest rewrites an escaping exception's own `getFile()/getLine()` to its `postJson` wrapper (Http.php:219). Use trace frames, not `getFile()`.
- The router caches a route's controller instance after first use: an `app()->bind()` made after the first request to that route is never consulted. Do the binding-based control before any request.
- `withServerVariables` must be imported as `Pest\Laravel\withServerVariables` (not `test()->`), or PHPStan fails method.notFound.
- Pint's strict-types fixer rewrites FQCNs in return types into imports; PHPStan then needs `@return TestResponse<Response>`.
- Exhaustiveness over a directory (`glob` of the exceptions folder vs the list driven) is how to make "a seventh type is mapped" executable without reading source.

**Findings carried for the next auth task.** AC-22: increment is one UPDATE but the lock decision is a separate SELECT plus unconditional reset, so "lock applied once" is not structural. Kernel's `TenantDatabaseNotActive` renders 500 (no renderer, unlike `TenantSchemaBehind`). CORS is the framework default `*` on api/*. `login_history.email` stores whatever was typed in the email field (a password typed there is kept, by AC-23). AC-52's literal sentence is broken by five edited pre-existing T-008 test/support files (count and list updates plus the 5b guard strengthening).

Related: [[t008-built-but-not-wired]], [[qa-negative-control-every-guard]], [[qa-assert-contract-not-symptom]].
