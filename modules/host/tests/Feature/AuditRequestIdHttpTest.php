<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Platform\Support\PlatformAudit;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| T-017 steps 1, 2 and 4 - the request id, at the wire
|--------------------------------------------------------------------------
|
| AssignRequestId is global middleware (bootstrap/app.php), so every
| request made here - tenant or platform - carries it. Each scratch route
| manages its own tenant scope end to end within one request
| (tenancy()->leave() before the response, per the access-http-surface
| seam), so two postJson() calls in one test method never collide.
*/

beforeEach(function () {
    AccessTenantFixture::boot();

    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);
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

it('step 1: mints 26 Crockford characters, a different one per request, and ignores an inbound header', function () {
    Route::get('t17/request-id-probe', fn () => response()->json(['ok' => true]));

    $first = getJson('t17/request-id-probe');
    $second = getJson('t17/request-id-probe');
    $spoofed = getJson('t17/request-id-probe', ['X-Request-Id' => 'AAAAAAAAAAAAAAAAAAAAAAAAAA']);

    $firstId = (string) $first->headers->get('X-Request-Id');
    $secondId = (string) $second->headers->get('X-Request-Id');
    $spoofedId = (string) $spoofed->headers->get('X-Request-Id');

    expect($firstId)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($secondId)->not->toBe($firstId)
        ->and($spoofedId)->not->toBe('AAAAAAAAAAAAAAAAAAAAAAAAAA');
});

it('step 2 (tenant half): two rows written in one request share the response s id, and user_agent is the sent header', function () {
    Route::post('t17/tenant-audit-probe', function () {
        AccessTenantFixture::enter('tenant-a');

        $key = AccessSeed::permission('fixture.t17');
        $actor = AccessSeed::actorHolding('t17-actor', [$key]);

        /** @var AuditTrail $audit */
        $audit = app(AuditTrail::class);
        $audit->record('access', $actor, 'role_created', 'role', 'SUBJECT000000000000000001', null, null);
        $audit->record('access', $actor, 'role_deleted', 'role', 'SUBJECT000000000000000002', null, null);

        tenancy()->leave();

        return response()->json(['ok' => true]);
    });

    $response = postJson('t17/tenant-audit-probe', [], ['User-Agent' => 'T17-Fixture/1.0']);
    $responseId = (string) $response->headers->get('X-Request-Id');

    AccessTenantFixture::enter('tenant-a');

    $rows = AccessSeed::connection()->table('audit_logs')->orderByDesc('id')->limit(2)->get();

    expect($rows)->toHaveCount(2)
        ->and((string) $rows[0]->request_id)->toBe($responseId)
        ->and((string) $rows[1]->request_id)->toBe($responseId)
        ->and($rows[0]->user_agent)->toBe('T17-Fixture/1.0');

    tenancy()->leave();

    $again = postJson('t17/tenant-audit-probe', [], ['User-Agent' => 'T17-Fixture/1.0']);
    $againId = (string) $again->headers->get('X-Request-Id');

    expect($againId)->not->toBe($responseId);
});

it('step 2 (tenant half): a command invocation stores request_id null', function () {
    AccessTenantFixture::enter('tenant-a');

    $key = AccessSeed::permission('fixture.t17.cli');
    $actor = AccessSeed::actorHolding('t17-cli-actor', [$key]);

    // Artisan::command() defers registration to the console application's
    // "starting" event, which already fired for this test's earlier
    // Artisan::call('migrate', ...) in beforeEach() - a closure registered
    // after that point would never be added. registerCommand() adds
    // directly to the (already built) console application instead.
    app(ConsoleKernel::class)->registerCommand(new ClosureCommand('t17:probe', function () use ($actor) {
        /** @var AuditTrail $audit */
        $audit = app(AuditTrail::class);
        $audit->record('access', $actor, 'role_created', 'role', 'SUBJECT000000000000000003', null, null);
    }));

    expect(Artisan::call('t17:probe'))->toBe(0);

    $row = AccessSeed::connection()->table('audit_logs')->orderByDesc('id')->first();

    expect($row->request_id)->toBeNull();

    tenancy()->leave();
});

it('step 4 (platform half): two rows resolved through the container in one request share the response s id', function () {
    Route::post('t17/platform-audit-probe', function () {
        $connection = DB::connection('master');

        /** @var PlatformAudit $audit */
        $audit = app(PlatformAudit::class);
        $audit->write($connection, 'tenant_status_synced', 'tenant', 'SUBJECT000000000000000004', null, null, null, null);
        $audit->write($connection, 'tenant_status_synced', 'tenant', 'SUBJECT000000000000000005', null, null, null, null);

        return response()->json(['ok' => true]);
    });

    $response = postJson('t17/platform-audit-probe');
    $responseId = (string) $response->headers->get('X-Request-Id');

    $rows = DB::connection('master')->table('platform_audit_logs')->orderByDesc('id')->limit(2)->get();

    expect($rows)->toHaveCount(2)
        ->and((string) $rows[0]->request_id)->toBe($responseId)
        ->and((string) $rows[1]->request_id)->toBe($responseId);
});
