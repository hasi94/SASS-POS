<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\json;

/*
|--------------------------------------------------------------------------
| T-014 step 10 - the terminal routes over HTTP (AC-26, AC-28, AC-37, AC-41, AC-42)
|--------------------------------------------------------------------------
|
| The slices are proved in the package with a double for auth's `TerminalSessions`.
| What this file adds is the real one: the tenant part of the printed code is
| the master's `tenants.code`, read through `MasterTerminalSessions`, so the
| shop's code appears in the response and a code the master could not print
| (a lower-case one) is a 500 with nothing written.
|
| **The plaintext is looked for where it must not be**: the response of every
| later read, and the stored row. The one place it exists is the response that
| created it.
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
 * A branch with a store through the API, and a terminal at it.
 *
 * @param  array{user: string, tenant: string, owner: string}  $world
 * @return array{branch: string, store: string, terminal: string, code: string}
 */
function orgHttpTerminal(array $world, string $token, string $code = 't1'): array
{
    $branch = orgHttpCall('POST', '/api/v1/organization/branches', $token, [
        'code' => 'br'.random_int(10, 99), 'name' => 'Main', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ]);

    $created = orgHttpCall('POST', '/api/v1/organization/terminals', $token, [
        'branch_id' => $branch->json('branch_id'), 'location_id' => $branch->json('location_id'), 'code' => $code, 'name' => 'Front till',
    ]);

    expect($created->getStatusCode())->toBe(201);

    return [
        'branch' => (string) $branch->json('branch_id'),
        'store' => (string) $branch->json('location_id'),
        'terminal' => (string) $created->json('terminal_id'),
        'code' => (string) $created->json('activation_code'),
    ];
}

it('prints the shops own code in front of the secret, once, and never again (AC-26, AC-27)', function () {
    $world = orgHttpWorld();
    $token = orgHttpToken($world, $world['owner']);

    $made = orgHttpTerminal($world, $token);

    expect(preg_match('/\ASHOP-A-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}\z/', $made['code']))->toBe(1);

    $secret = str_replace('-', '', substr($made['code'], -9));

    // Every later read of the terminal and its devices, and the stored row.
    $reads = [
        orgHttpCall('GET', '/api/v1/organization/terminals/'.$made['terminal'], $token),
        orgHttpCall('GET', '/api/v1/organization/terminals', $token),
        orgHttpCall('GET', '/api/v1/organization/terminals/'.$made['terminal'].'/devices', $token),
    ];

    foreach ($reads as $read) {
        expect($read->getStatusCode())->toBe(200)
            ->and((string) $read->getContent())->not->toContain($made['code'])
            ->and((string) $read->getContent())->not->toContain($secret)
            ->and((string) $read->getContent())->not->toContain('activation_code_hash');
    }

    $stored = orgHttpRead(static fn (): array => (array) AccessSeed::connection()->table('terminals')->where('id', $made['terminal'])->first());

    expect($reads[0]->json('has_pending_code'))->toBeTrue()
        ->and(json_encode($stored))->not->toContain($made['code'])
        ->and(json_encode($stored))->not->toContain($secret)
        ->and($stored['activation_code_hash'])->toBe(hash('sha256', $secret));
});

it('is a 500 with nothing written when the shops stored code cannot be printed (Ruling 7)', function () {
    $world = orgHttpWorld();
    $token = orgHttpToken($world, $world['owner']);

    $branch = orgHttpCall('POST', '/api/v1/organization/branches', $token, [
        'code' => 'br1', 'name' => 'Main', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ]);

    DB::connection('master')->table('tenants')->where('id', $world['tenant'])->update(['code' => 'shop-a']);

    $before = orgHttpRead(static fn (): int => AccessSeed::connection()->table('terminals')->count());

    $refused = orgHttpCall('POST', '/api/v1/organization/terminals', $token, [
        'branch_id' => $branch->json('branch_id'), 'location_id' => $branch->json('location_id'), 'code' => 't1', 'name' => 'Till',
    ]);

    expect($refused->getStatusCode())->toBe(500)
        ->and(orgHttpRead(static fn (): int => AccessSeed::connection()->table('terminals')->count()))->toBe($before);
});

