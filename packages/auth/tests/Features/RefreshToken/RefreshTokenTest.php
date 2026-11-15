<?php

use Flexipos\Auth\Exceptions\AccessTokenRefused;
use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Exceptions\NoTenantAccess;
use Flexipos\Auth\Exceptions\TenantNotServable;
use Flexipos\Auth\Features\Login\Login;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttempt;
use Flexipos\Auth\Features\RefreshToken\RefreshToken;
use Flexipos\Auth\Features\SelectTenant\SelectTenant;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessToken;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessTokenHandler;
use Flexipos\Auth\Tenancy\TenantSelectionPolicy;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Auth\Tokens\TokenClaims;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 11: RefreshToken - rotation, replay, and the two refusals that
| consume nothing
|--------------------------------------------------------------------------
|
| AC-33 to AC-38, and AC-30's "F0 still works" half. AC-49 is logout and
| lives beside this file in LogoutTest.php.
|
| **Rotation is never proved by the absence of an error.**
| `UNIQUE(refresh_tokens.token_hash)` would refuse a duplicated value even
| if rotation were broken (plan section 7, mask shape 2), so every rotation
| assertion here names the two things only rotation produces: `used_at` on
| the row that was presented, and a new row carrying the same `family_id`.
|
| **The two non-consumption cases are two tests, deliberately.** A tenant
| refusal (D-24) and an operational signing failure both end with `used_at`
| null and the token still usable later, so that shared observation is
| evidence of neither. They are told apart by what was recorded: a
| `login_history` row with a named reason against no row at all. Key-first
| makes them mutually exclusive by construction, so neither test can drift
| into exercising the other.
|
| The master is the suite's in-memory SQLite stand-in. Nothing here reaches
| the engine at 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../Support/auth-fixture.php';

uses(TestCase::class);

const REFRESH_CLOCK = '2026-10-15 12:00:00.000';

const REFRESH_EXPIRY = '2026-10-29 12:00:00.000';

const REFRESH_KID = 'refresh-key-1';

const REFRESH_PASSWORD = 'correct-horse-battery-staple';

beforeEach(function () {
    authFixtureClockAt(REFRESH_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(REFRESH_KID);
});

/**
 * A signed-in person and the platform refresh token their login produced.
 *
 * The session comes from the shipped `Login` handler rather than from a
 * hand-written row, so what is rotated here is a token the application
 * really issues.
 *
 * @return array{0: string, 1: string} user id, refresh token
 */
function refreshSession(string $email = 'owner@example.com'): array
{
    $userId = authFixtureUser($email);

    $login = authFixtureLoginHandler()->handle(new Login(
        email: $email,
        password: REFRESH_PASSWORD,
        ip: '203.0.113.7',
    ));

    return [$userId, $login->refreshToken];
}

/**
 * A person who has opened a shop, and the tenant-scoped refresh token that
 * selection produced.
 *
 * @return array{0: string, 1: string, 2: string} user id, tenant id, refresh token
 */
function refreshTenantSession(): array
{
    [$userId] = refreshSession();

    // The shop is selectable when the token is issued, always: a session
    // that could not be created is not a session whose re-check means
    // anything. Tests that need a tenant to go bad change it afterwards,
    // which is also what happens in life.
    $tenantId = authFixtureTenant('shop-a');
    authFixtureMembership($tenantId, $userId);

    $selection = authFixtureSelectTenantHandler()->handle(new SelectTenant(
        userId: $userId,
        code: 'shop-a',
        ip: '203.0.113.7',
    ));

    return [$userId, $tenantId, $selection->refreshToken];
}

/**
 * Present a refresh token.
 */
function refreshCommand(mixed $token): RefreshToken
{
    return new RefreshToken(token: $token, ip: '203.0.113.7', userAgent: 'FlexiPOS/1.0', deviceId: 'till-3');
}

/**
 * The claims of an access token, as the shipped verifier reads them.
 *
 * @return array<string, mixed>
 */
function refreshClaimsOf(string $token, string $type = TokenClaims::TYPE_PLATFORM): array
{
    return app(VerifyAccessTokenHandler::class)->handle(new VerifyAccessToken($token, $type));
}

/*
|--------------------------------------------------------------------------
| AC-33: one rotation
|--------------------------------------------------------------------------
*/

it('rotates a platform token inside its family, consuming the row it was given', function () {
    [$userId, $presented] = refreshSession();

    $familyId = authFixtureRefreshRow($presented)['family_id'];

    $result = authFixtureRefreshHandler()->handle(refreshCommand($presented));

    // The two things only rotation produces. Neither is "no error was
    // thrown": the unique index on `token_hash` would refuse a duplicated
    // value even if nothing rotated (mask shape 2).
    $rows = authFixtureFamilyRows((string) $familyId);

    expect($result->familyId)->toBe($familyId)
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]['token_hash'])->toBe(hash('sha256', $presented))
        ->and($rows[0]['used_at'])->toBe(REFRESH_CLOCK)
        ->and($rows[1]['used_at'])->toBeNull()
        ->and($rows[1]['token_hash'])->toBe(hash('sha256', $result->refreshToken))
        // Fourteen days from the clock's now, not from the presented row's
        // own expiry - a rotation is a new token, not an extension.
        ->and($rows[1]['expires_at'])->toBe(REFRESH_EXPIRY)
        ->and($rows[1]['revoked_at'])->toBeNull()
        ->and($rows[1]['tenant_id'])->toBeNull();

    // The new access token is a real platform token for the same person.
    expect(refreshClaimsOf($result->accessToken)['sub'])->toBe($userId)
        ->and($result->tenantId)->toBeNull();
});

