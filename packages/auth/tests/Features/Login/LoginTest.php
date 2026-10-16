<?php

use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Exceptions\InvalidLoginInput;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Features\Login\Login;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessToken;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessTokenHandler;
use Flexipos\Auth\Support\IpAddress;
use Flexipos\Auth\Tenancy\TenantSelectionPolicy;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Auth\Tokens\TokenClaims;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 8: Login - the success path, the tenant list, and validation
|--------------------------------------------------------------------------
|
| AC-1, AC-2, AC-3, AC-4, AC-5, AC-9, AC-10, AC-11, AC-12, AC-13, AC-14,
| AC-15 and AC-31. The refusal matrix, the dummy hash and the lockout are
| step 9 and live in LoginRefusalTest.php; what this file ships of refusal is
| the three branches the handler cannot be coherent without, each with one
| case here and its full treatment there.
|
| **Two orderings are what most of this file actually measures.** Validation
| is in front of everything, so AC-9's three absences - no hasher call, no
| history row, no moved `failed_attempts` - are properties of position; and
| the signing key is resolved in front of the lookup, so AC-31's three
| absences are too. An absence proved by position still needs a liveness
| control (plan section 7, shape 5), because an uncalled hasher, an empty
| table and an untouched column all look exactly like success when the test
| is simply not reaching the code: every absence asserted here is paired with
| the same fixture producing the presence.
|
| **The tenant list is the first shipped consumer of TenantSelectionPolicy**,
| which makes it the first of AC-47 residual (b)'s three moved-value proofs.
| Step 6 proved the binding reaches a consumer; this proves a consumer that
| exists reads it.
|
| The master is the suite's in-memory SQLite stand-in, asserted to be SQLite
| before anything migrates. Nothing here reaches the engine at
| 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../Support/auth-fixture.php';

uses(TestCase::class);

const LOGIN_PASSWORD = 'correct-horse-battery-staple';

const LOGIN_CLOCK = '2026-10-15 09:00:00.000';

beforeEach(function () {
    authFixtureClockAt(LOGIN_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey('login-key-1');
});

/**
 * A login command for the standard caller - one address, one password, one
 * IPv4 client.
 */
function loginCommand(mixed $email, mixed $password = LOGIN_PASSWORD, ?string $ip = '203.0.113.7'): Login
{
    return new Login(
        email: $email,
        password: $password,
        ip: $ip,
        userAgent: 'FlexiPOS/1.0',
        deviceId: 'till-3',
    );
}

/**
 * The claims of an access token, as the shipped verifier reads them.
 *
 * Decoding through `VerifyAccessToken` rather than through the library
 * directly means a token this file calls "a platform access token" is one the
 * application would actually accept.
 *
 * @return array<string, mixed>
 */
function loginClaimsOf(string $token): array
{
    return app(VerifyAccessTokenHandler::class)->handle(
        new VerifyAccessToken($token, TokenClaims::TYPE_PLATFORM)
    );
}

/**
 * Key names a login response may never carry, whatever they hold.
 *
 * Two families: the credential itself, and anything that names where a
 * tenant's data lives. The frontend never names a database
 * (architecture.md), and the surest way to keep it that way is never to
 * have handed it one.
 *
 * @return list<string>
 */
function loginForbiddenKeys(): array
{
    return [
        'password',
        'password_hash',
        'secret',
        'private_key',
        'private_key_ref',
        'database',
        'database_name',
        'host',
        'port',
        'username',
        'driver',
        'dsn',
        'credential',
        'credentials',
    ];
}

/**
 * Walk a response body, however deep, and hand back every (key, scalar) in
 * it.
 *
 * @param  array<array-key, mixed>  $value
 * @return list<array{string, string}>
 */
function loginFlatten(array $value): array
{
    $found = [];

    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $found = array_merge($found, loginFlatten($item));

            continue;
        }

        $found[] = [(string) $key, is_scalar($item) ? (string) $item : ''];
    }

    return $found;
}

