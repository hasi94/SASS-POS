<?php

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/*
 * T-009 QA, BUG-1 re-test: the recorder must win even when the Log facade has
 * ALREADY resolved the real LogManager in this test. That is the only case in
 * which the Facade::clearResolvedInstance('log') line in the fixture does any
 * work; RecordingLoggerCoverageTest installs the recorder on a fresh app and
 * so cannot see that line go missing.
 */

require_once __DIR__.'/auth-fixture.php';

uses(TestCase::class);

it('records a facade write even after the facade had cached the real logger', function () {
    // Liveness control: the facade really did resolve something else first.
    $before = Log::getFacadeRoot();
    expect($before)->not->toBeInstanceOf(AuthFixtureRecordingLogger::class);

    $log = authFixtureRecordingLogger();

    Log::error('after-cache');

    expect($log->messagesAt(authFixtureErrorLevel()))->toBe(['after-cache']);
});
