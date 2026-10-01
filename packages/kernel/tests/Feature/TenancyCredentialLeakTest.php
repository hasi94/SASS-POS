<?php

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionFailed;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionMismatch;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Tests\TestCase;

/*
 * AC-12, AC-13, AC-14.
 *
 * One distinctive fake password, one sweep over every refusal path plus a forced
 * connection failure, and three places it must never turn up: an exception (its
 * message, its string form, its trace, anywhere in its previous chain), a log
 * record, and rendered debug output.
 *
 * The fixture helpers come from ../Support/tenancy-fixture.php, required below;
 * every tenant in that mapping resolves its credential to
 * tenancyFixturePassword(). This file used to rely on TenancyResolutionTest.php
 * having been collected first, so running it on its own died on an undefined
 * function (T-004 FP-164).
 */

require_once __DIR__.'/../Support/tenancy-fixture.php';

uses(TestCase::class);

/**
 * Run the callback, swallow whatever it refuses with, and return it.
 */
function tenancyRefusalOf(Closure $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

/**
 * Every log record written while the callback runs.
 *
 * @return list<string>
 */
function tenancyCaptureLog(Closure $callback): array
{
    $records = [];

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$records): void {
        $records[] = $logged->level.' '.$logged->message.' '.(string) json_encode($logged->context);
    });

    tenancyRefusalOf($callback);

    return $records;
}

/**
 * Everything an exception can be made to say: its own string form, its trace,
 * and the message and trace of every exception in its previous chain.
 *
 * @return list<string>
 */
function tenancyExceptionSurface(Throwable $exception): array
{
    $surface = [(string) $exception, $exception->getTraceAsString()];

    for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
        $surface[] = $current->getMessage();
        $surface[] = $current->getTraceAsString();
    }

    return $surface;
}

/**
 * Whatever the application would show a developer with APP_DEBUG=true - through
 * the real handler, and through Symfony's fallback renderer, which is the one
 * that prints argument *values* rather than types.
 *
 * @return list<string>
 */
function tenancyRenderedSurface(Throwable $exception): array
{
    config()->set('app.debug', true);

    $rendered = [(new HtmlErrorRenderer(true))->render($exception)->getAsString()];

    $failure = tenancyRefusalOf(function () use ($exception, &$rendered): void {
        $rendered[] = (string) app(ExceptionHandler::class)
            ->render(Request::create('/tenancy-probe'), $exception)
            ->getContent();
    });

    if ($failure instanceof Throwable) {
        // A renderer that cannot render is not a leak, but record what it said
        // so a framework upgrade cannot silently skip this half of the check.
        $rendered[] = $failure->getMessage();
    }

    return $rendered;
}

/**
 * The refusals AC-12 to AC-14 sweep over: no mapping (AC-9), two non-active
 * statuses (AC-10), an invalid identifier (AC-11), a disallowed driver, and a
 * database that cannot be opened at all.
 *
 * @return array<string, string>
 */
function tenancyLeakProbeTenants(): array
{
    return [
        'no mapping' => 'tenant-does-not-exist',
        'suspended' => 'tenant-suspended',
        'provisioning' => 'tenant-provisioning',
        'invalid identifier' => '   ',
        'disallowed driver' => 'tenant-wrong-driver',
        'unopenable database' => 'tenant-unopenable',
    ];
}

beforeEach(function () {
    tenancyBootFixture();
});

afterEach(function () {
    tenancyForgetFixture();
});

// T-004 FP-155 (AC-6): the two shared SQLite files are removed once per file, not
// once per case. Native calls only - afterAll runs outside the application.
afterAll(function () {
    tenancyDeleteFixtureRoot();
});

it('keeps the credential out of every exception every refusal path raises', function (string $tenantId) {
    $refusal = tenancyRefusalOf(function () use ($tenantId): void {
        tenancy()->enter($tenantId);
    });

    expect($refusal)->toBeInstanceOf(Throwable::class);

    foreach (tenancyExceptionSurface($refusal ?? new RuntimeException('nothing was refused')) as $text) {
        expect($text)->not->toContain(tenancyFixturePassword());
    }
})->with(tenancyLeakProbeTenants());

it('keeps the credential out of every log record every refusal path writes', function (string $tenantId) {
    $records = tenancyCaptureLog(function () use ($tenantId): void {
        tenancy()->enter($tenantId);
    });

    // Most refusals write nothing at all, which is itself right: a refused
    // resolution is not an incident to be recorded together with its
    // configuration. The one path that does log is asserted at the bottom of
    // this file.
    expect(implode("\n", $records))->not->toContain(tenancyFixturePassword());
})->with(tenancyLeakProbeTenants());

it('keeps the credential out of debug output rendered for a refusal', function (string $tenantId) {
    $refusal = tenancyRefusalOf(function () use ($tenantId): void {
        tenancy()->enter($tenantId);
    });

    expect($refusal)->toBeInstanceOf(Throwable::class);

    foreach (tenancyRenderedSurface($refusal ?? new RuntimeException('nothing was refused')) as $output) {
        expect($output)->not->toContain(tenancyFixturePassword());
    }
})->with(tenancyLeakProbeTenants());

