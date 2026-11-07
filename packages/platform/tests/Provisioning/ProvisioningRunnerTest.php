<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\WritableSecretStore;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRunRefused;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\ProvisioningStepFailed;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 7: the runner core, with three in-test fake steps
|--------------------------------------------------------------------------
|
| AC-2, AC-3, AC-4, AC-6 (resume half), AC-18. Proven generically: the real
| eleven step classes do not exist yet, so this exercises the engine
| (ProvisioningRunner/ProvisioningRuns/StepJournal/the lease/the sanitiser)
| against three fake Flexipos\Platform\Provisioning\ProvisioningStep
| implementations declared below.
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
});

/**
 * Journals that it ran, and counts invocations across the whole test
 * process - the "execution counter" the mandatory mutation (drop "skip
 * done") is checked against.
 */
final class CountingFakeStep implements ProvisioningStep
{
    public int $runs = 0;

    public function run(RunContext $context, StepJournal $journal): array
    {
        $this->runs++;

        return ['ran' => $this->runs];
    }

    public function compensate(RunContext $context): ?string
    {
        return null;
    }
}

/**
 * Journals a credential reference through StepJournal, then throws with the
 * resolved password embedded in its message - the AC-18 control.
 */
final class ThrowingFakeStep implements ProvisioningStep
{
    public function __construct(private readonly string $credentialRef, private readonly string $password) {}

    public function run(RunContext $context, StepJournal $journal): array
    {
        $journal->write(['credential_ref' => $this->credentialRef]);

        throw new RuntimeException("could not create the user, password was [{$this->password}]");
    }

    public function compensate(RunContext $context): ?string
    {
        return null;
    }
}

/**
 * Throws only on its first call, so a resume can prove the journal survives
 * a thrown step and the retried step succeeds the second time.
 */
final class FlakyFakeStep implements ProvisioningStep
{
    public int $attempts = 0;

    public function run(RunContext $context, StepJournal $journal): array
    {
        $this->attempts++;

        if ($this->attempts === 1) {
            throw new RuntimeException('transient failure');
        }

        return ['attempts' => $this->attempts];
    }

    public function compensate(RunContext $context): ?string
    {
        return null;
    }
}

function provisioningRunner(): ProvisioningRunner
{
    return app(ProvisioningRunner::class);
}

/**
 * Simulates another worker successfully claiming this exact run (a stale
 * takeover) while this step is still "executing" - the review round 1 W-3
 * gap: before this fix, nothing after the initial claim ever re-checked
 * `claimed_by`, so the original worker's later writes (markStepDone,
 * markRunCompleted, ...) would silently keep going.
 */
final class StealsLeaseFakeStep implements ProvisioningStep
{
    public function run(RunContext $context, StepJournal $journal): array
    {
        PlatformSeed::connection()->table('tenant_provisioning_runs')
            ->where('id', $context->runId)
            ->update(['claimed_by' => 'worker-2']);

        return [];
    }

    public function compensate(RunContext $context): ?string
    {
        return null;
    }
}

it('writes the plan whole, with every step pending and current_step first, then runs them in order', function () {
    $runner = provisioningRunner();
    $order = [StepName::ReserveIdentity, StepName::SelectServer, StepName::CreateDatabase];

    $runId = $runner->plan('create', ['code' => 'TEN001'], null, 'worker-1', $order);

    $runRow = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
    expect((array) $runRow)
        ->toMatchArray(['status' => 'running', 'current_step' => 'reserve_identity', 'claimed_by' => 'worker-1']);

    $steps = PlatformSeed::connection()->table('tenant_provisioning_steps')
        ->where('run_id', $runId)->orderBy('sequence')->get();

    expect($steps->pluck('step')->all())->toBe(['reserve_identity', 'select_server', 'create_database'])
        ->and($steps->pluck('status')->all())->toBe(['pending', 'pending', 'pending']);

    $one = new CountingFakeStep;
    $two = new CountingFakeStep;
    $three = new CountingFakeStep;

    $runner->run($runId, [
        'reserve_identity' => $one,
        'select_server' => $two,
        'create_database' => $three,
    ], ['code' => 'TEN001'], null, 'worker-1');

    expect($one->runs)->toBe(1)->and($two->runs)->toBe(1)->and($three->runs)->toBe(1);

    $finished = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
    expect($finished->status)->toBe('completed')->and($finished->current_step)->toBeNull();

    $doneSteps = PlatformSeed::connection()->table('tenant_provisioning_steps')->where('run_id', $runId)->get();
    expect($doneSteps->pluck('status')->unique()->all())->toBe(['done']);
});

