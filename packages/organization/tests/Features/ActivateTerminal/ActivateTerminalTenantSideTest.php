<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ActivateTerminal;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Auth\TerminalSessions;
use Flexipos\Contracts\Organization\ActivatedTerminal;
use Flexipos\Contracts\Organization\ActivationAttempt;
use Flexipos\Contracts\Organization\TerminalActivation;
use Flexipos\Contracts\Organization\TerminalActivationRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminal;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminalHandler;
use Flexipos\Organization\Features\ReissueActivationCode\ReissueActivationCode;
use Flexipos\Organization\Features\ReissueActivationCode\ReissueActivationCodeHandler;
use Flexipos\Organization\Tests\Support\FixedTerminalSessions;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Illuminate\Database\QueryException;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 11 - the tenant side of activation (AC-29, AC-30, AC-32, D-16, D-34)
|--------------------------------------------------------------------------
|
| The slice is driven through the contract it implements, with a callable
| standing in for the mint, and the callable is the instrument for the two
| properties that matter most: **it is called last and inside the
| transaction**, and **when it throws nothing the slice wrote survives**.
|
| Every refusal asserts that no device, credential or log row exists and the
| terminal is untouched: a refusal that wrote and then threw would otherwise
| read as a refusal. The expiry boundary is both sides of one instant.
|
| `activated_by` is read back from the issuing audit row, and its three cases
| each have a test: the creator, a later re-issuer, and the audit row absent.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-22 10:00:00.000'));
    app()->instance(TerminalSessions::class, new FixedTerminalSessions);
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * A terminal created through the slice, so its hash, expiry and audit row are
 * the shipped ones, and the secret its code carries.
 *
 * @return array{terminal: string, branch: string, secret: string, creator: string, store: string}
 */
function activationTenantWorld(): array
{
    $branch = OrganizationSeed::branch();
    $store = OrganizationSeed::location($branch, 'ST1', 'branch_store', ['is_sellable_from' => true]);
    $creator = AccessSeed::employee('act_creator');

    $created = app(CreateTerminalHandler::class)->handle(new CreateTerminal(
        ['branch_id' => $branch, 'location_id' => $store, 'code' => 't1', 'name' => 'Till'],
        $creator
    ));

    return [
        'terminal' => $created->terminalId,
        'branch' => $branch,
        'secret' => substr($created->activationCode, -9),
        'creator' => $creator,
        'store' => $store,
    ];
}

/**
 * Move the clock, and drop the activation singleton that captured the old one:
 * a clock bound after the handler was first resolved is never seen by it.
 */
function tenantSideClockAt(string $at): void
{
    app()->instance(RunnerClock::class, RunnerClock::fixedAt($at));
    app()->forgetInstance(TerminalActivation::class);
}

function tenantSideAttempt(string $secret, ?string $fingerprint = null): ActivationAttempt
{
    return new ActivationAttempt($secret, $fingerprint ?? hash('sha256', 'device-one'), 'Windows', null, null, null, '203.0.113.5');
}

/**
 * Activate, with a callable that records when it ran.
 *
 * @param  array<string, mixed>  $seen
 */
function tenantSideActivate(ActivationAttempt $attempt, array &$seen = []): mixed
{
    /** @var TerminalActivation $activation */
    $activation = app(TerminalActivation::class);

    return $activation->activate($attempt, function (ActivatedTerminal $terminal) use (&$seen): string {
        $connection = OrganizationSeed::connection();

        $seen = [
            'terminal' => $terminal->terminalId,
            'branch' => $terminal->branchId,
            'insideTransaction' => $connection->transactionLevel() > 0,
            'deviceWritten' => $connection->table('devices')->where('terminal_id', $terminal->terminalId)->exists(),
            'credentialWritten' => $connection->table('terminal_credentials')->where('terminal_id', $terminal->terminalId)->exists(),
            'logWritten' => $connection->table('access_log')->where('terminal_id', $terminal->terminalId)->exists(),
            'status' => (string) $connection->table('terminals')->where('id', $terminal->terminalId)->value('status'),
        ];

        return 'minted';
    });
}

