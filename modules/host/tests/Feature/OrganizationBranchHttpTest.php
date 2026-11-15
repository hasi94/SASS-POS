<?php

use App\Http\Responses\OrganizationRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Support\OrganizationRefusal;

use function Pest\Laravel\json;

/*
|--------------------------------------------------------------------------
| T-014 step 8 - the branch routes over HTTP, and the refusal shapes (AC-63)
|--------------------------------------------------------------------------
|
| The slices are proved in the package; this file proves the wiring: the
| middleware pair, the body-only input, the status and body of every
| refusal. Each refusal is asserted by its **cause**, not by its status
| class - eleven of the thirteen share the 403.
|
| `OrganizationRefusal` is iterated whole in the last case and every code
| must have the status and body AC-63 states, so a reason added with another
| shape, or rendered by a second code path, fails here.
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

it('creates, reads, updates and deactivates a branch as the owner, with the shapes AC-63 states', function () {
    $world = orgHttpWorld();
    $token = orgHttpToken($world, $world['owner']);

    $created = orgHttpCall('POST', '/api/v1/organization/branches', $token, [
        'code' => 'main', 'name' => 'Main', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ]);

    expect($created->getStatusCode())->toBe(201);

    $branch = (string) $created->json('branch_id');

    expect($created->json())->toHaveKeys(['branch_id', 'location_id', 'code'])
        ->and($created->json('code'))->toBe('MAIN');

    // Read it back, through a lower-case spelling (AC-61).
    $read = orgHttpCall('GET', '/api/v1/organization/branches/'.strtolower($branch), $token);

    expect($read->getStatusCode())->toBe(200)
        ->and($read->json('id'))->toBe($branch)
        ->and($read->json('default_location_id'))->toBe($created->json('location_id'));

    expect(orgHttpCall('PATCH', '/api/v1/organization/branches/'.$branch, $token, ['name' => 'Renamed'])->getStatusCode())->toBe(200);

    $list = orgHttpCall('GET', '/api/v1/organization/branches?page_size=1', $token);

    expect($list->json('branches'))->toHaveCount(1)
        ->and($list->json('branches.0.name'))->toBe('Renamed');

    // The only active branch: last_branch, 403 with that body.
    $refused = orgHttpCall('PUT', '/api/v1/organization/branches/'.$branch.'/status', $token, ['is_active' => false]);

    expect($refused->getStatusCode())->toBe(403)
        ->and($refused->json())->toBe(['error' => 'last_branch']);

    // A second branch lets the first go.
    orgHttpCall('POST', '/api/v1/organization/branches', $token, [
        'code' => 'two', 'name' => 'Two', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ]);

    $ok = orgHttpCall('PUT', '/api/v1/organization/branches/'.$branch.'/status', $token, ['is_active' => false]);

    expect($ok->getStatusCode())->toBe(200)
        ->and($ok->json('is_active'))->toBeFalse();
});

it('answers each refusal with its own status and body (AC-63)', function () {
    $world = orgHttpWorld();
    $token = orgHttpToken($world, $world['owner']);

    // 422 validation, with no reason.
    $invalid = orgHttpCall('POST', '/api/v1/organization/branches', $token, ['code' => 'x']);

    expect($invalid->getStatusCode())->toBe(422)
        ->and($invalid->json())->toBe(['error' => 'validation']);

    // A field the operation does not take is validation too, not ignored.
    $extra = orgHttpCall('POST', '/api/v1/organization/branches', $token, [
        'code' => 'ok', 'name' => 'Ok', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR', 'is_active' => false,
    ]);

    expect($extra->getStatusCode())->toBe(422);

    // 404 not_found, the first in this API.
    $missing = orgHttpCall('GET', '/api/v1/organization/branches/01ARZ3NDEKTSV4RRFFQ69G5FAV', $token);

    expect($missing->getStatusCode())->toBe(404)
        ->and($missing->json())->toBe(['error' => 'not_found']);

    // A malformed id is validation, not a 404.
    expect(orgHttpCall('GET', '/api/v1/organization/branches/not-an-id', $token)->getStatusCode())->toBe(422);
});

it('refuses an employee without the key, and a request without a token (AC-18, AC-63)', function () {
    $world = orgHttpWorld();
    $viewer = orgHttpEmployeeHolding('viewer', ['branches.view']);
    $token = orgHttpToken($world, $viewer);

    expect(orgHttpCall('GET', '/api/v1/organization/branches', $token)->getStatusCode())->toBe(200);

    $forbidden = orgHttpCall('POST', '/api/v1/organization/branches', $token, [
        'code' => 'x1', 'name' => 'X', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ]);

    expect($forbidden->getStatusCode())->toBe(403)
        ->and($forbidden->json())->toBe(['error' => 'not_permitted'])
        ->and(orgHttpRead(static fn (): int => AccessSeed::connection()->table('branches')->count()))->toBe(0);

    expect(json('GET', '/api/v1/organization/branches')->getStatusCode())->toBe(401);
});

it('renders every organization refusal with the status and body AC-63 states', function () {
    $expected = [
        'validation' => [422, ['error' => 'validation']],
        'not_found' => [404, ['error' => 'not_found']],
        'unknown_branch' => [422, ['error' => 'validation', 'reason' => 'unknown_branch']],
        'unknown_terminal' => [422, ['error' => 'validation', 'reason' => 'unknown_terminal']],
        'branch_inactive' => [403, ['error' => 'branch_inactive']],
        'has_active_terminals' => [403, ['error' => 'has_active_terminals']],
        'last_branch' => [403, ['error' => 'last_branch']],
        'location_in_use' => [403, ['error' => 'location_in_use']],
        'terminal_not_pending' => [403, ['error' => 'terminal_not_pending']],
        'terminal_not_active' => [403, ['error' => 'terminal_not_active']],
        'terminal_revoked' => [403, ['error' => 'terminal_revoked']],
        'scope_not_supported' => [403, ['error' => 'scope_not_supported']],
        'actor_required' => [403, ['error' => 'actor_required']],
    ];

    $seen = [];

    foreach (OrganizationRefusal::cases() as $case) {
        $response = OrganizationRefusals::of($case);

        expect(array_key_exists($case->value, $expected))->toBeTrue("[{$case->value}] has no stated shape");

        [$status, $body] = $expected[$case->value];

        expect($response->getStatusCode())->toBe($status, $case->value)
            ->and(json_decode((string) $response->getContent(), true))->toBe($body, $case->value);

        $seen[] = $case->value;
    }

    // The set is closed in both directions: nothing stated is unrendered.
    sort($seen);
    $stated = array_keys($expected);
    sort($stated);

    expect($seen)->toBe($stated)->and($seen)->toHaveCount(13);
});
