<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\SetSetting;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\ClearSetting\ClearSetting;
use Flexipos\Organization\Features\ClearSetting\ClearSettingHandler;
use Flexipos\Organization\Features\SetSetting\SetSetting;
use Flexipos\Organization\Features\SetSetting\SetSettingHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Flexipos\Organization\Tests\Support\RecordingLogger;
use Flexipos\Organization\Tests\Support\SettingsSeed;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 14 - writing and clearing settings (AC-45, AC-46, AC-47, AC-51)
|--------------------------------------------------------------------------
|
| One tenant fixture per case. Every refusal in the table is asked with the
| whole `settings` and `audit_logs` state snapshotted first and compared whole
| after, so "no row changes" is a statement about rows, and each group is
| followed by a control that the same call, made valid, succeeds.
|
| The registry has no writer in the product (Ruling 9), so the fixture keys
| are inserted directly by `SettingsSeed`.
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
function setSetting(array $fields, ?string $actor = null, ?RecordingLogger $logger = null): array
{
    if ($logger !== null) {
        app()->instance(LoggerInterface::class, $logger);
    }

    /** @var SetSettingHandler $handler */
    $handler = app()->make(SetSettingHandler::class);

    return $handler->handle(new SetSetting($fields, $actor));
}

/**
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function clearSetting(array $fields, ?string $actor = null): array
{
    /** @var ClearSettingHandler $handler */
    $handler = app()->make(ClearSettingHandler::class);

    return $handler->handle(new ClearSetting($fields, $actor));
}

function settingsState(): string
{
    return SettingsSeed::snapshot().json_encode(OrganizationSeed::audit());
}

it('writes one row at company, branch and terminal scope, replaces on a repeat, and a different scope makes a second row (AC-45)', function () {
    $p = SettingsSeed::places();
    $actor = AccessSeed::employee('st_actor', ['display_name' => 'Setter']);
    SettingsRegistryHolder::boot();

    $company = setSetting(['key' => 'pos.rounding', 'scope_type' => 'company', 'scope_id' => null, 'value' => 'C'], $actor);

    expect($company['scope_id'])->toBeNull();

    $rows = fn (): array => OrganizationSeed::connection()->table('settings')->where('key', 'pos.rounding')->get()->all();

    expect($rows())->toHaveCount(1)
        ->and($rows()[0]->scope_type)->toBe('company')
        ->and($rows()[0]->scope_id)->toBeNull()
        ->and(json_decode((string) $rows()[0]->value))->toBe('C')
        ->and($rows()[0]->updated_by)->toBe($actor);

    // Replace at the company scope - the NULL-id case the sentinel key exists for.
    setSetting(['key' => 'pos.rounding', 'scope_type' => 'company', 'value' => 'C2'], $actor);

    expect($rows())->toHaveCount(1)->and(json_decode((string) $rows()[0]->value))->toBe('C2');

    // Control: the same value at other scopes is a second and third row.
    setSetting(['key' => 'pos.rounding', 'scope_type' => 'branch', 'scope_id' => strtolower($p['x']), 'value' => 'C2'], $actor);
    setSetting(['key' => 'pos.rounding', 'scope_type' => 'terminal', 'scope_id' => $p['t1'], 'value' => 'C2'], $actor);

    expect($rows())->toHaveCount(3)
        ->and(OrganizationSeed::connection()->table('settings')->where('scope_type', 'branch')->value('scope_id'))->toBe($p['x']);

    $audit = array_values(array_filter(OrganizationSeed::audit(), static fn (array $r): bool => $r['action'] === 'setting_set'));

    // One audit row per write: four writes, four rows.
    expect($audit)->toHaveCount(4);

    $second = $audit[1];
    $before = json_decode((string) $second['before'], true);
    $after = json_decode((string) $second['after'], true);

    expect($second['actor_id'])->toBe($actor)
        ->and($second['subject_type'])->toBe('setting')
        ->and($second['branch_id'])->toBeNull()
        ->and($before)->toBe(['key' => 'pos.rounding', 'scope_type' => 'company', 'scope_id' => null, 'value' => 'C'])
        ->and($after)->toBe(['key' => 'pos.rounding', 'scope_type' => 'company', 'scope_id' => null, 'value' => 'C2'])
        ->and(json_decode((string) $second['changed_fields'], true))->toBe(['value']);

    // The terminal write carries the terminal's branch; the branch write its own.
    expect($audit[2]['branch_id'])->toBe($p['x'])->and($audit[3]['branch_id'])->toBe($p['x']);
});

