<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\RevokeTerminal;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Auth\TerminalSessions;
use Flexipos\Organization\Features\RevokeTerminal\RevokeTerminal;
use Flexipos\Organization\Features\RevokeTerminal\RevokeTerminalHandler;
use Flexipos\Organization\Features\RevokeTerminal\RevokeTerminalResult;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\FixedTerminalSessions;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Flexipos\Organization\Tests\Support\RecordingLogger;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 13 - revoking a terminal (AC-38 tenant half, AC-39, AC-40)
|--------------------------------------------------------------------------
|
| The master half is proved over the real `MasterTerminalSessions` in the
| host's HTTP test; here the contract is a double so that the failure of the
| second step can be injected. One fixture per case, built once.
|
| Each refusal-shaped claim has its control: a repeat revoke with the master
| failing is a success that changes nothing on the tenant side, and the same
| repeat with the master healthy is the one that completes the retry.
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

/**
 * @return array{terminal: string, other: string, device: string, actor: string, branch: string}
 */
function revokeWorld(): array
{
    $branch = OrganizationSeed::branch();
    $store = OrganizationSeed::location($branch, 'ST1', 'branch_store', ['is_sellable_from' => true]);
    $terminal = OrganizationSeed::terminal($branch, $store, 'T1', 'active');
    $other = OrganizationSeed::terminal($branch, $store, 'T2', 'active');

    $c = OrganizationSeed::connection();
    $device = (string) Str::ulid();

    foreach ([[$terminal, $device], [$other, (string) Str::ulid()]] as [$t, $d]) {
        $c->table('devices')->insert([
            'id' => $d, 'terminal_id' => $t, 'fingerprint' => str_repeat('b', 64),
            'registered_at' => OrganizationSeed::NOW, 'created_at' => OrganizationSeed::NOW, 'updated_at' => OrganizationSeed::NOW,
        ]);
        $c->table('terminals')->where('id', $t)->update(['current_device_id' => $d]);
        $c->table('terminal_credentials')->insert(['terminal_id' => $t, 'status' => 'active', 'activated_at' => OrganizationSeed::NOW]);
    }

    return ['terminal' => $terminal, 'other' => $other, 'device' => $device, 'actor' => AccessSeed::employee('rv_actor', ['display_name' => 'Revoker']), 'branch' => $branch];
}

function revoke(string $terminalId, ?string $actor, FixedTerminalSessions $sessions, ?RecordingLogger $logger = null): RevokeTerminalResult
{
    app()->instance(TerminalSessions::class, $sessions);

    if ($logger !== null) {
        app()->instance(LoggerInterface::class, $logger);
    }

    /** @var RevokeTerminalHandler $handler */
    $handler = app()->make(RevokeTerminalHandler::class);

    return $handler->handle(new RevokeTerminal($terminalId, $actor));
}

/**
 * @return array<string, mixed>
 */
function revokeRows(string $terminalId): array
{
    $c = OrganizationSeed::connection();

    return [
        'terminal' => (array) $c->table('terminals')->where('id', $terminalId)->first(),
        'credential' => (array) $c->table('terminal_credentials')->where('terminal_id', $terminalId)->first(),
        'devices' => $c->table('devices')->where('terminal_id', $terminalId)->get()->map(static fn (object $r): array => (array) $r)->all(),
    ];
}

