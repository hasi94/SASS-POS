<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Support\GenericRefusal;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\json;
use function Pest\Laravel\withServerVariables;

/*
|--------------------------------------------------------------------------
| T-014 step 11 - activating a terminal over HTTP (AC-29 to AC-33, AC-43)
|--------------------------------------------------------------------------
|
| One world per case and, inside a case, as many terminals as the scenario
| needs: a terminal is a row and a code, and a fresh one per attempt costs far
| less than a fresh tenant.
|
| **AC-30 is a comparison of bytes.** Every refusal cause is driven through the
| real route and its status, body and headers (the clock-dependent `Date` and
| nothing else removed) are compared against each other, against the bytes
| `GenericRefusal` produces, and against the absence of a `Retry-After`. The
| positive control sits in the same test: the right code and a fresh
| fingerprint succeed, so what is compared is a refusal against a success and
| not against a route that refuses everything. After each refusal a content
| digest of every table in both databases is compared, which is the "nothing
| is written" half: the throttle lives in the cache, and nothing else moves.
| A digest, not a count, so an UPDATE that left every row count unchanged
| would still be caught (W-4).
|
| The limit's numbers are configuration, and the lockout cases move the clock,
| never sleep. Time is the injected `RunnerClock`; a limiter that read Carbon
| would not move.
*/

require_once __DIR__.'/../Support/organization-http-fixture.php';