/**
 * The state a refusal must leave exactly as it found it.
 *
 * @return array<string, mixed>
 */
function tenantSideSnapshot(string $terminalId): array
{
    $c = OrganizationSeed::connection();

    return [
        'terminal' => (array) $c->table('terminals')->where('id', $terminalId)->first(),
        'devices' => $c->table('devices')->count(),
        'credentials' => $c->table('terminal_credentials')->count(),
        'log' => $c->table('access_log')->count(),
    ];
}

it('writes device, terminal, credential and log in one transaction and calls the mint last, inside it (AC-29)', function () {
    $w = activationTenantWorld();
    $seen = [];

    $result = tenantSideActivate(tenantSideAttempt($w['secret']), $seen);

    expect($result)->toBe('minted')
        ->and($seen['terminal'])->toBe($w['terminal'])
        ->and($seen['branch'])->toBe($w['branch'])
        ->and($seen['insideTransaction'])->toBeTrue()
        // Every tenant write had happened by the time the callable ran.
        ->and($seen['deviceWritten'])->toBeTrue()
        ->and($seen['credentialWritten'])->toBeTrue()
        ->and($seen['logWritten'])->toBeTrue()
        ->and($seen['status'])->toBe('active');
});

it('leaves no tenant row at all when the mint throws (AC-29)', function () {
    $w = activationTenantWorld();
    $before = tenantSideSnapshot($w['terminal']);

    /** @var TerminalActivation $activation */
    $activation = app(TerminalActivation::class);

    expect(fn () => $activation->activate(tenantSideAttempt($w['secret']), static function (): never {
        throw new RuntimeException('the master is unavailable');
    }))->toThrow(RuntimeException::class, 'the master is unavailable');

    expect(tenantSideSnapshot($w['terminal']))->toBe($before)
        ->and($before['terminal']['status'])->toBe('pending')
        ->and($before['terminal']['activation_code_hash'])->not->toBeNull();

    // The control: the same code, with a mint that does not throw, activates.
    expect(tenantSideActivate(tenantSideAttempt($w['secret'])))->toBe('minted');
});

it('consumes the code: the hash and expiry are cleared and the same secret is then unknown (AC-29)', function () {
    $w = activationTenantWorld();

    tenantSideActivate(tenantSideAttempt($w['secret']));

    $row = (array) OrganizationSeed::connection()->table('terminals')->where('id', $w['terminal'])->first();

    expect($row['activation_code_hash'])->toBeNull()
        ->and($row['activation_expires_at'])->toBeNull()
        ->and($row['status'])->toBe('active')
        ->and($row['last_seen_at'])->toBe('2026-10-22 10:00:00.000');

    $reason = null;

    try {
        tenantSideActivate(tenantSideAttempt($w['secret'], hash('sha256', 'device-two')));
    } catch (TerminalActivationRefused $refused) {
        $reason = $refused->reasonClass;
    }

    expect($reason)->toBe(TerminalActivationRefused::UNKNOWN_CODE);
});

it('refuses an unknown secret, a revoked, paused or active terminals code, writing nothing (AC-30)', function (string $status) {
    $w = activationTenantWorld();

    if ($status !== 'unknown') {
        OrganizationSeed::connection()->table('terminals')->where('id', $w['terminal'])->update(['status' => $status]);
    }

    $before = tenantSideSnapshot($w['terminal']);
    $secret = $status === 'unknown' ? 'ABCD-EFGH' : $w['secret'];
    $reason = null;

    try {
        tenantSideActivate(tenantSideAttempt($secret));
    } catch (TerminalActivationRefused $refused) {
        $reason = $refused->reasonClass;
    }

    expect($reason)->toBe(TerminalActivationRefused::UNKNOWN_CODE)
        ->and(tenantSideSnapshot($w['terminal']))->toBe($before);
})->with(['unknown', 'revoked', 'inactive', 'active']);

