<?php

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Flexipos\Auth\Exceptions\AccessTokenRefused;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessToken;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessTokenHandler;
use Flexipos\Auth\Keys\KeyPairGenerator;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 5, AC-29: the eight refusals, each with its control
|--------------------------------------------------------------------------
|
| **Which of these are ours, and which are the library's.** This matters more
| than any individual case, because a test that only exercises the library's
| half passes against our code doing nothing (mask shape 1 - an earlier gate
| absorbs it):
|
| - **The library's, by its own contract**: `alg: none` (absent from
|   `JWT::$supported_algs`), a header `alg` different from the key's (refused
|   by `constantTimeEquals($key->getAlgorithm(), $header->alg)`), a payload
|   altered after signing and a signature from a foreign key
|   (`SignatureInvalidException`), and `exp` (refused at exactly `exp`,
|   accepted at `exp - 1`). Each case below therefore also asserts **which
|   exception the library raised**, as `getPrevious()`, so the evidence names
|   the mechanism rather than only the outcome. The caller names the algorithm
|   and our code never reads `alg` from the header, which is what makes
|   alg-confusion the library's problem and not ours.
| - **Ours, and shown red before the real code landed**: the **`kid` of a
|   `retired` key** and the **wrong `token_type`**. Measured red against a
|   keyring with no status gate and a handler with no type check, then green
|   with both - recorded in the commit message. Also ours: an unknown `kid`, a
|   malformed token, and the exact claim set.
|
| **The `token_type` check runs before the claim-set comparison, on purpose.**
| The platform and tenant shapes differ by the `tenant` claim, so a set
| comparison against the *required* type would refuse a wrong-type token as
| `claim_set` and the `token_type` rule would never be reached - a guard
| absorbed by its neighbour. Ordered this way the two refusals are
| distinguishable, and every case below pins the reason.
|
| **The exp boundary needs both sides in one dataset**, which is why the clock
| is the injected RunnerClock and `JWT::$timestamp` is driven from it: a `>`
| written for `>=` is visible only at exactly `exp`.
|
| Keys are ephemeral (D-23); the master is the suite's in-memory SQLite
| stand-in, asserted before anything migrates. The engine at 127.0.0.1:3306 is
| on hold and nothing here goes near it.
|
*/

require_once __DIR__.'/../../Support/auth-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    authFixtureClockAt('2026-10-15 09:00:00.000');
    authFixtureMigrateMaster();
    authFixtureInstallKey('kid-active');
});

/**
 * A platform token for the installed active key, minted by the real issuer.
 */
function verifyValidToken(string $type = TokenClaims::TYPE_PLATFORM): string
{
    return app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(
        tokenType: $type,
        userId: '01HUSER0000000000000000001',
        tenantId: $type === TokenClaims::TYPE_TENANT ? '01HTENANT00000000000000001' : null,
    ))->accessToken;
}

/**
 * Verify, through the container, against the required token type.
 *
 * @return array<string, mixed>
 */
function verifyToken(string $token, string $required = TokenClaims::TYPE_PLATFORM): array
{
    return app(VerifyAccessTokenHandler::class)->handle(new VerifyAccessToken($token, $required));
}

/**
 * The refusal of one call, or null when it was accepted.
 */
function verifyRefusalOf(string $token, string $required = TokenClaims::TYPE_PLATFORM): ?AccessTokenRefused
{
    try {
        verifyToken($token, $required);
    } catch (AccessTokenRefused $refusal) {
        return $refusal;
    }

    return null;
}

/**
 * The instant the injected clock is stopped at, as a JWT timestamp.
 */
function verifyNow(): int
{
    return app(RunnerClock::class)->now()->getTimestamp();
}

/**
 * One JOSE segment - a header or a claim set - as unpadded base64url.
 *
 * The docblock is step 5b's: packages/auth/tests entered PHPStan's paths
 * there, and this helper's bare `array` was the one error the whole
 * directory produced.
 *
 * @param  array<string, mixed>  $value
 */
function verifySegment(array $value): string
{
    return rtrim(strtr(base64_encode((string) json_encode($value)), '+/', '-_'), '=');
}

/*
|--------------------------------------------------------------------------
| The control the other cases are measured against
|--------------------------------------------------------------------------
*/

