<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ReissueActivationCode;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Auth\TerminalSessions;
use Flexipos\Contracts\Organization\ActivationCodeFormat;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminalResult;
use Flexipos\Organization\Features\ReissueActivationCode\ReissueActivationCode;
use Flexipos\Organization\Features\ReissueActivationCode\ReissueActivationCodeHandler;
use Flexipos\Organization\Support\ActivationCode;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\FixedTerminalSessions;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 10 - re-issuing an activation code (AC-28, AC-20)
|--------------------------------------------------------------------------
|
| "The old code now fails activation" is asserted at the row: the stored hash
| is the new code's and no longer the old one's, which is exactly what the
| activation lookup keys on. The control AC-28 names - the new code activates
| it - is the activation slice's (step 11); here the new hash is shown to be
| the one the new code produces.
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

function reissueCode(string $terminalId, ?string $actorId = null): CreateTerminalResult
{
    /** @var ReissueActivationCodeHandler $handler */
    $handler = app(ReissueActivationCodeHandler::class);

    return $handler->handle(new ReissueActivationCode($terminalId, $actorId));
}

/**
 * A pending terminal carrying a known old code.
 *
 * @return array{terminal: string, branch: string, oldHash: string, actor: string}
 */
function reissueWorld(string $status = 'pending'): array
{
    $branch = OrganizationSeed::branch();
    $store = OrganizationSeed::location($branch, 'ST1', 'branch_store', ['is_sellable_from' => true]);
    $oldHash = ActivationCode::hashOf('ABCD-EFGH');

    $terminal = OrganizationSeed::terminal($branch, $store, 'T1', $status, [
        'activation_code_hash' => $status === 'pending' ? $oldHash : null,
        'activation_expires_at' => $status === 'pending' ? '2026-10-22 08:00:00.000' : null,
    ]);

    return ['terminal' => $terminal, 'branch' => $branch, 'oldHash' => $oldHash, 'actor' => AccessSeed::employee('re_actor', ['display_name' => 'Reissue Person'])];
}

it('replaces the code and the expiry of a pending terminal, even an expired one, and the old hash is gone (AC-28, AC-20)', function () {
    $ids = reissueWorld();

    $result = reissueCode(strtolower($ids['terminal']), $ids['actor']);

    $row = (array) OrganizationSeed::connection()->table('terminals')->where('id', $ids['terminal'])->first();
    $parsed = ActivationCodeFormat::parse($result->activationCode);

    expect($parsed)->not->toBeNull()
        ->and($row['activation_code_hash'])->not->toBe($ids['oldHash'])
        ->and($row['activation_code_hash'])->toBe(hash('sha256', str_replace('-', '', $parsed['secret'])))
        ->and($row['activation_expires_at'])->toBe('2026-10-23 10:00:00.000')
        ->and($result->terminalId)->toBe($ids['terminal'])
        ->and($row['status'])->toBe('pending');

    $audit = OrganizationSeed::onlyAuditRow();

    expect($audit['action'])->toBe('activation_code_issued')
        ->and($audit['subject_id'])->toBe($ids['terminal'])
        ->and($audit['branch_id'])->toBe($ids['branch'])
        ->and($audit['actor_id'])->toBe($ids['actor'])
        ->and($audit['changed_fields'])->toBe(['activation_expires_at'])
        ->and((string) json_encode($audit))->not->toContain($parsed['secret'])
        ->and((string) json_encode($audit))->not->toContain((string) $row['activation_code_hash'])
        ->and((string) json_encode($audit))->not->toContain($ids['oldHash']);
});

it('issues a different code every time (AC-28)', function () {
    $ids = reissueWorld();

    $first = reissueCode($ids['terminal'])->activationCode;
    $second = reissueCode($ids['terminal'])->activationCode;

    expect($first)->not->toBe($second);
});

it('refuses an active or inactive terminal as terminal_not_pending, and a revoked one as terminal_revoked, changing nothing (AC-28)', function (string $status, OrganizationRefusal $reason) {
    $ids = reissueWorld($status);
    $before = (array) OrganizationSeed::connection()->table('terminals')->where('id', $ids['terminal'])->first();

    OrganizationSeed::refusal($reason, fn () => reissueCode($ids['terminal'], $ids['actor']));

    expect((array) OrganizationSeed::connection()->table('terminals')->where('id', $ids['terminal'])->first())->toBe($before)
        ->and(OrganizationSeed::audit())->toBe([]);
})->with([
    'active' => ['active', OrganizationRefusal::TerminalNotPending],
    'inactive' => ['inactive', OrganizationRefusal::TerminalNotPending],
    'revoked' => ['revoked', OrganizationRefusal::TerminalRevoked],
]);

it('answers not_found for an unknown terminal and validation for a malformed id (AC-28)', function () {
    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => reissueCode('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => reissueCode('nope'));

    expect(OrganizationSeed::audit())->toBe([]);
});
