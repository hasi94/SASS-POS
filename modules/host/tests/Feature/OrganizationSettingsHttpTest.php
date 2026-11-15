<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Support\GenericRefusal;
use Flexipos\Organization\Tests\Support\SettingsSeed;
use Illuminate\Support\Str;

use function Pest\Laravel\json;

/*
|--------------------------------------------------------------------------
| T-014 step 14 - the settings routes over HTTP (AC-45 to AC-48, AC-50)
|--------------------------------------------------------------------------
|
| The slices are proved in the package. What this file adds is the wire: the
| permission on each route and its control, the refusal bodies of Ruling 8,
| the query-string refusal of AC-47, a secret that never comes back, and the
| terminal route behind `RequireTerminalToken`, with the states that must
| refuse it. One world per case, built once.
|
| The fixture registry keys are inserted into the fixture tenant directly:
| T-014 ships no setting keys (Ruling 9).
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
 * @return array<string, mixed>
 */
function settingsHttpWorld(): array
{
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($owner);

    orgHttpRead(static function (): void {
        SettingsSeed::register('pos.rounding', 'string', 'D', ['max_length' => 10], sync: true);
        SettingsSeed::register('pos.copies', 'integer', 1, ['min' => 1, 'max' => 10], sync: false);
        SettingsSeed::register('gateway.token', 'string', null, secret: true, sync: true);
    });

    return ['world' => $world, 'owner' => $owner, 'at' => $at];
}

/**
 * @return list<array<string, mixed>>
 */
function settingsHttpSettings(string $token, string $query): array
{
    $response = orgHttpCall('GET', '/api/v1/organization/settings?'.$query, $token);

    expect($response->getStatusCode())->toBe(200);

    /** @var list<array<string, mixed>> $settings */
    $settings = (array) $response->json('settings');

    return $settings;
}

it('writes, lists and clears over HTTP, with the refusal bodies of Ruling 8 and the query-string refusal (AC-45, AC-46, AC-47, AC-48)', function () {
    $s = settingsHttpWorld();
    $owner = $s['owner'];
    $branch = $s['at']['branch'];

    $set = orgHttpCall('PUT', '/api/v1/organization/settings', $owner, [
        'key' => 'pos.rounding', 'scope_type' => 'branch', 'scope_id' => strtolower($branch), 'value' => 'B',
    ]);

    expect($set->getStatusCode())->toBe(200)
        ->and(array_keys($set->json()))->toBe(['setting_id', 'key', 'scope_type', 'scope_id'])
        ->and($set->json('scope_id'))->toBe($branch);

    $company = settingsHttpSettings($owner, 'scope_type=company');
    $atBranch = settingsHttpSettings($owner, 'scope_type=branch&scope_id='.$branch);

    expect(array_column($atBranch, 'key'))->toBe(['gateway.token', 'pos.copies', 'pos.rounding'])
        ->and($atBranch[2])->toBe(['key' => 'pos.rounding', 'value' => 'B', 'source' => 'branch'])
        ->and($company[2])->toBe(['key' => 'pos.rounding', 'value' => 'D', 'source' => 'default']);

    $refusals = [
        'unknown branch' => [orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'pos.rounding', 'scope_type' => 'branch', 'scope_id' => (string) Str::ulid(), 'value' => 'x']), 422, ['error' => 'validation', 'reason' => 'unknown_branch']],
        'unknown terminal' => [orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'pos.rounding', 'scope_type' => 'terminal', 'scope_id' => (string) Str::ulid(), 'value' => 'x']), 422, ['error' => 'validation', 'reason' => 'unknown_terminal']],
        'employee scope' => [orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'pos.rounding', 'scope_type' => 'employee', 'scope_id' => $branch, 'value' => 'x']), 403, ['error' => 'scope_not_supported']],
        'bad value' => [orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'pos.copies', 'scope_type' => 'company', 'value' => 99]), 422, ['error' => 'validation']],
        'json null' => [orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'pos.copies', 'scope_type' => 'company', 'value' => null]), 422, ['error' => 'validation']],
        'unknown scope on read' => [orgHttpCall('GET', '/api/v1/organization/settings?scope_type=branch&scope_id='.Str::ulid(), $owner), 404, ['error' => 'not_found']],
        'employee scope on read' => [orgHttpCall('GET', '/api/v1/organization/settings?scope_type=employee&scope_id='.$branch, $owner), 403, ['error' => 'scope_not_supported']],
        'nothing to clear' => [orgHttpCall('POST', '/api/v1/organization/settings/clear', $owner, ['key' => 'pos.copies', 'scope_type' => 'company']), 422, ['error' => 'validation']],
    ];

    foreach ($refusals as $label => [$response, $status, $body]) {
        expect($response->getStatusCode())->toBe($status, $label)->and($response->json())->toBe($body, $label);
    }

    // The value in the query string is refused outright, with a valid body, and nothing is written.
    $rows = orgHttpRead(static fn (): int => AccessSeed::connection()->table('settings')->count());
    $viaUrl = orgHttpCall('PUT', '/api/v1/organization/settings?value=LEAKED', $owner, [
        'key' => 'pos.copies', 'scope_type' => 'company', 'value' => 3,
    ]);

    expect($viaUrl->getStatusCode())->toBe(422)
        ->and($viaUrl->json())->toBe(['error' => 'validation'])
        ->and(orgHttpRead(static fn (): int => AccessSeed::connection()->table('settings')->count()))->toBe($rows);

    // Control: the same body without the query string is accepted.
    expect(orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'pos.copies', 'scope_type' => 'company', 'value' => 3])->getStatusCode())->toBe(200);

    $clear = orgHttpCall('POST', '/api/v1/organization/settings/clear', $owner, ['key' => 'pos.rounding', 'scope_type' => 'branch', 'scope_id' => $branch]);

    expect($clear->getStatusCode())->toBe(200)
        ->and($clear->json())->toBe(['key' => 'pos.rounding', 'scope_type' => 'branch', 'scope_id' => $branch])
        ->and(settingsHttpSettings($owner, 'scope_type=branch&scope_id='.$branch)[2]['source'])->toBe('default');
});

