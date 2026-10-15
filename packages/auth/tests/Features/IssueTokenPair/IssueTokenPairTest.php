<?php

use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Keys\KeyPairGenerator;
use Flexipos\Auth\Tokens\RefreshTokenValue;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 5: the one place a JWT is minted and a refresh row is written
|--------------------------------------------------------------------------
|
| AC-28, AC-31's issue-time half and AC-32. Three slices call this one - Login,
| SelectTenant and RefreshToken - which is why the claim sets and the refresh
| row live here instead of in each of them.
|
| **The mirror, not the in-folder test.** modules/host/phpunit.xml collects the
| tests directory of each package, so a Test.php under packages/auth/src/ is
| collected by nothing and never runs while the gate stays green;
| VerticalSliceLayoutTest enforces the mirror.
|
| **The signing key is resolved before anything is written.** That ordering is
| what makes AC-31's "no refresh row, `last_login_at` not updated" structurally
| true rather than a cleanup path, and the no-active-key case at the foot of
| this file asserts the table is still empty rather than asserting a rollback.
|
| **Keys are ephemeral and never committed** (D-23): the fixture generates a
| pair at run time, stores the public half in the row and the private half
| behind the row's own `private_key_ref`. Nothing is written to disk.
|
| The master is the suite's in-memory SQLite stand-in, asserted to be SQLite
| before anything migrates. Nothing here reaches the engine at
| 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../Support/auth-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    authFixtureClockAt('2026-10-15 09:00:00.000');
    authFixtureMigrateMaster();
});

/**
 * The handler, built by the container so the bindings under test are the
 * shipped ones.
 */
function issueHandler(): IssueTokenPairHandler
{
    return app(IssueTokenPairHandler::class);
}

/**
 * Decode a token this test minted, at the instant the test's clock is stopped
 * at.
 *
 * `JWT::$timestamp` is the library's own time seam and it is a static: without
 * this the decode below would read the wall clock, and a fifteen-minute token
 * minted at a fixed 09:00 would be "expired" for everyone who runs the suite
 * after 09:15. The verifier does the same thing through the injected clock and
 * restores the static afterwards; here the restore is the assertion's own
 * scope.
 *
 * @return array<string, mixed>
 */
function issueDecode(string $token, string $publicKey): array
{
    $restore = JWT::$timestamp;

    JWT::$timestamp = app(RunnerClock::class)->now()->getTimestamp();

    try {
        return (array) JWT::decode($token, new Key($publicKey, KeyPairGenerator::ALGORITHM));
    } finally {
        JWT::$timestamp = $restore;
    }
}

/*
|--------------------------------------------------------------------------
| AC-28: the platform token
|--------------------------------------------------------------------------
*/

it('signs a platform token with the active key and names that kid in the header', function () {
    $key = authFixtureInstallKey('kid-active');

    $result = issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
    ));

    [$header] = explode('.', $result->accessToken);
    $decodedHeader = json_decode((string) base64_decode(strtr($header, '-_', '+/'), true), true);

    expect($decodedHeader['kid'])->toBe('kid-active')
        ->and($decodedHeader['alg'])->toBe(KeyPairGenerator::ALGORITHM)
        ->and($result->kid)->toBe('kid-active');

    // The row's own public key verifies it, which is the half AC-28 asks for.
    $payload = issueDecode($result->accessToken, $key['public']);

    expect($payload['sub'])->toBe('01HUSER0000000000000000001')
        ->and($payload['token_type'])->toBe('platform');
});

it('mints exactly the platform claim set, and fails the same comparison with one claim more', function () {
    authFixtureInstallKey('kid-active');

    $result = issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
    ));

    expect(TokenClaims::namesOf($result->claims))->toBe(['exp', 'iat', 'jti', 'sub', 'token_type'])
        ->and(TokenClaims::isExactly($result->claims, TokenClaims::TYPE_PLATFORM))->toBeTrue();

    // The negative control AC-28 names, in the same test: a token minted here
    // with one extra claim must fail the comparison the criterion uses. Without
    // it, "the names matched" is also what a comparison of nothing returns.
    $polluted = $result->claims + ['database' => 'flexipos_shop_1'];

    expect(TokenClaims::isExactly($polluted, TokenClaims::TYPE_PLATFORM))->toBeFalse();

    // And the extra claim really does survive into a signed token, so the
    // control is over a token and not only over an array.
    $pollutedToken = JWT::encode($polluted, KeyPairGenerator::generate()['private'], KeyPairGenerator::ALGORITHM, 'kid-active');
    $pollutedClaims = issueDecode($pollutedToken, KeyPairGenerator::generate()['public']);

    expect($pollutedClaims)->toHaveKey('database')
        ->and(TokenClaims::isExactly($pollutedClaims, TokenClaims::TYPE_PLATFORM))->toBeFalse();
});

it('expires the access token fifteen minutes after it was issued, by the injected clock', function () {
    authFixtureInstallKey('kid-active');

    $result = issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
    ));

    $issuedAt = CarbonImmutable::parse('2026-10-15 09:00:00.000')->getTimestamp();

    expect($result->claims['iat'])->toBe($issuedAt)
        ->and($result->claims['exp'])->toBe($issuedAt + 15 * 60)
        ->and($result->claims['exp'] - $result->claims['iat'])->toBe(config('authentication.access_ttl_minutes') * 60);
});

/*
|--------------------------------------------------------------------------
| The tenant token
|--------------------------------------------------------------------------
*/

