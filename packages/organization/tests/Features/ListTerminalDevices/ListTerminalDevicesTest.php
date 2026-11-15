<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ListTerminalDevices;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\ListTerminalDevices\ListTerminalDevices;
use Flexipos\Organization\Features\ListTerminalDevices\ListTerminalDevicesHandler;
use Flexipos\Organization\Support\ActivationCode;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 10 - reading a terminal's devices (AC-41)
|--------------------------------------------------------------------------
|
| Ordered by `registered_at`, with the ten fields the criterion names and no
| activation hash or code anywhere. The devices of ANOTHER terminal are not in
| the list, which a handler that listed every device would fail.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function deviceFor(string $terminalId, string $fingerprint, string $registeredAt, array $overrides = []): string
{
    $id = (string) Str::ulid();

    OrganizationSeed::connection()->table('devices')->insert(array_merge([
        'id' => $id, 'terminal_id' => $terminalId, 'fingerprint' => $fingerprint, 'registered_at' => $registeredAt,
        'created_at' => $registeredAt, 'updated_at' => $registeredAt,
    ], $overrides));

    return $id;
}

it('lists a terminals devices oldest first with the named fields and without any hash or code (AC-41)', function () {
    $branch = OrganizationSeed::branch();
    $store = OrganizationSeed::location($branch, 'ST1', 'branch_store', ['is_sellable_from' => true]);
    $hash = ActivationCode::hashOf('ABCD-EFGH');
    $terminal = OrganizationSeed::terminal($branch, $store, 'T1', 'revoked', ['activation_code_hash' => $hash]);
    $other = OrganizationSeed::terminal($branch, $store, 'T2', 'active');
    $revoker = AccessSeed::employee('dev_revoker');

    $late = deviceFor($terminal, str_repeat('b', 64), '2026-10-22 09:30:00.000', [
        'platform' => 'Windows', 'browser' => 'Chrome 130', 'os' => 'Windows 11', 'screen' => '1920x1080',
        'last_seen_at' => '2026-10-22 09:45:00.000', 'revoked_at' => '2026-10-22 09:50:00.000', 'revoked_by' => $revoker,
    ]);
    $early = deviceFor($terminal, str_repeat('a', 64), '2026-10-22 08:00:00.000');
    deviceFor($other, str_repeat('c', 64), '2026-10-22 07:00:00.000');

    /** @var ListTerminalDevicesHandler $handler */
    $handler = app(ListTerminalDevicesHandler::class);

    $devices = $handler->handle(new ListTerminalDevices(strtolower($terminal)));

    expect(array_column($devices, 'id'))->toBe([$early, $late])
        ->and(array_keys($devices[1]))->toBe([
            'id', 'fingerprint', 'platform', 'browser', 'os', 'screen', 'registered_at', 'last_seen_at', 'revoked_at', 'revoked_by',
        ])
        ->and($devices[1]['platform'])->toBe('Windows')
        ->and($devices[1]['revoked_by'])->toBe($revoker)
        ->and($devices[0]['platform'])->toBeNull()
        ->and((string) json_encode($devices))->not->toContain($hash)
        ->and((string) json_encode($devices))->not->toContain('activation')
        ->and((string) json_encode($devices))->not->toContain(str_repeat('c', 64));
});

it('answers an empty list for a terminal with no device, not_found for one that does not exist, validation for a malformed id (AC-41)', function () {
    $branch = OrganizationSeed::branch();
    $store = OrganizationSeed::location($branch, 'ST1', 'branch_store', ['is_sellable_from' => true]);
    $terminal = OrganizationSeed::terminal($branch, $store, 'T1');

    /** @var ListTerminalDevicesHandler $handler */
    $handler = app(ListTerminalDevicesHandler::class);

    expect($handler->handle(new ListTerminalDevices($terminal)))->toBe([]);

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => $handler->handle(new ListTerminalDevices('01ARZ3NDEKTSV4RRFFQ69G5FAV')));
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new ListTerminalDevices('nope')));
});