it('refuses each invalid write with the named reason, changing no row and writing no audit row (AC-46, AC-51)', function () {
    $p = SettingsSeed::places();
    SettingsRegistryHolder::boot();
    $inactive = OrganizationSeed::branch(false);
    $revoked = OrganizationSeed::terminal($p['x'], (string) OrganizationSeed::connection()->table('locations')->where('branch_id', $p['x'])->value('id'), 'TR', 'revoked');

    $good = ['key' => 'pos.rounding', 'scope_type' => 'company', 'scope_id' => null, 'value' => 'C'];
    $ghost = (string) Str::ulid();

    $cases = [
        'unregistered key' => [['key' => 'no.such'] + $good, OrganizationRefusal::Validation],
        'non-string key' => [['key' => ['pos.rounding']] + $good, OrganizationRefusal::Validation],
        'integer key' => [['key' => 7] + $good, OrganizationRefusal::Validation],
        'empty key' => [['key' => ''] + $good, OrganizationRefusal::Validation],
        'missing key' => [array_diff_key($good, ['key' => 1]), OrganizationRefusal::Validation],
        'json null value' => [['value' => null] + $good, OrganizationRefusal::Validation],
        'absent value' => [array_diff_key($good, ['value' => 1]), OrganizationRefusal::Validation],
        'wrong type (integer for a string key)' => [['value' => 5] + $good, OrganizationRefusal::Validation],
        'string over max_length' => [['value' => 'ABCDEFGHIJK'] + $good, OrganizationRefusal::Validation],
        'integer below min' => [['key' => 'pos.copies', 'value' => 0] + $good, OrganizationRefusal::Validation],
        'integer above max' => [['key' => 'pos.copies', 'value' => 11] + $good, OrganizationRefusal::Validation],
        'integer given as a string' => [['key' => 'pos.copies', 'value' => '3'] + $good, OrganizationRefusal::Validation],
        'value outside in' => [['key' => 'pos.mode', 'value' => 'zzz'] + $good, OrganizationRefusal::Validation],
        'boolean given as a string' => [['key' => 'pos.flag', 'value' => 'true'] + $good, OrganizationRefusal::Validation],
        'json given as a scalar' => [['key' => 'pos.blob', 'value' => 'text'] + $good, OrganizationRefusal::Validation],
        'company scope with a scope_id' => [['scope_id' => $p['x']] + $good, OrganizationRefusal::Validation],
        'branch scope without a scope_id' => [['scope_type' => 'branch'] + $good, OrganizationRefusal::Validation],
        'terminal scope with a malformed scope_id' => [['scope_type' => 'terminal', 'scope_id' => 'nope'] + $good, OrganizationRefusal::Validation],
        'branch scope with a non-string scope_id' => [['scope_type' => 'branch', 'scope_id' => [1]] + $good, OrganizationRefusal::Validation],
        'branch that does not exist' => [['scope_type' => 'branch', 'scope_id' => $ghost] + $good, OrganizationRefusal::UnknownBranch],
        'inactive branch' => [['scope_type' => 'branch', 'scope_id' => $inactive] + $good, OrganizationRefusal::BranchInactive],
        'terminal that does not exist' => [['scope_type' => 'terminal', 'scope_id' => $ghost] + $good, OrganizationRefusal::UnknownTerminal],
        'revoked terminal' => [['scope_type' => 'terminal', 'scope_id' => $revoked] + $good, OrganizationRefusal::TerminalRevoked],
        'employee scope' => [['scope_type' => 'employee', 'scope_id' => $p['t1']] + $good, OrganizationRefusal::ScopeNotSupported],
        'employee scope without an id' => [['scope_type' => 'employee', 'scope_id' => null] + $good, OrganizationRefusal::ScopeNotSupported],
        'unknown scope type' => [['scope_type' => 'galaxy'] + $good, OrganizationRefusal::Validation],
        'non-string scope type' => [['scope_type' => ['company']] + $good, OrganizationRefusal::Validation],
        'a field the operation does not take' => [['extra' => 1] + $good, OrganizationRefusal::Validation],
    ];

    $before = settingsState();
    $seen = [];

    foreach ($cases as $label => [$fields, $reason]) {
        OrganizationSeed::refusal($reason, fn () => setSetting($fields), $label);

        expect(settingsState())->toBe($before, $label);

        $seen[] = $label;
    }

    fwrite(STDOUT, PHP_EOL.'AC-46 refusals checked ('.count($seen).'): '.implode('; ', $seen).PHP_EOL);

    // Controls: the valid call changes the state, and so do valid calls at each
    // refused scope's usable counterpart.
    setSetting($good);
    setSetting(['scope_type' => 'branch', 'scope_id' => $p['x']] + $good);

    expect(settingsState())->not->toBe($before)
        ->and(OrganizationSeed::connection()->table('settings')->count())->toBe(2);
});