/**
 * AC-1's no-leak assertion: no forbidden key name anywhere in the body, and
 * none of the given values anywhere in it either.
 *
 * It is a function rather than a block of expectations inside one test for
 * one reason: the criterion requires the **same** assertion to be shown red
 * against a deliberately polluted body, and "the same assertion" has to be
 * one thing to be run twice.
 *
 * @param  array<array-key, mixed>  $response
 * @param  list<string>  $forbiddenValues
 */
function loginAssertNoLeak(array $response, array $forbiddenValues): void
{
    foreach (loginFlatten($response) as [$key, $scalar]) {
        expect(mb_strtolower($key))->not->toBeIn(loginForbiddenKeys());

        foreach ($forbiddenValues as $forbidden) {
            if ($forbidden === '') {
                continue;
            }

            expect($scalar)->not->toContain($forbidden);
        }
    }
}

/*
|--------------------------------------------------------------------------
| AC-1: what a successful login returns, and what it must not
|--------------------------------------------------------------------------
*/

it('returns the four parts of a login for a member of one active tenant', function () {
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('acme');
    authFixtureMembership($tenantId, $userId, ['is_default' => true]);

    $result = authFixtureLoginHandler()->handle(loginCommand('owner@example.com'));

    // (a) a platform access token, and it is one the shipped verifier accepts.
    $claims = loginClaimsOf($result->accessToken);
    expect($claims['sub'])->toBe($userId)
        ->and($claims['token_type'])->toBe(TokenClaims::TYPE_PLATFORM)
        // No tenant is named by a login, for any tenant (AC-5, AC-14).
        ->and(TokenClaims::namesOf($claims))->toBe(TokenClaims::allowedNames(TokenClaims::TYPE_PLATFORM));

    // (b) a refresh token, whose row holds the hash and never the value.
    expect($result->refreshToken)->not->toBe('');
    $refreshRows = authFixtureRows('refresh_tokens');
    expect($refreshRows)->toHaveCount(1)
        ->and($refreshRows[0]['token_hash'])->toBe(hash('sha256', $result->refreshToken))
        ->and($refreshRows[0]['tenant_id'])->toBeNull();

    // (c) a tenant list of one entry, in the shape AC-1 names.
    expect($result->tenants)->toBe([[
        'id' => $tenantId,
        'code' => 'acme',
        'name' => 'Shop acme',
        'status' => 'active',
        'selectable' => true,
        'is_default' => true,
    ]])
        ->and($result->defaultTenantId)->toBe($tenantId)
        // (d) and the flag of AC-4.
        ->and($result->mustChangePassword)->toBeFalse();

    // The rendered body carries those four and nothing else.
    expect(array_keys($result->toResponse()))->toBe([
        'access_token',
        'refresh_token',
        'tenants',
        'default_tenant_id',
        'must_change_password',
    ]);

    loginAssertNoLeak($result->toResponse(), [
        LOGIN_PASSWORD,
        (string) authFixtureUserRow($userId)['password_hash'],
        ':memory:',
    ]);
});

it('is red when the same no-leak assertion is run against a polluted body', function () {
    $userId = authFixtureUser('owner@example.com');
    $hash = (string) authFixtureUserRow($userId)['password_hash'];

    $clean = [
        'access_token' => 'header.payload.signature',
        'tenants' => [['id' => '01HQ', 'code' => 'acme']],
        'must_change_password' => false,
    ];

    // The control's control: clean is clean, or the two reds below would be
    // the assertion refusing everything rather than refusing pollution.
    loginAssertNoLeak($clean, [LOGIN_PASSWORD, $hash]);

    // Pollution 1: a forbidden key name, holding something innocuous.
    $withKey = $clean;
    $withKey['tenants'][0]['database'] = 'flexipos_acme';

    expect(fn () => loginAssertNoLeak($withKey, [LOGIN_PASSWORD, $hash]))
        ->toThrow(ExpectationFailedException::class);

    // Pollution 2: an allowed key name, holding a forbidden value.
    $withValue = $clean;
    $withValue['tenants'][0]['note'] = $hash;

    expect(fn () => loginAssertNoLeak($withValue, [LOGIN_PASSWORD, $hash]))
        ->toThrow(ExpectationFailedException::class);
});

