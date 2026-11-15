<?php

use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantQueueJob;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| What the queue carries
|--------------------------------------------------------------------------
|
| T-008 step 11, AC-41's payload half. A queue payload is written to a store,
| copied into failed-job rows and read by anyone with queue access, so a
| secret in one is a secret at rest in a place nobody is watching.
|
| The scan is over the **serialised** payload, not over the object's property
| list: serialisation is what actually reaches the store, and a property that
| held a credential indirectly - inside a set, inside a record - would not be
| visible to a list of property names.
|
| The control is the one the criterion names: the same scan reports a payload
| with a planted password. Without it, "no credential found" is also what a
| scan of nothing returns.
|
*/

require_once __DIR__.'/../../../Support/migration-run-fixture.php';

uses(TestCase::class);

/**
 * Every credential-shaped thing a mapping row can hold, as the scan looks for
 * them.
 *
 * @return list<string>
 */
function queuePayloadForbidden(): array
{
    return ['env://', 'password', 'credential', 'secret'];
}

/**
 * Which forbidden fragments a payload contains, case-insensitively.
 *
 * @return list<string>
 */
function queuePayloadLeaks(string $payload): array
{
    $found = [];

    foreach (queuePayloadForbidden() as $fragment) {
        if (stripos($payload, $fragment) !== false) {
            $found[] = $fragment;
        }
    }

    return $found;
}

it('carries the identifiers, the set and nothing credential-shaped', function () {
    $set = runFixtureSet();

    $job = MigrateTenantQueueJob::forJob(
        'RUN0000000000000000000001',
        'JOB0000000000000000000001',
        'TENANT00000000000000000001',
        'worker-one',
        $set,
        (string) config('schema.queue')
    );

    $payload = serialize($job);

    expect(queuePayloadLeaks($payload))->toBe([])
        // The identifiers are there: a payload that carried nothing would also
        // pass the assertion above.
        ->and($payload)->toContain('RUN0000000000000000000001')
        ->and($payload)->toContain('JOB0000000000000000000001')
        ->and($payload)->toContain('TENANT00000000000000000001')
        ->and($job->setName)->toBe($set->name)
        ->and($job->setDirectories)->toBe($set->directories);
});

it('goes on the configured migrations queue', function () {
    $job = MigrateTenantQueueJob::forJob(
        'RUN0000000000000000000001',
        'JOB0000000000000000000001',
        'TENANT00000000000000000001',
        'worker-one',
        runFixtureSet(),
        (string) config('schema.queue')
    );

    expect($job->queue)->toBe('migrations')
        ->and(config('schema.queue'))->toBe('migrations')
        // Retrying belongs to the runner's attempt counter (D-20), never to
        // the queue: a queue retry would increment nothing and could start a
        // second migration against a tenant whose first is still running.
        ->and($job->tries)->toBe(1);
});

it('reports a payload with a planted password', function () {
    // The control. The scan can see what it claims to see.
    $planted = serialize(['tenant' => 'T1', 'password' => 'hunter2']);

    expect(queuePayloadLeaks($planted))->toContain('password');
});

it('rebuilds the same set from the payload', function () {
    $set = runFixtureSet(1);

    $job = MigrateTenantQueueJob::forJob('R', 'J', 'T', 'w', $set, 'migrations');

    $rebuilt = MigrationSet::named($job->setName, ...$job->setDirectories);

    // A version is a position in a set, so a payload that lost the order would
    // migrate a tenant to a different schema under the same number.
    expect($rebuilt->directories)->toBe($set->directories)
        ->and($rebuilt->highestVersion())->toBe($set->highestVersion());
});
