<?php

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 BUG-1: what the recording logger actually records
|--------------------------------------------------------------------------
|
| `authFixtureRecordingLogger()` documents itself as covering both a
| constructor-injected `LoggerInterface` and the `Log` facade. QA found the
| second half false: `Container::instance()` drops the alias it is handed, so
| registering under `LoggerInterface::class` left `Log::`, `logger()` and
| `app('log')` resolving the real `LogManager`. Nothing in auth or the host
| logs through those routes today, so no leak was hidden - but AC-26's scan is
| only as wide as the recorder, and the first author to write `Log::error()`
| would have inherited a seam that silently stopped seeing it.
|
| This file is the fix's proof and its guard: one case per route, so the
| recorder's real coverage is asserted rather than described. Remove either
| `instance()` registration in the fixture and a case here goes red.
|
| No database, no engine: binding a logger and calling it touches neither.
*/

require_once __DIR__.'/auth-fixture.php';

uses(TestCase::class);

it('records what a constructor-injected LoggerInterface is told', function () {
    $log = authFixtureRecordingLogger();

    app(LoggerInterface::class)->error('injected');

    expect($log->messagesAt(authFixtureErrorLevel()))->toBe(['injected']);
});

it('records what the Log facade is told', function () {
    $log = authFixtureRecordingLogger();

    Log::error('facade');

    expect($log->messagesAt(authFixtureErrorLevel()))->toBe(['facade']);
});

it('records what the logger() helper is told', function () {
    $log = authFixtureRecordingLogger();

    logger()->error('helper');

    expect($log->messagesAt(authFixtureErrorLevel()))->toBe(['helper']);
});

it('records what the log container binding is told', function () {
    $log = authFixtureRecordingLogger();

    app('log')->error('binding');

    expect($log->messagesAt(authFixtureErrorLevel()))->toBe(['binding']);
});

it('records a channel the way it records the default', function () {
    // The framework's deprecation handler resolves `LogManager::class` - an
    // alias of `log` - and calls `channel()` on it. Binding the recorder under
    // `log` therefore has to answer that call, and the entry has to arrive in
    // the same place as every other, or a channelled write would be a fifth
    // route out of the scan.
    $log = authFixtureRecordingLogger();

    Log::channel('deprecations')->error('channelled');

    expect($log->messagesAt(authFixtureErrorLevel()))->toBe(['channelled']);
});

it('keeps the context of a facade entry inside AC-26 scan range', function () {
    // The point of widening the seam: a secret passed as context through the
    // facade is in the haystack, not outside it.
    $log = authFixtureRecordingLogger();

    Log::warning('an entry', ['value' => 'sentinel-bug-1']);

    expect($log->contains('sentinel-bug-1'))->toBeTrue();
});

it('is the guard the fixture names, under the name it actually has', function () {
    // BUG-2. The fixture's docblock told the next author which file fails if
    // a registration is removed, and named `AuthFixtureRecordingLoggerTest`,
    // which has never existed - so the instruction pointed at nothing. The
    // name is now read back out of the fixture and resolved to a file, which
    // is a check a rename cannot pass by being plausible.
    $source = (string) file_get_contents(__DIR__.'/auth-fixture.php');

    preg_match('/`([A-Za-z]+Test)` fails if any of them stops landing here/', $source, $named);

    expect($named)->toHaveCount(2)
        ->and($named[1])->toBe(basename(__FILE__, '.php'))
        ->and(is_file(__DIR__.'/'.$named[1].'.php'))->toBeTrue();
});
