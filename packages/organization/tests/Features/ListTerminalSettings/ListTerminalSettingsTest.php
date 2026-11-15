<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ListTerminalSettings;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\ListTerminalSettings\ListTerminalSettings;
use Flexipos\Organization\Features\ListTerminalSettings\ListTerminalSettingsHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Flexipos\Organization\Tests\Support\SettingsSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 14 - what a terminal is sent about itself (AC-50)
|--------------------------------------------------------------------------
|
| The request-time half (a paused or revoked terminal is refused before this
| handler runs) is the host's `OrganizationSettingsHttpTest`; this file is the
| selection and the resolution. The absent keys are asserted by name, and each
| absence has its control: the same key, asked for another terminal or in
| another configuration, does appear.
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

it('sends a terminal exactly the sync_to_pos keys that are not secret, resolved for it and nothing of another terminal or branch (AC-50)', function () {
    $p = SettingsSeed::places();
    SettingsSeed::register('sync.plain', 'string', 'D', sync: true);
    SettingsSeed::register('sync.secret', 'string', 'D', sync: true, secret: true);
    SettingsSeed::register('nosync.plain', 'string', 'D', sync: false);
    SettingsSeed::register('sync.other', 'integer', 1, sync: true);

    SettingsSeed::override('branch', $p['x'], 'sync.plain', 'X-BRANCH');
    SettingsSeed::override('terminal', $p['t2'], 'sync.plain', 'T2-ONLY');
    SettingsSeed::override('branch', $p['y'], 'sync.other', 99);
    SettingsSeed::override('terminal', $p['t1'], 'sync.secret', 'SECRET-T1');
    SettingsSeed::override('terminal', $p['t1'], 'nosync.plain', 'HIDDEN');

    /** @var ListTerminalSettingsHandler $handler */
    $handler = app(ListTerminalSettingsHandler::class);

    $mine = $handler->handle(new ListTerminalSettings($p['t1']));
    $encoded = (string) json_encode($mine);

    expect($mine)->toBe([
        ['key' => 'sync.other', 'value' => 1, 'source' => 'default'],
        ['key' => 'sync.plain', 'value' => 'X-BRANCH', 'source' => 'branch'],
    ]);

    // Absent: the secret key that is synced, the key that is not synced,
    // and every value that belongs to another terminal or branch.
    foreach (['SECRET-T1', 'HIDDEN', 'T2-ONLY', 'sync.secret', 'nosync.plain'] as $absent) {
        expect($encoded)->not->toContain($absent);
    }

    // Control: the other terminal at the same branch, and the one at the other branch, see their own.
    expect($handler->handle(new ListTerminalSettings($p['t2']))[1])->toBe(['key' => 'sync.plain', 'value' => 'T2-ONLY', 'source' => 'terminal']);
    expect($handler->handle(new ListTerminalSettings($p['t3']))[0])->toBe(['key' => 'sync.other', 'value' => 99, 'source' => 'branch']);

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => $handler->handle(new ListTerminalSettings((string) Str::ulid())));
});

it('takes the branch from the terminal row, not from anything else it is given (AC-50)', function () {
    $p = SettingsSeed::places();
    SettingsSeed::register('sync.plain', 'string', 'D', sync: true);
    SettingsSeed::override('branch', $p['x'], 'sync.plain', 'X');
    SettingsSeed::override('branch', $p['y'], 'sync.plain', 'Y');

    /** @var ListTerminalSettingsHandler $handler */
    $handler = app(ListTerminalSettingsHandler::class);

    expect($handler->handle(new ListTerminalSettings($p['t1']))[0]['value'])->toBe('X');
    expect($handler->handle(new ListTerminalSettings($p['t3']))[0]['value'])->toBe('Y');

    // Move T1 to branch Y in the tenant database: the next answer follows the row.
    $location = (string) OrganizationSeed::connection()->table('locations')->where('branch_id', $p['y'])->value('id');
    OrganizationSeed::connection()->table('terminals')->where('id', $p['t1'])->update(['branch_id' => $p['y'], 'location_id' => $location]);

    expect($handler->handle(new ListTerminalSettings($p['t1']))[0]['value'])->toBe('Y');
});