beforeEach(function () {
    orgHttpBoot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * A digest of a table's rows that an UPDATE moves as surely as an INSERT or a
 * DELETE (W-4): a row count alone is unchanged by an UPDATE, so "nothing
 * written" is judged here by content, not by how many rows there are. The
 * rows are sorted by their own serialised form before hashing, so storage
 * order - which this check makes no claim about - cannot flip the digest.
 *
 * @param  iterable<int, object>  $rows
 */
function activationTableDigest(iterable $rows): string
{
    $serialized = array_map(
        static fn (object $row): string => serialize((array) $row),
        is_array($rows) ? $rows : iterator_to_array($rows)
    );

    sort($serialized);

    return md5(implode('|', $serialized));
}

/**
 * A content digest of every table of both databases, keyed by database and
 * table.
 *
 * @return array<string, string>
 */
function activationRowCounts(): array
{
    $digests = [];

    foreach (['master' => 'master'] as $label => $connection) {
        foreach (Schema::connection($connection)->getTables() as $table) {
            $name = (string) $table['name'];

            if (! str_starts_with($name, 'sqlite_')) {
                $digests[$label.'.'.$name] = activationTableDigest(DB::connection($connection)->table($name)->get());
            }
        }
    }

    foreach (orgHttpInTenant(static fn (): array => array_map(
        static fn (array $table): string => (string) $table['name'],
        Schema::connection(AccessTenantFixture::connectionName())->getTables()
    )) as $name) {
        if (! str_starts_with($name, 'sqlite_')) {
            $digests['tenant.'.$name] = orgHttpInTenant(static fn (): string => activationTableDigest(AccessSeed::connection()->table($name)->get()));
        }
    }

    ksort($digests);

    return $digests;
}

/**
 * What a response looks like on the wire, without the one header that is the
 * clock's.
 *
 * @param  TestResponse<Response>  $response
 * @return array{status: int, body: string, headers: array<string, mixed>}
 */
function activationWire($response): array
{
    $headers = $response->headers->all();

    unset($headers['date']);

    // T-017 AC-1: every response now carries its own request's X-Request-Id
    // (bootstrap/app.php's AssignRequestId, prepended), which varies the
    // same way `date` already does - per request, not per the uniform
    // refusal contract this test is about.
    unset($headers['x-request-id']);

    // T-021 D-16, AC-52: the limiter now applies to every existing api/*
    // route, including this one, and x-ratelimit-remaining varies per
    // request - the same reason x-request-id is unset above.
    // x-ratelimit-limit stays compared: it does not vary across these cases.
    unset($headers['x-ratelimit-remaining']);

    ksort($headers);

    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent(), 'headers' => $headers];
}

it('activates: one tenant transaction of device, terminal, credential and log row, a new family, and a terminal token (AC-29, AC-32, AC-33)', function () {
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($owner);
    $made = orgHttpTerminalAt($owner, $at, 't1');
    $fingerprint = orgHttpFingerprint('till-one');

    $response = orgHttpActivate([
        'code' => strtolower($made['code']),
        'fingerprint' => $fingerprint,
        'platform' => 'Windows', 'browser' => 'Chrome 130', 'os' => 'Windows 11', 'screen' => '1920x1080',
    ], '203.0.113.9');

    expect($response->getStatusCode())->toBe(200)
        ->and(array_keys($response->json()))->toBe(['terminal_id', 'branch_id', 'access_token', 'refresh_token'])
        ->and($response->json('terminal_id'))->toBe($made['terminal'])
        ->and($response->json('branch_id'))->toBe($at['branch'])
        ->and((string) $response->getContent())->not->toContain($made['code']);

    $now = '2026-10-22 10:00:00.000';

    $rows = orgHttpRead(static function () use ($made): array {
        $c = AccessSeed::connection();

        return [
            'terminal' => (array) $c->table('terminals')->where('id', $made['terminal'])->first(),
            'devices' => $c->table('devices')->where('terminal_id', $made['terminal'])->get()->map(static fn ($r): array => (array) $r)->all(),
            'credential' => (array) $c->table('terminal_credentials')->where('terminal_id', $made['terminal'])->first(),
            'log' => $c->table('access_log')->where('type', 'terminal_login')->get()->map(static fn ($r): array => (array) $r)->all(),
            'audit' => $c->table('audit_logs')->where('action', 'terminal_created')->where('subject_id', $made['terminal'])->first(),
        ];
    });

    expect($rows['devices'])->toHaveCount(1);

    $device = $rows['devices'][0];

    // The terminal, whole: active, its device, the code consumed.
    expect($rows['terminal']['status'])->toBe('active')
        ->and($rows['terminal']['current_device_id'])->toBe($device['id'])
        ->and($rows['terminal']['activation_code_hash'])->toBeNull()
        ->and($rows['terminal']['activation_expires_at'])->toBeNull()
        ->and($rows['terminal']['last_seen_at'])->toBe($now);

    // One device with the fingerprint exactly and every descriptive field.
    expect(array_diff_key($device, ['id' => 1]))->toBe([
        'terminal_id' => $made['terminal'],
        'fingerprint' => $fingerprint,
        'platform' => 'Windows', 'browser' => 'Chrome 130', 'os' => 'Windows 11', 'screen' => '1920x1080',
        'registered_at' => $now, 'last_seen_at' => null, 'revoked_at' => null, 'revoked_by' => null,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // One credential, whole: activated by the employee who issued the code.
    expect($rows['credential'])->toBe([
        'terminal_id' => $made['terminal'],
        'device_fingerprint' => $fingerprint,
        'status' => 'active',
        'activated_at' => $now,
        'activated_by' => $world['owner'],
        'revoked_at' => null,
        'revoked_by' => null,
    ]);

    // One access-log row of type terminal_login, with the terminal, the
    // branch and the caller's address, and no employee.
    expect($rows['log'])->toHaveCount(1)
        ->and(array_diff_key($rows['log'][0], ['id' => 1]))->toBe([
            'employee_id' => null,
            'terminal_id' => $made['terminal'],
            'type' => 'terminal_login',
            'permission_key' => null,
            'branch_id' => $at['branch'],
            'detail' => null,
            'ip' => inet_pton('203.0.113.9'),
            'created_at' => $now,
        ]);

    // The master: one terminal refresh row in a NEW family.
    $refresh = DB::connection('master')->table('refresh_tokens')->where('principal_type', 'terminal')->get();

    expect($refresh)->toHaveCount(1)
        ->and($refresh[0]->principal_id)->toBe($made['terminal'])
        ->and($refresh[0]->tenant_id)->toBe($world['tenant'])
        ->and($refresh[0]->used_at)->toBeNull()
        ->and($refresh[0]->revoked_at)->toBeNull()
        ->and($refresh[0]->expires_at)->toBe('2026-11-05 10:00:00.000')
        ->and($refresh[0]->token_hash)->not->toBe((string) $response->json('refresh_token'));

    // AC-33: the claims, exactly, and none of the employee ones.
    $claims = orgHttpClaims((string) $response->json('access_token'));
    $names = array_keys($claims);
    sort($names);

    expect($names)->toBe(['branch_ids', 'exp', 'iat', 'jti', 'sub', 'tenant', 'terminal_id', 'token_type'])
        ->and($claims['token_type'])->toBe('terminal')
        ->and($claims['tenant'])->toBe($world['tenant'])
        ->and($claims['terminal_id'])->toBe($made['terminal'])
        ->and($claims['sub'])->toBe($made['terminal'])
        ->and($claims['branch_ids'])->toBe([$at['branch']])
        ->and($claims['exp'] - $claims['iat'])->toBe(15 * 60);

    foreach (['employee', 'all_branches', 'perm_version', 'perm_hash'] as $absent) {
        expect(array_key_exists($absent, $claims))->toBeFalse("[{$absent}] must not be in a terminal token");
    }

    // The control: a tenant token for an employee still carries its five.
    $employeeClaims = orgHttpClaims($owner);

    foreach (['employee', 'branch_ids', 'all_branches', 'perm_version', 'perm_hash'] as $present) {
        expect(array_key_exists($present, $employeeClaims))->toBeTrue("[{$present}] must still be in an employee token");
    }

    expect(TokenClaims::isExactly($claims, TokenClaims::TYPE_TERMINAL))->toBeTrue()
        ->and($employeeClaims['token_type'])->toBe('tenant');

    // AC-43, the stand-in half: the code is consumed, so the same request is refused.
    $again = orgHttpActivate(['code' => $made['code'], 'fingerprint' => orgHttpFingerprint('another')]);

    expect($again->getStatusCode())->toBe(401);
});

it('answers every refusal with the same bytes, writes nothing, and succeeds for the right code (AC-30)', function () {
    config()->set('authentication.terminal_activation.max_failures', 1000);

    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($owner);

    $fresh = static fn (string $code): array => orgHttpTerminalAt($owner, $at, $code);
    $good = static fn (array $terminal, array $extra = []): array => array_merge(
        ['code' => $terminal['code'], 'fingerprint' => orgHttpFingerprint($terminal['code'])],
        $extra
    );
    $tenantPart = 'SHOP-A';

    // One terminal per cause, each prepared to fail for that cause alone.
    $causes = [];

    $causes['a malformed code'] = ['code' => 'nonsense', 'fingerprint' => orgHttpFingerprint('x')];
    $causes['a non-string code'] = ['code' => ['x'], 'fingerprint' => orgHttpFingerprint('x')];
    $causes['an unknown tenant part'] = ['code' => 'NOSUCH-ABCD-EFGH', 'fingerprint' => orgHttpFingerprint('x')];
    $causes['an unknown code'] = ['code' => $tenantPart.'-ABCD-EFGH', 'fingerprint' => orgHttpFingerprint('x')];

    $expired = $fresh('ex');
    $causes['an expired code'] = $good($expired);

    $used = $fresh('us');
    $usedBody = $good($used);
    $causes['an already-used code'] = $usedBody;

    $revoked = $fresh('rv');
    $causes['a revoked terminal'] = $good($revoked);

    $paused = $fresh('pa');
    $causes['a paused terminal'] = $good($paused);

    $suspended = $fresh('su');
    $causes['a tenant that is not active'] = $good($suspended);

    $noFingerprint = $fresh('nf');
    $causes['a missing fingerprint'] = ['code' => $noFingerprint['code']];

    $upper = $fresh('uc');
    $causes['an upper-case fingerprint'] = ['code' => $upper['code'], 'fingerprint' => strtoupper(orgHttpFingerprint('uc'))];

    $mixed = $fresh('mc');
    $mixedFingerprint = orgHttpFingerprint('mc');
    $causes['a mixed-case fingerprint'] = ['code' => $mixed['code'], 'fingerprint' => substr($mixedFingerprint, 0, 32).strtoupper(substr($mixedFingerprint, 32))];

    $long = $fresh('lg');
    $causes['an over-length platform'] = $good($long, ['platform' => str_repeat('p', 51)]);

    $busy = $fresh('bu');
    $other = $fresh('ot');
    $busyFingerprint = orgHttpFingerprint('shared-device');
    $causes['a fingerprint that is another terminals live device'] = ['code' => $busy['code'], 'fingerprint' => $busyFingerprint];

    // Prepare the causes that are about state.
    orgHttpRead(static function () use ($expired, $revoked, $paused): void {
        $c = AccessSeed::connection();
        $c->table('terminals')->where('id', $expired['terminal'])->update(['activation_expires_at' => '2026-10-22 09:59:59.999']);
        $c->table('terminals')->where('id', $revoked['terminal'])->update(['status' => 'revoked']);
        $c->table('terminals')->where('id', $paused['terminal'])->update(['status' => 'inactive']);
    });

    // The branch cause needs its own branch: a second branch with a terminal,
    // deactivated by hand (the API refuses to deactivate one with a pending terminal).
    $second = orgHttpBranch($owner, 'br2');
    $branchTerminal = orgHttpTerminalAt($owner, $second, 'ib2');
    $causes['a terminal whose branch is inactive'] = ['code' => $branchTerminal['code'], 'fingerprint' => orgHttpFingerprint('ib2')];

    orgHttpRead(static fn (): int => AccessSeed::connection()->table('branches')->where('id', $second['branch'])->update(['is_active' => false]));

    // The device that makes the last cause fail: another terminal's live one.
    $holder = orgHttpActivate(['code' => $other['code'], 'fingerprint' => $busyFingerprint]);

    expect($holder->getStatusCode())->toBe(200);

    // Use one code, so its second presentation is "already used".
    expect(orgHttpActivate($usedBody)->getStatusCode())->toBe(200);

    $uniform = GenericRefusal::json();
    $reference = null;
    $seen = [];

    foreach ($causes as $label => $body) {
        $before = activationRowCounts();

        if ($label === 'a tenant that is not active') {
            DB::connection('master')->table('tenants')->where('id', $world['tenant'])->update(['status' => 'suspended']);
        }

        $wire = activationWire(orgHttpActivate($body));

        if ($label === 'a tenant that is not active') {
            DB::connection('master')->table('tenants')->where('id', $world['tenant'])->update(['status' => 'active']);
        }

        $seen[$label] = $wire['status'];
        $reference ??= $wire;

        expect($wire)->toBe($reference, "[{$label}] differs from the first refusal")
            ->and($wire['body'])->toBe($uniform, "[{$label}] is not the uniform body")
            ->and($wire['status'])->toBe(401)
            ->and(array_key_exists('retry-after', $wire['headers']))->toBeFalse("[{$label}] carries Retry-After")
            ->and(activationRowCounts())->toBe($before, "[{$label}] wrote to a table");
    }

    // The domain, printed: how many causes were compared.
    fwrite(STDOUT, PHP_EOL.'AC-30 compared '.count($seen).' refusal causes byte for byte: '.implode('; ', array_keys($seen)).PHP_EOL);

    expect(count($seen))->toBeGreaterThanOrEqual(14);

    // The positive control, in the same test: the right code and a fresh
    // fingerprint on a terminal that is still pending succeed.
    $control = $fresh('ok');

    expect(orgHttpActivate($good($control))->getStatusCode())->toBe(200);
});

it('refuses a code in the URL without accepting it, and reads the body only (AC-27, AC-29)', function () {
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $made = orgHttpTerminalAt($owner, orgHttpBranch($owner), 't1');

    withServerVariables(['REMOTE_ADDR' => '203.0.113.5']);

    $fromUrl = json('POST', '/api/v1/terminal/activate?code='.$made['code'], ['fingerprint' => orgHttpFingerprint('u')]);
    $fromAlias = json('POST', '/api/v1/terminal/activate?activation_code='.$made['code'], ['fingerprint' => orgHttpFingerprint('u')]);

    expect($fromUrl->getStatusCode())->toBe(401)
        ->and((string) $fromUrl->getContent())->toBe(GenericRefusal::json())
        ->and((string) $fromAlias->getContent())->toBe(GenericRefusal::json())
        ->and((string) $fromUrl->getContent())->not->toContain($made['code']);

    // The code in the path of any other route is not a route at all.
    expect(json('POST', '/api/v1/terminal/activate/'.$made['code'])->getStatusCode())->toBe(404);

    // And the terminal is still pending: nothing consumed it.
    expect(orgHttpRead(static fn (): string => (string) AccessSeed::connection()->table('terminals')->where('id', $made['terminal'])->value('status')))->toBe('pending');
});

it('locks a tenant and an address after ten failures, on the injected clock, and not before (AC-31)', function () {
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($owner);

    $clock = app(RunnerClock::class);
    $wrong = static fn (string $ip): int => orgHttpActivate(['code' => 'SHOP-A-ABCD-EFGH', 'fingerprint' => orgHttpFingerprint('w')], $ip)->getStatusCode();

    // 9 failures then the correct code: succeeds (the control).
    $first = orgHttpTerminalAt($owner, $at, 't1');

    for ($i = 0; $i < 9; $i++) {
        expect($wrong('203.0.113.50'))->toBe(401);
    }

    expect(orgHttpActivate(['code' => $first['code'], 'fingerprint' => orgHttpFingerprint('t1')], '203.0.113.50')->getStatusCode())->toBe(200);

    // Success does not reset: the nine failures still stand, so one more
    // failure is the tenth and the address and tenant are locked.
    $second = orgHttpTerminalAt($owner, $at, 't2');

    expect($wrong('203.0.113.50'))->toBe(401);

    $lockedBody = orgHttpActivate(['code' => $second['code'], 'fingerprint' => orgHttpFingerprint('t2')], '203.0.113.50');

    expect($lockedBody->getStatusCode())->toBe(401)
        ->and((string) $lockedBody->getContent())->toBe(GenericRefusal::json())
        ->and(orgHttpRead(static fn (): string => (string) AccessSeed::connection()->table('terminals')->where('id', $second['terminal'])->value('status')))->toBe('pending');

    // The tenant is locked too: the correct code from ANOTHER address is refused.
    expect(orgHttpActivate(['code' => $second['code'], 'fingerprint' => orgHttpFingerprint('t2')], '198.51.100.7')->getStatusCode())->toBe(401);

    // A refusal while locked neither counts nor postpones the unlock: ask at
    // minute 14, then at minute 15 from the first failure the window is over.
    $clock->advanceMinutes(14);
    expect(orgHttpActivate(['code' => $second['code'], 'fingerprint' => orgHttpFingerprint('t2')], '198.51.100.7')->getStatusCode())->toBe(401);

    $clock->advanceMinutes(1);
    expect(orgHttpActivate(['code' => $second['code'], 'fingerprint' => orgHttpFingerprint('t2')], '198.51.100.7')->getStatusCode())->toBe(200);
});

it('counts an unknown tenant part against the address only, so another address and the real tenant are untouched (AC-31)', function () {
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $made = orgHttpTerminalAt($owner, orgHttpBranch($owner), 't1');

    for ($i = 0; $i < 10; $i++) {
        expect(orgHttpActivate(['code' => 'NOSUCH-ABCD-EFGH', 'fingerprint' => orgHttpFingerprint('p')], '203.0.113.77')->getStatusCode())->toBe(401);
    }

    // That address is locked: the correct code from it is refused...
    expect(orgHttpActivate(['code' => $made['code'], 'fingerprint' => orgHttpFingerprint('t1')], '203.0.113.77')->getStatusCode())->toBe(401);

    // ...but the real tenant was never counted, so the same code from another
    // address activates.
    expect(orgHttpActivate(['code' => $made['code'], 'fingerprint' => orgHttpFingerprint('t1')], '198.51.100.9')->getStatusCode())->toBe(200);
});

it('rolls the tenant side back and answers 503, uncounted, when the master cannot write the refresh row (AC-29, Ruling 12)', function () {
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $made = orgHttpTerminalAt($owner, orgHttpBranch($owner), 't1');

    $before = activationRowCounts();

    DB::connection('master')->statement('drop table refresh_tokens');

    $failed = orgHttpActivate(['code' => $made['code'], 'fingerprint' => orgHttpFingerprint('t1')]);

    expect($failed->getStatusCode())->toBe(503)
        ->and($failed->json())->toBe(['error' => 'activation_unavailable']);

    // Nothing is left on the tenant side: the terminal is still pending with
    // its code, and there is no device, credential or log row.
    $after = activationRowCounts();

    unset($before['master.refresh_tokens']);

    foreach ($before as $table => $count) {
        expect($after[$table] ?? null)->toBe($count, "[{$table}] changed");
    }

    expect(orgHttpRead(static function () use ($made): array {
        $row = (array) AccessSeed::connection()->table('terminals')->where('id', $made['terminal'])->first();

        return [$row['status'], $row['activation_code_hash'] !== null, $row['current_device_id']];
    }))->toBe(['pending', true, null]);
});

it('answers 503 and counts nothing when no signing key exists (Ruling 12)', function () {
    config()->set('authentication.terminal_activation.max_failures', 2);

    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $made = orgHttpTerminalAt($owner, orgHttpBranch($owner), 't1');

    DB::connection('master')->table('signing_keys')->update(['status' => 'retired']);

    // Three attempts with no key: more than the limit of two, and none counted.
    for ($i = 0; $i < 3; $i++) {
        expect(orgHttpActivate(['code' => $made['code'], 'fingerprint' => orgHttpFingerprint('t1')])->getStatusCode())->toBe(503);
    }

    DB::connection('master')->table('signing_keys')->update(['status' => 'active']);

    // With the key back, the correct code activates: the outage locked nobody.
    expect(orgHttpActivate(['code' => $made['code'], 'fingerprint' => orgHttpFingerprint('t1')])->getStatusCode())->toBe(200);
});
