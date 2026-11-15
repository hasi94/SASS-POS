<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning\Steps;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Provisioning\Steps\ReserveIdentity;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 9: reserve_identity
|--------------------------------------------------------------------------
|
| AC-6, AC-7 (stand-in half), AC-10, AC-11, AC-12, AC-37.
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
});

afterEach(function () {
    ReserveIdentity::$raceHook = null;
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reserveIdentityInput(array $overrides = []): array
{
    return array_merge([
        'code' => 'TEN001',
        'slug' => 'ten001',
        'name' => 'Ten Shop',
        'country' => 'LK',
        'timezone' => 'Asia/Colombo',
        'currency' => 'LKR',
        'business_type' => 'retail',
        'owner_email' => 'owner@example.test',
        'owner_name' => null,
        'plan_id' => null,
        'active' => false,
        'branch_code' => null,
        'branch_name' => null,
        'server_code' => null,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $input
 */
function reserveIdentityPlanAndContext(array $input): RunContext
{
    $runId = app(ProvisioningRunner::class)->plan('create', $input, null, 'worker-1', [StepName::ReserveIdentity]);

    return new RunContext($runId, $input, null);
}

function reserveIdentityJournal(RunContext $context): StepJournal
{
    return new StepJournal(app(ProvisioningRuns::class), $context->runId, StepName::ReserveIdentity);
}

it('inserts a tenant row matching AC-10, with provisioning status and a null plan_id', function () {
    $input = reserveIdentityInput();
    $context = reserveIdentityPlanAndContext($input);
    $journal = reserveIdentityJournal($context);

    $output = app(ReserveIdentity::class)->run($context, $journal);

    $row = (array) PlatformSeed::connection()->table('tenants')->where('id', $output['tenant_id'])->first();

    expect($row['status'])->toBe('provisioning')
        ->and($row['plan_id'])->toBeNull()
        ->and($row['code'])->toBe('TEN001')
        ->and($row['slug'])->toBe('ten001')
        ->and($row['name'])->toBe('Ten Shop')
        ->and($row['country'])->toBe('LK')
        ->and($row['timezone'])->toBe('Asia/Colombo')
        ->and($row['currency'])->toBe('LKR')
        ->and($row['business_type'])->toBe('retail')
        ->and((int) $row['sequence'])->toBe(1);

    $run = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $context->runId)->first();
    expect($run->tenant_id)->toBe($output['tenant_id']);
});

it('derives sequence as MAX + 1, and the mechanical names once from it', function () {
    PlatformSeed::tenant('existing', 'active', ['sequence' => 5]);

    $context = reserveIdentityPlanAndContext(reserveIdentityInput(['code' => 'TEN002']));
    $journal = reserveIdentityJournal($context);

    $output = app(ReserveIdentity::class)->run($context, $journal);

    expect($output['sequence'])->toBe(6)
        ->and($output['database_name'])->toBe('flx_t_000006')
        ->and($output['app_username'])->toBe('flx_t000006_app')
        ->and($output['migrate_username'])->toBe('flx_t000006_mig');
});

it('normalises --code to upper case before writing (AC-11)', function () {
    $context = reserveIdentityPlanAndContext(reserveIdentityInput(['code' => 'ten-001']));
    $journal = reserveIdentityJournal($context);

    $output = app(ReserveIdentity::class)->run($context, $journal);

    expect($output['code'])->toBe('TEN-001');

    $row = PlatformSeed::connection()->table('tenants')->where('id', $output['tenant_id'])->first();
    expect($row->code)->toBe('TEN-001');
});

it('refuses a code collision, naming the existing run (AC-6)', function () {
    $first = reserveIdentityPlanAndContext(reserveIdentityInput(['code' => 'TEN003']));
    app(ReserveIdentity::class)->run($first, reserveIdentityJournal($first));

    $second = reserveIdentityPlanAndContext(reserveIdentityInput(['code' => 'ten003']));

    expect(fn () => app(ReserveIdentity::class)->run($second, reserveIdentityJournal($second)))
        ->toThrow(PlatformRefused::class, $first->runId);

    // No half-written row for the losing attempt.
    expect(PlatformSeed::connection()->table('tenants')->where('code', 'TEN003')->count())->toBe(1);

    $secondRun = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $second->runId)->first();
    expect($secondRun->tenant_id)->toBeNull();
});

it('AC-7 stand-in half: a unique violation on code, inserted between the read and the insert, is refused naming the winner', function () {
    // The racer's own up-front check passes - nobody holds TEN004 yet - and
    // only once this attempt is about to insert does another process (the
    // hook) win the same code, which is exactly the window AC-7 describes.
    $racer = reserveIdentityPlanAndContext(reserveIdentityInput(['code' => 'TEN004']));

    $winnerRunId = null;

    ReserveIdentity::$raceHook = function () use (&$winnerRunId) {
        $winnerInput = reserveIdentityInput(['code' => 'TEN004']);
        $winnerRunId = app(ProvisioningRunner::class)
            ->plan('create', $winnerInput, null, 'worker-2', [StepName::ReserveIdentity]);
        $winnerContext = new RunContext($winnerRunId, $winnerInput, null);

        app(ReserveIdentity::class)->run($winnerContext, reserveIdentityJournal($winnerContext));
    };

    $caught = null;

    try {
        app(ReserveIdentity::class)->run($racer, reserveIdentityJournal($racer));
    } catch (PlatformRefused $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toContain((string) $winnerRunId);

    // No half-written row for the losing attempt, and its run never
    // recorded a tenant.
    expect(PlatformSeed::connection()->table('tenants')->where('code', 'TEN004')->count())->toBe(1);

    $racerAfter = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $racer->runId)->first();
    expect($racerAfter->tenant_id)->toBeNull();
});

it('AC-7 stand-in half: a sequence collision inserted between the read and the insert is a bounded retry, not a refusal', function () {
    $context = reserveIdentityPlanAndContext(reserveIdentityInput(['code' => 'TEN006']));

    $hookRan = false;

    ReserveIdentity::$raceHook = function () use (&$hookRan) {
        $hookRan = true;

        // The conflicting row takes sequence 1 (what this attempt also
        // chose) under a different code, so the insert collides on
        // `sequence`'s unique index rather than `code`'s.
        PlatformSeed::tenant('TEN999', 'provisioning', ['sequence' => 1]);
    };

    $output = app(ReserveIdentity::class)->run($context, reserveIdentityJournal($context));

    expect($hookRan)->toBeTrue()
        ->and($output['sequence'])->toBe(2)
        ->and($output['code'])->toBe('TEN006');

    $row = PlatformSeed::connection()->table('tenants')->where('id', $output['tenant_id'])->first();
    expect((int) $row->sequence)->toBe(2);
});

it('resumes idempotently: a crashed attempt with the row already inserted is a no-op', function () {
    $context = reserveIdentityPlanAndContext(reserveIdentityInput(['code' => 'TEN007']));
    $journal = reserveIdentityJournal($context);

    $first = app(ReserveIdentity::class)->run($context, $journal);
    $countBefore = PlatformSeed::connection()->table('tenants')->count();

    $second = app(ReserveIdentity::class)->run($context, $journal);

    expect($second)->toBe($first)
        ->and(PlatformSeed::connection()->table('tenants')->count())->toBe($countBefore);
});

it('resumes the exact same identity when the journal recorded an intent but the row never committed', function () {
    $context = reserveIdentityPlanAndContext(reserveIdentityInput(['code' => 'TEN008']));
    $journal = reserveIdentityJournal($context);

    // Simulate a crash between "intent journalled" and "insert committed":
    // write the intent directly, without ever inserting the row.
    $journal->write([
        'tenant_id' => '01HFAKE0000000000000000TEN8',
        'sequence' => 1,
        'code' => 'TEN008',
        'database_name' => 'flx_t_000001',
        'app_username' => 'flx_t000001_app',
        'migrate_username' => 'flx_t000001_mig',
    ]);

    $output = app(ReserveIdentity::class)->run($context, $journal);

    expect($output['tenant_id'])->toBe('01HFAKE0000000000000000TEN8')
        ->and($output['sequence'])->toBe(1);

    $row = PlatformSeed::connection()->table('tenants')->where('id', '01HFAKE0000000000000000TEN8')->first();
    expect($row)->not->toBeNull()->and($row->code)->toBe('TEN008');
});
