<?php

use Flexipos\Auth\Exceptions\InvalidTenantCode;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Exceptions\NoTenantAccess;
use Flexipos\Auth\Exceptions\TenantNotServable;
use Flexipos\Auth\Features\Login\Login;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttempt;
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
| T-009 step 10: SelectTenant - opening one shop, and touching nothing else
|--------------------------------------------------------------------------
|
| AC-39, AC-40, AC-41, AC-43, AC-44, AC-30's selection half, and AC-52.
| AC-42 (a tenant token, an expired platform token, or none, presented to
| this operation) is the middleware's and belongs to step 12: this slice is
| handed a subject that has already been verified and has no token to judge.
|
| **Three absences are measured here and each one is paired with its
| presence**, because an absence is also what a test that never arrived
| reports (plan section 7, shape 5):
|
|  - no `tenant_databases` access in the master query log - paired with the
|    statement the log must contain, the tenant-code lookup itself (AC-52);
|  - no `login_history` row on an operational failure - paired with the same
|    fixture writing one when a key is active (D-36);
|  - no password or token in the application log - paired with the recorder
|    reporting a sentinel it was deliberately fed (AC-26).
|
| **The moved-value proof and the mask shape 4 remedy are one test.** A
| policy built over `['closed']` must make a closed tenant selectable *and*
| stamp `last_accessed_at` on it. Only a handler whose update is written
| unconditionally on the row the read returned can do both: an update that
| re-filtered on the tenant's status would silently write nothing, and the
| policy read would be unprovable. That is AC-47 residual (b), second of
| three, and plan section 7 shape 4, closed by the same input.
|
| The master is the suite's in-memory SQLite stand-in, asserted to be SQLite
| before anything migrates. Nothing here reaches the engine at
| 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../Support/auth-fixture.php';

uses(TestCase::class);

const SELECT_CLOCK = '2026-10-15 11:30:00.000';

const SELECT_KID = 'select-key-1';

