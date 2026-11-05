<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\ReissueActivationCode\ReissueActivationCode;
use Flexipos\Organization\Features\ReissueActivationCode\ReissueActivationCodeHandler;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\json;

/*
|--------------------------------------------------------------------------
| T-014 step 16 - the activation code is a credential (AC-27)
|--------------------------------------------------------------------------
|
| The code is shown once, in the response that creates or re-issues it, and
| lives nowhere else. This sweep issues two (one created, one re-issued),
| activates one (so one is consumed and one is still live), provokes the
| error paths, then looks for every form of both - the printed code, its eight
| secret characters and the two groups - in:
|
|   - every column of every table of the tenant database and of the master,
|     enumerated live and counted;
|   - the log file, at every level, written through the real file channel;
|   - the exception messages of refused operations;
|   - every later response, headers included;
|   - the claims of the tokens the activation minted.
|
| **Controls**: the creating and re-issuing responses DO hold the code (the one
| place), so the scan can see it; a code that was never issued is looked for in
| the same sinks and is found nowhere (the baseline-collision control); and a
| planted copy in each kind of sink is reported.
|
| The URL: a code in the query string, with no body, is the same uniform
| refusal as a wrong code, never consumes the live code (it still activates
| afterwards), and a code in the path is not a route at all.
*/

require_once __DIR__.'/../Support/organization-http-fixture.php';
require_once __DIR__.'/../Support/org-sink-harness.php';

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
 * The secrets of one printed code, by label.
 *
 * @return array<string, string>
 */
function actSecrecySecrets(string $label, string $code): array
{
    $groups = substr($code, -9);

    return [
        $label.' (full code)' => $code,
        $label.' (secret, 8 characters)' => str_replace('-', '', $groups),
        $label.' (two groups)' => $groups,
    ];
}