/*
|--------------------------------------------------------------------------
| AC-2: the lookup is case-insensitive and trimmed, on the stand-in itself
|--------------------------------------------------------------------------
*/

it('signs in whatever the case and the surrounding whitespace', function (string $submitted) {
    $userId = authFixtureUser('Owner@Example.com');

    $result = authFixtureLoginHandler()->handle(loginCommand($submitted));

    expect($result->userId)->toBe($userId)
        // The display form is the user's: the lookup lowered, the column did
        // not.
        ->and(authFixtureUserRow($userId)['email'])->toBe('Owner@Example.com')
        // And the row records what was typed, trimmed (AC-23).
        ->and(authFixtureRows('login_history')[0]['email'])->toBe(trim($submitted));
})->with([
    'as stored' => 'Owner@Example.com',
    'all lower' => 'owner@example.com',
    'shouted domain' => 'owner@example.COM',
    'wrapped in spaces' => '   owner@example.com   ',
]);

it('does not confuse two addresses that differ by more than case', function () {
    $owner = authFixtureUser('Owner@Example.com');
    $other = authFixtureUser('other@example.com');

    expect(authFixtureLoginHandler()->handle(loginCommand('OWNER@EXAMPLE.COM'))->userId)->toBe($owner)
        ->and(authFixtureLoginHandler()->handle(loginCommand('Other@Example.com'))->userId)->toBe($other);
});

/*
|--------------------------------------------------------------------------
| AC-3, AC-4: what a success writes to the account
|--------------------------------------------------------------------------
*/

it('stamps the login and clears a run of failures', function () {
    $userId = authFixtureUser('owner@example.com', LOGIN_PASSWORD, ['failed_attempts' => 4]);

    authFixtureLoginHandler()->handle(loginCommand('owner@example.com'));

    $row = authFixtureUserRow($userId);

    expect($row['last_login_at'])->toBe(LOGIN_CLOCK)
        ->and(IpAddress::unpack(is_string($row['last_login_ip']) ? $row['last_login_ip'] : null))->toBe('203.0.113.7')
        ->and((int) $row['failed_attempts'])->toBe(0)
        ->and($row['locked_until'])->toBeNull();
});

it('round-trips an IPv6 caller into the account and the history', function () {
    $userId = authFixtureUser('owner@example.com');

    authFixtureLoginHandler()->handle(loginCommand('owner@example.com', ip: '2001:db8::1'));

    $row = authFixtureUserRow($userId);
    $history = authFixtureRows('login_history')[0];

    expect(IpAddress::unpack(is_string($row['last_login_ip']) ? $row['last_login_ip'] : null))->toBe('2001:db8::1')
        ->and(IpAddress::unpack(is_string($history['ip']) ? $history['ip'] : null))->toBe('2001:db8::1');
});

it('reports must_change_password as the account holds it', function (bool $flag) {
    authFixtureUser('owner@example.com', LOGIN_PASSWORD, ['must_change_password' => $flag]);

    $result = authFixtureLoginHandler()->handle(loginCommand('owner@example.com'));

    expect($result->mustChangePassword)->toBe($flag)
        ->and($result->toResponse()['must_change_password'])->toBe($flag);
})->with([
    'the flag is set' => [true],
    'the control: the flag is not' => [false],
]);

/*
|--------------------------------------------------------------------------
| AC-5, AC-15: a login with no shops behind it
|--------------------------------------------------------------------------
*/

it('signs in a user with no memberships and names no tenant', function () {
    $userId = authFixtureUser('owner@example.com');

    $result = authFixtureLoginHandler()->handle(loginCommand('owner@example.com'));

    expect($result->tenants)->toBe([])
        ->and($result->defaultTenantId)->toBeNull()
        ->and($result->accessToken)->not->toBe('')
        ->and(loginClaimsOf($result->accessToken)['token_type'])->toBe(TokenClaims::TYPE_PLATFORM)
        // The platform claim set has no `tenant` in it, so there is no tenant
        // token in this response and nowhere for one to hide.
        ->and(TokenClaims::namesOf(loginClaimsOf($result->accessToken)))
        ->not->toContain('tenant');

    expect($userId)->toBe($result->userId);
});

