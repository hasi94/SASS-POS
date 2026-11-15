<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ClearSetting;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\ClearSetting\ClearSetting;
use Flexipos\Organization\Features\ClearSetting\ClearSettingHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Flexipos\Organization\Tests\Support\SettingsSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 14 - clearing a setting override (AC-46, clearing half; D-6)
|--------------------------------------------------------------------------
|
| The overrides are seeded directly: this slice is asked about clearing, and
| going through the write slice would make a write bug read as a clear bug.
| Every refusal is followed by a control, and the whole `settings` and
| `audit_logs` state is compared before and after a refused call.
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
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function clearRun(array $fields, ?string $actor = null): array
{
    /** @var ClearSettingHandler $handler */
    $handler = app()->make(ClearSettingHandler::class);

    return $handler->handle(new ClearSetting($fields, $actor));
}

it('clears an override, writes one whole audit row, and leaves the other layers alone (AC-46)', function () {
    $p = SettingsSeed::places();
    SettingsSeed::register('pos.rounding', 'string', 'D');
    $actor = AccessSeed::employee('cl_actor', ['display_name' => 'Clearer']);

    SettingsSeed::override('terminal', $p['t1'], 'pos.rounding', 'T');
    SettingsSeed::override('company', null, 'pos.rounding', 'C');

    $result = clearRun(['key' => 'pos.rounding', 'scope_type' => 'terminal', 'scope_id' => strtolower($p['t1'])], $actor);

    expect($result)->toBe(['key' => 'pos.rounding', 'scope_type' => 'terminal', 'scope_id' => $p['t1']])
        ->and(OrganizationSeed::connection()->table('settings')->count())->toBe(1)
        ->and(OrganizationSeed::connection()->table('settings')->value('scope_type'))->toBe('company');

    $row = OrganizationSeed::onlyAuditRow();

    expect($row)->toBe([
        'actor_type' => 'employee',
        'actor_id' => $actor,
        'actor_name' => 'Clearer',
        'branch_id' => $p['x'],
        'module' => 'organization',
        'action' => 'setting_cleared',
        'subject_type' => 'setting',
        'subject_id' => $row['subject_id'],
        'before' => ['key' => 'pos.rounding', 'scope_type' => 'terminal', 'scope_id' => $p['t1'], 'value' => 'T'],
        'after' => null,
        // The writer lists the changed fields sorted by name.
        'changed_fields' => ['key', 'scope_id', 'scope_type', 'value'],
        'user_agent' => null,
        'request_id' => null,
    ]);

    // Control: the company row clears too, and then nothing is left.
    clearRun(['key' => 'pos.rounding', 'scope_type' => 'company']);

    expect(OrganizationSeed::connection()->table('settings')->count())->toBe(0);
});

it('refuses a clear with nothing to clear, an unsupported scope or a stray field, changing nothing (AC-46, AC-51)', function () {
    $p = SettingsSeed::places();
    SettingsSeed::register('pos.rounding', 'string', 'D');
    SettingsSeed::override('company', null, 'pos.rounding', 'C');

    $state = SettingsSeed::snapshot().json_encode(OrganizationSeed::audit());

    $cases = [
        'nothing at that terminal scope' => [['key' => 'pos.rounding', 'scope_type' => 'terminal', 'scope_id' => $p['t1']], OrganizationRefusal::Validation],
        'an unregistered key' => [['key' => 'no.such', 'scope_type' => 'company'], OrganizationRefusal::Validation],
        'a company scope with an id' => [['key' => 'pos.rounding', 'scope_type' => 'company', 'scope_id' => $p['x']], OrganizationRefusal::Validation],
        'the employee scope' => [['key' => 'pos.rounding', 'scope_type' => 'employee', 'scope_id' => $p['t1']], OrganizationRefusal::ScopeNotSupported],
        'an unknown scope type' => [['key' => 'pos.rounding', 'scope_type' => 'galaxy'], OrganizationRefusal::Validation],
        'a non-string key' => [['key' => 5, 'scope_type' => 'company'], OrganizationRefusal::Validation],
        'a field it does not take' => [['key' => 'pos.rounding', 'scope_type' => 'company', 'value' => 'x'], OrganizationRefusal::Validation],
    ];

    foreach ($cases as $label => [$fields, $reason]) {
        OrganizationSeed::refusal($reason, fn () => clearRun($fields), $label);

        expect(SettingsSeed::snapshot().json_encode(OrganizationSeed::audit()))->toBe($state, $label);
    }

    // Control: the valid clear of the one row that exists succeeds.
    clearRun(['key' => 'pos.rounding', 'scope_type' => 'company']);

    expect(OrganizationSeed::connection()->table('settings')->count())->toBe(0);
});

it('keeps an override removable after its branch is deactivated or its terminal revoked (D-6)', function () {
    $p = SettingsSeed::places();
    SettingsSeed::register('pos.rounding', 'string', 'D');
    SettingsSeed::override('branch', $p['y'], 'pos.rounding', 'B');
    SettingsSeed::override('terminal', $p['t3'], 'pos.rounding', 'T');

    OrganizationSeed::connection()->table('branches')->where('id', $p['y'])->update(['is_active' => false]);
    OrganizationSeed::connection()->table('terminals')->where('id', $p['t3'])->update(['status' => 'revoked']);

    clearRun(['key' => 'pos.rounding', 'scope_type' => 'branch', 'scope_id' => $p['y']]);
    clearRun(['key' => 'pos.rounding', 'scope_type' => 'terminal', 'scope_id' => $p['t3']]);

    expect(OrganizationSeed::connection()->table('settings')->count())->toBe(0)
        ->and(OrganizationSeed::connection()->table('audit_logs')->where('action', 'setting_cleared')->count())->toBe(2);
});

it('clears a secret override with an is_set audit and no value anywhere (AC-47)', function () {
    $p = SettingsSeed::places();
    SettingsSeed::register('gateway.token', 'string', null, secret: true);
    $sentinel = 'SENTINEL-CLEAR-80213';

    SettingsSeed::override('branch', $p['x'], 'gateway.token', $sentinel);

    clearRun(['key' => 'gateway.token', 'scope_type' => 'branch', 'scope_id' => $p['x']]);

    $audit = OrganizationSeed::onlyAuditRow();

    expect($audit['before'])->toBe(['key' => 'gateway.token', 'scope_type' => 'branch', 'scope_id' => $p['x'], 'is_set' => true])
        ->and($audit['after'])->toBeNull()
        ->and((string) json_encode($audit))->not->toContain($sentinel);
});
