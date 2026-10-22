<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ListEffectiveSettings;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\ListEffectiveSettings\ListEffectiveSettings;
use Flexipos\Organization\Features\ListEffectiveSettings\ListEffectiveSettingsHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Flexipos\Organization\Tests\Support\SettingsSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 14 - effective settings (AC-44, AC-48, AC-50, AC-51)
|--------------------------------------------------------------------------
|
| The truth table of AC-44 is a dataset-free list of named rows run over ONE
| tenant fixture, built once; each row seeds its own overrides, asks for a
| terminal, and compares both the value and the layer it came from. The table
| is printed, and the test asserts it holds every row the criterion names, so
| deleting a row is a red test and not a shorter table.
|
| What the mandatory mutation (company beats terminal) must turn red is the
| rows where two layers disagree; a resolver that always answers the default
| fails the first row after "only default". Both are qa's to run.
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
 * @return array<string, mixed> key => ['value' => ..., 'source' => ...]
 */
function effectiveFor(string $terminalId): array
{
    /** @var ListEffectiveSettingsHandler $handler */
    $handler = app(ListEffectiveSettingsHandler::class);

    $byKey = [];

    foreach ($handler->handle(new ListEffectiveSettings('terminal', $terminalId)) as $item) {
        $byKey[$item['key']] = $item;
    }

    return $byKey;
}

it('resolves terminal, then branch, then company, then the registry default, and prints the truth table (AC-44)', function () {
    $p = SettingsSeed::places();
    SettingsSeed::register('pos.rounding', 'string', 'DEFAULT');

    // [label, overrides as [type, scope, value], asked terminal, expected value, expected source]
    $table = [
        ['only the default', [], 't1', 'DEFAULT', 'default'],
        ['company only', [['company', null, 'C']], 't1', 'C', 'company'],
        ['company and branch', [['company', null, 'C'], ['branch', 'x', 'B']], 't1', 'B', 'branch'],
        ['branch override beaten by the terminal override', [['company', null, 'C'], ['branch', 'x', 'B'], ['terminal', 't1', 'T']], 't1', 'T', 'terminal'],
        ['terminal beats the company directly', [['company', null, 'C'], ['terminal', 't1', 'T']], 't1', 'T', 'terminal'],
        ['another branch override is ignored', [['branch', 'y', 'BY']], 't1', 'DEFAULT', 'default'],
        ['another terminal override is ignored', [['terminal', 't2', 'T2']], 't1', 'DEFAULT', 'default'],
        ['a terminal at X sees the override of X', [['branch', 'x', 'BX'], ['branch', 'y', 'BY']], 't1', 'BX', 'branch'],
        ['a terminal at Y sees the override of Y and not X', [['branch', 'x', 'BX'], ['branch', 'y', 'BY']], 't3', 'BY', 'branch'],
        ['a company value still reaches a terminal with an unrelated override elsewhere', [['company', null, 'C'], ['terminal', 't2', 'T2'], ['branch', 'y', 'BY']], 't1', 'C', 'company'],
    ];

    $printed = [];

    foreach ($table as [$label, $overrides, $asked, $value, $source]) {
        SettingsSeed::clearOverrides();

        $present = [];

        foreach ($overrides as [$type, $scope, $v]) {
            SettingsSeed::override($type, $scope === null ? null : $p[$scope], 'pos.rounding', $v);
            $present[] = $type.($scope === null ? '' : ':'.$scope).'='.$v;
        }

        $got = effectiveFor($p[$asked])['pos.rounding'];

        $printed[] = sprintf('%-62s rows[%s] asked %s -> %s from %s', $label, implode(', ', $present), $asked, $got['value'], $got['source']);

        expect([$got['value'], $got['source']])->toBe([$value, $source], $label);
    }

    // The clear walk: terminal, then branch, then company, then the default.
    SettingsSeed::clearOverrides();
    $ids = [
        'terminal' => SettingsSeed::override('terminal', $p['t1'], 'pos.rounding', 'T'),
        'branch' => SettingsSeed::override('branch', $p['x'], 'pos.rounding', 'B'),
        'company' => SettingsSeed::override('company', null, 'pos.rounding', 'C'),
    ];

    foreach (['terminal' => ['T', 'terminal'], 'branch' => ['B', 'branch'], 'company' => ['C', 'company']] as $layer => [$value, $source]) {
        $got = effectiveFor($p['t1'])['pos.rounding'];

        expect([$got['value'], $got['source']])->toBe([$value, $source], 'before clearing '.$layer);

        OrganizationSeed::connection()->table('settings')->where('id', $ids[$layer])->delete();

        $printed[] = sprintf('%-62s after clearing %s the next layer applies', 'the walk down', $layer);
    }

    expect(effectiveFor($p['t1'])['pos.rounding'])->toBe(['key' => 'pos.rounding', 'value' => 'DEFAULT', 'source' => 'default']);

    fwrite(STDOUT, PHP_EOL.'AC-44 truth table ('.count($table).' rows):'.PHP_EOL.implode(PHP_EOL, $printed).PHP_EOL);

    expect(count($table))->toBeGreaterThanOrEqual(7);
});

