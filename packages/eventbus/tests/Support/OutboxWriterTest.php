<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\NewEvent;
use Flexipos\Contracts\Events\Outbox;
use Flexipos\Contracts\Events\OutboxEventRefused;
use Flexipos\Eventbus\Support\OutboxWriter;
use Flexipos\Kernel\Http\RequestId;
use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 5 - the one outbox writer (AC-6 to AC-13)
|--------------------------------------------------------------------------
*/

require_once __DIR__.'/eventbus-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    eventbusEnterTenant('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

/**
 * Cast to array immediately, the same way
 * `Flexipos\Organization\Tests\Features\CreateBranch\CreateBranchTest`
 * reads a row - a bare `object|null` return confuses Pest's `Expectation`
 * generic inference under PHPStan, and array-key access needs no nullable
 * property fetch at all.
 *
 * @return array<string, mixed>
 */
function eventbusOutboxRow(string $id): array
{
    $row = DB::connection(AccessTenantFixture::connectionName())
        ->table('outbox_events')
        ->where('id', $id)
        ->first();

    return $row === null ? [] : (array) $row;
}

it('AC-7: a caller supplying only the business columns gets the shipped defaults', function () {
    $clock = eventbusClockAt('2026-02-01 10:00:00');

    $id = app(Outbox::class)->record(new NewEvent(
        'fixture.pinged',
        'fixture',
        '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ['n' => 1],
    ));

    $row = eventbusOutboxRow($id);

    expect($row)->not->toBe([])
        ->and($row['status'])->toBe('pending')
        ->and((int) $row['attempts'])->toBe(0)
        ->and($row['published_at'])->toBeNull()
        ->and((int) $row['version'])->toBe(1)
        ->and($row['occurred_at'])->toBe($clock->timestamp());
});

it('AC-8: the actor JSON for employee, for system, and the default system, and never queries employees', function () {
    DB::connection(AccessTenantFixture::connectionName())->enableQueryLog();

    $employeeId = app(Outbox::class)->record(new NewEvent(
        'fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        actor: EventActor::employee('01ARZ3NDEKTSV4RRFFQ69G5FBB', 'Kamal'),
    ));
    $systemId = app(Outbox::class)->record(new NewEvent(
        'fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FCC',
        actor: EventActor::system(),
    ));
    $defaultId = app(Outbox::class)->record(new NewEvent(
        'fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FDD',
    ));

    $log = DB::connection(AccessTenantFixture::connectionName())->getQueryLog();
    DB::connection(AccessTenantFixture::connectionName())->disableQueryLog();

    $queriedEmployees = array_filter($log, static fn ($entry) => str_contains((string) $entry['query'], 'employees'));

    expect($queriedEmployees)->toBe([]);

    expect(json_decode((string) eventbusOutboxRow($employeeId)['actor'], true))
        ->toBe(['type' => 'employee', 'id' => '01ARZ3NDEKTSV4RRFFQ69G5FBB', 'name' => 'Kamal']);
    expect(json_decode((string) eventbusOutboxRow($systemId)['actor'], true))
        ->toBe(['type' => 'system', 'id' => null, 'name' => null]);
    expect(json_decode((string) eventbusOutboxRow($defaultId)['actor'], true))
        ->toBe(['type' => 'system', 'id' => null, 'name' => null]);
});

it('AC-8 snapshot: a stored actor name is unchanged after the caller\'s source changes', function () {
    $name = 'Kamal';
    $id = app(Outbox::class)->record(new NewEvent(
        'fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        actor: EventActor::employee('01ARZ3NDEKTSV4RRFFQ69G5FBB', $name),
    ));

    $name = 'Changed later';

    $decoded = json_decode((string) eventbusOutboxRow($id)['actor'], true);

    expect($decoded['name'])->toBe('Kamal');
});