it('keeps every form of an issued activation code out of both databases, the log, exceptions, later responses and claims (AC-27)', function () {
    $log = orgSinkRedirectLog();
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($owner);

    // Two codes: one created, then activated (consumed); one created and re-issued (live).
    $first = orgHttpCall('POST', '/api/v1/organization/terminals', $owner, ['branch_id' => $at['branch'], 'location_id' => $at['store'], 'code' => 'a1', 'name' => 'Till A1']);
    $second = orgHttpCall('POST', '/api/v1/organization/terminals', $owner, ['branch_id' => $at['branch'], 'location_id' => $at['store'], 'code' => 'a2', 'name' => 'Till A2']);
    $reissued = orgHttpCall('POST', '/api/v1/organization/terminals/'.$second->json('terminal_id').'/activation-code', $owner);

    $consumed = (string) $first->json('activation_code');
    $replaced = (string) $second->json('activation_code');
    $live = (string) $reissued->json('activation_code');

    expect([$first->getStatusCode(), $second->getStatusCode(), $reissued->getStatusCode()])->toBe([201, 201, 200]);
    expect($live)->not->toBe($replaced);

    $secrets = actSecrecySecrets('consumed', $consumed) + actSecrecySecrets('replaced', $replaced) + actSecrecySecrets('live', $live);

    // The one place: the three issuing responses hold their own code.
    $issuing = ['create 1' => (string) $first->getContent(), 'create 2' => (string) $second->getContent(), 'reissue' => (string) $reissued->getContent()];

    expect(orgSinkFind($issuing, $secrets))->toContain('create 1<-consumed (full code)', 'create 2<-replaced (full code)', 'reissue<-live (full code)');

    // The URL: a code in the query string with no body is the uniform refusal, and a code in the path is no route.
    $fingerprint = orgHttpFingerprint('act-secrecy');
    $viaQuery = json('POST', '/api/v1/terminal/activate?code='.urlencode($live).'&fingerprint='.$fingerprint, []);
    $viaWrong = orgHttpActivate(['code' => 'SHOP-A-ZZZZ-ZZZZ', 'fingerprint' => $fingerprint], '203.0.113.9');
    // A code in the path is not a route (404). **T-021 AC-6/AC-51, D-19:** the
    // envelope renderer now answers every api/* 404, and it never echoes the
    // path - so, unlike the bare framework shape this test once pinned, the
    // body carries no copy of the code at all.
    config()->set('app.debug', false);
    $viaPath = json('POST', '/api/v1/terminal/activate/'.urlencode($live), ['fingerprint' => $fingerprint]);

    fwrite(STDOUT, PHP_EOL.'AC-27 finding: the 404 for a code in the path echoes the path to its sender: '.(str_contains((string) $viaPath->getContent(), $live) ? 'yes' : 'no').PHP_EOL);
    expect($viaPath->json('errors.0.code'))->toBe('kernel.not_found');
    expect((string) $viaPath->getContent())->not->toContain($live);

    expect($viaQuery->getStatusCode())->toBe($viaWrong->getStatusCode());
    expect((string) $viaQuery->getContent())->toBe((string) $viaWrong->getContent());
    expect($viaPath->getStatusCode())->toBe(404);

    // Provoke an error-level log line that exists for its own reasons (a non-conforming tenant code).
    DB::connection('master')->table('tenants')->where('id', $world['tenant'])->update(['code' => 'bad code']);
    $broken = orgHttpCall('POST', '/api/v1/organization/terminals', $owner, ['branch_id' => $at['branch'], 'location_id' => $at['store'], 'code' => 'a3', 'name' => 'Till A3']);
    DB::connection('master')->table('tenants')->where('id', $world['tenant'])->update(['code' => 'SHOP-A']);

    expect($broken->getStatusCode())->toBe(500);

    // Activate both live codes' terminals the way a till does: the consumed one, and the live one after the query attempt.
    $activatedFirst = orgHttpActivate(['code' => $consumed, 'fingerprint' => orgHttpFingerprint('first'), 'platform' => 'Windows']);
    $activatedLive = orgHttpActivate(['code' => $live, 'fingerprint' => orgHttpFingerprint('live'), 'platform' => 'Windows'], '203.0.113.20');

    // The live code was not consumed by the query attempt: it activates now.
    expect($activatedFirst->getStatusCode())->toBe(200);
    expect($activatedLive->getStatusCode())->toBe(200);

    // The code that was replaced no longer works either, and is refused uniformly.
    $stale = orgHttpActivate(['code' => $replaced, 'fingerprint' => orgHttpFingerprint('stale')], '203.0.113.30');

    expect($stale->getStatusCode())->toBe($viaWrong->getStatusCode());

    // Later responses: every read after issuing, bodies and headers.
    $responses = [];
    $terminals = [(string) $first->json('terminal_id'), (string) $second->json('terminal_id')];

    foreach ([
        'list terminals' => orgHttpCall('GET', '/api/v1/organization/terminals', $owner),
        'get 1' => orgHttpCall('GET', '/api/v1/organization/terminals/'.$terminals[0], $owner),
        'get 2' => orgHttpCall('GET', '/api/v1/organization/terminals/'.$terminals[1], $owner),
        'devices 1' => orgHttpCall('GET', '/api/v1/organization/terminals/'.$terminals[0].'/devices', $owner),
        'rename 2' => orgHttpCall('PATCH', '/api/v1/organization/terminals/'.$terminals[1], $owner, ['name' => 'Renamed']),
        'pause 2' => orgHttpCall('PUT', '/api/v1/organization/terminals/'.$terminals[1].'/status', $owner, ['status' => 'inactive']),
        'refused reissue (active)' => orgHttpCall('POST', '/api/v1/organization/terminals/'.$terminals[0].'/activation-code', $owner),
        'uniform refusal' => $viaWrong,
        'stale code' => $stale,
        'via query' => $viaQuery,
        'activation 1' => $activatedFirst,
        'activation 2' => $activatedLive,
    ] as $label => $response) {
        $responses['response: '.$label] = $response->getContent().json_encode($response->headers->all());
    }

    // Exceptions: the message chain of a refused operation that names a terminal.
    $exceptions = '';

    try {
        orgHttpRead(static function () use ($terminals): void {
            app(ReissueActivationCodeHandler::class)
                ->handle(new ReissueActivationCode($terminals[0]));
        });
    } catch (Throwable $thrown) {
        for ($c = $thrown; $c !== null; $c = $c->getPrevious()) {
            $exceptions .= $c::class.': '.$c->getMessage()."\n";
        }
    }

    // Claims: both activated terminals' tokens, decoded.
    $claims = json_encode([orgHttpClaims((string) $activatedFirst->json('access_token')), orgHttpClaims((string) $activatedLive->json('access_token'))]);

    $tenantDump = orgHttpRead(static fn (): array => orgSinkDump(AccessSeed::connection(), 'tenant'));
    $masterDump = orgSinkDump(DB::connection('master'), 'master');

    $sinks = $tenantDump['sinks'] + $masterDump['sinks'] + $responses + [
        'log file' => orgSinkReadLog($log),
        'exceptions' => $exceptions,
        'claims' => (string) $claims,
    ];

    fwrite(STDOUT, PHP_EOL.sprintf(
        'AC-27 scanned %d tenant tables / %d columns, %d master tables / %d columns, %d responses, the log (%d bytes), exceptions, claims; %d secret forms',
        $tenantDump['tables'], $tenantDump['columns'], $masterDump['tables'], $masterDump['columns'], count($responses), strlen(orgSinkReadLog($log)), count($secrets)
    ).PHP_EOL);

    // The scan is wide, and live: both databases were enumerated, the five new tables included.
    // Counted independently of the harness: one query that joins the table list to its columns.
    $liveCount = static fn (Connection $c): int => (int) $c->selectOne("select count(*) as n from sqlite_master m, pragma_table_info(m.name) p where m.type = 'table' and m.name not like 'sqlite_%'")->n;

    expect($tenantDump['columns'])->toBe(orgHttpRead(static fn (): int => $liveCount(AccessSeed::connection())));
    expect($masterDump['columns'])->toBe($liveCount(DB::connection('master')));
    expect($tenantDump['columns'])->toBeGreaterThan(100);
    expect(array_key_exists('tenant.terminals.activation_code_hash', $sinks))->toBeTrue();
    expect(array_key_exists('tenant.terminal_credentials.status', $sinks))->toBeTrue();
    expect(array_key_exists('tenant.devices.fingerprint', $sinks))->toBeTrue();

    // The log sink is alive: the error path above wrote to it.
    expect(orgSinkReadLog($log))->toContain('ERROR');

    // The measurement: nothing anywhere holds any form of any code.
    expect(orgSinkFind($sinks, $secrets))->toBe([]);

    // Baseline-collision control: a well-formed code that was never issued is found nowhere either.
    expect(orgSinkFind($sinks, actSecrecySecrets('never issued', 'SHOP-A-QWER-TYUP')))->toBe([]);

    // Consumed codes leave no hash behind: both activated terminals cleared theirs.
    $stored = orgHttpRead(static fn (): array => AccessSeed::connection()->table('terminals')->whereNotNull('activation_code_hash')->pluck('activation_code_hash')->all());

    expect($stored)->toBe([]);

    // Planted copies, one per kind of sink, are each reported.
    orgHttpRead(static function () use ($live): void {
        AccessSeed::connection()->table('terminals')->update(['name' => 'planted '.$live]);
    });
    DB::connection('master')->table('tenants')->where('id', $world['tenant'])->update(['name' => 'planted '.$live]);
    Log::error('planted', ['code' => $live]);

    $planted = orgHttpRead(static fn (): array => orgSinkDump(AccessSeed::connection(), 'tenant')['sinks']);
    $plantedMaster = orgSinkDump(DB::connection('master'), 'master')['sinks'];
    $plantedAll = $planted + $plantedMaster + [
        'log file' => orgSinkReadLog($log),
        'response: planted' => '{"leak":"'.$live.'"}',
        'exceptions' => 'RuntimeException: '.$live,
        'claims' => (string) json_encode(['code' => $live]),
    ];

    $hits = orgSinkFind($plantedAll, ['live' => $live]);

    foreach (['tenant.terminals.name<-live', 'master.tenants.name<-live', 'log file<-live', 'response: planted<-live', 'exceptions<-live', 'claims<-live'] as $expected) {
        expect(in_array($expected, $hits, true))->toBeTrue("planted sink not reported: {$expected}");
    }
});

it('reads the code from the body only: a query-string code with no body is refused and leaves the live code usable (AC-27)', function () {
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($owner);
    $terminal = orgHttpTerminalAt($owner, $at, 'q1');

    $fingerprint = orgHttpFingerprint('q1');
    $viaQuery = json('POST', '/api/v1/terminal/activate?code='.urlencode($terminal['code']).'&fingerprint='.$fingerprint, []);

    expect($viaQuery->getStatusCode())->toBeGreaterThanOrEqual(400);
    expect(orgHttpRead(static fn (): string => (string) AccessSeed::connection()->table('terminals')->where('id', $terminal['terminal'])->value('status')))->toBe('pending');

    // Control: the same code in the body activates.
    expect(orgHttpActivate(['code' => $terminal['code'], 'fingerprint' => $fingerprint])->getStatusCode())->toBe(200);
});