it('drops the driver exception entirely when a tenant database cannot be opened', function () {
    $refusal = tenancyRefusalOf(function (): void {
        tenancy()->enter('tenant-unopenable');
    });

    expect($refusal)->toBeInstanceOf(TenantConnectionFailed::class);

    // AC-12: previous is null on purpose. The driver exception's trace carries a
    // Connector::createConnection($dsn, array $config, $options) frame whose
    // $config array holds the plaintext password, and Symfony's
    // FlattenException::flattenArgs() copies string argument values verbatim
    // into a flattened trace.
    expect($refusal?->getPrevious())->toBeNull();

    $message = (string) $refusal?->getMessage();

    expect($message)->toContain('tenant-unopenable')
        ->and($message)->toContain('Could not open the database')
        ->and($message)->not->toContain(tenancyFixturePassword());
});

it('leaves no tenant resolved and no configuration behind after a connection failure', function () {
    tenancyRefusalOf(function (): void {
        tenancy()->enter('tenant-unopenable');
    });

    expect(tenancy()->current()->hasTenant())->toBeFalse()
        ->and(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza());
});

it('redacts the credential if a driver message ever quotes it back', function () {
    // The sanitiser is not decoration: a driver is free to echo connection
    // parameters into its error text. Making the unopenable database path *be*
    // the credential value forces the driver to name it, and the refusal must
    // still not contain it.
    $password = tenancyFixturePassword();

    config()->set('tenancy.databases.tenant-echo', [
        'driver' => 'sqlite',
        'host' => 'db-fixture-a',
        'port' => 3307,
        'database' => $password,
        'username' => 'flx_ta_app',
        'credential_ref' => 'env://TENANT_A_APP_PASSWORD',
        'status' => 'active',
    ]);

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetScopedInstances();

    $refusal = tenancyRefusalOf(function (): void {
        tenancy()->enter('tenant-echo');
    });

    expect($refusal)->toBeInstanceOf(TenantConnectionFailed::class);

    $message = (string) $refusal?->getMessage();

    expect($message)->toContain('[redacted]')
        ->and($message)->not->toContain($password);
});

it('logs a redacted warning when a tenant scope is left with an open transaction', function () {
    // The one code path in Tenancy that logs at all. It must name the tenant and
    // the transaction level and nothing else - never the connection
    // configuration - and leave() must not throw, because it runs in a finally.
    $records = tenancyCaptureLog(function (): void {
        tenancy()->enter('tenant-a');

        DB::connection('tenant')->beginTransaction();

        tenancy()->leave();
    });

    $logged = implode("\n", $records);

    expect($records)->not->toBe([])
        ->and($logged)->toContain('open transaction')
        ->and($logged)->toContain('tenant-a')
        ->and($logged)->not->toContain(tenancyFixturePassword());

    // And the scope really did close, rollback and all.
    expect(tenancy()->current()->hasTenant())->toBeFalse()
        ->and(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza());
});

it('refuses a stanza that does not belong to the resolved tenant, credential-free', function () {
    // The level-2 guard's config/context invariant. Reaching it needs the AC-15
    // rule to be broken on purpose - something other than Tenancy writing the
    // stanza - which is exactly what the check exists to catch.
    tenancy()->enter('tenant-a');

    try {
        config()->set('database.connections.tenant.'.Tenancy::TENANT_MARKER, 'tenant-b');

        DB::purge('tenant');

        $refusal = tenancyRefusalOf(function (): void {
            DB::connection('tenant')->scalar('select value from marker');
        });

        expect($refusal)->toBeInstanceOf(TenantConnectionMismatch::class);

        $message = (string) $refusal?->getMessage();

        expect($message)->toContain('tenant-b')
            ->and($message)->toContain('only code allowed to write that configuration')
            ->and($message)->not->toContain(tenancyFixturePassword())
            ->and((string) $refusal)->not->toContain(tenancyFixturePassword())
            ->and($refusal?->getPrevious())->toBeNull();
    } finally {
        tenancy()->leave();
    }
});

it('keeps the credential out of rendered debug output when a leaked stanza is rebuilt', function () {
    // Plan step 13.3. This is the narrow path that used to leak, promoted from a
    // code comment into an executing assertion.
    //
    // DatabaseManager::makeConnection() invokes the connection extension through
    // `call_user_func($this->extensions[$name], $config, $name)`. PHP records the
    // arguments of that framework frame, and #[\SensitiveParameter] on our own
    // closure parameter cannot mark an internal variadic - so with a plain string
    // password the whole stanza reaches a *flattened* trace, and Symfony's
    // fallback renderer prints argument values. Storing the password as a
    // ConnectionPassword closes it: FlattenException records an object as
    // ['object', get_debug_type($value)] and discards the value.
    //
    // A comment cannot catch a renderer or language change that starts rendering
    // these arguments by value again. This test can, and it is the only
    // regression direction that would otherwise fail silently (plan R-12).
    tenancy()->enter('tenant-a');

    // The shape a queue worker produces: the job forgot to leave, the worker
    // reset the container scope, and something purged the connection - so the
    // next query rebuilds it from the stanza the job left behind.
    app()->forgetScopedInstances();
    DB::purge('tenant');

    $refusal = tenancyRefusalOf(function (): void {
        DB::connection('tenant')->scalar('select value from marker');
    });

    expect($refusal)->toBeInstanceOf(NoTenantResolved::class);

    // Rendered through the real handler with debug on, and through Symfony's
    // fallback renderer, which is the one that prints argument values.
    foreach (tenancyRenderedSurface($refusal ?? new RuntimeException('nothing was refused')) as $output) {
        expect($output)->not->toContain(tenancyFixturePassword());
    }
});