it('rotates again, so a family is a chain and not a pair', function () {
    [, $first] = refreshSession();

    $second = authFixtureRefreshHandler()->handle(refreshCommand($first));
    $third = authFixtureRefreshHandler()->handle(refreshCommand($second->refreshToken));

    $rows = authFixtureFamilyRows($third->familyId);

    expect($rows)->toHaveCount(3)
        ->and(array_slice(array_column($rows, 'used_at'), 0, 2))->toBe([REFRESH_CLOCK, REFRESH_CLOCK])
        ->and($rows[2]['used_at'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| AC-34: a replayed token takes its whole family with it
|--------------------------------------------------------------------------
*/

it('revokes the entire family on a replay, records token_reuse, and the newest token dies with it', function () {
    [$userId, $first] = refreshSession();

    // Rotate twice, so the family has a newest token that is demonstrably
    // good.
    $second = authFixtureRefreshHandler()->handle(refreshCommand($first));
    $third = authFixtureRefreshHandler()->handle(refreshCommand($second->refreshToken));

    // **The control AC-34 asks for, in its own test**: the newest token
    // worked immediately before the replay. It is proved by the rotation
    // above - `$third` exists only because `$second` was accepted - and the
    // newest one is asserted usable here without consuming it, by the
    // absence of any revocation on its row.
    expect(authFixtureRefreshRow($third->refreshToken)['revoked_at'])->toBeNull();

    $historyBefore = count(authFixtureRows('login_history'));

    // The replay: the first token, already used, presented again.
    expect(fn () => authFixtureRefreshHandler()->handle(refreshCommand($first)))
        ->toThrow(CredentialRefused::class);

    $rows = authFixtureFamilyRows($third->familyId);

    expect($rows)->toHaveCount(3)
        // **Every** row, including the newest and the ones already used.
        ->and(array_column($rows, 'revoked_at'))->toBe([REFRESH_CLOCK, REFRESH_CLOCK, REFRESH_CLOCK]);

    // And the newest is refused after it, which is the half that makes the
    // revocation more than a record.
    expect(fn () => authFixtureRefreshHandler()->handle(refreshCommand($third->refreshToken)))
        ->toThrow(CredentialRefused::class);

    $events = authFixtureRows('security_events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe(RecordLoginAttempt::EVENT_TOKEN_REUSE)
        ->and($events[0]['user_id'])->toBe($userId)
        ->and(json_decode((string) $events[0]['detail'], true))->toBe([
            'family_id' => $third->familyId,
            'revoked_rows' => 3,
        ])
        // A reuse is an event, not a credential judgement: no `login_history`
        // row is invented for it (D-36), and the closed reason set has no
        // word for one.
        ->and(authFixtureRows('login_history'))->toHaveCount($historyBefore);
});

it('puts no token and no hash in the event it records', function () {
    // AC-26 over the one sink a replay writes to. The detail column is the
    // only free-form column in the system, which is exactly why it is
    // scanned rather than trusted.
    [, $first] = refreshSession();

    $second = authFixtureRefreshHandler()->handle(refreshCommand($first));

    expect(fn () => authFixtureRefreshHandler()->handle(refreshCommand($first)))
        ->toThrow(CredentialRefused::class);

    $detail = (string) authFixtureRows('security_events')[0]['detail'];

    expect($detail)->not->toContain($first);
    expect($detail)->not->toContain($second->refreshToken);
    expect($detail)->not->toContain(hash('sha256', $first));

    // The positive control: the scan is looking at something, and would
    // report a value that really was in there.
    expect($detail)->toContain('family_id');
});

/*
|--------------------------------------------------------------------------
| AC-35: expired, revoked, unknown, empty, malformed
|--------------------------------------------------------------------------
*/

it('refuses a token that is not presentable, and issues nothing', function (string $case) {
    [, $token] = refreshSession();

    $presented = match ($case) {
        'unknown' => 'not-a-token-this-system-ever-issued',
        'empty' => '',
        'malformed' => "\x00\x01not base64url at all",
        default => $token,
    };

    if ($case === 'revoked') {
        DB::connection('master')->table('refresh_tokens')->update(['revoked_at' => REFRESH_CLOCK]);
    }

    if ($case === 'expired') {
        // Exactly `expires_at`: the instant the token stops working. A `>`
        // written where a `>=` was meant is invisible everywhere else.
        authFixtureClockAt(REFRESH_EXPIRY);
    }

    $before = count(authFixtureRows('refresh_tokens'));

    expect(fn () => authFixtureRefreshHandler()->handle(refreshCommand($presented)))
        ->toThrow(CredentialRefused::class);

    expect(authFixtureRows('refresh_tokens'))->toHaveCount($before);
})->with([
    'expired' => ['expired'],
    'revoked' => ['revoked'],
    'unknown' => ['unknown'],
    'empty' => ['empty'],
    'malformed' => ['malformed'],
]);

it('accepts the same token one second before it expires', function () {
    // The other side of AC-35's boundary, which is mandatory: without it
    // "refused at `expires_at`" is satisfied by a token that was never
    // valid.
    [, $token] = refreshSession();

    authFixtureClockAt('2026-10-29 11:59:59.000');

    expect(authFixtureRefreshHandler()->handle(refreshCommand($token))->refreshToken)->not->toBe($token);
});

it('refuses a non-string without a type error', function (mixed $token) {
    expect(fn () => authFixtureRefreshHandler()->handle(refreshCommand($token)))
        ->toThrow(CredentialRefused::class);
})->with([
    'null' => [null],
    'an integer' => [7],
    'an array' => [['token' => 'x']],
]);

/*
|--------------------------------------------------------------------------
| AC-36: the account behind the session
|--------------------------------------------------------------------------
*/

it('refuses and revokes the family when the account stops being active', function (string $column, mixed $value) {
    [$userId, $first] = refreshSession();

    // The control: the same chain worked immediately before the change.
    $second = authFixtureRefreshHandler()->handle(refreshCommand($first));

    DB::connection('master')->table('users')->where('id', $userId)->update([$column => $value]);

    expect(fn () => authFixtureRefreshHandler()->handle(refreshCommand($second->refreshToken)))
        ->toThrow(CredentialRefused::class);

    // D-13: the session ends, not just this request. Contrast D-24, where a
    // tenant refusal leaves the token intact.
    expect(array_column(authFixtureFamilyRows($second->familyId), 'revoked_at'))
        ->toBe([REFRESH_CLOCK, REFRESH_CLOCK]);
})->with([
    'disabled' => ['status', 'disabled'],
    'invited' => ['status', 'invited'],
    'locked status' => ['status', 'locked'],
    'soft-deleted' => ['deleted_at', REFRESH_CLOCK],
]);

it('does not cut off a live session because the account is locked', function () {
    // D-5 and AC-36's last sentence: lockout governs signing in. A lock
    // leaves `users.status` at `active`, so this predicate never sees one,
    // and that is a decision rather than an oversight.
    [$userId, $token] = refreshSession();

    DB::connection('master')->table('users')->where('id', $userId)->update([
        'locked_until' => '2026-10-15 12:15:00.000',
        'failed_attempts' => 0,
    ]);

    $result = authFixtureRefreshHandler()->handle(refreshCommand($token));

    expect(authFixtureRefreshRow($result->refreshToken))->not->toBeNull()
        ->and(authFixtureRefreshRow($token)['used_at'])->toBe(REFRESH_CLOCK);
});

/*
|--------------------------------------------------------------------------
| AC-37: a tenant-scoped token is re-checked, and a refusal consumes nothing
|--------------------------------------------------------------------------
*/

it('rotates a tenant token for the same tenant and never another', function () {
    [$userId, $tenantId, $token] = refreshTenantSession();

    $result = authFixtureRefreshHandler()->handle(refreshCommand($token));

    $claims = refreshClaimsOf($result->accessToken, TokenClaims::TYPE_TENANT);

    expect($result->tenantId)->toBe($tenantId)
        ->and($claims['tenant'])->toBe($tenantId)
        ->and($claims['sub'])->toBe($userId)
        ->and(authFixtureRefreshRow($result->refreshToken)['tenant_id'])->toBe($tenantId)
        ->and(authFixtureRefreshRow($token)['used_at'])->toBe(REFRESH_CLOCK);
});

it('refuses a tenant that may no longer be selected without consuming the token, and records why', function () {
    [, $tenantId, $token] = refreshTenantSession();

    DB::connection('master')->table('tenants')->where('id', $tenantId)->update(['status' => 'suspended']);

    expect(fn () => authFixtureRefreshHandler()->handle(refreshCommand($token)))
        ->toThrow(TenantNotServable::class);

    $row = authFixtureRefreshRow($token);
    $history = authFixtureRows('login_history');

    // **The business refusal, in full** - and the bottom two rows of the
    // table are not what distinguishes it. What does is the reasoned record.
    expect($row['used_at'])->toBeNull()
        ->and($row['revoked_at'])->toBeNull()
        ->and($history[count($history) - 1]['failure_reason'])->toBe(RecordLoginAttempt::REASON_TENANT_NOT_SERVABLE)
        ->and($history[count($history) - 1]['tenant_id'])->toBe($tenantId);

    // D-24's liveness control, and it is required rather than optional: a
    // token that was never valid also has a null `used_at`. Reinstating the
    // tenant makes the *same* token work.
    DB::connection('master')->table('tenants')->where('id', $tenantId)->update(['status' => 'active']);

    expect(authFixtureRefreshHandler()->handle(refreshCommand($token))->tenantId)->toBe($tenantId)
        ->and(authFixtureRefreshRow($token)['used_at'])->toBe(REFRESH_CLOCK);
});

it('refuses a membership that is gone without consuming the token, and records why', function () {
    [$userId, $tenantId, $token] = refreshTenantSession();

    DB::connection('master')->table('tenant_users')
        ->where('user_id', $userId)->update(['status' => 'suspended']);

    expect(fn () => authFixtureRefreshHandler()->handle(refreshCommand($token)))
        ->toThrow(NoTenantAccess::class);

    $row = authFixtureRefreshRow($token);
    $history = authFixtureRows('login_history');

    expect($row['used_at'])->toBeNull()
        ->and($row['revoked_at'])->toBeNull()
        ->and($history[count($history) - 1]['failure_reason'])->toBe(RecordLoginAttempt::REASON_NO_ACCESS);

    // The same liveness control: reinstating the membership makes the same
    // token work.
    DB::connection('master')->table('tenant_users')
        ->where('user_id', $userId)->update(['status' => 'active']);

    expect(authFixtureRefreshHandler()->handle(refreshCommand($token))->tenantId)->toBe($tenantId);
});

/*
|--------------------------------------------------------------------------
| The operational failure, which is the other non-consumption case
|--------------------------------------------------------------------------
*/

it('consumes nothing and records nothing at all when no key can sign', function () {
    // The recorder is installed **before** the session is built, and that
    // ordering is load-bearing: the keyring is a singleton, so a logger
    // swapped in after it has been resolved would be handed to nobody and
    // the probe would report an empty log - which looks exactly like a
    // failure that was never logged.
    $log = authFixtureRecordingLogger();

    [, , $token] = refreshTenantSession();

    $historyBefore = count(authFixtureRows('login_history'));

    authFixtureSetKeyStatus(REFRESH_KID, SigningKeyring::STATUS_RETIRED);

    expect(fn () => authFixtureRefreshHandler()->handle(refreshCommand($token)))
        ->toThrow(NoActiveSigningKey::class);

    $row = authFixtureRefreshRow($token);

    // **The operational column of the table, in full.** It agrees with the
    // business refusal on `used_at` and on the token still working, and
    // differs on the only row that discriminates: nothing was recorded,
    // because nothing was judged (D-36).
    expect($row['used_at'])->toBeNull()
        ->and($row['revoked_at'])->toBeNull()
        ->and(authFixtureRows('login_history'))->toHaveCount($historyBefore)
        ->and(authFixtureRows('security_events'))->toBe([])
        // The single trace T-009 leaves, asserted rather than assumed
        // (D-37, FP-201 carries the gap).
        ->and($log->messagesAt(authFixtureErrorLevel()))->toBe([SigningKeyring::LOG_MESSAGE]);

    // The liveness half, which both non-consumption cases owe: the same
    // token really is still usable.
    authFixtureSetKeyStatus(REFRESH_KID, SigningKeyring::STATUS_ACTIVE);

    expect(authFixtureRefreshHandler()->handle(refreshCommand($token))->refreshToken)->not->toBe($token);
});

it('judges nothing before the key, so an unknown token with no key is the operational failure', function () {
    // The discriminating input for the ordering, the same shape step 10
    // needed: with a valid token both orderings look identical. Here the
    // token would be refused anyway - a handler that judged first would
    // answer `CredentialRefused` and the operational failure would be
    // hidden behind a token refusal.
    refreshSession();

    authFixtureSetKeyStatus(REFRESH_KID, SigningKeyring::STATUS_RETIRED);

    expect(fn () => authFixtureRefreshHandler()->handle(refreshCommand('not-a-token-of-ours')))
        ->toThrow(NoActiveSigningKey::class);
});

/*
|--------------------------------------------------------------------------
| AC-47 residual (b), third of three - and residual (b) closes here
|--------------------------------------------------------------------------
*/

it('re-checks the tenant against the policy it was given, and ignores a policy nobody bound', function () {
    [, $tenantId, $token] = refreshTenantSession();

    // The shop closes after the token was issued, which is the case the
    // re-check exists for: nothing about the token changed, only the answer
    // to the question it is re-asked.
    DB::connection('master')->table('tenants')->where('id', $tenantId)->update(['status' => 'closed']);

    expect(fn () => authFixtureRefreshHandler()->handle(refreshCommand($token)))
        ->toThrow(TenantNotServable::class);

    // The moved value: a policy over a different set, bound where the
    // shipped one was, and the *shipped consumer* - the refresh re-check -
    // follows it. A copy of the list cannot follow a value that moved.
    app()->instance(
        TenantSelectionPolicy::class,
        TenantSelectionPolicy::withSelectableStatuses(['closed'])
    );

    $result = authFixtureRefreshHandler()->handle(refreshCommand($token));

    expect($result->tenantId)->toBe($tenantId);

    // The negative control: a second policy object, constructed and never
    // bound, changes nothing. Without it, "the re-check followed the policy"
    // could as easily be "the test moved something nobody reads".
    TenantSelectionPolicy::withSelectableStatuses(['archived']);

    expect(authFixtureRefreshHandler()->handle(refreshCommand($result->refreshToken))->tenantId)->toBe($tenantId);
});

/*
|--------------------------------------------------------------------------
| AC-38: nothing a caller sends chooses a tenant, and the two token kinds
| are not interchangeable
|--------------------------------------------------------------------------
*/

it('gives a refresh request nowhere to carry a tenant, a database or a scope', function () {
    $constructor = (new ReflectionClass(RefreshToken::class))->getConstructor();

    expect($constructor)->not->toBeNull();

    $names = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $constructor instanceof ReflectionMethod ? $constructor->getParameters() : []
    );

    // The token and three facts about the connection. The issued token's
    // tenant is the stored row's because there is nowhere else it could
    // come from (AC-38).
    expect($names)->toBe(['token', 'ip', 'userAgent', 'deviceId']);
});

it('refuses an access token presented as a refresh token, and the reverse', function () {
    [, $token] = refreshSession();

    $access = authFixtureRefreshHandler()->handle(refreshCommand($token))->accessToken;

    // A signed JWT is not a refresh token: it hashes to nothing stored.
    expect(fn () => authFixtureRefreshHandler()->handle(refreshCommand($access)))
        ->toThrow(CredentialRefused::class);

    // And a refresh token is not an access token: it is not even three
    // segments.
    expect(fn () => app(VerifyAccessTokenHandler::class)->handle(
        new VerifyAccessToken($token, TokenClaims::TYPE_PLATFORM)
    ))->toThrow(AccessTokenRefused::class);
});

/*
|--------------------------------------------------------------------------
| AC-30: the platform family outlives the tenant families
|--------------------------------------------------------------------------
*/

it('leaves the platform family usable after a tenant family has been rotated', function () {
    [$userId, , $tenantToken] = refreshTenantSession();

    $platformToken = DB::connection('master')->table('refresh_tokens')->whereNull('tenant_id')->value('token_hash');

    authFixtureRefreshHandler()->handle(refreshCommand($tenantToken));

    // F0 is neither used nor revoked by anything F1 did.
    $platform = DB::connection('master')->table('refresh_tokens')->where('token_hash', $platformToken)->first();

    expect($platform->used_at)->toBeNull()
        ->and($platform->revoked_at)->toBeNull()
        ->and($platform->principal_id)->toBe($userId);
});