it('reads a branch and the company scope with the layers that apply to them, orders by key, and answers not_found for a scope that does not exist (AC-48)', function () {
    $p = SettingsSeed::places();
    SettingsSeed::register('b.second', 'integer', 2);
    SettingsSeed::register('a.first', 'boolean', false);
    SettingsSeed::override('company', null, 'b.second', 20);
    SettingsSeed::override('branch', $p['x'], 'b.second', 21);
    SettingsSeed::override('terminal', $p['t1'], 'b.second', 22);

    /** @var ListEffectiveSettingsHandler $handler */
    $handler = app(ListEffectiveSettingsHandler::class);

    $company = $handler->handle(new ListEffectiveSettings('company', null));
    $branch = $handler->handle(new ListEffectiveSettings('branch', strtolower($p['x'])));
    $terminal = $handler->handle(new ListEffectiveSettings('terminal', $p['t1']));

    expect(array_column($company, 'key'))->toBe(['a.first', 'b.second'])
        ->and($company[1])->toBe(['key' => 'b.second', 'value' => 20, 'source' => 'company'])
        ->and($company[0])->toBe(['key' => 'a.first', 'value' => false, 'source' => 'default'])
        // The terminal row is not read for a branch, nor the branch row for the company.
        ->and($branch[1])->toBe(['key' => 'b.second', 'value' => 21, 'source' => 'branch'])
        ->and($terminal[1])->toBe(['key' => 'b.second', 'value' => 22, 'source' => 'terminal']);

    foreach ([['branch', (string) Str::ulid()], ['terminal', (string) Str::ulid()]] as [$type, $id]) {
        OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => $handler->handle(new ListEffectiveSettings($type, $id)), $type);
    }

    // An inactive branch and a revoked terminal still exist, so they still read.
    $inactive = OrganizationSeed::branch(false);

    expect($handler->handle(new ListEffectiveSettings('branch', $inactive)))->toHaveCount(2);
});

it('ignores a stored employee-scope row in every answer and refuses the employee scope and an unknown type (AC-51)', function () {
    $p = SettingsSeed::places();
    SettingsSeed::register('pos.rounding', 'string', 'DEFAULT');

    // Planted with the terminal's own id as the scope id, which is the shape
    // most likely to be picked up by a resolver that matched on id alone.
    SettingsSeed::override('employee', $p['t1'], 'pos.rounding', 'EMPLOYEE-WINS');

    $got = effectiveFor($p['t1'])['pos.rounding'];

    expect($got['value'])->toBe('DEFAULT')->and($got['source'])->toBe('default');

    /** @var ListEffectiveSettingsHandler $handler */
    $handler = app(ListEffectiveSettingsHandler::class);

    OrganizationSeed::refusal(OrganizationRefusal::ScopeNotSupported, fn () => $handler->handle(new ListEffectiveSettings('employee', $p['t1'])));
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new ListEffectiveSettings('galaxy', $p['t1'])));
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new ListEffectiveSettings(['terminal'], $p['t1'])));
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new ListEffectiveSettings('company', $p['x'])));
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new ListEffectiveSettings('branch', null)));
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new ListEffectiveSettings('branch', 'not-a-ulid')));
});

it('lists a secret as is_set with its layer and no value, at every layer including the default (AC-47, AC-48)', function () {
    $p = SettingsSeed::places();
    SettingsSeed::register('gateway.token', 'string', 'DEFAULT-SECRET', secret: true);
    SettingsSeed::register('gateway.other', 'string', 'plain');

    $sentinel = 'SENTINEL-VALUE-91827';

    /** @var ListEffectiveSettingsHandler $handler */
    $handler = app(ListEffectiveSettingsHandler::class);

    $unset = $handler->handle(new ListEffectiveSettings('company', null));

    expect($unset[1])->toBe(['key' => 'gateway.token', 'is_set' => false, 'source' => 'default'])
        ->and(json_encode($unset))->not->toContain('DEFAULT-SECRET');

    SettingsSeed::override('branch', $p['x'], 'gateway.token', $sentinel);

    $set = $handler->handle(new ListEffectiveSettings('branch', $p['x']));

    expect($set[1])->toBe(['key' => 'gateway.token', 'is_set' => true, 'source' => 'branch'])
        ->and(json_encode($set))->not->toContain($sentinel)
        // Control: the same scan finds a value that is not secret.
        ->and(json_encode($set))->toContain('plain');
});
