<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairResult;
use Flexipos\Auth\Features\RefreshTerminalToken\RefreshTerminalToken;
use Flexipos\Auth\Features\RefreshTerminalToken\RefreshTerminalTokenHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Organization\TerminalAuthority;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 12 - the terminal refresh slice, against a double of the tenant
| authority (AC-36, D-35, Ruling 5)
|--------------------------------------------------------------------------
|
| `TerminalAuthority` is bound to a scripted double, which lets this file prove
| what belongs to the auth side of the exchange without a tenant database:
|
| - **`recordSeen()` happens BEFORE the presented row is consumed**: the double
|   reads the row's `used_at` at the moment it is called, so a refactor that
|   moved the stamp after the rotation reads non-null and fails;
| - **a paused terminal keeps the family and the token, any other refusal
|   revokes it** - the two states the double scripts differ only in
|   `isPaused()`;
| - **the tenancy is left on every path**, including the refusals.
|
| The HTTP half - the real authority, replay, fourteen days, the two token
| families - is `TerminalSessionHttpTest`'s.
*/

require_once __DIR__.'/../../Support/auth-fixture.php';

uses(TestCase::class);

/**
 * An authority double with a script.
 */
final class ScriptedTerminalAuthority implements TerminalAuthority
{
    public ?string $branch = 'BRANCH00000000000000000000';

    public bool $paused = false;

    /** @var list<string|null> the presented row's `used_at` seen at each recordSeen() call */
    public array $usedAtWhenSeen = [];

    public string $presentedHash = '';

    public function activeBranchOf(string $terminalId): ?string
    {
        return $this->branch;
    }

    public function isPaused(string $terminalId): bool
    {
        return $this->paused;
    }

    public function recordSeen(string $terminalId): void
    {
        $used = DB::connection('master')->table('refresh_tokens')->where('token_hash', $this->presentedHash)->value('used_at');

        $this->usedAtWhenSeen[] = $used === null ? null : (string) $used;
    }
}

beforeEach(function () {
    authFixtureClockAt('2026-10-22 10:00:00.000');
    authFixtureMigrateMaster();
    authFixtureInstallKey('refresh-slice-key');

    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * A terminal pair already minted for a mapped tenant, and the double bound.
 *
 * @return array{tenantId: string, pair: IssueTokenPairResult, authority: ScriptedTerminalAuthority}
 */
function refreshSlice(): array
{
    $tenantId = authFixtureTenant('SHOP-A');

    $record = tenancyFixtureMapping()['tenant-a'];
    $record['schema_version'] = AccessTenantFixture::topVersion();

    /** @var array<string, array<string, mixed>> $configured */
    $configured = config('tenancy.databases', []);

    config()->set('tenancy.databases', $configured + [$tenantId => $record]);
    config()->set('tenancy.required_schema_version', AccessTenantFixture::topVersion());

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);

    $pair = app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TERMINAL,
        userId: 'TERMINAL000000000000000000',
        tenantId: $tenantId,
        branchId: 'BRANCH00000000000000000000',
    ));

    $authority = new ScriptedTerminalAuthority;
    $authority->presentedHash = hash('sha256', $pair->refreshToken);

    app()->instance(TerminalAuthority::class, $authority);

    return ['tenantId' => $tenantId, 'pair' => $pair, 'authority' => $authority];
}

function refreshSliceCall(string $token): void
{
    app(RefreshTerminalTokenHandler::class)->handle(new RefreshTerminalToken($token, '203.0.113.5'));
}

it('stamps last_seen before it consumes the row, then rotates in the same family (AC-36)', function () {
    $s = refreshSlice();

    $result = app(RefreshTerminalTokenHandler::class)->handle(new RefreshTerminalToken($s['pair']->refreshToken, '203.0.113.5'));

    expect($s['authority']->usedAtWhenSeen)->toBe([null])
        ->and($result->familyId)->toBe($s['pair']->familyId)
        ->and($result->terminalId)->toBe('TERMINAL000000000000000000')
        ->and($result->branchId)->toBe('BRANCH00000000000000000000')
        ->and(DB::connection('master')->table('refresh_tokens')->where('id', $s['pair']->refreshTokenId)->value('used_at'))->toBe('2026-10-22 10:00:00.000')
        ->and(tenancy()->current()->hasTenant())->toBeFalse();
});

it('refuses a paused terminal without consuming the token or touching the family (D-35)', function () {
    $s = refreshSlice();
    $s['authority']->branch = null;
    $s['authority']->paused = true;

    expect(fn () => refreshSliceCall($s['pair']->refreshToken))->toThrow(CredentialRefused::class);

    $row = (array) DB::connection('master')->table('refresh_tokens')->where('id', $s['pair']->refreshTokenId)->first();

    expect($row['used_at'])->toBeNull()
        ->and($row['revoked_at'])->toBeNull()
        ->and($s['authority']->usedAtWhenSeen)->toBe([])
        ->and(tenancy()->current()->hasTenant())->toBeFalse();
});

it('refuses a terminal that is neither active nor paused and revokes its family (AC-36)', function () {
    $s = refreshSlice();
    $s['authority']->branch = null;
    $s['authority']->paused = false;

    expect(fn () => refreshSliceCall($s['pair']->refreshToken))->toThrow(CredentialRefused::class);

    expect(DB::connection('master')->table('refresh_tokens')->where('id', $s['pair']->refreshTokenId)->value('revoked_at'))->not->toBeNull()
        ->and($s['authority']->usedAtWhenSeen)->toBe([])
        ->and(tenancy()->current()->hasTenant())->toBeFalse();
});

it('refuses a tenant that is gone or may not be served before the tenant is entered, consuming nothing (D-24)', function () {
    $s = refreshSlice();

    DB::connection('master')->table('tenants')->where('id', $s['tenantId'])->update(['status' => 'suspended']);

    expect(fn () => refreshSliceCall($s['pair']->refreshToken))->toThrow(CredentialRefused::class);

    $row = (array) DB::connection('master')->table('refresh_tokens')->where('id', $s['pair']->refreshTokenId)->first();

    expect($row['used_at'])->toBeNull()
        ->and($row['revoked_at'])->toBeNull()
        ->and($s['authority']->usedAtWhenSeen)->toBe([]);

    DB::connection('master')->table('tenants')->where('id', $s['tenantId'])->update(['deleted_at' => '2026-10-22 09:00:00.000', 'status' => 'active']);

    expect(fn () => refreshSliceCall($s['pair']->refreshToken))->toThrow(CredentialRefused::class);
});
