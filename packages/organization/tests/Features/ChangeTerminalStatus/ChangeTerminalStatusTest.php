<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ChangeTerminalStatus;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\ChangeTerminalStatus\ChangeTerminalStatus;
use Flexipos\Organization\Features\ChangeTerminalStatus\ChangeTerminalStatusHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 10 - pausing and resuming a terminal (AC-37 state half, AC-20)
|--------------------------------------------------------------------------
|
| What the criterion's control asks is that the round trip leaves the
| credential and the device untouched, so the round trip is run over a
| terminal that HAS a credential and a device, and both rows are compared
| whole before and after. The token half of AC-37 (the request-time refusal
| and the refresh families) is step 12's.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function terminalStatus(string $terminalId, mixed $status, ?string $actorId = null): void
{
    /** @var ChangeTerminalStatusHandler $handler */
    $handler = app(ChangeTerminalStatusHandler::class);

    $handler->handle(new ChangeTerminalStatus($terminalId, $status, $actorId));
}

/**
 * A terminal with a device and an active credential.
 *
 * @return array{terminal: string, branch: string, actor: string}
 */
function terminalStatusWorld(string $status = 'active'): array
{
    $branch = OrganizationSeed::branch();
    $store = OrganizationSeed::location($branch, 'ST1', 'branch_store', ['is_sellable_from' => true]);
    $terminal = OrganizationSeed::terminal($branch, $store, 'T1', $status);

    if ($status !== 'pending') {
        $device = (string) Str::ulid();

        OrganizationSeed::connection()->table('devices')->insert([
            'id' => $device, 'terminal_id' => $terminal, 'fingerprint' => str_repeat('a', 64),
            'registered_at' => OrganizationSeed::NOW, 'created_at' => OrganizationSeed::NOW, 'updated_at' => OrganizationSeed::NOW,
        ]);
        OrganizationSeed::connection()->table('terminals')->where('id', $terminal)->update(['current_device_id' => $device]);
        OrganizationSeed::connection()->table('terminal_credentials')->insert([
            'terminal_id' => $terminal, 'status' => 'active', 'activated_at' => OrganizationSeed::NOW,
        ]);
    }

    return ['terminal' => $terminal, 'branch' => $branch, 'actor' => AccessSeed::employee('ts_actor', ['display_name' => 'Pause Person'])];
}

function terminalRowsSnapshot(string $terminalId): string
{
    return (string) json_encode([
        OrganizationSeed::connection()->table('terminal_credentials')->where('terminal_id', $terminalId)->get()->all(),
        OrganizationSeed::connection()->table('devices')->where('terminal_id', $terminalId)->get()->all(),
    ]);
}

it('pauses and resumes, writing one whole audit row each, and leaves the credential and device rows unchanged (AC-37, AC-20)', function () {
    $ids = terminalStatusWorld();
    $snapshot = terminalRowsSnapshot($ids['terminal']);

    terminalStatus($ids['terminal'], 'inactive', $ids['actor']);

    expect(OrganizationSeed::connection()->table('terminals')->where('id', $ids['terminal'])->value('status'))->toBe('inactive')
        ->and(OrganizationSeed::onlyAuditRow())->toBe([
            'actor_type' => 'employee',
            'actor_id' => $ids['actor'],
            'actor_name' => 'Pause Person',
            'branch_id' => $ids['branch'],
            'module' => 'organization',
            'action' => 'terminal_deactivated',
            'subject_type' => 'terminal',
            'subject_id' => $ids['terminal'],
            'before' => ['status' => 'active'],
            'after' => ['status' => 'inactive'],
            'changed_fields' => ['status'],
            'user_agent' => null,
            'request_id' => null,
        ]);

    OrganizationSeed::connection()->table('audit_logs')->delete();

    terminalStatus(strtolower($ids['terminal']), 'active', $ids['actor']);

    expect(OrganizationSeed::connection()->table('terminals')->where('id', $ids['terminal'])->value('status'))->toBe('active')
        ->and(OrganizationSeed::onlyAuditRow()['action'])->toBe('terminal_reactivated')
        // The round trip leaves the credential and the device rows whole.
        ->and(terminalRowsSnapshot($ids['terminal']))->toBe($snapshot);
});

it('refuses a pending terminal as terminal_not_active and a revoked one as terminal_revoked, either way, changing nothing (AC-37)', function (string $status, OrganizationRefusal $reason, string $asked) {
    $ids = terminalStatusWorld($status);

    OrganizationSeed::refusal($reason, fn () => terminalStatus($ids['terminal'], $asked, $ids['actor']));

    expect(OrganizationSeed::connection()->table('terminals')->where('id', $ids['terminal'])->value('status'))->toBe($status)
        ->and(OrganizationSeed::audit())->toBe([]);
})->with([
    'pending, pause' => ['pending', OrganizationRefusal::TerminalNotActive, 'inactive'],
    'pending, resume' => ['pending', OrganizationRefusal::TerminalNotActive, 'active'],
    'revoked, pause' => ['revoked', OrganizationRefusal::TerminalRevoked, 'inactive'],
    'revoked, resume' => ['revoked', OrganizationRefusal::TerminalRevoked, 'active'],
]);

it('refuses pausing twice and resuming an active terminal as validation, with no audit row (AC-37)', function () {
    $ids = terminalStatusWorld();

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => terminalStatus($ids['terminal'], 'active', $ids['actor']), 'resume an active terminal');

    terminalStatus($ids['terminal'], 'inactive', $ids['actor']);
    OrganizationSeed::connection()->table('audit_logs')->delete();

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => terminalStatus($ids['terminal'], 'inactive', $ids['actor']), 'pause twice');

    expect(OrganizationSeed::audit())->toBe([]);
});

it('refuses any status but inactive and active, and an unknown terminal (AC-37)', function () {
    $ids = terminalStatusWorld();

    foreach (['revoked', 'pending', 'paused', '', null, 0, ['inactive']] as $bad) {
        OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => terminalStatus($ids['terminal'], $bad, $ids['actor']));
    }

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => terminalStatus('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'inactive'));

    expect(OrganizationSeed::connection()->table('terminals')->where('id', $ids['terminal'])->value('status'))->toBe('active');
});