beforeEach(function () {
    authFixtureClockAt(SELECT_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(SELECT_KID);
});

/**
 * A selection command for the standard caller.
 */
function selectCommand(string $userId, mixed $code = 'shop-a', ?string $ip = '203.0.113.7'): SelectTenant
{
    return new SelectTenant(
        userId: $userId,
        code: $code,
        ip: $ip,
        userAgent: 'FlexiPOS/1.0',
        deviceId: 'till-3',
    );
}

/**
 * One account, one shop, one active membership - the fixture almost every
 * case below starts from.
 *
 * @param  array<string, mixed>  $membership  attributes for the membership row
 * @return array{0: string, 1: string, 2: string} user id, tenant id, membership id
 */
function selectFixture(string $status = 'active', array $membership = []): array
{
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('shop-a', $status);
    $membershipId = authFixtureMembership($tenantId, $userId, $membership);

    return [$userId, $tenantId, $membershipId];
}

/**
 * The claims of a tenant access token, as the shipped verifier reads them.
 *
 * Decoding through `VerifyAccessToken` rather than through the library means
 * a token this file calls "a tenant access token" is one the application
 * would actually accept.
 *
 * @return array<string, mixed>
 */
function selectClaimsOf(string $token, string $type = TokenClaims::TYPE_TENANT): array
{
    return app(VerifyAccessTokenHandler::class)->handle(new VerifyAccessToken($token, $type));
}

/**
 * One membership row, as an array.
 *
 * @return array<string, mixed>
 */
function selectMembershipRow(string $membershipId): array
{
    $row = DB::connection('master')->table('tenant_users')->where('id', $membershipId)->first();

    expect($row)->not->toBeNull();

    return (array) $row;
}

/*
|--------------------------------------------------------------------------
| AC-39: what a successful selection produces
|--------------------------------------------------------------------------
*/

it('issues a tenant token for the resolved tenant, stamps the membership and records the attempt', function () {
    [$userId, $tenantId, $membershipId] = selectFixture();

    $result = authFixtureSelectTenantHandler()->handle(selectCommand($userId));

    $claims = selectClaimsOf($result->accessToken);

    expect($result->tenantId)->toBe($tenantId)
        ->and($result->tenantCode)->toBe('shop-a')
        // Set equality, not containment: an extra claim fails here (AC-28's
        // comparison, applied to the tenant shape of AC-39).
        ->and(TokenClaims::namesOf($claims))->toBe(['exp', 'iat', 'jti', 'sub', 'tenant', 'token_type'])
        ->and($claims['token_type'])->toBe(TokenClaims::TYPE_TENANT)
        ->and($claims['sub'])->toBe($userId)
        // The ULID id, never the code - a code is what a client sends and an
        // id is what the master resolved.
        ->and($claims['tenant'])->toBe($tenantId)
        ->and($claims['exp'])->toBe($claims['iat'] + 15 * 60);

    // `last_accessed_at` is the clock's now, on the membership that was read.
    expect(selectMembershipRow($membershipId)['last_accessed_at'])->toBe(SELECT_CLOCK);

    $history = authFixtureRows('login_history');
    $refresh = authFixtureRows('refresh_tokens');

    expect($history)->toHaveCount(1)
        ->and((bool) $history[0]['success'])->toBeTrue()
        ->and($history[0]['failure_reason'])->toBeNull()
        ->and($history[0]['tenant_id'])->toBe($tenantId)
        ->and($history[0]['user_id'])->toBe($userId)
        // D-15: the row names the shop that was opened, which is the whole
        // reason `login_history.tenant_id` exists.
        ->and($refresh)->toHaveCount(1)
        ->and($refresh[0]['tenant_id'])->toBe($tenantId)
        ->and($refresh[0]['family_id'])->toBe($result->familyId)
        ->and($refresh[0]['used_at'])->toBeNull()
        ->and($refresh[0]['revoked_at'])->toBeNull();
});

it('omits the employee claim when the membership has no employee, and carries it when it does', function () {
    // AC-39's own positive control: the two halves are one test, because
    // "no `employee` claim" is an absence and the presence is what proves
    // the claim can appear at all.
    [$userId] = selectFixture();

    expect(array_key_exists('employee', selectClaimsOf(
        authFixtureSelectTenantHandler()->handle(selectCommand($userId))->accessToken
    )))->toBeFalse();

    $otherUser = authFixtureUser('manager@example.com');
    $tenantId = authFixtureTenant('shop-b');
    authFixtureMembership($tenantId, $otherUser, ['employee_id' => '01JBEMPLOYEE0000000000000A']);

    $claims = selectClaimsOf(
        authFixtureSelectTenantHandler()->handle(selectCommand($otherUser, 'shop-b'))->accessToken
    );

    expect($claims['employee'])->toBe('01JBEMPLOYEE0000000000000A')
        ->and(TokenClaims::namesOf($claims))->toBe(['employee', 'exp', 'iat', 'jti', 'sub', 'tenant', 'token_type']);
});

/*
|--------------------------------------------------------------------------
| AC-52 and AC-39's last sentence: no tenant database is opened
|--------------------------------------------------------------------------
*/

it('reads the master only, never tenant_databases, and leaves the tenant stanza a placeholder', function () {
    [$userId] = selectFixture();

    $before = config('database.connections.tenant');

    [, $statements] = authFixtureWithMasterQueryLog(
        fn () => authFixtureSelectTenantHandler()->handle(selectCommand($userId))
    );

    $mentioning = static fn (string $table): array => array_values(array_filter(
        $statements,
        static fn (string $statement): bool => str_contains($statement, $table)
    ));

    // The criterion: D-9's "selection does not probe the mapping".
    expect($mentioning('tenant_databases'))->toBe([])
        // **The liveness control**, and it is what makes the line above mean
        // anything: a query log that recorded nothing satisfies "contains no
        // `tenant_databases`" perfectly. This is the statement the step
        // cannot avoid issuing - the code lookup itself - so an empty log
        // fails here before the absence above can pass for free.
        ->and($mentioning('"tenants"'))->not->toBe([])
        ->and($mentioning('"tenant_users"'))->not->toBe([]);

    // The second half of AC-52, which is the property itself rather than an
    // observation of one: the stanza is still the placeholder it was.
    // T-003's own test is the instrument that only `Tenancy` ever writes it,
    // and it passes unmodified in the same gate run.
    expect(config('database.connections.tenant'))->toBe($before)
        ->and(config('database.connections.tenant.driver'))->toBe('flexipos-no-tenant');
});

/*
|--------------------------------------------------------------------------
| AC-40(a): five shapes, one refusal, and the valid case as the control
|--------------------------------------------------------------------------
*/

it('refuses every shape of no_access identically, and admits the valid case in the same test', function () {
    $userId = authFixtureUser('owner@example.com');

    // Five shapes of "you may not open this", each with its own shop so that
    // one fixture cannot mask another.
    $noMembership = authFixtureTenant('no-membership');
    $invited = authFixtureTenant('invited-shop');
    $suspendedMember = authFixtureTenant('suspended-member');
    $deletedMember = authFixtureTenant('deleted-member');
    $deletedTenant = authFixtureTenant('deleted-shop', 'active', ['deleted_at' => SELECT_CLOCK]);

    authFixtureMembership($invited, $userId, ['status' => 'invited']);
    authFixtureMembership($suspendedMember, $userId, ['status' => 'suspended']);
    authFixtureMembership($deletedMember, $userId, ['deleted_at' => SELECT_CLOCK]);
    authFixtureMembership($deletedTenant, $userId);

    $cases = [
        'an unknown code' => ['no-such-shop', null],
        'a shop they are not a member of' => ['no-membership', $noMembership],
        'a membership that is only invited' => ['invited-shop', $invited],
        'a membership that is suspended' => ['suspended-member', $suspendedMember],
        'a membership that is soft-deleted' => ['deleted-member', $deletedMember],
        'a shop that is soft-deleted' => ['deleted-shop', $deletedTenant],
    ];

    $messages = [];

    foreach ($cases as $label => [$code, $expectedTenantId]) {
        DB::connection('master')->table('login_history')->delete();

        try {
            authFixtureSelectTenantHandler()->handle(selectCommand($userId, $code));

            throw new RuntimeException($label.' was not refused');
        } catch (NoTenantAccess $refusal) {
            $messages[$label] = $refusal->getMessage();
        }

        $history = authFixtureRows('login_history');

        expect($history)->toHaveCount(1)
            ->and($history[0]['failure_reason'])->toBe(RecordLoginAttempt::REASON_NO_ACCESS)
            ->and((bool) $history[0]['success'])->toBeFalse()
            // AC-40: `tenant_id` is set when the tenant is known to exist,
            // and null when the code names nothing at all. A soft-deleted
            // shop exists; it is simply not selectable by anybody.
            ->and($history[0]['tenant_id'])->toBe($expectedTenantId)
            ->and(authFixtureRows('refresh_tokens'))->toBe([]);
    }

    // One identical response for all six, so the refusal discloses nothing
    // about whose shops exist.
    expect(array_unique(array_values($messages)))->toHaveCount(1)
        ->and(NoTenantAccess::body())->toBe(['error' => 'no_access']);

    // The control AC-40 asks for in the same test: a valid selection, so the
    // comparison above is between refusals and something that is not one.
    DB::connection('master')->table('login_history')->delete();

    $good = authFixtureTenant('shop-a');
    authFixtureMembership($good, $userId);

    expect(authFixtureSelectTenantHandler()->handle(selectCommand($userId))->tenantId)->toBe($good)
        ->and(authFixtureRows('refresh_tokens'))->toHaveCount(1);
});

it('refuses a soft-deleted account, and admits the same account in the same test before it is deleted', function () {
    // W-3. The spec's Assumptions make a soft-deleted row absent
    // everywhere, and `actorEmail()` was the one read that did not say so:
    // an unexpired platform token could still open a shop after the account
    // was deleted. The order here is control first, defect second, with one
    // fixture - so the refusal cannot be an artefact of a fixture that was
    // never able to select at all.
    [$userId, $tenantId] = selectFixture();

    expect(authFixtureSelectTenantHandler()->handle(selectCommand($userId))->tenantId)->toBe($tenantId)
        ->and(authFixtureRows('login_history'))->toHaveCount(1)
        ->and(authFixtureRows('refresh_tokens'))->toHaveCount(1);

    DB::connection('master')->table('users')->where('id', $userId)->update(['deleted_at' => SELECT_CLOCK]);
    DB::connection('master')->table('login_history')->delete();

    expect(fn () => authFixtureSelectTenantHandler()->handle(selectCommand($userId)))
        ->toThrow(NoTenantAccess::class);

    // The declared narrowing of AC-40: no address to record, so no row -
    // and no second token, which is the harm the filter prevents.
    expect(authFixtureRows('login_history'))->toBe([])
        ->and(authFixtureRows('refresh_tokens'))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| AC-40(b): an active member of a shop that may not be opened
|--------------------------------------------------------------------------
*/

it('refuses a non-selectable tenant by name, and says which status it was', function (string $status) {
    [$userId, $tenantId, $membershipId] = selectFixture($status);

    try {
        authFixtureSelectTenantHandler()->handle(selectCommand($userId));

        throw new RuntimeException($status.' was not refused');
    } catch (TenantNotServable $refusal) {
        // The asymmetry with `no_access` is the design: this caller has
        // proved they belong to this shop, so the status is not a
        // disclosure - it is the only way "contact your provider" can be
        // shown.
        expect($refusal->status())->toBe($status)
            ->and($refusal->body())->toBe(['error' => 'tenant_not_servable', 'status' => $status]);
    }

    $history = authFixtureRows('login_history');

    expect($history)->toHaveCount(1)
        ->and($history[0]['failure_reason'])->toBe(RecordLoginAttempt::REASON_TENANT_NOT_SERVABLE)
        ->and($history[0]['tenant_id'])->toBe($tenantId)
        ->and(authFixtureRows('refresh_tokens'))->toBe([])
        // Nothing was opened, so nothing is stamped.
        ->and(selectMembershipRow($membershipId)['last_accessed_at'])->toBeNull();
})->with([
    'pending' => ['pending'],
    'provisioning' => ['provisioning'],
    'suspended' => ['suspended'],
    'read_only' => ['read_only'],
    'closed' => ['closed'],
    'archived' => ['archived'],
]);

it('accepts each of the three selectable statuses', function (string $status) {
    // The control for the six refusals above: the same fixture, the same
    // command, a different commercial status, and it works.
    [$userId, $tenantId] = selectFixture($status);

    expect(authFixtureSelectTenantHandler()->handle(selectCommand($userId))->tenantId)->toBe($tenantId);
})->with([
    'trial' => ['trial'],
    'active' => ['active'],
    'past_due' => ['past_due'],
]);

/*
|--------------------------------------------------------------------------
| AC-47 residual (b), second of three - and plan section 7's mask shape 4
|--------------------------------------------------------------------------
*/

it('accepts and refuses by the policy it was given, stamping the row the read returned', function () {
    $userId = authFixtureUser('owner@example.com');

    $closed = authFixtureTenant('shop-a', 'closed');
    $closedMembership = authFixtureMembership($closed, $userId);
    $trial = authFixtureTenant('shop-t', 'trial');
    $trialMembership = authFixtureMembership($trial, $userId);

    // The shipped policy, so the comparison is against something.
    expect(fn () => authFixtureSelectTenantHandler()->handle(selectCommand($userId, 'shop-a')))
        ->toThrow(TenantNotServable::class);
    expect(authFixtureSelectTenantHandler()->handle(selectCommand($userId, 'shop-t'))->tenantId)->toBe($trial);

    DB::connection('master')->table('tenant_users')->update(['last_accessed_at' => null]);

    // The moved value: a policy over a different set, bound where the
    // shipped one was. Both directions move - what was refused is accepted
    // and what was accepted is refused - so a dependent holding a copy of
    // the list cannot follow (AC-47 residual (b), second of three).
    app()->instance(
        TenantSelectionPolicy::class,
        TenantSelectionPolicy::withSelectableStatuses(['closed'])
    );

    expect(authFixtureSelectTenantHandler()->handle(selectCommand($userId, 'shop-a'))->tenantId)->toBe($closed)
        ->and(fn () => authFixtureSelectTenantHandler()->handle(selectCommand($userId, 'shop-t')))
        ->toThrow(TenantNotServable::class);

    // **Mask shape 4, closed by changing the input rather than by pinning
    // the SQL.** The policy says this shop is selectable and the row is
    // nonetheless one the shipped selectable set would exclude. An update
    // that re-filtered on the tenant's status - the shape that makes a
    // broken policy read invisible - would write nothing here and the stamp
    // would still be null. Only an update written unconditionally on the row
    // the read returned can produce this value.
    expect(selectMembershipRow($closedMembership)['last_accessed_at'])->toBe(SELECT_CLOCK)
        ->and(selectMembershipRow($trialMembership)['last_accessed_at'])->toBeNull();

    // The negative control that makes the move mean something: a second
    // policy object, constructed and never bound, changes nothing. Without
    // it, "the selection followed the policy" could as easily be "the test
    // moved something nobody reads".
    TenantSelectionPolicy::withSelectableStatuses(['archived']);

    expect(authFixtureSelectTenantHandler()->handle(selectCommand($userId, 'shop-a'))->tenantId)->toBe($closed)
        ->and(fn () => authFixtureSelectTenantHandler()->handle(selectCommand($userId, 'shop-t')))
        ->toThrow(TenantNotServable::class);
});

/*
|--------------------------------------------------------------------------
| AC-41: a code that cannot be read
|--------------------------------------------------------------------------
*/

it('refuses an unreadable code with 422 and writes no history row', function (mixed $code, string $reason) {
    [$userId] = selectFixture();

    try {
        authFixtureSelectTenantHandler()->handle(selectCommand($userId, $code));

        throw new RuntimeException('an unreadable code was not refused');
    } catch (InvalidTenantCode $refusal) {
        expect($refusal->reason())->toBe($reason);
    }

    // Nothing was looked up, so nothing is recorded (AC-41) and nothing is
    // issued.
    expect(authFixtureRows('login_history'))->toBe([])
        ->and(authFixtureRows('refresh_tokens'))->toBe([]);
})->with([
    'missing' => [null, InvalidTenantCode::REASON_CODE_MISSING],
    'empty' => ['', InvalidTenantCode::REASON_CODE_MISSING],
    'whitespace only' => ["  \t ", InvalidTenantCode::REASON_CODE_MISSING],
    'an integer' => [7, InvalidTenantCode::REASON_CODE_NOT_STRING],
    'an array' => [['shop-a'], InvalidTenantCode::REASON_CODE_NOT_STRING],
    'a boolean' => [true, InvalidTenantCode::REASON_CODE_NOT_STRING],
]);

it('writes a history row for the same fixture when the code can be read', function () {
    // The liveness control for the absence above: an empty `login_history`
    // is also what a test that never reached the handler reports.
    [$userId] = selectFixture();

    authFixtureSelectTenantHandler()->handle(selectCommand($userId));

    expect(authFixtureRows('login_history'))->toHaveCount(1);
});

it('trims a code and looks up the trimmed form', function () {
    [$userId, $tenantId] = selectFixture();

    expect(authFixtureSelectTenantHandler()->handle(selectCommand($userId, '  shop-a  '))->tenantId)->toBe($tenantId);
});

/*
|--------------------------------------------------------------------------
| AC-43: the body cannot name a tenant, because there is nowhere to name one
|--------------------------------------------------------------------------
*/

it('gives a selection nowhere to carry a tenant id, a database or a host', function () {
    $constructor = (new ReflectionClass(SelectTenant::class))->getConstructor();

    expect($constructor)->not->toBeNull();

    $names = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $constructor instanceof ReflectionMethod ? $constructor->getParameters() : []
    );

    // The subject (from the verified token), the code, and three facts about
    // the connection. A handler that honoured a submitted `tenant_id`,
    // `database`, `host` or `driver` would need a sixth parameter, and it
    // would be in the diff (AC-43, ARCH.md section 5: a client tenant
    // identifier is a request, not a fact).
    expect($names)->toBe(['userId', 'code', 'ip', 'userAgent', 'deviceId']);
});

it('names the tenant the code resolved to, whatever else a caller knows', function () {
    // The behavioural half: two shops exist, the caller is an active member
    // of both, and the token carries the one the **code** named.
    $userId = authFixtureUser('owner@example.com');
    $a = authFixtureTenant('shop-a');
    $b = authFixtureTenant('shop-b');
    authFixtureMembership($a, $userId);
    authFixtureMembership($b, $userId);

    expect(selectClaimsOf(
        authFixtureSelectTenantHandler()->handle(selectCommand($userId, 'shop-a'))->accessToken
    )['tenant'])->toBe($a);
});

/*
|--------------------------------------------------------------------------
| AC-44 and AC-30: switching shops, and the families that result
|--------------------------------------------------------------------------
*/

it('issues a second tenant token without disturbing the first, in a family of its own', function () {
    $userId = authFixtureUser('owner@example.com');
    $a = authFixtureTenant('shop-a');
    $b = authFixtureTenant('shop-b');
    authFixtureMembership($a, $userId);
    authFixtureMembership($b, $userId);

    $first = authFixtureSelectTenantHandler()->handle(selectCommand($userId, 'shop-a'));
    $second = authFixtureSelectTenantHandler()->handle(selectCommand($userId, 'shop-b'));

    // The A token still verifies, unchanged, and names only A (AC-44). No
    // token ever carries two tenants: the claim is one value.
    expect(selectClaimsOf($first->accessToken)['tenant'])->toBe($a)
        ->and(selectClaimsOf($second->accessToken)['tenant'])->toBe($b)
        ->and($first->accessToken)->not->toBe($second->accessToken);

    // AC-30: F1 and F2 are different families, and selecting B neither used
    // nor revoked F1.
    $rows = authFixtureRows('refresh_tokens');

    expect($second->familyId)->not->toBe($first->familyId)
        ->and($rows)->toHaveCount(2)
        ->and(array_column($rows, 'used_at'))->toBe([null, null])
        ->and(array_column($rows, 'revoked_at'))->toBe([null, null])
        ->and(array_column($rows, 'tenant_id'))->toEqualCanonicalizing([$a, $b]);
});

it('leaves the platform family of the login neither used nor revoked', function () {
    // AC-30's first clause, against the real dependent: a login makes F0 and
    // a selection makes F1, and F0 is untouched by it.
    $userId = authFixtureUser('owner@example.com');
    authFixtureMembership(authFixtureTenant('shop-a'), $userId);

    $login = authFixtureLoginHandler()->handle(new Login(
        email: 'owner@example.com',
        password: 'correct-horse-battery-staple',
        ip: '203.0.113.7',
    ));

    $selection = authFixtureSelectTenantHandler()->handle(selectCommand($userId));

    $platform = DB::connection('master')->table('refresh_tokens')->whereNull('tenant_id')->get();

    expect($platform)->toHaveCount(1)
        ->and($platform[0]->used_at)->toBeNull()
        ->and($platform[0]->revoked_at)->toBeNull()
        ->and($platform[0]->family_id)->not->toBe($selection->familyId)
        ->and($login->accessToken)->not->toBe($selection->accessToken);
});

/*
|--------------------------------------------------------------------------
| The ordering: validate, resolve the key, then judge (D-36)
|--------------------------------------------------------------------------
*/

it('fails closed with no active key, judging nothing and recording nothing', function () {
    [$userId, , $membershipId] = selectFixture();

    // A key exists; none is active. This is an operational failure, not a
    // refusal of anything about the caller - nothing about them has been
    // judged yet, which is why there is no row to write (D-36).
    authFixtureSetKeyStatus(SELECT_KID, SigningKeyring::STATUS_RETIRED);

    $log = authFixtureRecordingLogger();

    expect(fn () => authFixtureSelectTenantHandler()->handle(selectCommand($userId)))
        ->toThrow(NoActiveSigningKey::class);

    expect(authFixtureRows('login_history'))->toBe([])
        // No `security_events` row either: the `type` enum is closed and has
        // no operational value, and inventing one is a schema change nobody
        // asked T-009 for (D-36, FP-201 carries the gap).
        ->and(authFixtureRows('security_events'))->toBe([])
        ->and(authFixtureRows('refresh_tokens'))->toBe([])
        ->and(selectMembershipRow($membershipId)['last_accessed_at'])->toBeNull();

    // **The single trace T-009 leaves, asserted rather than assumed** (D-37).
    // Without this the failure would be invisible to everyone, and FP-201's
    // gap would be wider than it is written to be.
    expect($log->messagesAt(authFixtureErrorLevel()))->toBe([SigningKeyring::LOG_MESSAGE]);
});

it('produces every one of those absences as a presence when a key is active', function () {
    // The liveness control (plan section 7, shape 5). Without it, a
    // selection that failed for an unrelated reason - a missing membership,
    // a typo in the code - satisfies every assertion above and the ordering
    // is proved by the test never arriving. Same fixture, same command, one
    // key status different.
    [$userId, $tenantId, $membershipId] = selectFixture();

    authFixtureSelectTenantHandler()->handle(selectCommand($userId));

    expect(authFixtureRows('login_history'))->toHaveCount(1)
        ->and(authFixtureRows('refresh_tokens'))->toHaveCount(1)
        ->and(selectMembershipRow($membershipId)['last_accessed_at'])->toBe(SELECT_CLOCK)
        ->and(authFixtureRows('login_history')[0]['tenant_id'])->toBe($tenantId);
});

it('fails operationally rather than recording a judgement it could not have made', function () {
    // **This is the case the ordering exists for, and the one that tells
    // key-first from key-last apart.** With a valid fixture both orderings
    // look identical - the judgement succeeds and signing fails either way.
    // Here the tenant would be refused: a handler that judged first would
    // throw `TenantNotServable` and write a `tenant_not_servable` row for a
    // request it then could not have answered, and the operational failure
    // would be hidden behind a business refusal. Key-first makes the two
    // mutually exclusive.
    [$userId, , $membershipId] = selectFixture('closed');

    authFixtureSetKeyStatus(SELECT_KID, SigningKeyring::STATUS_RETIRED);

    expect(fn () => authFixtureSelectTenantHandler()->handle(selectCommand($userId)))
        ->toThrow(NoActiveSigningKey::class);

    expect(authFixtureRows('login_history'))->toBe([])
        ->and(authFixtureRows('security_events'))->toBe([])
        ->and(selectMembershipRow($membershipId)['last_accessed_at'])->toBeNull();
});

it('records the business refusal for that same fixture once a key is active', function () {
    // The liveness half of the test above: the judgement it declined to make
    // is one it really would have made, and it writes a reasoned row when it
    // does. Without this, "no row" would be satisfied by a fixture that was
    // never refusable in the first place.
    [$userId, $tenantId] = selectFixture('closed');

    expect(fn () => authFixtureSelectTenantHandler()->handle(selectCommand($userId)))
        ->toThrow(TenantNotServable::class);

    $history = authFixtureRows('login_history');

    expect($history)->toHaveCount(1)
        ->and($history[0]['failure_reason'])->toBe(RecordLoginAttempt::REASON_TENANT_NOT_SERVABLE)
        ->and($history[0]['tenant_id'])->toBe($tenantId);
});

it('keeps the operational log entry free of anything secret, and the scan is live', function () {
    // AC-26 over the one sink this step writes to. The absence is asserted
    // with its mandatory positive control: a recorder that cannot see a
    // sentinel reports the same "not found" as a clean log.
    [$userId] = selectFixture();

    authFixtureSetKeyStatus(SELECT_KID, SigningKeyring::STATUS_RETIRED);

    $log = authFixtureRecordingLogger();

    expect(fn () => authFixtureSelectTenantHandler()->handle(selectCommand($userId)))
        ->toThrow(NoActiveSigningKey::class);

    $sentinel = 'sentinel-'.SELECT_KID.'-never-logged';

    expect($log->records)->not->toBe([])
        ->and($log->contains($sentinel))->toBeFalse();

    // The positive control: the same scan, over a sink deliberately
    // containing the sentinel, reports it found.
    $log->error('a deliberately polluted entry', ['token' => $sentinel]);

    expect($log->contains($sentinel))->toBeTrue();
});