it('refuses at exactly the expiry instant and accepts a millisecond before it (AC-30)', function () {
    $w = activationTenantWorld();

    // The code was issued at 10:00:00.000 and lives 24 hours.
    tenantSideClockAt('2026-10-23 09:59:59.999');

    $early = tenantSideActivate(tenantSideAttempt($w['secret']));

    expect($early)->toBe('minted');

    $second = activationTenantWorld2();

    tenantSideClockAt('2026-10-23 10:00:00.000');

    $before = tenantSideSnapshot($second['terminal']);
    $reason = null;

    try {
        tenantSideActivate(tenantSideAttempt($second['secret'], hash('sha256', 'device-two')));
    } catch (TerminalActivationRefused $refused) {
        $reason = $refused->reasonClass;
    }

    expect($reason)->toBe(TerminalActivationRefused::EXPIRED)
        ->and(tenantSideSnapshot($second['terminal']))->toBe($before);
});

/**
 * A second pending terminal in a second branch, created at the fixed clock.
 *
 * @return array{terminal: string, secret: string}
 */
function activationTenantWorld2(): array
{
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-22 10:00:00.000'));

    $branch = OrganizationSeed::branch();
    $store = OrganizationSeed::location($branch, 'ST2', 'branch_store', ['is_sellable_from' => true]);

    $created = app(CreateTerminalHandler::class)->handle(new CreateTerminal(
        ['branch_id' => $branch, 'location_id' => $store, 'code' => 't2', 'name' => 'Second'],
        AccessSeed::employee('act_second')
    ));

    return ['terminal' => $created->terminalId, 'secret' => substr($created->activationCode, -9)];
}

it('refuses a terminal whose branch has been deactivated since the code was issued (AC-30)', function () {
    $w = activationTenantWorld();

    OrganizationSeed::connection()->table('branches')->where('id', $w['branch'])->update(['is_active' => false]);

    $before = tenantSideSnapshot($w['terminal']);
    $reason = null;

    try {
        tenantSideActivate(tenantSideAttempt($w['secret']));
    } catch (TerminalActivationRefused $refused) {
        $reason = $refused->reasonClass;
    }

    expect($reason)->toBe(TerminalActivationRefused::BRANCH_INACTIVE)
        ->and(tenantSideSnapshot($w['terminal']))->toBe($before);
});

it('refuses a fingerprint that is another terminals live device, accepts it once that terminal is revoked, and ignores the terminals own (AC-30, AC-32, D-16)', function () {
    $first = activationTenantWorld();
    $second = activationTenantWorld2();
    $shared = hash('sha256', 'the-shared-tablet');

    tenantSideActivate(tenantSideAttempt($first['secret'], $shared));

    $before = tenantSideSnapshot($second['terminal']);
    $reason = null;

    try {
        tenantSideActivate(tenantSideAttempt($second['secret'], $shared));
    } catch (TerminalActivationRefused $refused) {
        $reason = $refused->reasonClass;
    }

    expect($reason)->toBe(TerminalActivationRefused::DEVICE_IN_USE)
        ->and(tenantSideSnapshot($second['terminal']))->toBe($before);

    // Revoke the first terminal's device: the fingerprint is free again.
    OrganizationSeed::connection()->table('devices')->where('terminal_id', $first['terminal'])->update(['revoked_at' => OrganizationSeed::NOW]);

    expect(tenantSideActivate(tenantSideAttempt($second['secret'], $shared)))->toBe('minted')
        ->and(OrganizationSeed::connection()->table('devices')->where('fingerprint', $shared)->count())->toBe(2);
});

