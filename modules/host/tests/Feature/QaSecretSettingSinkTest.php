<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\SetSetting\SetSetting;
use Flexipos\Organization\Features\SetSetting\SetSettingHandler;
use Flexipos\Organization\Tests\Support\SettingsSeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\json;

/*
|--------------------------------------------------------------------------
| T-014 step 16 - a secret setting's value has one home (AC-47)
|--------------------------------------------------------------------------
|
| A secret value is written to `settings.value` and is read by nothing. This
| sweep sets one at company and at branch scope (the sentinel holds a slash and
| a double quote on purpose, so the JSON-escaped forms are different bytes from
| the raw one), then reads, lists, audits, refuses and clears, and looks for
| every form of it - raw, JSON-escaped both ways, URL-encoded, reversed - in:
|
|   - every column of every table of the tenant database and of the master,
|     enumerated live (so `audit_logs`, `access_log` and `outbox_events` are
|     in it);
|   - the log file at every level, written through the real file channel;
|   - the message chain of a refused write that was handed the value;
|   - every response and header the operations produced;
|   - the claims of a terminal token that reads its own settings.
|
| **Two stages.** While the override is set, exactly one column may hold it:
| `tenant.settings.value`, which is also the control that the scan can see it.
| After the clear, nothing anywhere holds it. A value in the URL is refused and
| ends up nowhere. A planted copy in each kind of sink is reported, and an
| unrelated sentinel of the same shape is found nowhere (baseline collision).
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
 * Every sink the sweep reads, rebuilt from the live state.
 *
 * @param  array<string, string>  $responses
 * @return array{sinks: array<string, string>, columns: int}
 */
function secretSinkCollect(array $responses, string $log, string $exceptions, string $claims): array
{
    $tenant = orgHttpRead(static fn (): array => orgSinkDump(AccessSeed::connection(), 'tenant'));
    $master = orgSinkDump(DB::connection('master'), 'master');

    return [
        'sinks' => $tenant['sinks'] + $master['sinks'] + $responses + ['log file' => orgSinkReadLog($log), 'exceptions' => $exceptions, 'claims' => $claims],
        'columns' => $tenant['columns'] + $master['columns'],
    ];
}

