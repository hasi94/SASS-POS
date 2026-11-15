---
name: http-test-seams
description: Laravel HTTP tests here - withHeaders leaks between requests, $this->postJson fails PHPStan, and how a test registers a throwaway guarded route
metadata:
  type: project
---

Three facts that cost reds in T-009 step 12, all about HTTP tests in `modules/host/tests/`.

**`$this->withHeaders([...])` persists for the rest of the test case.** It merges into the
test's default headers, so a later request with *no* `Authorization` still carries the one set
three requests ago. Two "no token -> 401" cases came back 200 and 403 for that reason. Use the
third argument instead: `postJson($uri, $data, $headers)` is per-request.

**`$this->postJson()` fails PHPStan here**, 45 errors of `Call to an undefined method
PHPUnit\Framework\TestCase::postJson()` - larastan does not see the `uses(TestCase::class)->in()`
binding in `tests/Pest.php`. Existing files work around it with `/** @var TestCase $this */` per
closure. The cleaner fix is the Pest Laravel plugin's global functions, imported explicitly:
`use function Pest\Laravel\postJson;` - same signature `(string $uri, array $data, array $headers)`,
and `get`, `getJson`, `post` exist too. `$this->postJson` and the global are otherwise identical.

**A test may register its own route at runtime** - `Route::middleware(SomeMiddleware::class)->post('/t9/probe', fn () => ...)`
inside the test body, before the request. Each test gets a fresh application, so it cannot leak.
A closure action is fine: the "never an inline closure" rule (T-004 AC-13) is asserted against the
*source of `modules/host/routes/web.php`* only, and `RouteCachingTest` actually requires at least
one closure route to exist in the live table.

Route URIs from `Route::getRoutes()` have **no leading slash** (`api/v1/auth/login`).

See [[middleware-mutations-need-the-right-case]] for mutating a guard like this.

**Nested Artisan output (T-011, 2026-10-21).** When a command calls another
command (the real tenant applier runs `migrate` per file), `Artisan::output()`
returns the NESTED call's output, not the outer command's. Pass a
`Symfony\Component\Console\Output\BufferedOutput` as the third argument to
`Artisan::call()` and read `->fetch()` to get the outer command's own lines.