it('mints a tenant token naming the tenant id, with the employee claim only when there is one', function () {
    authFixtureInstallKey('kid-active');

    $without = issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TENANT,
        userId: '01HUSER0000000000000000001',
        tenantId: '01HTENANT00000000000000001',
    ));

    $with = issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TENANT,
        userId: '01HUSER0000000000000000001',
        tenantId: '01HTENANT00000000000000001',
        employeeId: '01HEMPLOYEE0000000000000001',
    ));

    expect(TokenClaims::namesOf($without->claims))->toBe(['exp', 'iat', 'jti', 'sub', 'tenant', 'token_type'])
        ->and($without->claims['tenant'])->toBe('01HTENANT00000000000000001')
        ->and($with->claims['employee'])->toBe('01HEMPLOYEE0000000000000001')
        ->and(TokenClaims::isExactly($with->claims, TokenClaims::TYPE_TENANT))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| AC-32: the refresh row
|--------------------------------------------------------------------------
*/

it('stores the sha-256 hex of the refresh token and never the token', function () {
    authFixtureInstallKey('kid-active');

    $result = issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
    ));

    $rows = authFixtureRows('refresh_tokens');

    expect($rows)->toHaveCount(1);

    $row = $rows[0];

    expect($row['token_hash'])->toBe(hash('sha256', $result->refreshToken))
        ->and(strlen((string) $row['token_hash']))->toBe(RefreshTokenValue::HASH_LENGTH)
        ->and($row['principal_type'])->toBe('user')
        ->and($row['principal_id'])->toBe('01HUSER0000000000000000001')
        ->and($row['tenant_id'])->toBeNull()
        ->and($row['used_at'])->toBeNull()
        ->and($row['revoked_at'])->toBeNull()
        ->and($row['expires_at'])->toBe('2026-10-29 09:00:00.000')
        ->and($row['id'])->toBe($result->refreshTokenId)
        ->and($row['family_id'])->toBe($result->familyId);

    // The plaintext is in no column of the row it created. The whole-schema
    // version of this scan, with its positive control, is AC-32's and QA's.
    $leaks = [];

    foreach ($row as $column => $value) {
        if (str_contains((string) $value, $result->refreshToken)) {
            $leaks[] = (string) $column;
        }
    }

    // Collected and compared to an empty list rather than asserted per column:
    // a negated toContain() with a second argument passes when *either*
    // argument fails to match, which the suite's own assertion-shape guard
    // reports.
    expect($leaks)->toBe([]);

    // The control for that scan, here so the loop above is not asserting over
    // nothing: pointed at the same row, the search *does* find the hash of the
    // value. An absence proved by a search that finds nothing anywhere is not
    // an absence.
    expect(implode('|', array_map('strval', $row)))->toContain(hash('sha256', $result->refreshToken));
});

it('starts a family when none is given and stays in one when it is', function () {
    authFixtureInstallKey('kid-active');

    $first = issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
    ));

    $second = issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
    ));

    $rotated = issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
        familyId: $first->familyId,
    ));

    expect($second->familyId)->not->toBe($first->familyId)
        ->and($rotated->familyId)->toBe($first->familyId)
        ->and($rotated->refreshTokenId)->not->toBe($first->refreshTokenId)
        ->and($rotated->refreshToken)->not->toBe($first->refreshToken);
});

it('writes the caller address and agent it was given, and nulls when it was given none', function () {
    authFixtureInstallKey('kid-active');

    $packed = (string) inet_pton('2001:db8::1');

    issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
        ip: $packed,
        userAgent: 'FlexiPOS/1.0',
    ));

    $row = authFixtureRows('refresh_tokens')[0];

    // Asserted on the PHP value after inet_ntop, never on SQL length(): SQLite
    // truncates a packed address at its first NUL (step 2's recorded artefact).
    expect(inet_ntop((string) $row['ip']))->toBe('2001:db8::1')
        ->and($row['user_agent'])->toBe('FlexiPOS/1.0');
});

/*
|--------------------------------------------------------------------------
| AC-31: no active key, and nothing written
|--------------------------------------------------------------------------
*/

it('refuses to issue when the only key is retiring or retired, and writes no row', function () {
    authFixtureInstallKey('kid-retiring', SigningKeyring::STATUS_RETIRING);
    authFixtureInstallKey('kid-retired', SigningKeyring::STATUS_RETIRED);

    expect(fn () => issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
    )))->toThrow(NoActiveSigningKey::class);

    // Not a rollback: the key is resolved before the row is built, so there was
    // never a row to roll back.
    expect(authFixtureRows('refresh_tokens'))->toBe([]);

    // Control, in the same test: making one of them active makes the same call
    // succeed, so the refusal is caused by the status and not by the fixture.
    authFixtureSetKeyStatus('kid-retiring', SigningKeyring::STATUS_ACTIVE);

    expect(issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
    ))->kid)->toBe('kid-retiring');
});

it('refuses to sign with a key whose row names another algorithm', function () {
    authFixtureInstallKey('kid-active');

    // The algorithm is ES256 and is named by the code (Ruling 1). A row that
    // disagrees is a misconfiguration, and signing with the algorithm the
    // *row* asked for would be alg-confusion arriving through the database.
    DB::connection('master')->table('signing_keys')->where('kid', 'kid-active')->update(['algorithm' => 'RS256']);

    expect(fn () => issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
    )))->toThrow(NoActiveSigningKey::class, 'RS256');

    expect(authFixtureRows('refresh_tokens'))->toBe([]);

    // Control: the same row with the algorithm it really has signs normally.
    DB::connection('master')->table('signing_keys')->where('kid', 'kid-active')->update(['algorithm' => 'ES256']);

    expect(issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
    ))->kid)->toBe('kid-active');
});

it('refuses when signing_keys is empty', function () {
    expect(authFixtureRows('signing_keys'))->toBe([]);

    expect(fn () => issueHandler()->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: '01HUSER0000000000000000001',
    )))->toThrow(NoActiveSigningKey::class);

    expect(authFixtureRows('refresh_tokens'))->toBe([]);
});
