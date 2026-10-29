<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Events\NewEvent;
use Flexipos\Contracts\Events\Outbox;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| T-018 step 5 - correlation_id at the wire, the HTTP half of AC-9
|--------------------------------------------------------------------------
|
| In T-017's AuditRequestIdHttpTest shape: AssignRequestId is global
| middleware, so every request here carries it. Proves the same laziness
| OutboxWriterTest proves at the unit level, through a real request this
| time, with a real RequestId::fromRequests() resolved from the container.
*/

beforeEach(function () {
    AccessTenantFixture::boot();
});

afterEach(function () {
    if (tenancy()->current()->hasTenant()) {
        tenancy()->leave();
    }

    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('two events written in one request share the response\'s X-Request-Id, and a second request gives a different one', function () {
    Route::post('t18/outbox-correlation-probe', function () {
        AccessTenantFixture::enter('tenant-a');

        /** @var Outbox $outbox */
        $outbox = app(Outbox::class);
        $outbox->record(new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA1'));
        $outbox->record(new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA2'));

        tenancy()->leave();

        return response()->json(['ok' => true]);
    });

    $response = postJson('t18/outbox-correlation-probe', []);
    $responseId = (string) $response->headers->get('X-Request-Id');

    AccessTenantFixture::enter('tenant-a');

    $rows = DB::connection(AccessTenantFixture::connectionName())
        ->table('outbox_events')
        ->orderByDesc('id')
        ->limit(2)
        ->get();

    expect($rows)->toHaveCount(2)
        ->and((string) $rows[0]->correlation_id)->toBe($responseId)
        ->and((string) $rows[1]->correlation_id)->toBe($responseId);

    tenancy()->leave();

    $again = postJson('t18/outbox-correlation-probe', []);
    $againId = (string) $again->headers->get('X-Request-Id');

    expect($againId)->not->toBe($responseId);
});

it('a command invocation stores correlation_id null', function () {
    AccessTenantFixture::enter('tenant-a');

    app(ConsoleKernel::class)->registerCommand(new ClosureCommand('t18:outbox-probe', function () {
        /** @var Outbox $outbox */
        $outbox = app(Outbox::class);
        $outbox->record(new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FA3'));
    }));

    expect(Artisan::call('t18:outbox-probe'))->toBe(0);

    $row = DB::connection(AccessTenantFixture::connectionName())->table('outbox_events')->orderByDesc('id')->first();

    expect($row->correlation_id)->toBeNull();

    tenancy()->leave();
});
