<?php

use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Features\Login\Login;
use Flexipos\Auth\Features\Logout\Logout;
use Flexipos\Auth\Features\Logout\LogoutResult;
use Flexipos\Auth\Features\RefreshToken\RefreshToken;
use Flexipos\Auth\Features\SelectTenant\SelectTenant;
use Flexipos\Auth\Tokens\SigningKeyring;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 11: Logout - one answer, whatever it is handed
|--------------------------------------------------------------------------
|
| AC-49, and AC-30's last clause (revoking F1 leaves F0 and F2 usable).
|
| **The criterion is mostly about what logout does not reveal**, so the
| shape of these tests is a comparison rather than an assertion about a
| single call: a valid token, an unknown one, an expired one and one that
| was already revoked are run through the same operation and their answers
| are compared with each other. One of them being right proves nothing; all
| four being identical is the criterion.
|
| The master is the suite's in-memory SQLite stand-in. Nothing here reaches
| the engine at 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../Support/auth-fixture.php';

uses(TestCase::class);

const LOGOUT_CLOCK = '2026-10-15 13:00:00.000';

const LOGOUT_KID = 'logout-key-1';

const LOGOUT_PASSWORD = 'correct-horse-battery-staple';

beforeEach(function () {
    authFixtureClockAt(LOGOUT_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(LOGOUT_KID);
});

/**
 * A signed-in person and the platform refresh token their login produced.
 *
 * @return array{0: string, 1: string} user id, refresh token
 */
function logoutSession(string $email = 'owner@example.com'): array
{
    $userId = authFixtureUser($email);

    $login = authFixtureLoginHandler()->handle(new Login(
        email: $email,
        password: LOGOUT_PASSWORD,
        ip: '203.0.113.7',
    ));

    return [$userId, $login->refreshToken];
}

/*
|--------------------------------------------------------------------------
| AC-49: the family goes, and a later refresh is refused
|--------------------------------------------------------------------------
*/

it('revokes the whole family and makes a later refresh impossible', function () {
    [, $token] = logoutSession();

    // Two rotations first, so the family is a chain: signing out of a
    // session that left links behind it must take those too.
    $second = authFixtureRefreshHandler()->handle(new RefreshToken(token: $token));
    $third = authFixtureRefreshHandler()->handle(new RefreshToken(token: $second->refreshToken));

    expect(authFixtureLogoutHandler()->handle(new Logout($third->refreshToken))->toResponse())
        ->toBe(LogoutResult::body());

    expect(array_column(authFixtureFamilyRows($third->familyId), 'revoked_at'))
        ->toBe([LOGOUT_CLOCK, LOGOUT_CLOCK, LOGOUT_CLOCK]);

    // The consequence the criterion names: a following refresh is refused
    // (AC-35's revoked case).
    expect(fn () => authFixtureRefreshHandler()->handle(new RefreshToken(token: $third->refreshToken)))
        ->toThrow(CredentialRefused::class);
});

it('answers a valid, an unknown, an expired and an already-revoked token identically', function () {
    [, $valid] = logoutSession();
    [, $revoked] = logoutSession('other@example.com');
    [, $expired] = logoutSession('third@example.com');

    DB::connection('master')->table('refresh_tokens')
        ->where('token_hash', hash('sha256', $revoked))
        ->update(['revoked_at' => LOGOUT_CLOCK]);

    DB::connection('master')->table('refresh_tokens')
        ->where('token_hash', hash('sha256', $expired))
        ->update(['expires_at' => '2026-10-01 00:00:00.000']);

    $answers = [
        'valid' => authFixtureLogoutHandler()->handle(new Logout($valid))->toResponse(),
        'unknown' => authFixtureLogoutHandler()->handle(new Logout('never-a-token-of-ours'))->toResponse(),
        'expired' => authFixtureLogoutHandler()->handle(new Logout($expired))->toResponse(),
        'revoked' => authFixtureLogoutHandler()->handle(new Logout($revoked))->toResponse(),
        'empty' => authFixtureLogoutHandler()->handle(new Logout(''))->toResponse(),
        'not a string' => authFixtureLogoutHandler()->handle(new Logout(7))->toResponse(),
    ];

    // One answer, six causes. The comparison is between all of them, not
    // between one of them and a literal, because a difference anywhere is
    // the disclosure.
    expect(array_unique(array_map(static fn (array $body): string => json_encode($body) ?: '', $answers)))
        ->toHaveCount(1)
        ->and($answers['valid'])->toBe(['status' => 'signed_out']);
});

it('changes nothing for a token it does not know', function () {
    [, $token] = logoutSession();

    $before = authFixtureRows('refresh_tokens');

    authFixtureLogoutHandler()->handle(new Logout('never-a-token-of-ours'));

    // The liveness control for that absence, in the same test: the same
    // handler, handed a token it does know, does change something. Without
    // it "nothing changed" is also what a handler that does nothing at all
    // reports.
    expect(authFixtureRows('refresh_tokens'))->toBe($before);

    authFixtureLogoutHandler()->handle(new Logout($token));

    expect(authFixtureRows('refresh_tokens'))->not->toBe($before);
});

it('is idempotent, and a second logout does not move the first revocation', function () {
    [, $token] = logoutSession();

    authFixtureLogoutHandler()->handle(new Logout($token));

    $afterFirst = authFixtureRows('refresh_tokens');

    // The clock moves between the two calls, so a handler that re-revoked
    // would overwrite the instant and the comparison below would see it.
    authFixtureClockAt('2026-10-15 13:45:00.000');

    expect(authFixtureLogoutHandler()->handle(new Logout($token))->toResponse())->toBe(LogoutResult::body())
        ->and(authFixtureRows('refresh_tokens'))->toBe($afterFirst);
});

it('leaves another person\'s session alone', function () {
    // AC-49's own control.
    [, $mine] = logoutSession();
    [$otherId, $theirs] = logoutSession('other@example.com');

    authFixtureLogoutHandler()->handle(new Logout($mine));

    $other = DB::connection('master')->table('refresh_tokens')
        ->where('token_hash', hash('sha256', $theirs))->first();

    expect($other->revoked_at)->toBeNull()
        ->and($other->principal_id)->toBe($otherId)
        // And it is not merely unrevoked: it still works.
        ->and(authFixtureRefreshHandler()->handle(new RefreshToken(token: $theirs))->refreshToken)
        ->not->toBe($theirs);
});

/*
|--------------------------------------------------------------------------
| AC-30: one family at a time
|--------------------------------------------------------------------------
*/

it('revokes only the family it was given, leaving the platform and the other tenant usable', function () {
    [$userId, $platform] = logoutSession();

    $a = authFixtureTenant('shop-a');
    $b = authFixtureTenant('shop-b');
    authFixtureMembership($a, $userId);
    authFixtureMembership($b, $userId);

    $first = authFixtureSelectTenantHandler()->handle(new SelectTenant(userId: $userId, code: 'shop-a'));
    $second = authFixtureSelectTenantHandler()->handle(new SelectTenant(userId: $userId, code: 'shop-b'));

    // Sign out of F1 only.
    authFixtureLogoutHandler()->handle(new Logout($first->refreshToken));

    expect(array_column(authFixtureFamilyRows($first->familyId), 'revoked_at'))->toBe([LOGOUT_CLOCK]);

    // F0 and F2 are untouched, and untouched means usable rather than
    // merely unrevoked.
    expect(authFixtureRefreshHandler()->handle(new RefreshToken(token: $platform))->tenantId)->toBeNull()
        ->and(authFixtureRefreshHandler()->handle(new RefreshToken(token: $second->refreshToken))->tenantId)->toBe($b);
});

/*
|--------------------------------------------------------------------------
| The one operation that still works with every key retired
|--------------------------------------------------------------------------
*/

it('signs a person out even when nothing can sign a token', function () {
    // Logout mints nothing, so the key-first rule that governs the three
    // issuing slices does not apply to it - and a deployment whose keys
    // have all retired must still be able to cut a lost laptop off. The
    // control is the line below: refresh, which does mint, cannot run under
    // the same conditions.
    [, $token] = logoutSession();

    authFixtureSetKeyStatus(LOGOUT_KID, SigningKeyring::STATUS_RETIRED);

    expect(authFixtureLogoutHandler()->handle(new Logout($token))->toResponse())->toBe(LogoutResult::body())
        ->and(authFixtureRefreshRow($token)['revoked_at'])->toBe(LOGOUT_CLOCK);
});
