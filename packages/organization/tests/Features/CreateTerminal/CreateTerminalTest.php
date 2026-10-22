<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\CreateTerminal;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Auth\TerminalSessions;
use Flexipos\Contracts\Organization\ActivationCodeFormat;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\ActivationCodeUnavailable;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminal;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminalHandler;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminalResult;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\FixedTerminalSessions;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Flexipos\Organization\Tests\Support\RecordingLogger;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 10 - creating a terminal and issuing its code (AC-26, AC-15, AC-20)
|--------------------------------------------------------------------------
|
| `TerminalSessions` is a double bound over the contract (auth's real one is
| step 13's), answering a fixed tenant part, so the code's shape is asserted
| against a known prefix.
|
| **The code is asserted from three sides**: the printed form and its alphabet
| (including that none of the five ambiguous characters ever appears, over
| many draws), the stored hash being the SHA-256 of the eight characters and
| not the plaintext, and the expiry being the clock plus the configured
| lifetime, moved by moving the clock and by moving the configuration.
|
| Every refusal asserts that no terminal, no audit row and nothing else was
| written. The non-conforming tenant code is the one refusal that is a server
| error, so it is asserted as an exception and an error-level log line.
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
 * A branch with a sellable store, and an actor.
 *
 * @return array{branch: string, store: string, actor: string}
 */
function createTerminalWorld(): array
{
    $branch = OrganizationSeed::branch();

    return [
        'branch' => $branch,
        'store' => OrganizationSeed::location($branch, 'ST'.random_int(10, 99), 'branch_store', ['is_sellable_from' => true]),
        'actor' => AccessSeed::employee('ct_actor', ['display_name' => 'Terminal Person']),
    ];
}

/**
 * @param  array<string, mixed>  $fields
 */
function createTerminal(array $fields, ?string $actorId = null): CreateTerminalResult
{
    /** @var CreateTerminalHandler $handler */
    $handler = app(CreateTerminalHandler::class);

    return $handler->handle(new CreateTerminal($fields, $actorId));
}

/**
 * @param  array<string, string>  $ids
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function terminalInput(array $ids, array $overrides = []): array
{
    return array_merge(['branch_id' => $ids['branch'], 'location_id' => $ids['store'], 'code' => 't1', 'name' => 'Front till'], $overrides);
}

it('creates a pending terminal with a hashed code, expiring 24 hours on, and returns the code once (AC-26, AC-20)', function () {
    $ids = createTerminalWorld();

    $result = createTerminal(terminalInput($ids), $ids['actor']);

    $parsed = ActivationCodeFormat::parse($result->activationCode);
    $row = (array) OrganizationSeed::connection()->table('terminals')->where('id', $result->terminalId)->first();

    expect($parsed)->not->toBeNull()
        ->and($parsed['tenantPart'])->toBe('TEN-000123')
        ->and(preg_match('/\ATEN-000123-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}\z/', $result->activationCode))->toBe(1)
        ->and($row['status'])->toBe('pending')
        ->and($row['code'])->toBe('T1')
        ->and($row['current_device_id'])->toBeNull()
        ->and(strlen((string) $row['activation_code_hash']))->toBe(64)
        ->and($row['activation_code_hash'])->toBe(hash('sha256', str_replace('-', '', $parsed['secret'])))
        ->and($row['activation_code_hash'])->not->toBe($result->activationCode)
        ->and($row['activation_expires_at'])->toBe('2026-10-23 10:00:00.000')
        ->and($result->activationExpiresAt)->toBe('2026-10-23 10:00:00.000')
        ->and(OrganizationSeed::connection()->table('devices')->count())->toBe(0)
        ->and(OrganizationSeed::connection()->table('terminal_credentials')->count())->toBe(0);

    $audit = OrganizationSeed::onlyAuditRow();

    expect($audit['action'])->toBe('terminal_created')
        ->and($audit['subject_type'])->toBe('terminal')
        ->and($audit['subject_id'])->toBe($result->terminalId)
        ->and($audit['branch_id'])->toBe($ids['branch'])
        ->and($audit['actor_name'])->toBe('Terminal Person')
        ->and($audit['before'])->toBeNull()
        ->and($audit['after']['status'])->toBe('pending')
        ->and($audit['after']['has_pending_code'])->toBeTrue()
        // The code and the hash are in no part of the audit row.
        ->and((string) json_encode($audit))->not->toContain($parsed['secret'])
        ->and((string) json_encode($audit))->not->toContain((string) $row['activation_code_hash']);
});

it('draws only from the 31-character alphabet, over many codes, and two terminals get different codes (AC-26)', function () {
    $ids = createTerminalWorld();

    $codes = [];

    for ($i = 0; $i < 40; $i++) {
        $codes[] = createTerminal(terminalInput($ids, ['code' => 'a'.$i]))->activationCode;
    }

    $secrets = '';

    foreach ($codes as $code) {
        $secrets .= str_replace('-', '', substr($code, -9));
    }

    foreach (['0', 'O', '1', 'I', 'L'] as $ambiguous) {
        expect(str_contains($secrets, $ambiguous))->toBeFalse("[{$ambiguous}] must never appear in a secret");
    }

    expect(count(array_unique($codes)))->toBe(40)
        ->and(strlen($secrets))->toBe(40 * 8);
});

it('moves the expiry with the clock and with the configured lifetime (AC-26)', function () {
    $ids = createTerminalWorld();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-12-31 23:00:00.000'));
    config()->set('organization.activation_code_lifetime_hours', 6);

    $result = createTerminal(terminalInput($ids));

    expect($result->activationExpiresAt)->toBe('2027-01-01 05:00:00.000');
});

it('refuses an unknown branch, an inactive one and every bad location, and writes nothing (AC-26, AC-15)', function () {
    $ids = createTerminalWorld();
    $inactiveBranch = OrganizationSeed::branch(false);
    $inactiveStore = OrganizationSeed::location($inactiveBranch, 'IS1', 'branch_store', ['is_sellable_from' => true]);

    $otherBranch = OrganizationSeed::branch();
    $otherStore = OrganizationSeed::location($otherBranch, 'OS1', 'branch_store', ['is_sellable_from' => true]);

    $central = OrganizationSeed::location(null, 'CW1', 'warehouse', ['is_sellable_from' => true]);
    $notSellable = OrganizationSeed::location($ids['branch'], 'NS1', 'warehouse', ['is_sellable_from' => false]);
    $deactivated = OrganizationSeed::location($ids['branch'], 'DA1', 'warehouse', ['is_sellable_from' => true, 'is_active' => false]);

    $before = OrganizationSeed::counts();

    $cases = [
        'an unknown branch' => [OrganizationRefusal::UnknownBranch, ['branch_id' => (string) Str::ulid()]],
        'an inactive branch' => [OrganizationRefusal::BranchInactive, ['branch_id' => $inactiveBranch, 'location_id' => $inactiveStore]],
        'a malformed branch id' => [OrganizationRefusal::Validation, ['branch_id' => 'branch-7']],
        'a location of another branch' => [OrganizationRefusal::Validation, ['location_id' => $otherStore]],
        'a null-branch location' => [OrganizationRefusal::Validation, ['location_id' => $central]],
        'a location that cannot be sold from' => [OrganizationRefusal::Validation, ['location_id' => $notSellable]],
        'an inactive location' => [OrganizationRefusal::Validation, ['location_id' => $deactivated]],
        'an unknown location' => [OrganizationRefusal::Validation, ['location_id' => (string) Str::ulid()]],
        'a missing location' => [OrganizationRefusal::Validation, ['location_id' => null]],
        'a malformed code' => [OrganizationRefusal::Validation, ['code' => 'a b']],
        'a code over ten characters' => [OrganizationRefusal::Validation, ['code' => 'ABCDEFGHIJK']],
        'a missing name' => [OrganizationRefusal::Validation, ['name' => null]],
        'a field it does not take' => [OrganizationRefusal::Validation, ['status' => 'active']],
    ];

    foreach ($cases as $label => [$reason, $overrides]) {
        OrganizationSeed::refusal($reason, fn () => createTerminal(terminalInput($ids, $overrides)), $label);
    }

    expect(OrganizationSeed::counts())->toBe($before)
        // The control: the unmodified input is accepted, so each refusal above
        // was attributable to the one thing it changed.
        ->and(createTerminal(terminalInput($ids))->terminalId)->not->toBe('');
});

it('refuses a duplicate code within a branch, a soft-deleted one included, and accepts it under another branch (AC-26)', function () {
    $ids = createTerminalWorld();
    $other = OrganizationSeed::branch();
    $otherStore = OrganizationSeed::location($other, 'XS1', 'branch_store', ['is_sellable_from' => true]);

    $first = createTerminal(terminalInput($ids));

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => createTerminal(terminalInput($ids, ['code' => 'T1'])), 'a live terminal');

    OrganizationSeed::connection()->table('terminals')->where('id', $first->terminalId)->update(['deleted_at' => '2026-10-22 00:00:00.000']);

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => createTerminal(terminalInput($ids)), 'a soft-deleted terminal');

    // The control: the same code under a different branch is accepted.
    $second = createTerminal(['branch_id' => $other, 'location_id' => $otherStore, 'code' => 't1', 'name' => 'Other till']);

    expect($second->terminalId)->not->toBe($first->terminalId);
});

it('refuses with a server error, an error-level log and nothing written when the tenant code does not fit (Ruling 7)', function (string $badCode) {
    $ids = createTerminalWorld();
    $logger = new RecordingLogger;

    app()->instance(LoggerInterface::class, $logger);
    app()->instance(TerminalSessions::class, new FixedTerminalSessions($badCode));

    $before = OrganizationSeed::counts();

    expect(fn () => createTerminal(terminalInput($ids)))->toThrow(ActivationCodeUnavailable::class)
        ->and(OrganizationSeed::counts())->toBe($before)
        ->and($logger->at('error'))->toHaveCount(1)
        ->and($logger->text())->not->toContain($badCode === '' ? 'never-matches-empty' : $badCode);
})->with([
    'lower case' => ['ten-000123'],
    'a leading hyphen' => ['-TEN'],
    'a trailing hyphen' => ['TEN-'],
    'a space' => ['TEN 1'],
    'empty' => [''],
    'over thirty characters' => [str_repeat('A', 31)],
]);

it('refuses with a server error when the tenant has no live row, before anything is written (Ruling 7)', function () {
    $ids = createTerminalWorld();
    $logger = new RecordingLogger;

    app()->instance(LoggerInterface::class, $logger);
    app()->instance(TerminalSessions::class, new FixedTerminalSessions(missingTenant: true));

    $before = OrganizationSeed::counts();

    expect(fn () => createTerminal(terminalInput($ids)))->toThrow(ActivationCodeUnavailable::class)
        ->and(OrganizationSeed::counts())->toBe($before)
        ->and($logger->at('error'))->toHaveCount(1);
});

it('accepts a tenant code with inner hyphens and digits, as the format allows (Ruling 7 control)', function () {
    $ids = createTerminalWorld();

    app()->instance(TerminalSessions::class, new FixedTerminalSessions('A-1-B'));

    $result = createTerminal(terminalInput($ids));

    expect(ActivationCodeFormat::parse($result->activationCode)['tenantPart'] ?? null)->toBe('A-1-B');
});