it('fails closed on a malformed registry row, logging the key and never the value (AC-46, AC-64)', function () {
    SettingsSeed::register('bad.type', 'color', null);
    SettingsSeed::register('bad.rule', 'string', null, ['pattern' => '^x']);
    SettingsSeed::register('bad.misplaced', 'boolean', null, ['max_length' => 3]);
    SettingsSeed::register('bad.shape', 'integer', null, '[1,2]');
    SettingsSeed::register('bad.argument', 'integer', null, ['min' => 'low']);
    SettingsSeed::register('ok.string', 'string', null, ['max_length' => 5]);

    $secretValue = 'LEAK-ME-4455';
    $before = settingsState();

    foreach (['bad.type', 'bad.rule', 'bad.misplaced', 'bad.shape', 'bad.argument'] as $key) {
        $logger = new RecordingLogger;

        OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => setSetting(['key' => $key, 'scope_type' => 'company', 'value' => $secretValue], null, $logger), $key);

        $errors = $logger->at('error');

        expect($errors)->toHaveCount(1)
            ->and($errors[0]['context']['key'])->toBe($key)
            ->and(json_encode($logger->lines))->not->toContain($secretValue)
            ->and(settingsState())->toBe($before, $key);
    }

    // Control: a well-formed row is written, and logs no error.
    $logger = new RecordingLogger;
    setSetting(['key' => 'ok.string', 'scope_type' => 'company', 'value' => 'fine'], null, $logger);

    expect($logger->at('error'))->toBe([])
        ->and(OrganizationSeed::connection()->table('settings')->count())->toBe(1);
});

it('keeps a secret value out of the audit, the response, the logs and every column but settings.value (AC-47)', function () {
    $p = SettingsSeed::places();
    SettingsSeed::register('gateway.token', 'string', 'DEFAULT-SECRET', secret: true);
    SettingsSeed::register('gateway.name', 'string', null);

    $sentinel = 'SENTINEL-SECRET-73645';
    $logger = new RecordingLogger;

    $response = setSetting(['key' => 'gateway.token', 'scope_type' => 'branch', 'scope_id' => $p['x'], 'value' => $sentinel], null, $logger);
    setSetting(['key' => 'gateway.token', 'scope_type' => 'branch', 'scope_id' => $p['x'], 'value' => $sentinel.'-2'], null, $logger);
    clearSetting(['key' => 'gateway.token', 'scope_type' => 'branch', 'scope_id' => $p['x']]);

    // Control for the scan: a plain setting's value does reach the audit row, by design.
    setSetting(['key' => 'gateway.name', 'scope_type' => 'company', 'value' => 'PLAIN-VALUE-1'], null, $logger);

    $audit = (string) json_encode(OrganizationSeed::audit());
    $set = OrganizationSeed::audit();

    foreach ([(string) json_encode($response), $audit, (string) json_encode($logger->lines)] as $sink) {
        expect($sink)->not->toContain($sentinel);
    }

    expect($audit)->not->toContain('DEFAULT-SECRET');
    expect($audit)->toContain('PLAIN-VALUE-1');

    $first = json_decode((string) $set[0]['after'], true);
    $second = json_decode((string) $set[1]['before'], true);
    $cleared = json_decode((string) $set[2]['before'], true);

    expect($first)->toBe(['key' => 'gateway.token', 'scope_type' => 'branch', 'scope_id' => $p['x'], 'is_set' => true])
        ->and($set[0]['before'])->toBeNull()
        ->and($second)->toBe($first)
        ->and($cleared['is_set'])->toBeTrue()
        ->and($set[2]['after'])->toBeNull();
});

/**
 * The fixture keys the write tests share: one string with a length cap, an
 * integer with a range, an enumeration, a boolean and a json object.
 */
final class SettingsRegistryHolder
{
    public static function boot(): void
    {
        SettingsSeed::register('pos.rounding', 'string', 'D', ['max_length' => 10]);
        SettingsSeed::register('pos.copies', 'integer', 1, ['min' => 1, 'max' => 10]);
        SettingsSeed::register('pos.mode', 'string', 'a', ['in' => ['a', 'b']]);
        SettingsSeed::register('pos.flag', 'boolean', false);
        SettingsSeed::register('pos.blob', 'json', ['k' => 'v']);
    }
}