it('gives platform staff the same platform token and the same empty list', function () {
    authFixtureUser('staff@example.com', LOGIN_PASSWORD, ['is_platform_staff' => true]);
    // A shop they are not a member of. Staff get no bypass (D-18): it is not
    // listed, and selecting it is step 10's refusal.
    authFixtureTenant('acme');

    $result = authFixtureLoginHandler()->handle(loginCommand('staff@example.com'));

    expect($result->tenants)->toBe([])
        ->and($result->defaultTenantId)->toBeNull()
        ->and(loginClaimsOf($result->accessToken)['token_type'])->toBe(TokenClaims::TYPE_PLATFORM);
});

/*
|--------------------------------------------------------------------------
| AC-11: which memberships are listed at all
|--------------------------------------------------------------------------
*/

it('lists only the active, undeleted membership of an undeleted tenant', function () {
    $userId = authFixtureUser('owner@example.com');

    $good = authFixtureTenant('a-good');
    authFixtureMembership($good, $userId);

    $invited = authFixtureTenant('b-invited');
    authFixtureMembership($invited, $userId, ['status' => 'invited']);

    $suspendedMembership = authFixtureTenant('c-suspended-member');
    authFixtureMembership($suspendedMembership, $userId, ['status' => 'suspended']);

    $deletedMembership = authFixtureTenant('d-deleted-member');
    authFixtureMembership($deletedMembership, $userId, ['deleted_at' => LOGIN_CLOCK]);

    $deletedTenant = authFixtureTenant('e-deleted-tenant', 'active', ['deleted_at' => LOGIN_CLOCK]);
    authFixtureMembership($deletedTenant, $userId);

    $result = authFixtureLoginHandler()->handle(loginCommand('owner@example.com'));

    expect(array_column($result->tenants, 'code'))->toBe(['a-good']);

    // The control AC-11 names: make the other four valid and all five appear,
    // so the single entry above is the four conditions and not a broken join.
    DB::connection('master')->table('tenant_users')
        ->where('user_id', $userId)
        ->update(['status' => 'active', 'deleted_at' => null]);
    DB::connection('master')->table('tenants')->update(['deleted_at' => null]);

    expect(array_column(
        authFixtureLoginHandler()->handle(loginCommand('owner@example.com'))->tenants,
        'code'
    ))->toBe(['a-good', 'b-invited', 'c-suspended-member', 'd-deleted-member', 'e-deleted-tenant']);
});

/*
|--------------------------------------------------------------------------
| AC-12: selectable, and AC-47 residual (b), first of three
|--------------------------------------------------------------------------
*/

/**
 * One account with an active membership in a tenant of each status the spec
 * names, nine in all.
 *
 * @return array{0: string, 1: list<string>}
 */
function loginNineTenantFixture(): array
{
    $statuses = ['trial', 'active', 'past_due', 'pending', 'provisioning', 'suspended', 'read_only', 'closed', 'archived'];

    $userId = authFixtureUser('owner@example.com');

    foreach ($statuses as $status) {
        authFixtureMembership(authFixtureTenant($status, $status), $userId);
    }

    sort($statuses);

    return [$userId, $statuses];
}

/**
 * The status of every listed tenant mapped to its `selectable` flag.
 *
 * @return array<string, bool>
 */
function loginSelectableByStatus(): array
{
    $tenants = authFixtureLoginHandler()->handle(loginCommand('owner@example.com'))->tenants;

    return array_combine(
        array_column($tenants, 'status'),
        array_column($tenants, 'selectable')
    );
}

it('marks exactly trial, active and past_due as selectable', function () {
    [, $statuses] = loginNineTenantFixture();

    $selectable = loginSelectableByStatus();

    expect(array_keys($selectable))->toBe($statuses)
        ->and(array_keys(array_filter($selectable)))->toBe(['active', 'past_due', 'trial']);
});

