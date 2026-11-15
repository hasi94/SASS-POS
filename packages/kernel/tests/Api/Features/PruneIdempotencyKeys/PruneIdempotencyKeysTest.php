<?php

use Flexipos\Kernel\Api\Features\PruneIdempotencyKeys\PruneIdempotencyKeysCommand;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * T-021 AC-23: idempotency:prune, over the kernel's own tenancy fixture.
 * The same sweep shape as audit:archive (AccessServiceProvider precedent).
 */

require_once __DIR__.'/../../../Support/tenancy-fixture.php';

uses(TestCase::class);

function pruneIdempotencyKeysCreateTable(): void
{
    // The fixture's two SQLite files are shared across every test in this
    // file (built once per file, not once per test - see
    // tenancy-fixture.php), so a fresh table is dropped and recreated on
    // every call rather than created once.
    Schema::connection('tenant')->dropIfExists('idempotency_keys');

    Schema::connection('tenant')->create('idempotency_keys', function ($table) {
        $table->string('key', 80)->primary();
        $table->char('request_hash', 64);
        $table->unsignedSmallInteger('response_code')->nullable();
        $table->json('response_body')->nullable();
        $table->dateTime('created_at', 3);
        $table->index('created_at');
    });
}

function pruneIdempotencyKeysSeed(string $key, string $createdAt): void
{
    DB::connection('tenant')->table('idempotency_keys')->insert([
        'key' => $key,
        'request_hash' => str_repeat('a', 64),
        'response_code' => 200,
        'response_body' => '{}',
        'created_at' => $createdAt,
    ]);
}

beforeEach(function () {
    tenancyBootFixture();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-30 00:00:00.000'));
});

afterEach(function () {
    tenancyForgetFixture();
});

afterAll(function () {
    tenancyDeleteFixtureRoot();
});

it('deletes rows older than the retention and keeps younger ones, reporting the count', function () {
    tenancy()->enter('tenant-a');
    pruneIdempotencyKeysCreateTable();
    pruneIdempotencyKeysSeed('aged', '2026-10-28 23:00:00.000'); // 25h old
    pruneIdempotencyKeysSeed('fresh', '2026-10-29 01:00:00.000'); // 23h old
    tenancy()->leave();

    Artisan::call('idempotency:prune', ['--tenant' => ['tenant-a']]);

    tenancy()->enter('tenant-a');
    $remaining = DB::connection('tenant')->table('idempotency_keys')->pluck('key')->all();
    tenancy()->leave();

    $output = Artisan::output();

    expect($remaining)->toBe(['fresh'])
        ->and($output)->toContain('tenant-a')
        ->and($output)->toContain('1 key(s) deleted');
});

it('--tenant narrows the run', function () {
    tenancy()->enter('tenant-a');
    pruneIdempotencyKeysCreateTable();
    pruneIdempotencyKeysSeed('a-aged', '2026-10-28 23:00:00.000');
    tenancy()->leave();

    tenancy()->enter('tenant-b');
    pruneIdempotencyKeysCreateTable();
    pruneIdempotencyKeysSeed('b-aged', '2026-10-28 23:00:00.000');
    tenancy()->leave();

    Artisan::call('idempotency:prune', ['--tenant' => ['tenant-a']]);

    tenancy()->enter('tenant-a');
    $aRemaining = DB::connection('tenant')->table('idempotency_keys')->count();
    tenancy()->leave();

    tenancy()->enter('tenant-b');
    $bRemaining = DB::connection('tenant')->table('idempotency_keys')->count();
    tenancy()->leave();

    expect($aRemaining)->toBe(0)
        ->and($bRemaining)->toBe(1);
});

it('a failing tenant is logged, the loop continues, and the command exits 1', function () {
    Log::shouldReceive('error')->once()->withArgs(
        fn (string $message, array $context) => $context['tenant_id'] === 'tenant-unopenable'
    );

    tenancy()->enter('tenant-a');
    pruneIdempotencyKeysCreateTable();
    pruneIdempotencyKeysSeed('a-aged', '2026-10-28 23:00:00.000');
    tenancy()->leave();

    $exitCode = Artisan::call('idempotency:prune', ['--tenant' => ['tenant-a', 'tenant-unopenable']]);

    expect($exitCode)->toBe(PruneIdempotencyKeysCommand::FAILURE);
});

it('is scheduled hourly, without overlapping', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn ($event) => str_contains($event->command ?? '', 'idempotency:prune')
    );

    expect($event)->not->toBeNull()
        ->and($event->getExpression())->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