it('fails the step and the run on a thrown step, and never attempts a later one', function () {
    $runner = provisioningRunner();
    $order = [StepName::ReserveIdentity, StepName::SelectServer, StepName::CreateDatabase];
    $runId = $runner->plan('create', [], null, 'worker-1', $order);

    $never = new CountingFakeStep;

    expect(fn () => $runner->run($runId, [
        'reserve_identity' => new CountingFakeStep,
        'select_server' => new class implements ProvisioningStep
        {
            public function run(RunContext $context, StepJournal $journal): array
            {
                throw new RuntimeException('select_server blew up');
            }

            public function compensate(RunContext $context): ?string
            {
                return null;
            }
        },
        'create_database' => $never,
    ], [], null, 'worker-1'))->toThrow(ProvisioningStepFailed::class);

    expect($never->runs)->toBe(0);

    $run = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
    expect($run->status)->toBe('failed')->and($run->current_step)->toBe('select_server');

    $stepRows = PlatformSeed::connection()->table('tenant_provisioning_steps')
        ->where('run_id', $runId)->orderBy('sequence')->get()->keyBy('step');

    expect($stepRows['reserve_identity']->status)->toBe('done')
        ->and($stepRows['select_server']->status)->toBe('failed')
        ->and($stepRows['select_server']->error)->toContain('select_server blew up')
        ->and($stepRows['create_database']->status)->toBe('pending');
});

it('resumes a run, skipping steps already done, and the retried step succeeds on its second attempt', function () {
    $runner = provisioningRunner();
    $order = [StepName::ReserveIdentity, StepName::SelectServer, StepName::CreateDatabase];
    $runId = $runner->plan('create', [], null, 'worker-1', $order);

    $reserve = new CountingFakeStep;
    $flaky = new FlakyFakeStep;
    $never = new CountingFakeStep;

    expect(fn () => $runner->run($runId, [
        'reserve_identity' => $reserve,
        'select_server' => $flaky,
        'create_database' => $never,
    ], [], null, 'worker-1'))->toThrow(ProvisioningStepFailed::class);

    expect($reserve->runs)->toBe(1)->and($flaky->attempts)->toBe(1)->and($never->runs)->toBe(0);

    // A real resume always claims first (ProvisionTenantHandler::resume()):
    // claim() is what takes the run back to `running`, which
    // markRunCompleted() now requires (review round 1 W-3).
    $runner->claim($runId, 'worker-1');

    // Resume: reserve_identity is skipped (already done), select_server is
    // retried and this time succeeds, create_database then runs.
    $runner->run($runId, [
        'reserve_identity' => $reserve,
        'select_server' => $flaky,
        'create_database' => $never,
    ], [], null, 'worker-1');

    expect($reserve->runs)->toBe(1)
        ->and($flaky->attempts)->toBe(2)
        ->and($never->runs)->toBe(1);

    $run = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
    expect($run->status)->toBe('completed');
});

it('AC-18: sanitises a stored error, redacting both the resolved password and the journalled reference', function () {
    $root = sys_get_temp_dir().'/flexipos-t026-runner-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    config(['tenancy.secrets.file_root' => $root]);
    app()->forgetInstance(SecretResolver::class);
    app()->forgetInstance(WritableSecretStore::class);

    try {
        $reference = 'file://'.$root.'/tenant_000001_app';
        $password = 'sup3r-s3cr3t-pa55word';
        app(WritableSecretStore::class)->put($reference, new Secret($password));

        $runner = provisioningRunner();
        $order = [StepName::ReserveIdentity, StepName::SelectServer];
        $runId = $runner->plan('create', [], null, 'worker-1', $order);

        expect(fn () => $runner->run($runId, [
            'reserve_identity' => new ThrowingFakeStep($reference, $password),
            'select_server' => new CountingFakeStep,
        ], [], null, 'worker-1'))->toThrow(ProvisioningStepFailed::class);

        $stepRow = PlatformSeed::connection()->table('tenant_provisioning_steps')
            ->where('run_id', $runId)->where('step', 'reserve_identity')->first();
        $runRow = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();

        expect($stepRow->error)->not->toContain($password)
            ->and($stepRow->error)->not->toContain($reference)
            ->and($runRow->error)->not->toContain($password)
            ->and($runRow->error)->not->toContain($reference);

        // The reference the step journalled before throwing did survive
        // into the output - the control that this is a real redaction and
        // not an accident of nothing being journalled at all.
        $output = json_decode((string) $stepRow->output, true);
        expect($output['credential_ref'])->toBe($reference);
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            @unlink($root.'/'.$entry);
        }

        @rmdir($root);
    }
});