it('holds each route to its own key and refuses a caller with the other one (AC-45, AC-48)', function () {
    $s = settingsHttpWorld();
    $viewer = orgHttpToken($s['world'], orgHttpEmployeeHolding('viewer', ['settings.view']));
    $manager = orgHttpToken($s['world'], orgHttpEmployeeHolding('manager', ['settings.manage']));
    $body = ['key' => 'pos.rounding', 'scope_type' => 'company', 'value' => 'M'];

    $results = [
        'viewer reads' => orgHttpCall('GET', '/api/v1/organization/settings?scope_type=company', $viewer)->getStatusCode(),
        'viewer writes' => orgHttpCall('PUT', '/api/v1/organization/settings', $viewer, $body)->getStatusCode(),
        'viewer clears' => orgHttpCall('POST', '/api/v1/organization/settings/clear', $viewer, $body)->getStatusCode(),
        'manager reads' => orgHttpCall('GET', '/api/v1/organization/settings?scope_type=company', $manager)->getStatusCode(),
        'manager writes' => orgHttpCall('PUT', '/api/v1/organization/settings', $manager, $body)->getStatusCode(),
        'manager clears' => orgHttpCall('POST', '/api/v1/organization/settings/clear', $manager, ['key' => 'pos.rounding', 'scope_type' => 'company'])->getStatusCode(),
    ];

    expect($results)->toBe([
        'viewer reads' => 200,
        'viewer writes' => 403,
        'viewer clears' => 403,
        'manager reads' => 403,
        'manager writes' => 200,
        'manager clears' => 200,
    ]);
});

it('never returns a secret value, over the write, the read or the list (AC-47)', function () {
    $s = settingsHttpWorld();
    $owner = $s['owner'];
    $sentinel = 'SENTINEL-WIRE-55291';

    $set = orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'gateway.token', 'scope_type' => 'company', 'value' => $sentinel]);
    $list = orgHttpCall('GET', '/api/v1/organization/settings?scope_type=company', $owner);

    $wire = [(string) $set->getContent(), (string) $list->getContent(), json_encode($set->headers->all()), json_encode($list->headers->all())];

    foreach ($wire as $sink) {
        expect($sink)->not->toContain($sentinel);
        expect($sink)->not->toContain(urlencode($sentinel));
        expect($sink)->not->toContain(strrev($sentinel));
    }

    expect($list->json('settings.0'))->toBe(['key' => 'gateway.token', 'is_set' => true, 'source' => 'company'])
        // The one place the value exists.
        ->and(orgHttpRead(static fn (): string => (string) AccessSeed::connection()->table('settings')->where('key', 'gateway.token')->value('value')))->toBe(json_encode($sentinel));

    // Control: the same scan does find a value that is not secret, in the list.
    orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'pos.rounding', 'scope_type' => 'company', 'value' => 'PLAINVAL']);

    expect((string) orgHttpCall('GET', '/api/v1/organization/settings?scope_type=company', $owner)->getContent())->toContain('PLAINVAL');
});

it('gives a terminal its own sync_to_pos non-secret settings, and refuses it in each state that must refuse (AC-50)', function () {
    $s = settingsHttpWorld();
    $owner = $s['owner'];
    $till = orgHttpActivated($owner, $s['at'], 't1');
    $terminal = $till['terminal'];

    orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'pos.rounding', 'scope_type' => 'branch', 'scope_id' => $s['at']['branch'], 'value' => 'BR']);
    orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'gateway.token', 'scope_type' => 'terminal', 'scope_id' => $terminal, 'value' => 'SECRET-SYNC']);
    orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'pos.copies', 'scope_type' => 'terminal', 'scope_id' => $terminal, 'value' => 4]);

    $get = static fn (string $token) => json('GET', '/api/v1/terminal/settings', [], ['Authorization' => 'Bearer '.$token]);

    $response = $get($till['access']);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json())->toBe(['settings' => [['key' => 'pos.rounding', 'value' => 'BR', 'source' => 'branch']]]);

    // A secret that is synced, and a key that is not synced, are both absent.
    $content = (string) $response->getContent();

    expect($content)->not->toContain('SECRET-SYNC');
    expect($content)->not->toContain('gateway.token');
    expect($content)->not->toContain('pos.copies');

    // Control: a paused terminal is refused with the one body, and works again once resumed.
    orgHttpCall('PUT', '/api/v1/organization/terminals/'.$terminal.'/status', $owner, ['status' => 'inactive']);

    $paused = $get($till['access']);

    expect($paused->getStatusCode())->toBe(401)->and((string) $paused->getContent())->toBe(GenericRefusal::json());

    orgHttpCall('PUT', '/api/v1/organization/terminals/'.$terminal.'/status', $owner, ['status' => 'active']);

    expect($get($till['access'])->getStatusCode())->toBe(200);

    orgHttpCall('POST', '/api/v1/organization/terminals/'.$terminal.'/revoke', $owner);

    expect($get($till['access'])->getStatusCode())->toBe(401)
        // An employee token is not a terminal token, and a terminal token is not an employee's.
        ->and($get($owner)->getStatusCode())->toBe(403)
        ->and(orgHttpCall('GET', '/api/v1/organization/settings?scope_type=company', $till['access'])->getStatusCode())->toBe(403);
});