it('re-issues, pauses, resumes and lists devices over HTTP, with the refusals as AC-63 states them (AC-28, AC-37, AC-41)', function () {
    $world = orgHttpWorld();
    $token = orgHttpToken($world, $world['owner']);
    $made = orgHttpTerminal($world, $token);

    $reissued = orgHttpCall('POST', '/api/v1/organization/terminals/'.$made['terminal'].'/activation-code', $token);

    expect($reissued->getStatusCode())->toBe(200)
        ->and($reissued->json('activation_code'))->not->toBe($made['code'])
        ->and(preg_match('/\ASHOP-A-/', (string) $reissued->json('activation_code')))->toBe(1);

    // A pending terminal cannot be paused: terminal_not_active, 403 with that body.
    $notActive = orgHttpCall('PUT', '/api/v1/organization/terminals/'.$made['terminal'].'/status', $token, ['status' => 'inactive']);

    expect($notActive->getStatusCode())->toBe(403)
        ->and($notActive->json())->toBe(['error' => 'terminal_not_active']);

    // Activate by hand (activation is a later slice), then the round trip.
    orgHttpRead(static fn (): int => AccessSeed::connection()->table('terminals')->where('id', $made['terminal'])->update(['status' => 'active']));

    expect(orgHttpCall('PUT', '/api/v1/organization/terminals/'.$made['terminal'].'/status', $token, ['status' => 'inactive'])->getStatusCode())->toBe(200)
        ->and(orgHttpCall('PUT', '/api/v1/organization/terminals/'.$made['terminal'].'/status', $token, ['status' => 'active'])->getStatusCode())->toBe(200);

    // The same terminal, now not pending: a re-issue is terminal_not_pending.
    $notPending = orgHttpCall('POST', '/api/v1/organization/terminals/'.$made['terminal'].'/activation-code', $token);

    expect($notPending->getStatusCode())->toBe(403)
        ->and($notPending->json())->toBe(['error' => 'terminal_not_pending'])
        ->and(orgHttpCall('GET', '/api/v1/organization/terminals/'.$made['terminal'].'/devices', $token)->json('devices'))->toBe([]);

    // An unknown terminal is a 404 and an unknown filter a 422.
    expect(orgHttpCall('GET', '/api/v1/organization/terminals/01ARZ3NDEKTSV4RRFFQ69G5FAV', $token)->json())->toBe(['error' => 'not_found'])
        ->and(orgHttpCall('GET', '/api/v1/organization/terminals?status=paused', $token)->getStatusCode())->toBe(422);
});

it('refuses a terminal at an unknown branch as a 422 validation with its reason, and an inactive one as a 403 (AC-15, AC-63)', function () {
    $world = orgHttpWorld();
    $token = orgHttpToken($world, $world['owner']);

    $unknown = orgHttpCall('POST', '/api/v1/organization/terminals', $token, [
        'branch_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'location_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'code' => 't1', 'name' => 'T',
    ]);

    expect($unknown->getStatusCode())->toBe(422)
        ->and($unknown->json())->toBe(['error' => 'validation', 'reason' => 'unknown_branch']);

    // Two branches so the first can be deactivated, then a terminal at it.
    $first = orgHttpCall('POST', '/api/v1/organization/branches', $token, ['code' => 'b1', 'name' => 'One', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR']);
    orgHttpCall('POST', '/api/v1/organization/branches', $token, ['code' => 'b2', 'name' => 'Two', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR']);
    orgHttpCall('PUT', '/api/v1/organization/branches/'.$first->json('branch_id').'/status', $token, ['is_active' => false]);

    $inactive = orgHttpCall('POST', '/api/v1/organization/terminals', $token, [
        'branch_id' => $first->json('branch_id'), 'location_id' => $first->json('location_id'), 'code' => 't1', 'name' => 'T',
    ]);

    expect($inactive->getStatusCode())->toBe(403)
        ->and($inactive->json())->toBe(['error' => 'branch_inactive']);
});

it('lets a viewer read terminals and not manage them, and refuses a missing token (AC-18)', function () {
    $world = orgHttpWorld();
    $viewer = orgHttpEmployeeHolding('term_viewer', ['terminals.view']);
    $token = orgHttpToken($world, $viewer);

    expect(orgHttpCall('GET', '/api/v1/organization/terminals', $token)->getStatusCode())->toBe(200);

    $refused = orgHttpCall('POST', '/api/v1/organization/terminals', $token, ['code' => 't1']);

    expect($refused->getStatusCode())->toBe(403)
        ->and($refused->json())->toBe(['error' => 'not_permitted'])
        ->and(orgHttpCall('POST', '/api/v1/organization/terminals/01ARZ3NDEKTSV4RRFFQ69G5FAV/activation-code', $token)->getStatusCode())->toBe(403)
        ->and(json('GET', '/api/v1/organization/terminals')->getStatusCode())->toBe(401);
});