it('stores the fingerprint and the descriptive fields exactly (AC-32)', function () {
    $w = activationTenantWorld();
    $fingerprint = hash('sha256', 'exact-device');

    /** @var TerminalActivation $activation */
    $activation = app(TerminalActivation::class);
    $activation->activate(new ActivationAttempt($w['secret'], $fingerprint, 'Linux', 'Firefox', 'Ubuntu', '1366x768', '2001:db8::1'), static fn (): string => 'minted');

    $device = (array) OrganizationSeed::connection()->table('devices')->where('terminal_id', $w['terminal'])->first();

    expect($device['fingerprint'])->toBe($fingerprint)
        ->and([$device['platform'], $device['browser'], $device['os'], $device['screen']])->toBe(['Linux', 'Firefox', 'Ubuntu', '1366x768'])
        ->and($device['revoked_at'])->toBeNull()
        ->and(OrganizationSeed::connection()->table('terminals')->where('id', $w['terminal'])->value('current_device_id'))->toBe($device['id'])
        ->and(OrganizationSeed::connection()->table('terminal_credentials')->where('terminal_id', $w['terminal'])->value('device_fingerprint'))->toBe($fingerprint);

    // The packed address of the log row round-trips.
    $log = (array) OrganizationSeed::connection()->table('access_log')->where('terminal_id', $w['terminal'])->first();

    expect($log['type'])->toBe('terminal_login')
        ->and($log['employee_id'])->toBeNull()
        ->and($log['branch_id'])->toBe($w['branch'])
        ->and($log['ip'])->toBe(inet_pton('2001:db8::1'));
});

it('records the creator as activated_by, the latest re-issuer when the code was re-issued, and null when no audit row exists (Ruling 4, D-34)', function () {
    // The creator.
    $w = activationTenantWorld();
    tenantSideActivate(tenantSideAttempt($w['secret']));

    expect(OrganizationSeed::connection()->table('terminal_credentials')->where('terminal_id', $w['terminal'])->value('activated_by'))->toBe($w['creator']);

    // A re-issuer: the newest issuing row names the actor of the code in force.
    $second = activationTenantWorld2();
    $reissuer = AccessSeed::employee('act_reissuer');

    $reissued = app(ReissueActivationCodeHandler::class)->handle(new ReissueActivationCode($second['terminal'], $reissuer));

    tenantSideActivate(tenantSideAttempt(substr($reissued->activationCode, -9), hash('sha256', 'device-r')));

    expect(OrganizationSeed::connection()->table('terminal_credentials')->where('terminal_id', $second['terminal'])->value('activated_by'))->toBe($reissuer);

    // No audit row: null, never a guess.
    $third = activationTenantWorld2b();

    OrganizationSeed::connection()->table('audit_logs')->where('subject_id', $third['terminal'])->delete();

    tenantSideActivate(tenantSideAttempt($third['secret'], hash('sha256', 'device-n')));

    expect(OrganizationSeed::connection()->table('terminal_credentials')->where('terminal_id', $third['terminal'])->value('activated_by'))->toBeNull();
});

/**
 * A third pending terminal.
 *
 * @return array{terminal: string, secret: string}
 */
function activationTenantWorld2b(): array
{
    $branch = OrganizationSeed::branch();
    $store = OrganizationSeed::location($branch, 'ST3', 'branch_store', ['is_sellable_from' => true]);

    $created = app(CreateTerminalHandler::class)->handle(new CreateTerminal(
        ['branch_id' => $branch, 'location_id' => $store, 'code' => 't3', 'name' => 'Third'],
        AccessSeed::employee('act_third')
    ));

    return ['terminal' => $created->terminalId, 'secret' => substr($created->activationCode, -9)];
}

it('refuses a second credential for one terminal at the key, so a double activation cannot create two (AC-2)', function () {
    $w = activationTenantWorld();

    tenantSideActivate(tenantSideAttempt($w['secret']));

    expect(fn () => OrganizationSeed::connection()->table('terminal_credentials')->insert([
        'terminal_id' => $w['terminal'], 'status' => 'active', 'activated_at' => OrganizationSeed::NOW,
    ]))->toThrow(QueryException::class);
});