it('accepts a token the issuer just minted', function () {
    $claims = verifyToken(verifyValidToken());

    expect($claims['sub'])->toBe('01HUSER0000000000000000001')
        ->and($claims['token_type'])->toBe('platform')
        ->and(TokenClaims::isExactly($claims, TokenClaims::TYPE_PLATFORM))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 1. The payload altered after signing
|--------------------------------------------------------------------------
*/

it('refuses a token whose payload was edited after it was signed, and accepts the original', function () {
    $token = verifyValidToken();

    [$header, $payload, $signature] = explode('.', $token);

    /** @var array<string, mixed> $claims */
    $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
    $claims['sub'] = '01HUSER0000000000000000002';

    $altered = $header.'.'.verifySegment($claims).'.'.$signature;

    expect($altered)->not->toBe($token);

    $refusal = verifyRefusalOf($altered);

    expect($refusal)->toBeInstanceOf(AccessTokenRefused::class)
        ->and($refusal->reason())->toBe('invalid_token')
        ->and($refusal->getPrevious())->toBeInstanceOf(SignatureInvalidException::class);

    // Control, same test: the untouched token still verifies.
    expect(verifyToken($token)['sub'])->toBe('01HUSER0000000000000000001');
});

/*
|--------------------------------------------------------------------------
| 2. Signed by a key that is not the one the kid names
|--------------------------------------------------------------------------
*/

it('refuses a token signed by a key not in signing_keys, and accepts the one that is', function () {
    // A second, genuinely different pair - never installed in signing_keys. The
    // kid is a *known* one, so this case is the signature and not the lookup.
    $intruder = KeyPairGenerator::generate('intruder');

    $forged = JWT::encode(
        TokenClaims::platform('01HUSER0000000000000000001', '01HJTI', verifyNow(), 15),
        $intruder['private'],
        KeyPairGenerator::ALGORITHM,
        'kid-active'
    );

    $refusal = verifyRefusalOf($forged);

    expect($refusal)->toBeInstanceOf(AccessTokenRefused::class)
        ->and($refusal->reason())->toBe('invalid_token')
        ->and($refusal->getPrevious())->toBeInstanceOf(SignatureInvalidException::class);

    expect(verifyToken(verifyValidToken())['sub'])->toBe('01HUSER0000000000000000001');
});

/*
|--------------------------------------------------------------------------
| 3 and 4. The algorithm header
|--------------------------------------------------------------------------
*/

it('refuses alg none and a mismatched alg, both by the library contract, and accepts ES256', function () {
    $claims = TokenClaims::platform('01HUSER0000000000000000001', '01HJTI', verifyNow(), 15);

    // alg: none, with an empty signature - the classic forgery.
    $none = verifySegment(['typ' => 'JWT', 'alg' => 'none', 'kid' => 'kid-active']).'.'.verifySegment($claims).'.';

    $refusedNone = verifyRefusalOf($none);

    expect($refusedNone)->toBeInstanceOf(AccessTokenRefused::class)
        ->and($refusedNone->reason())->toBe('invalid_token')
        ->and($refusedNone->getPrevious())->toBeInstanceOf(UnexpectedValueException::class);

    // A symmetric algorithm the key does not have. The key we hand the library
    // is always ES256, so this is refused before any signature check.
    $hmac = JWT::encode($claims, str_repeat('k', 32), 'HS256', 'kid-active');

    $refusedHmac = verifyRefusalOf($hmac);

    expect($refusedHmac)->toBeInstanceOf(AccessTokenRefused::class)
        ->and($refusedHmac->reason())->toBe('invalid_token')
        ->and($refusedHmac->getPrevious())->toBeInstanceOf(UnexpectedValueException::class);

    expect(verifyToken(verifyValidToken())['token_type'])->toBe('platform');
});

/*
|--------------------------------------------------------------------------
| 5. An unknown kid
|--------------------------------------------------------------------------
*/

it('refuses a kid that names no row, and accepts the one that does', function () {
    $pair = KeyPairGenerator::generate();
    $claims = TokenClaims::platform('01HUSER0000000000000000001', '01HJTI', verifyNow(), 15);

    $unknown = JWT::encode($claims, $pair['private'], KeyPairGenerator::ALGORITHM, 'kid-nobody');

    $refusal = verifyRefusalOf($unknown);

    expect($refusal)->toBeInstanceOf(AccessTokenRefused::class)
        ->and($refusal->reason())->toBe('unknown_kid')
        // Ours, not the library's: nothing was handed to the library at all.
        ->and($refusal->getPrevious())->toBeNull();

    expect(verifyToken(verifyValidToken())['sub'])->toBe('01HUSER0000000000000000001');
});

it('refuses a token with no kid in its header', function () {
    $pair = KeyPairGenerator::generate();
    $claims = TokenClaims::platform('01HUSER0000000000000000001', '01HJTI', verifyNow(), 15);

    $headerless = JWT::encode($claims, $pair['private'], KeyPairGenerator::ALGORITHM);

    $refusal = verifyRefusalOf($headerless);

    expect($refusal)->toBeInstanceOf(AccessTokenRefused::class)
        ->and($refusal->reason())->toBe('malformed_token');
});

it('refuses rubbish that is not a token at all', function () {
    foreach (['', 'not-a-token', 'a.b', 'a.b.c.d', 'a.b.c'] as $rubbish) {
        $refusal = verifyRefusalOf($rubbish);

        expect($refusal)->toBeInstanceOf(AccessTokenRefused::class, $rubbish)
            ->and($refusal->reason())->toBe('malformed_token', $rubbish);
    }
});

/*
|--------------------------------------------------------------------------
| 6. The kid of a retired key - ours
|--------------------------------------------------------------------------
*/

it('refuses a retired key and still verifies a retiring one, which signs nothing', function () {
    // The token is minted while the key is active, so the only thing that
    // changes between the acceptance and the refusal is the row's status.
    $token = verifyValidToken();

    expect(verifyToken($token)['sub'])->toBe('01HUSER0000000000000000001');

    authFixtureSetKeyStatus('kid-active', SigningKeyring::STATUS_RETIRING);

    // A retiring key still verifies (AC-29's last sentence) - and in the same
    // process, so this also proves the per-process key cache is not serving a
    // stale status.
    expect(verifyToken($token)['sub'])->toBe('01HUSER0000000000000000001');

    authFixtureSetKeyStatus('kid-active', SigningKeyring::STATUS_RETIRED);

    $refusal = verifyRefusalOf($token);

    expect($refusal)->toBeInstanceOf(AccessTokenRefused::class)
        ->and($refusal->reason())->toBe('retired_key')
        ->and($refusal->getPrevious())->toBeNull();

    // Control: back to active and the very same token verifies again, so the
    // refusal is the status and not the token.
    authFixtureSetKeyStatus('kid-active', SigningKeyring::STATUS_ACTIVE);

    expect(verifyToken($token)['sub'])->toBe('01HUSER0000000000000000001');
});

it('never signs with a retiring key even when it is the only one that verifies', function () {
    authFixtureSetKeyStatus('kid-active', SigningKeyring::STATUS_RETIRING);

    expect(fn () => verifyValidToken())->toThrow('Flexipos\Auth\Exceptions\NoActiveSigningKey');
});

/*
|--------------------------------------------------------------------------
| 7. exp, both sides of the boundary
|--------------------------------------------------------------------------
*/

it('accepts at exp minus one second and refuses at exactly exp', function () {
    $token = verifyValidToken();

    // Issued at 09:00:00, so exp is 09:15:00 exactly.
    authFixtureClockAt('2026-10-15 09:14:59.000');

    expect(verifyToken($token)['sub'])->toBe('01HUSER0000000000000000001');

    authFixtureClockAt('2026-10-15 09:15:00.000');

    $atExp = verifyRefusalOf($token);

    expect($atExp)->toBeInstanceOf(AccessTokenRefused::class)
        ->and($atExp->reason())->toBe('expired')
        ->and($atExp->getPrevious())->toBeInstanceOf(ExpiredException::class);

    authFixtureClockAt('2026-10-15 09:15:01.000');

    expect(verifyRefusalOf($token)?->reason())->toBe('expired');
});

it('reads the clock through the container rather than the wall clock', function () {
    // The library's own static timestamp is restored after every call, so a
    // verification cannot leave the process pinned to a test's instant.
    $token = verifyValidToken();

    expect(JWT::$timestamp)->toBeNull();

    verifyToken($token);

    expect(JWT::$timestamp)->toBeNull();

    authFixtureClockAt('2026-10-15 09:15:00.000');

    expect(verifyRefusalOf($token)?->reason())->toBe('expired')
        ->and(JWT::$timestamp)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 8. The wrong token_type for the operation - ours, both directions
|--------------------------------------------------------------------------
*/

it('refuses a platform token where a tenant one is required, and the reverse', function () {
    $platform = verifyValidToken(TokenClaims::TYPE_PLATFORM);
    $tenant = verifyValidToken(TokenClaims::TYPE_TENANT);

    $platformAsTenant = verifyRefusalOf($platform, TokenClaims::TYPE_TENANT);
    $tenantAsPlatform = verifyRefusalOf($tenant, TokenClaims::TYPE_PLATFORM);

    expect($platformAsTenant)->toBeInstanceOf(AccessTokenRefused::class)
        ->and($platformAsTenant->reason())->toBe('token_type')
        ->and($tenantAsPlatform)->toBeInstanceOf(AccessTokenRefused::class)
        ->and($tenantAsPlatform->reason())->toBe('token_type');

    // Controls, same test: each verifies for its own operation.
    expect(verifyToken($platform, TokenClaims::TYPE_PLATFORM)['token_type'])->toBe('platform')
        ->and(verifyToken($tenant, TokenClaims::TYPE_TENANT)['tenant'])->toBe('01HTENANT00000000000000001');
});

it('refuses a token that claims the other type while wearing this one shape', function () {
    // The case that makes the token-type check load-bearing rather than
    // merely precise. Measured: with the check deleted, the two cases above
    // are still refused - by the claim-set comparison, under the wrong reason,
    // because the two shapes differ by the `tenant` claim. This token does not
    // differ: its names are exactly the platform set and only its
    // `token_type` value says tenant, so the set comparison accepts it and the
    // type check is the only thing between it and a platform session.
    $claims = TokenClaims::platform('01HUSER0000000000000000001', '01HJTI', verifyNow(), 15);
    $claims['token_type'] = TokenClaims::TYPE_TENANT;

    expect(TokenClaims::namesOf($claims))->toBe(TokenClaims::allowedNames(TokenClaims::TYPE_PLATFORM));

    $forged = JWT::encode($claims, KeyPairGenerator::generate()['private'], KeyPairGenerator::ALGORITHM, 'kid-active');

    expect(verifyRefusalOf($forged, TokenClaims::TYPE_PLATFORM)?->reason())->toBe('token_type');
});

/*
|--------------------------------------------------------------------------
| AC-28 on the verifying side: the claim set is compared for equality
|--------------------------------------------------------------------------
*/

it('refuses a validly signed token carrying one extra claim', function () {
    $pair = KeyPairGenerator::generate();
    $claims = TokenClaims::platform('01HUSER0000000000000000001', '01HJTI', verifyNow(), 15);

    $extra = JWT::encode($claims + ['database' => 'flexipos_shop_1'], $pair['private'], KeyPairGenerator::ALGORITHM, 'kid-active');

    $refusal = verifyRefusalOf($extra);

    expect($refusal)->toBeInstanceOf(AccessTokenRefused::class)
        ->and($refusal->reason())->toBe('claim_set')
        // The signature was good: only the claim set refused it.
        ->and($refusal->getPrevious())->toBeNull();

    // Control: the same mint without the extra claim is accepted.
    $clean = JWT::encode($claims, $pair['private'], KeyPairGenerator::ALGORITHM, 'kid-active');

    expect(verifyToken($clean)['jti'])->toBe('01HJTI');
});

it('refuses a token missing a claim the set requires', function () {
    $pair = KeyPairGenerator::generate();
    $claims = TokenClaims::platform('01HUSER0000000000000000001', '01HJTI', verifyNow(), 15);

    unset($claims['jti']);

    $short = JWT::encode($claims, $pair['private'], KeyPairGenerator::ALGORITHM, 'kid-active');

    expect(verifyRefusalOf($short)?->reason())->toBe('claim_set');
});

/*
|--------------------------------------------------------------------------
| The refusal says nothing about the token
|--------------------------------------------------------------------------
*/

it('never puts the token or any claim value in the refusal message', function () {
    $token = verifyValidToken();

    authFixtureClockAt('2026-10-15 09:15:00.000');

    $refusal = verifyRefusalOf($token);

    expect($refusal->getMessage())->not->toContain($token)
        ->and($refusal->getMessage())->not->toContain('01HUSER0000000000000000001')
        ->and($refusal->getMessage())->not->toContain(explode('.', $token)[2]);
});