it('keeps a secret setting value in settings.value alone, and nowhere at all once cleared (AC-47)', function () {
    $log = orgSinkRedirectLog();
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($owner);
    $till = orgHttpActivated($owner, $at, 't1');

    orgHttpRead(static function (): void {
        SettingsSeed::register('gateway.token', 'string', null, secret: true, sync: true);
        SettingsSeed::register('gateway.name', 'string', 'plain');
        SettingsSeed::register('gateway.limit', 'integer', 1, ['min' => 1, 'max' => 10], secret: true);
        SettingsSeed::register('gateway.broken', 'color', null, secret: true);
    });

    $secret = 'SEC/"RET-4d9f-Zx81';
    $second = 'SEC/"RET-7b2c-Qp53';
    $secrets = ['secret' => $secret, 'second' => $second];

    /** @var array<string, string> $responses */
    $responses = [];
    $keep = static function (string $label, $response) use (&$responses): void {
        $responses['response: '.$label] = $response->getContent().json_encode($response->headers->all());
    };

    $put = static fn (array $body, string $query = '') => orgHttpCall('PUT', '/api/v1/organization/settings'.$query, $owner, $body);

    $keep('set company', $put(['key' => 'gateway.token', 'scope_type' => 'company', 'value' => $secret]));
    $keep('set branch', $put(['key' => 'gateway.token', 'scope_type' => 'branch', 'scope_id' => $at['branch'], 'value' => $second]));
    $keep('replace branch', $put(['key' => 'gateway.token', 'scope_type' => 'branch', 'scope_id' => $at['branch'], 'value' => $second]));

    // Refusals that were handed the value: a wrong type, an out-of-range value, a malformed registry row, and the value in the URL.
    $keep('wrong type', $put(['key' => 'gateway.limit', 'scope_type' => 'company', 'value' => $secret]));
    $keep('out of range', $put(['key' => 'gateway.limit', 'scope_type' => 'company', 'value' => 99]));
    $keep('malformed registry row', $put(['key' => 'gateway.broken', 'scope_type' => 'company', 'value' => $secret]));
    $viaUrl = $put(['key' => 'gateway.token', 'scope_type' => 'terminal', 'scope_id' => $till['terminal'], 'value' => 'x'], '?value='.urlencode($secret));
    $keep('value in the url', $viaUrl);

    expect($viaUrl->getStatusCode())->toBe(422);

    foreach (['scope_type=company', 'scope_type=branch&scope_id='.$at['branch'], 'scope_type=terminal&scope_id='.$till['terminal']] as $query) {
        $keep('list '.$query, orgHttpCall('GET', '/api/v1/organization/settings?'.$query, $owner));
    }

    $terminalRead = json('GET', '/api/v1/terminal/settings', [], ['Authorization' => 'Bearer '.$till['access']]);
    $keep('terminal settings', $terminalRead);

    expect($terminalRead->getStatusCode())->toBe(200);
    expect($terminalRead->json('settings'))->toBe([]);

    // The message chain of a refused write handed the value, straight from the slice.
    $exceptions = '';

    foreach ([['gateway.limit', $secret], ['gateway.broken', $secret], ['gateway.token', null]] as [$key, $value]) {
        try {
            orgHttpRead(static function () use ($key, $value): void {
                app(SetSettingHandler::class)->handle(new SetSetting([
                    'key' => $key, 'scope_type' => 'company', 'value' => $value,
                ]));
            });
        } catch (Throwable $thrown) {
            for ($c = $thrown; $c !== null; $c = $c->getPrevious()) {
                $exceptions .= $c::class.': '.$c->getMessage()."\n";
            }
        }
    }

    $claims = (string) json_encode(orgHttpClaims($till['access']));

    // Stage one: set. Exactly one column holds it.
    $stageOne = secretSinkCollect($responses, $log, $exceptions, $claims);
    $hits = orgSinkFind($stageOne['sinks'], $secrets);

    fwrite(STDOUT, PHP_EOL.sprintf('AC-47 stage one: %d columns of both databases, %d responses, log %d bytes; hits: %s', $stageOne['columns'], count($responses), strlen(orgSinkReadLog($log)), implode(', ', $hits)).PHP_EOL);

    expect($stageOne['columns'])->toBeGreaterThan(300);
    expect($hits)->toBe(['tenant.settings.value<-second', 'tenant.settings.value<-secret']);

    // The log sink is alive: the malformed registry row logged an error line (naming the key).
    expect(orgSinkReadLog($log))->toContain('gateway.broken');

    // Reads show the key as set, with no value.
    $list = orgHttpCall('GET', '/api/v1/organization/settings?scope_type=branch&scope_id='.$at['branch'], $owner);
    /** @var list<array<string, mixed>> $items */
    $items = (array) $list->json('settings');
    $row = array_values(array_filter($items, static fn (array $i): bool => $i['key'] === 'gateway.token'))[0] ?? null;

    expect($row)->toBe(['key' => 'gateway.token', 'is_set' => true, 'source' => 'branch']);

    // Stage two: cleared. Nothing anywhere holds either value.
    foreach ([['company', null], ['branch', $at['branch']]] as [$type, $id]) {
        $keep('clear '.$type, orgHttpCall('POST', '/api/v1/organization/settings/clear', $owner, ['key' => 'gateway.token', 'scope_type' => $type, 'scope_id' => $id]));
    }

    $stageTwo = secretSinkCollect($responses, $log, $exceptions, $claims);

    expect(orgSinkFind($stageTwo['sinks'], $secrets))->toBe([]);

    // Baseline-collision control: an unrelated sentinel of the same shape is found in neither stage.
    expect(orgSinkFind($stageOne['sinks'], ['unrelated' => 'SEC/"RET-0000-AAAA']))->toBe([]);

    // Planted copies, one per kind of sink, are each reported by the same scan.
    orgHttpRead(static function () use ($secret): void {
        AccessSeed::connection()->table('settings_registry')->where('key', 'gateway.name')->update(['plugin_name' => $secret]);
    });
    DB::connection('master')->table('tenants')->where('id', $world['tenant'])->update(['name' => 'planted '.$secret]);
    Log::error('planted', ['value' => $secret]);

    $planted = secretSinkCollect(
        $responses + ['response: planted' => '{"value":"'.addcslashes($secret, '"').'"}'],
        $log,
        $exceptions.'RuntimeException: '.$secret,
        (string) json_encode(['v' => $secret]),
    );
    $plantedHits = orgSinkFind($planted['sinks'], ['secret' => $secret]);

    foreach (['tenant.settings_registry.plugin_name<-secret', 'master.tenants.name<-secret', 'log file<-secret', 'response: planted<-secret', 'exceptions<-secret', 'claims<-secret'] as $expected) {
        expect(in_array($expected, $plantedHits, true))->toBeTrue("planted sink not reported: {$expected}");
    }
});