it('AC-9: correlation_id is the fixed request id, null with no request, an explicit override beats both, and rebinding the current request changes the next write (laziness)', function () {
    // Outbox::class is a singleton, so rebinding RequestId::class in the
    // container would not reach an already-built OutboxWriter: each case
    // below builds its own writer with the exact RequestId it wants to
    // prove, through the concrete class rather than the singleton-bound
    // interface.
    $fixedId = app()->make(OutboxWriter::class, ['requestId' => RequestId::fixed('01ARZ3NDEKTSV4RRFFQ69G5FRQ')])
        ->record(new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA1'));
    expect(eventbusOutboxRow($fixedId)['correlation_id'])->toBe('01ARZ3NDEKTSV4RRFFQ69G5FRQ');

    $noneId = app()->make(OutboxWriter::class, ['requestId' => RequestId::none()])
        ->record(new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA2'));
    expect(eventbusOutboxRow($noneId)['correlation_id'])->toBeNull();

    $overrideId = app()->make(OutboxWriter::class, ['requestId' => RequestId::none()])
        ->record(new NewEvent(
            'fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA3',
            correlationId: '01ARZ3NDEKTSV4RRFFQ69G5FCV',
        ));
    expect(eventbusOutboxRow($overrideId)['correlation_id'])->toBe('01ARZ3NDEKTSV4RRFFQ69G5FCV');

    // Laziness: one writer, built on the real per-request resolver,
    // answers differently as the bound request changes between two
    // writes - the id is read at write time, never captured at
    // construction (the same property RequestId::fromRequests() itself
    // documents, proved again at the HTTP level in OutboxCorrelationHttpTest).
    $lazyWriter = app()->make(OutboxWriter::class, ['requestId' => RequestId::fromRequests(app())]);

    $requestA = Request::create('/probe-a');
    $requestA->attributes->set(RequestId::ATTRIBUTE, '01ARZ3NDEKTSV4RRFFQ69G5FRA');
    app()->instance('request', $requestA);

    $idA = $lazyWriter->record(new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA4'));
    expect(eventbusOutboxRow($idA)['correlation_id'])->toBe('01ARZ3NDEKTSV4RRFFQ69G5FRA');

    $requestB = Request::create('/probe-b');
    $requestB->attributes->set(RequestId::ATTRIBUTE, '01ARZ3NDEKTSV4RRFFQ69G5FRB');
    app()->instance('request', $requestB);

    $idB = $lazyWriter->record(new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA5'));
    expect(eventbusOutboxRow($idB)['correlation_id'])->toBe('01ARZ3NDEKTSV4RRFFQ69G5FRB');
});

it('AC-10: causation_id is null unless given, and given is stored', function () {
    $id1 = app(Outbox::class)->record(new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA1'));
    expect(eventbusOutboxRow($id1)['causation_id'])->toBeNull();

    $id2 = app(Outbox::class)->record(new NewEvent(
        'fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA2',
        causationId: '01ARZ3NDEKTSV4RRFFQ69G5FCA',
    ));
    expect(eventbusOutboxRow($id2)['causation_id'])->toBe('01ARZ3NDEKTSV4RRFFQ69G5FCA');
});

it('AC-11: a well-formed branch_id naming no branch is stored as given', function () {
    $id = app(Outbox::class)->record(new NewEvent(
        'fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA1',
        branchId: '01ARZ3NDEKTSV4RRFFQ69G5FBR',
    ));

    expect(eventbusOutboxRow($id)['branch_id'])->toBe('01ARZ3NDEKTSV4RRFFQ69G5FBR');
});

it('AC-12: version is passed through unchanged', function () {
    $id = app(Outbox::class)->record(new NewEvent(
        'fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA1', version: 3,
    ));

    expect((int) eventbusOutboxRow($id)['version'])->toBe(3);
});

it('AC-13: refuses every malformed field before any database write', function (NewEvent $event) {
    expect(fn () => app(Outbox::class)->record($event))->toThrow(OutboxEventRefused::class);

    expect(DB::connection(AccessTenantFixture::connectionName())->table('outbox_events')->count())->toBe(0);
})->with([
    'empty type' => [new NewEvent('', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV')],
    'non-dotted type' => [new NewEvent('Sale.Done', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV')],
    'overlong type' => [new NewEvent(str_repeat('a.', 41), 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV')],
    'empty aggregate type' => [new NewEvent('fixture.pinged', '', '01ARZ3NDEKTSV4RRFFQ69G5FAV')],
    'short aggregate id' => [new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA')],
    'lower-case aggregate id' => [new NewEvent('fixture.pinged', 'fixture', '01arz3ndektsv4rrffq69g5fav')],
    'version zero' => [new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV', version: 0)],
    'invalid utf-8 payload' => [new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV', payload: ['bad' => "\xB1\x31"])],
    'NAN payload' => [new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV', payload: ['bad' => NAN])],
]);

it('AC-13 control: validation runs before any tenant is resolved', function () {
    AccessTenantFixture::forget();

    expect(fn () => app(Outbox::class)->record(new NewEvent('', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV')))
        ->toThrow(OutboxEventRefused::class);
});

it('AC-13 control: a well-formed event with no tenant resolved throws NoTenantResolved, not OutboxEventRefused', function () {
    AccessTenantFixture::forget();

    expect(fn () => app(Outbox::class)->record(new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV')))
        ->toThrow(NoTenantResolved::class);
});