it('revokes the terminal, its credential and its devices in one transaction and writes one whole audit row (AC-38)', function () {
    $w = revokeWorld();
    $c = OrganizationSeed::connection();
    $c->table('terminals')->where('id', $w['terminal'])->update(['activation_code_hash' => str_repeat('c', 64), 'activation_expires_at' => '2026-10-23 10:00:00.000']);
    $untouched = json_encode(revokeRows($w['other']));
    $sessions = new FixedTerminalSessions;

    $result = revoke($w['terminal'], $w['actor'], $sessions);

    $rows = revokeRows($w['terminal']);
    $audit = OrganizationSeed::onlyAuditRow();

    expect($result->sessionsRevoked)->toBeTrue()
        ->and($result->toResponse())->toBe(['terminal_id' => $w['terminal'], 'status' => 'revoked', 'sessions_revoked' => true])
        ->and($rows['terminal']['status'])->toBe('revoked')
        ->and($rows['terminal']['activation_code_hash'])->toBeNull()
        ->and($rows['terminal']['activation_expires_at'])->toBeNull()
        ->and($rows['credential']['status'])->toBe('revoked')
        ->and($rows['credential']['revoked_by'])->toBe($w['actor'])
        ->and($rows['credential']['revoked_at'])->not->toBeNull()
        ->and($rows['devices'])->toHaveCount(1)
        ->and($rows['devices'][0]['revoked_by'])->toBe($w['actor'])
        ->and($rows['devices'][0]['revoked_at'])->not->toBeNull()
        // The master step ran once, for this tenant and this terminal only.
        ->and($sessions->revoked)->toBe([[tenancy()->current()->tenantId(), $w['terminal']]])
        // The other terminal in the same tenant is untouched, row by row.
        ->and(json_encode(revokeRows($w['other'])))->toBe($untouched)
        ->and($audit['action'])->toBe('terminal_revoked')
        ->and($audit['subject_id'])->toBe($w['terminal'])
        ->and($audit['actor_id'])->toBe($w['actor'])
        ->and($audit['branch_id'])->toBe($w['branch'])
        ->and($audit['before']['status'])->toBe('active')
        ->and($audit['after']['status'])->toBe('revoked')
        ->and($audit['changed_fields'])->toBe(['activation_expires_at', 'has_pending_code', 'status'])
        ->and($audit['before']['has_pending_code'])->toBeTrue()
        ->and($audit['after']['has_pending_code'])->toBeFalse();

    fwrite(STDOUT, PHP_EOL.'AC-38 audit row: '.json_encode($audit).PHP_EOL);
});

it('keeps the terminal revoked when the master step fails, says so, and logs the class and never the message (AC-39)', function () {
    $w = revokeWorld();
    $logger = new RecordingLogger;

    $result = revoke($w['terminal'], $w['actor'], new FixedTerminalSessions(failing: true), $logger);

    $logged = json_encode($logger->lines);

    expect($result->sessionsRevoked)->toBeFalse()
        ->and($result->toResponse()['sessions_revoked'])->toBeFalse()
        ->and(revokeRows($w['terminal'])['terminal']['status'])->toBe('revoked')
        ->and(OrganizationSeed::audit())->toHaveCount(1)
        ->and($logged)->toContain('RuntimeException')
        ->and($logged)->not->toContain('The master is unavailable');
});

it('repeats as a success that retries the master step and writes no second audit row (AC-39, AC-40)', function () {
    $w = revokeWorld();

    revoke($w['terminal'], $w['actor'], new FixedTerminalSessions(failing: true), new RecordingLogger);

    $snapshot = json_encode(revokeRows($w['terminal']));
    $auditBefore = json_encode(OrganizationSeed::audit());

    // Control: while the master is still down the repeat is the same honest answer.
    $again = revoke($w['terminal'], $w['actor'], new FixedTerminalSessions(failing: true), new RecordingLogger);

    expect($again->sessionsRevoked)->toBeFalse();

    $healthy = new FixedTerminalSessions;
    $retry = revoke($w['terminal'], $w['actor'], $healthy);

    expect($retry->sessionsRevoked)->toBeTrue()
        ->and($healthy->revoked)->toBe([[tenancy()->current()->tenantId(), $w['terminal']]])
        // Nothing moved on the tenant side, and no second audit row.
        ->and(json_encode(revokeRows($w['terminal'])))->toBe($snapshot)
        ->and(json_encode(OrganizationSeed::audit()))->toBe($auditBefore);
});

it('refuses an unknown terminal as not_found, writing nothing (AC-63)', function () {
    $w = revokeWorld();
    $sessions = new FixedTerminalSessions;

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => revoke((string) Str::ulid(), $w['actor'], $sessions));

    expect($sessions->revoked)->toBe([])
        ->and(OrganizationSeed::audit())->toBe([]);

    // Control: the same call against a real terminal is not refused.
    expect(revoke($w['terminal'], $w['actor'], $sessions)->sessionsRevoked)->toBeTrue();
});