it('refuses to claim an unknown run id', function () {
    $runner = provisioningRunner();

    expect(fn () => $runner->claim('not-a-real-id', 'worker-2'))->toThrow(ProvisioningRunRefused::class);
});

it('refuses to resume a completed run, with no write', function () {
    $runner = provisioningRunner();
    $order = [StepName::ReserveIdentity];
    $runId = $runner->plan('create', [], null, 'worker-1', $order);

    $runner->run($runId, ['reserve_identity' => new CountingFakeStep], [], null, 'worker-1');

    $before = (array) PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();

    expect(fn () => $runner->claim($runId, 'worker-2'))->toThrow(ProvisioningRunRefused::class);

    $after = (array) PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
    expect($after)->toBe($before);
});

it('refuses to claim a non-stale running run, and claims a stale one', function () {
    $runner = provisioningRunner();
    $order = [StepName::ReserveIdentity, StepName::SelectServer];
    $runId = $runner->plan('create', [], null, 'worker-1', $order);

    // Fresh plan: status running, heartbeat just now - not stale.
    expect(fn () => $runner->claim($runId, 'worker-2'))->toThrow(ProvisioningRunRefused::class);

    // Move the clock past the staleness window (default 30 minutes).
    $clock = app(RunnerClock::class);
    $clock->advanceMinutes(31);

    $claimed = $runner->claim($runId, 'worker-2');
    expect($claimed['claimed_by'])->toBe('worker-2');
});

it('refuses to resume or re-abandon a rolled_back run, with its own named refusal (review round 1 W-7)', function () {
    $runner = provisioningRunner();
    $order = [StepName::ReserveIdentity];
    $runId = $runner->plan('create', [], null, 'worker-1', $order);

    PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->update([
        'status' => 'rolled_back',
    ]);

    try {
        $runner->claim($runId, 'worker-2');
        $this->fail('Expected a ProvisioningRunRefused exception.');
    } catch (ProvisioningRunRefused $refused) {
        // Distinct from leaseHeld()'s "wait for the staleness window"
        // message, which would be actively misleading for a run that can
        // never become claimable again.
        expect($refused->getMessage())->toContain('abandoned')
            ->and($refused->getMessage())->not->toContain('staleness window');
    }

    $after = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
    expect($after->status)->toBe('rolled_back');
});

it('aborts mid-run, with no further write, when another worker claims the run under it (review round 1 W-3)', function () {
    $runner = provisioningRunner();
    $order = [StepName::ReserveIdentity, StepName::SelectServer];
    $runId = $runner->plan('create', [], null, 'worker-1', $order);

    $never = new CountingFakeStep;

    expect(fn () => $runner->run($runId, [
        'reserve_identity' => new StealsLeaseFakeStep,
        'select_server' => $never,
    ], [], null, 'worker-1'))->toThrow(ProvisioningRunRefused::class);

    // The second step never ran at all: the lease was lost before this
    // worker's own markStepDone() for reserve_identity could even commit.
    expect($never->runs)->toBe(0);

    $run = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
    expect($run->claimed_by)->toBe('worker-2')
        ->and($run->status)->not->toBe('completed');

    // worker-1's own markStepDone() for reserve_identity was itself refused:
    // the step row was never actually marked done under the lost lease.
    $reserveRow = PlatformSeed::connection()->table('tenant_provisioning_steps')
        ->where('run_id', $runId)->where('step', 'reserve_identity')->first();
    expect($reserveRow->status)->toBe('running');
});

it('lets exactly one of two sequential claims on a stale run win', function () {
    $runner = provisioningRunner();
    $order = [StepName::ReserveIdentity];
    $runId = $runner->plan('create', [], null, 'worker-1', $order);

    $clock = app(RunnerClock::class);
    $clock->advanceMinutes(31);

    $claimed = $runner->claim($runId, 'worker-2');
    expect($claimed['claimed_by'])->toBe('worker-2');

    // The run is now running again with a fresh heartbeat - immediately not
    // stale, so a second claim loses.
    expect(fn () => $runner->claim($runId, 'worker-3'))->toThrow(ProvisioningRunRefused::class);
});
