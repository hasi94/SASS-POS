<?php

use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Access\EmployeeClaims;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 11 - minting a terminal's pair (AC-29, AC-33)
|--------------------------------------------------------------------------
|
| `principal_type` is `terminal` for a terminal pair and `user` for the other
| two, asserted on the stored rows, which is the pin `IssueTokenPairTest` keeps
| for users and this file extends rather than edits. The constructor refuses
| every shape that would mint a claim no verifier admits.
*/

require_once __DIR__.'/../../Support/auth-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    authFixtureClockAt('2026-10-22 10:00:00.000');
    authFixtureMigrateMaster();
    authFixtureInstallKey('terminal-pair-key');
});

it('writes a terminal refresh row with principal type terminal, the tenant and a family, and signs the terminal claims (AC-29, AC-33)', function () {
    $tenantId = authFixtureTenant('SHOP-X');

    $pair = app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TERMINAL,
        userId: 'TERMINAL000000000000000000',
        tenantId: $tenantId,
        branchId: 'BRANCH00000000000000000000',
    ));

    $row = (array) DB::connection('master')->table('refresh_tokens')->where('id', $pair->refreshTokenId)->first();

    expect($row['principal_type'])->toBe('terminal')
        ->and($row['principal_id'])->toBe('TERMINAL000000000000000000')
        ->and($row['tenant_id'])->toBe($tenantId)
        ->and($row['family_id'])->toBe($pair->familyId)
        ->and($row['token_hash'])->toBe(hash('sha256', $pair->refreshToken))
        ->and($row['expires_at'])->toBe('2026-11-05 10:00:00.000')
        ->and(TokenClaims::isExactly($pair->claims, TokenClaims::TYPE_TERMINAL))->toBeTrue()
        ->and($pair->claims['branch_ids'])->toBe(['BRANCH00000000000000000000'])
        ->and($pair->claims['exp'] - $pair->claims['iat'])->toBe(900);
});

it('joins an existing family when asked, as a refresh does, and starts a new one otherwise (AC-36)', function () {
    $tenantId = authFixtureTenant('SHOP-X');

    $first = app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(TokenClaims::TYPE_TERMINAL, 'TERM', $tenantId, branchId: 'BRANCH'));
    $next = app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(TokenClaims::TYPE_TERMINAL, 'TERM', $tenantId, familyId: $first->familyId, branchId: 'BRANCH'));
    $other = app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(TokenClaims::TYPE_TERMINAL, 'TERM', $tenantId, branchId: 'BRANCH'));

    expect($next->familyId)->toBe($first->familyId)
        ->and($other->familyId)->not->toBe($first->familyId);
});

it('keeps user pairs as user rows, so the principal pin is not moved (AC-33 control)', function () {
    $userId = authFixtureUser('person@example.com');

    $platform = app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(TokenClaims::TYPE_PLATFORM, $userId));

    expect(DB::connection('master')->table('refresh_tokens')->where('id', $platform->refreshTokenId)->value('principal_type'))->toBe('user')
        ->and(IssueTokenPairHandler::PRINCIPAL_TYPE)->toBe('user')
        ->and(IssueTokenPairHandler::PRINCIPAL_TYPE_TERMINAL)->toBe('terminal');
});

it('refuses a terminal pair without a tenant or a branch, with employee claims, and a branch on any other type', function () {
    $employee = new EmployeeClaims('E', [], false, 1, str_repeat('a', EmployeeClaims::HASH_LENGTH));

    expect(fn () => new IssueTokenPair(TokenClaims::TYPE_TERMINAL, 'T', null, branchId: 'B'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new IssueTokenPair(TokenClaims::TYPE_TERMINAL, 'T', 'X'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new IssueTokenPair(TokenClaims::TYPE_TERMINAL, 'T', 'X', employeeClaims: $employee, branchId: 'B'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new IssueTokenPair(TokenClaims::TYPE_TENANT, 'U', 'X', branchId: 'B'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new IssueTokenPair(TokenClaims::TYPE_PLATFORM, 'U', branchId: 'B'))->toThrow(InvalidArgumentException::class);

    // The control: the well-formed shapes construct.
    expect((new IssueTokenPair(TokenClaims::TYPE_TERMINAL, 'T', 'X', branchId: 'B'))->branchId)->toBe('B');
});
