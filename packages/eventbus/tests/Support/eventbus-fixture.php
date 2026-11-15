<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| T-018 - shared file-scope helpers for the event bus package's tests
|--------------------------------------------------------------------------
|
| Plain functions, not a Pest test-case property: an ad-hoc $this->whatever
| in a Pest closure is a PHPStan property.notFound error, because Pest's
| test object is a plain PHPUnit\Framework\TestCase (memory:
| sqlite-fixture-costs-and-pest-properties).
|
| Built on Flexipos\Access\Tests\Support\AccessTenantFixture, not beside it
| - that fixture already owns the two SQLite files, the credentials, the
| mapping stand-in and the teardown, exactly as T-014's organization package
| already built its own slice tests on it.
|
*/

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Tests\Support\PingedConsumer;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Facades\DB;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Enter a fixture tenant, with foreign keys enforced - the shape every
 * slice test in this package needs.
 *
 * **Deliberately does not call `AccessTenantFixture::boot()`.** `boot()`
 * deletes and re-copies every tenant's database from the template on each
 * call (it is meant to run once, in `beforeEach()`) - calling it here too
 * would silently wipe out whatever a test had already written the moment
 * it entered a second tenant, or re-entered the same one after `leave()`.
 * Call `AccessTenantFixture::boot()` once per test before the first
 * `eventbusEnterTenant()`.
 */
function eventbusEnterTenant(string $tenant = 'tenant-a'): void
{
    AccessTenantFixture::enter($tenant);
}

/**
 * A clock fixed at one instant, bound as the shared instance every
 * collaborator resolves (OutboxWriter, the relay, the consumer runner, the
 * in-memory bus) - so advancing it from the test moves all of them, with no
 * real wait anywhere (AC-17).
 */
function eventbusClockAt(string $at): RunnerClock
{
    $clock = RunnerClock::fixedAt($at);

    app()->instance(RunnerClock::class, $clock);

    return $clock;
}

/**
 * The proof-of-pipeline fixture's own table (Ruling 7, Ruling 1). Not
 * schema: `fixture_pings` is a test's own table on the fixture tenant
 * connection, created and dropped by the test, never shipped as a
 * migration.
 */
function eventbusCreatePingsTable(): void
{
    $connection = DB::connection(AccessTenantFixture::connectionName());

    $connection->statement('drop table if exists fixture_pings');
    $connection->statement('create table fixture_pings (event_id varchar(26) not null primary key, effects_applied integer not null default 0)');
}

/**
 * A second test table, for the discriminating control in the redelivery
 * case: a marker row this package's slice tests insert and roll back
 * alongside an outbox write, to prove AC-6's "same transaction" claim.
 */
function eventbusCreateMarkerTable(): void
{
    $connection = DB::connection(AccessTenantFixture::connectionName());

    $connection->statement('drop table if exists eventbus_marker');
    $connection->statement('create table eventbus_marker (value varchar(50) not null)');
}

/**
 * A `fixture.pinged` envelope for the named tenant, with no payload, no
 * branch, no correlation and no causation - the proof-of-pipeline shape
 * every consumer-side test in this package reads back.
 */
function eventbusPingedEnvelope(string $id, string $tenantId = 'tenant-a'): EventEnvelope
{
    return new EventEnvelope(
        $id,
        $tenantId,
        PingedConsumer::EVENT_TYPE,
        1,
        'fixture',
        $id,
        null,
        null,
        '2026-01-01 00:00:00.000',
        null,
        null,
        EventActor::system(),
    );
}

/**
 * Replace the `Psr\Log\LoggerInterface` binding with one that keeps every
 * entry, and hand it back - the same shape
 * `Flexipos\Auth\Tests\Support\AuthFixtureRecordingLogger` uses, narrowed to
 * the one route this package's handlers actually use: every collaborator
 * here takes `LoggerInterface` by constructor injection, never the `Log`
 * facade, so a single binding is enough.
 */
function eventbusRecordingLogger(): EventbusFixtureRecordingLogger
{
    $logger = new EventbusFixtureRecordingLogger;

    app()->instance(LoggerInterface::class, $logger);

    return $logger;
}

/**
 * @see eventbusRecordingLogger()
 */
final class EventbusFixtureRecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  string|Stringable  $message
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    public function atLevel(string $level): array
    {
        return array_values(array_filter($this->records, static fn (array $record): bool => $record['level'] === $level));
    }
}