it('follows the selectable set it was given, and ignores a policy nobody bound', function () {
    loginNineTenantFixture();

    // The shipped policy, for the comparison to be against something.
    expect(array_keys(array_filter(loginSelectableByStatus())))->toBe(['active', 'past_due', 'trial']);

    // The moved value: a different selectable set, bound where the shipped
    // one was, and the *shipped consumer* - the login tenant list - follows
    // it. A copied list cannot follow a value that moved (AC-47 residual (b),
    // first of three; step 10 and step 11 carry the other two).
    app()->instance(
        TenantSelectionPolicy::class,
        TenantSelectionPolicy::withSelectableStatuses(['suspended', 'closed'])
    );

    expect(array_keys(array_filter(loginSelectableByStatus())))->toBe(['closed', 'suspended']);

    // The negative control that makes the move mean something: a second
    // policy object, constructed and not bound, changes nothing. Without it,
    // "the list followed the policy" could as easily be "the test moved
    // something nobody reads and the list happened to change".
    TenantSelectionPolicy::withSelectableStatuses(['archived']);

    expect(array_keys(array_filter(loginSelectableByStatus())))->toBe(['closed', 'suspended']);
});

it('lets the default follow the policy too', function () {
    $userId = authFixtureUser('owner@example.com');
    authFixtureMembership(authFixtureTenant('shut', 'closed'), $userId);

    expect(authFixtureLoginHandler()->handle(loginCommand('owner@example.com'))->defaultTenantId)->toBeNull();

    app()->instance(
        TenantSelectionPolicy::class,
        TenantSelectionPolicy::withSelectableStatuses(['closed'])
    );

    expect(authFixtureLoginHandler()->handle(loginCommand('owner@example.com'))->defaultTenantId)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| AC-13, AC-14: the default, and a user whose only shop is suspended
|--------------------------------------------------------------------------
*/

it('picks the default by AC-13s three rules in order', function () {
    $userId = authFixtureUser('owner@example.com');

    $a = authFixtureTenant('a-active');
    $b = authFixtureTenant('b-active');
    $shut = authFixtureTenant('c-closed', 'closed');

    authFixtureMembership($a, $userId);
    authFixtureMembership($b, $userId);
    // (a) would pick this one, but it is not selectable, so it falls through.
    authFixtureMembership($shut, $userId, ['is_default' => true]);

    // Two selectable, no selectable default: (c), null.
    expect(authFixtureLoginHandler()->handle(loginCommand('owner@example.com'))->defaultTenantId)->toBeNull();

    // (a): a selectable membership marked default wins over everything.
    DB::connection('master')->table('tenant_users')
        ->where('tenant_id', $b)->update(['is_default' => true]);

    expect(authFixtureLoginHandler()->handle(loginCommand('owner@example.com'))->defaultTenantId)->toBe($b);

    // (b): no default among the selectable, and exactly one of them - the
    // single selectable among several *listed*, which is the case AC-13 names.
    DB::connection('master')->table('tenant_users')->update(['is_default' => false]);
    DB::connection('master')->table('tenants')->where('id', $b)->update(['status' => 'archived']);

    expect(authFixtureLoginHandler()->handle(loginCommand('owner@example.com'))->defaultTenantId)->toBe($a);
});

it('signs in a member of one suspended shop and offers them nothing to open', function () {
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('acme', 'suspended');
    authFixtureMembership($tenantId, $userId, ['is_default' => true]);

    $result = authFixtureLoginHandler()->handle(loginCommand('owner@example.com'));

    expect($result->tenants)->toHaveCount(1)
        ->and($result->tenants[0]['status'])->toBe('suspended')
        ->and($result->tenants[0]['selectable'])->toBeFalse()
        ->and($result->defaultTenantId)->toBeNull()
        // No tenant token exists in the response: the one token it carries is
        // a platform one, and the platform claim set cannot name a tenant.
        ->and(TokenClaims::namesOf(loginClaimsOf($result->accessToken)))
        ->toBe(TokenClaims::allowedNames(TokenClaims::TYPE_PLATFORM));
});

/*
|--------------------------------------------------------------------------
| AC-9: a body that cannot be read, and the three absences
|--------------------------------------------------------------------------
*/

it('refuses an unreadable body before the hasher, the history and the account', function (mixed $email, mixed $password, string $reason) {
    $userId = authFixtureUser('owner@example.com', LOGIN_PASSWORD, ['failed_attempts' => 2]);
    $hasher = authFixtureCountingHasher();

    $caught = null;

    try {
        authFixtureLoginHandler()->handle(new Login($email, $password));
    } catch (InvalidLoginInput $refusal) {
        $caught = $refusal;
    }

    // The reason, not only the class: AC-9 has six cases and a test that
    // asserted the class alone would pass with one of them handling all six.
    expect($caught)->toBeInstanceOf(InvalidLoginInput::class)
        ->and($caught?->reason())->toBe($reason);

    expect($hasher->count())->toBe(0)
        ->and(authFixtureRows('login_history'))->toBe([])
        ->and((int) authFixtureUserRow($userId)['failed_attempts'])->toBe(2);
})->with([
    'no email' => [null, LOGIN_PASSWORD, InvalidLoginInput::REASON_EMAIL_MISSING],
    'whitespace email' => ['   ', LOGIN_PASSWORD, InvalidLoginInput::REASON_EMAIL_MISSING],
    'email is a number' => [42, LOGIN_PASSWORD, InvalidLoginInput::REASON_EMAIL_NOT_STRING],
    'email is an array' => [['owner@example.com'], LOGIN_PASSWORD, InvalidLoginInput::REASON_EMAIL_NOT_STRING],
    'no password' => ['owner@example.com', null, InvalidLoginInput::REASON_PASSWORD_MISSING],
    'empty password' => ['owner@example.com', '', InvalidLoginInput::REASON_PASSWORD_MISSING],
    'password is a number' => ['owner@example.com', 1234, InvalidLoginInput::REASON_PASSWORD_NOT_STRING],
]);

it('refuses an email of 192 characters and a password of 1025', function () {
    $userId = authFixtureUser('owner@example.com', LOGIN_PASSWORD, ['failed_attempts' => 2]);
    $hasher = authFixtureCountingHasher();

    $longEmail = str_repeat('a', 192 - strlen('@example.com')).'@example.com';
    expect(mb_strlen($longEmail))->toBe(192);

    expect(fn () => authFixtureLoginHandler()->handle(loginCommand($longEmail)))
        ->toThrow(InvalidLoginInput::class)
        ->and(fn () => authFixtureLoginHandler()->handle(loginCommand('owner@example.com', str_repeat('p', 1025))))
        ->toThrow(InvalidLoginInput::class);

    expect($hasher->count())->toBe(0)
        ->and(authFixtureRows('login_history'))->toBe([])
        ->and((int) authFixtureUserRow($userId)['failed_attempts'])->toBe(2);
});

it('accepts both boundary lengths as input and fails them as credentials', function () {
    // AC-9's own control, and the liveness half of the three absences above:
    // the same fixture, one character shorter, reaches the credential path -
    // so the empty history and the still hasher are validation refusing, not
    // the test failing to arrive.
    $userId = authFixtureUser('owner@example.com', LOGIN_PASSWORD, ['failed_attempts' => 2]);

    $limitEmail = str_repeat('a', 191 - strlen('@example.com')).'@example.com';
    expect(mb_strlen($limitEmail))->toBe(191);

    expect(fn () => authFixtureLoginHandler()->handle(loginCommand($limitEmail)))
        ->toThrow(CredentialRefused::class);

    expect(fn () => authFixtureLoginHandler()->handle(loginCommand('owner@example.com', str_repeat('p', 1024))))
        ->toThrow(CredentialRefused::class);

    $history = authFixtureRows('login_history');

    expect($history)->toHaveCount(2)
        ->and($history[0]['failure_reason'])->toBe('unknown_user')
        ->and($history[0]['user_id'])->toBeNull()
        ->and($history[1]['failure_reason'])->toBe('bad_password')
        ->and($history[1]['user_id'])->toBe($userId);
});

/*
|--------------------------------------------------------------------------
| AC-10: the command has nowhere to put a submitted tenant or database
|--------------------------------------------------------------------------
*/

it('has no field a client could use to name a tenant, a database or a host', function () {
    $constructor = (new ReflectionClass(Login::class))->getConstructor();

    expect($constructor)->not->toBeNull();

    $names = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $constructor instanceof ReflectionMethod ? $constructor->getParameters() : []
    );

    // Two credentials and three facts about the connection. A handler that
    // honoured a submitted `database` would need a sixth, and it would be in
    // the diff (AC-10, ARCH.md section 5).
    expect($names)->toBe(['email', 'password', 'ip', 'userAgent', 'deviceId']);
});

/*
|--------------------------------------------------------------------------
| AC-31: no active key, and the liveness control for its three absences
|--------------------------------------------------------------------------
*/

it('fails closed with no active key, writing no row and stamping nothing', function () {
    $userId = authFixtureUser('owner@example.com', LOGIN_PASSWORD, ['failed_attempts' => 2]);
    authFixtureMembership(authFixtureTenant('acme'), $userId);

    // A key exists; none is active. This is the operational failure of AC-31
    // and not a credential one.
    authFixtureSetKeyStatus('login-key-1', SigningKeyring::STATUS_RETIRED);

    expect(fn () => authFixtureLoginHandler()->handle(loginCommand('owner@example.com')))
        ->toThrow(NoActiveSigningKey::class);

    $row = authFixtureUserRow($userId);

    expect(authFixtureRows('refresh_tokens'))->toBe([])
        ->and($row['last_login_at'])->toBeNull()
        // Not counted toward lockout: the counter did not move, in either
        // direction, and no lock was taken.
        ->and((int) $row['failed_attempts'])->toBe(2)
        ->and($row['locked_until'])->toBeNull();
});

it('produces all three of AC-31s absences as presences when a key is active', function () {
    // The liveness control (plan section 7, shape 5). Without it, a login
    // that failed for an unrelated reason - a broken fixture, a missing
    // membership, a typo in the address - would satisfy every assertion
    // above, and the criterion would be proved by the test never arriving.
    // Same fixture, same command, one key status different.
    $userId = authFixtureUser('owner@example.com', LOGIN_PASSWORD, ['failed_attempts' => 2]);
    authFixtureMembership(authFixtureTenant('acme'), $userId);

    authFixtureLoginHandler()->handle(loginCommand('owner@example.com'));

    $row = authFixtureUserRow($userId);

    expect(authFixtureRows('refresh_tokens'))->toHaveCount(1)
        ->and($row['last_login_at'])->toBe(LOGIN_CLOCK)
        ->and((int) $row['failed_attempts'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The three refusal branches this step cannot ship without
|--------------------------------------------------------------------------
|
| One case each, so nothing in the handler is untested. The matrix - every
| status, the dummy hash, the lockout, the byte-identical response - is step 9
| and lives in LoginRefusalTest.php.
|
*/

it('refuses an unknown address, a wrong password and an account that is not active', function (string $email, string $password, string $reason, bool $accountIsNamed) {
    $ownerId = authFixtureUser('owner@example.com');
    $disabledId = authFixtureUser('disabled@example.com', LOGIN_PASSWORD, ['status' => 'disabled']);

    expect(fn () => authFixtureLoginHandler()->handle(loginCommand($email, $password)))
        ->toThrow(CredentialRefused::class);

    $history = authFixtureRows('login_history');
    $named = $email === 'disabled@example.com' ? $disabledId : $ownerId;

    expect($history)->toHaveCount(1)
        ->and($history[0]['failure_reason'])->toBe($reason)
        ->and((bool) $history[0]['success'])->toBeFalse()
        // `user_id` is the account when the address matched one and null when
        // it did not (AC-23) - the one place the two are distinguishable.
        ->and($history[0]['user_id'])->toBe($accountIsNamed ? $named : null)
        ->and(authFixtureRows('refresh_tokens'))->toBe([])
        ->and(authFixtureUserRow($ownerId)['last_login_at'])->toBeNull();
})->with([
    'nobody of that address' => ['nobody@example.com', LOGIN_PASSWORD, 'unknown_user', false],
    'the wrong password' => ['owner@example.com', 'not-the-password', 'bad_password', true],
    'an account that is disabled' => ['disabled@example.com', LOGIN_PASSWORD, 'not_active', true],
]);
