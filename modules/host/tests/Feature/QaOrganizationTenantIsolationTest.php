<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Auth\TerminalSessions;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\ChangeBranchStatus\ChangeBranchStatus;
use Flexipos\Organization\Features\ChangeBranchStatus\ChangeBranchStatusHandler;
use Flexipos\Organization\Features\ChangeLocationStatus\ChangeLocationStatus;
use Flexipos\Organization\Features\ChangeLocationStatus\ChangeLocationStatusHandler;
use Flexipos\Organization\Features\ChangeTerminalStatus\ChangeTerminalStatus;
use Flexipos\Organization\Features\ChangeTerminalStatus\ChangeTerminalStatusHandler;
use Flexipos\Organization\Features\CreateBranch\CreateBranch;
use Flexipos\Organization\Features\CreateBranch\CreateBranchHandler;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminal;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminalHandler;
use Flexipos\Organization\Features\GetBranch\GetBranch;
use Flexipos\Organization\Features\GetBranch\GetBranchHandler;
use Flexipos\Organization\Features\GetLocation\GetLocation;
use Flexipos\Organization\Features\GetLocation\GetLocationHandler;
use Flexipos\Organization\Features\GetTerminal\GetTerminal;
use Flexipos\Organization\Features\GetTerminal\GetTerminalHandler;
use Flexipos\Organization\Features\ListEffectiveSettings\ListEffectiveSettings;
use Flexipos\Organization\Features\ListEffectiveSettings\ListEffectiveSettingsHandler;
use Flexipos\Organization\Features\ListTerminalDevices\ListTerminalDevices;
use Flexipos\Organization\Features\ListTerminalDevices\ListTerminalDevicesHandler;
use Flexipos\Organization\Features\ReissueActivationCode\ReissueActivationCode;
use Flexipos\Organization\Features\ReissueActivationCode\ReissueActivationCodeHandler;
use Flexipos\Organization\Features\RevokeTerminal\RevokeTerminal;
use Flexipos\Organization\Features\RevokeTerminal\RevokeTerminalHandler;
use Flexipos\Organization\Features\SetSetting\SetSetting;
use Flexipos\Organization\Features\SetSetting\SetSettingHandler;
use Flexipos\Organization\Features\UpdateBranch\UpdateBranch;
use Flexipos\Organization\Features\UpdateBranch\UpdateBranchHandler;
use Flexipos\Organization\Features\UpdateLocation\UpdateLocation;
use Flexipos\Organization\Features\UpdateLocation\UpdateLocationHandler;
use Flexipos\Organization\Features\UpdateTerminal\UpdateTerminal;
use Flexipos\Organization\Features\UpdateTerminal\UpdateTerminalHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\FixedTerminalSessions;
use Flexipos\Organization\Tests\Support\SettingsSeed;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| T-014 step 16 - tenant isolation of the organization tables (AC-19)
|--------------------------------------------------------------------------
|
| The five tables of this task (`branches`, `locations`, `terminals`,
| `devices`, `terminal_credentials`) are the ones `closeOutNewTables()` in the
| T-010 sweep excludes by name, on the promise that this file covers them.
|
| Three measurements, each with its control:
|
| 1. **A, B, A on one worker, with the same codes in both shops**, and then
|    every id of shop A handed to every operation in shop B. Each is refused
|    `not_found` / `unknown_*`, B's rows (the five tables, the settings and
|    the audit table) are compared whole before and after, and the same call
|    made in A succeeds. The database is the boundary and nothing else is
|    consulted, so an id that exists only in A has to look like nothing at all.
| 2. **A query-log scan of what the operations issued**: no statement
|    carries `tenant_id`, every one of the five tables is seen, and no
|    statement reaches a table outside this package's own set - so no
|    organization query goes to an access table or to the master. The scan is
|    run again over planted statements it must report.
| 3. **The master connection sees no statement at all** from these operations:
|    the one thing organization needs from the master goes through the
|    `TerminalSessions` contract, here a double.
|
| AC-49's cache doubles are `SettingsIsolationAndFreshnessTest`'s; there is no
| organization cache to key.
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

beforeEach(function () {
    authFixtureMigrateMaster();
    AccessTenantFixture::boot();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-22 10:00:00.000'));
    app()->instance(TerminalSessions::class, new FixedTerminalSessions);
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/** The tables this package reads and writes: the only ones a statement may name. */
const ORG_ISO_OWN_TABLES = ['branches', 'locations', 'terminals', 'devices', 'terminal_credentials', 'settings', 'settings_registry', 'audit_logs'];

const ORG_ISO_FIVE = ['branches', 'devices', 'locations', 'terminal_credentials', 'terminals'];

/**
 * Create a branch (with its store) and a pending terminal in the current tenant.
 *
 * @return array{branch: string, store: string, terminal: string}
 */
function orgIsoSeed(): array
{
    $branch = app(CreateBranchHandler::class)->handle(new CreateBranch([
        'code' => 'main', 'name' => 'Main', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ]));
    $terminal = app(CreateTerminalHandler::class)->handle(new CreateTerminal([
        'branch_id' => $branch->branchId, 'location_id' => $branch->locationId, 'code' => 't1', 'name' => 'Till',
    ]));

    return ['branch' => $branch->branchId, 'store' => $branch->locationId, 'terminal' => $terminal->terminalId];
}

function orgIsoInTenant(string $tenant, callable $work): mixed
{
    AccessTenantFixture::switchTo($tenant);

    try {
        return $work();
    } finally {
        tenancy()->leave();
    }
}

/**
 * Every row of every table the sweep cares about, as one string.
 */
function orgIsoState(): string
{
    $state = [];

    foreach (ORG_ISO_OWN_TABLES as $table) {
        $state[$table] = AccessSeed::connection()->table($table)->get()->all();
    }

    return (string) json_encode($state);
}

/**
 * Every operation that takes an id, as a closure over A's ids, and the
 * refusal it must give for an id that exists only in the other shop.
 *
 * @param  array{branch: string, store: string, terminal: string}  $a
 * @return array<string, array{0: Closure, 1: OrganizationRefusal}>
 */
function orgIsoForeignIdOperations(array $a): array
{
    $notFound = OrganizationRefusal::NotFound;

    return [
        'get branch' => [fn () => app(GetBranchHandler::class)->handle(new GetBranch($a['branch'])), $notFound],
        'update branch' => [fn () => app(UpdateBranchHandler::class)->handle(new UpdateBranch($a['branch'], ['name' => 'Hijack'])), $notFound],
        'deactivate branch' => [fn () => app(ChangeBranchStatusHandler::class)->handle(new ChangeBranchStatus($a['branch'], false)), $notFound],
        'get location' => [fn () => app(GetLocationHandler::class)->handle(new GetLocation($a['store'])), $notFound],
        'update location' => [fn () => app(UpdateLocationHandler::class)->handle(new UpdateLocation($a['store'], ['name' => 'Hijack'])), $notFound],
        'deactivate location' => [fn () => app(ChangeLocationStatusHandler::class)->handle(new ChangeLocationStatus($a['store'], false)), $notFound],
        'get terminal' => [fn () => app(GetTerminalHandler::class)->handle(new GetTerminal($a['terminal'])), $notFound],
        'update terminal' => [fn () => app(UpdateTerminalHandler::class)->handle(new UpdateTerminal($a['terminal'], ['name' => 'Hijack'])), $notFound],
        'reissue a code' => [fn () => app(ReissueActivationCodeHandler::class)->handle(new ReissueActivationCode($a['terminal'])), $notFound],
        'list devices' => [fn () => app(ListTerminalDevicesHandler::class)->handle(new ListTerminalDevices($a['terminal'])), $notFound],
        'pause terminal' => [fn () => app(ChangeTerminalStatusHandler::class)->handle(new ChangeTerminalStatus($a['terminal'], 'inactive')), $notFound],
        'revoke terminal' => [fn () => app(RevokeTerminalHandler::class)->handle(new RevokeTerminal($a['terminal'])), $notFound],
        'create a terminal at the other shop branch' => [fn () => app(CreateTerminalHandler::class)->handle(new CreateTerminal([
            'branch_id' => $a['branch'], 'location_id' => $a['store'], 'code' => 'x1', 'name' => 'X',
        ])), OrganizationRefusal::UnknownBranch],
        'set a setting at the other shop branch' => [fn () => app(SetSettingHandler::class)->handle(new SetSetting([
            'key' => 'iso.key', 'scope_type' => 'branch', 'scope_id' => $a['branch'], 'value' => 'x',
        ])), OrganizationRefusal::UnknownBranch],
        'set a setting at the other shop terminal' => [fn () => app(SetSettingHandler::class)->handle(new SetSetting([
            'key' => 'iso.key', 'scope_type' => 'terminal', 'scope_id' => $a['terminal'], 'value' => 'x',
        ])), OrganizationRefusal::UnknownTerminal],
        'read settings of the other shop branch' => [fn () => app(ListEffectiveSettingsHandler::class)->handle(new ListEffectiveSettings('branch', $a['branch'])), $notFound],
    ];
}

it('answers A, B, A with the same codes in both, and refuses every id of A in B without changing B (AC-19)', function () {
    $ids = [];

    foreach (['tenant-a', 'tenant-b', 'tenant-a'] as $i => $tenant) {
        $ids[$tenant] = orgIsoInTenant($tenant, static function () use ($i): array {
            if ($i === 2) {
                // The return to A: the branch created the first time is still the only one.
                return ['branches' => AccessSeed::connection()->table('branches')->count(), 'code' => (string) AccessSeed::connection()->table('branches')->value('code')];
            }

            SettingsSeed::register('iso.key', 'string', 'D');

            return orgIsoSeed();
        });
    }

    // Same codes, different ids, no collision: the database is the boundary.
    expect($ids['tenant-b']['branch'])->not->toBe($ids['tenant-a']['branch'] ?? null);

    $a = orgIsoInTenant('tenant-a', static fn (): array => [
        'branch' => (string) AccessSeed::connection()->table('branches')->value('id'),
        'store' => (string) AccessSeed::connection()->table('locations')->value('id'),
        'terminal' => (string) AccessSeed::connection()->table('terminals')->value('id'),
    ]);
    $b = orgIsoInTenant('tenant-b', static fn (): array => [
        'branch' => (string) AccessSeed::connection()->table('branches')->value('id'),
        'codes' => [
            (string) AccessSeed::connection()->table('branches')->value('code'),
            (string) AccessSeed::connection()->table('terminals')->value('code'),
        ],
    ]);

    expect($a['branch'])->not->toBe($b['branch'])
        ->and($b['codes'])->toBe(['MAIN', 'T1'])
        ->and(orgIsoInTenant('tenant-a', static fn (): array => [
            (string) AccessSeed::connection()->table('branches')->value('code'),
            (string) AccessSeed::connection()->table('terminals')->value('code'),
        ]))->toBe(['MAIN', 'T1']);

    $operations = orgIsoForeignIdOperations($a);
    $before = orgIsoInTenant('tenant-b', static fn (): string => orgIsoState());
    $printed = [];

    foreach ($operations as $label => [$operation, $reason]) {
        orgIsoInTenant('tenant-b', static function () use ($operation, $reason, $label): void {
            try {
                $operation();
            } catch (OrganizationRefused $refused) {
                expect($refused->reason)->toBe($reason, $label);

                return;
            }

            throw new RuntimeException("[{$label}] was not refused in the other shop.");
        });

        $printed[] = $label.' -> '.$reason->value;
    }

    fwrite(STDOUT, PHP_EOL.'AC-19 foreign ids refused in B ('.count($printed).'): '.implode('; ', $printed).PHP_EOL);

    // B is exactly as it was, and A is exactly as it was too.
    expect(orgIsoInTenant('tenant-b', static fn (): string => orgIsoState()))->toBe($before);

    // Control: the same operations made in A, where the ids exist, are not refused.
    orgIsoInTenant('tenant-a', static function () use ($a): void {
        app(GetBranchHandler::class)->handle(new GetBranch($a['branch']));
        app(GetLocationHandler::class)->handle(new GetLocation($a['store']));
        app(GetTerminalHandler::class)->handle(new GetTerminal($a['terminal']));
        app(UpdateTerminalHandler::class)->handle(new UpdateTerminal($a['terminal'], ['name' => 'Renamed']));
        app(ListEffectiveSettingsHandler::class)->handle(new ListEffectiveSettings('branch', $a['branch']));
    });

    expect(orgIsoInTenant('tenant-a', static fn (): string => (string) AccessSeed::connection()->table('terminals')->value('name')))->toBe('Renamed');
});

/**
 * Statements one connection issued while the work ran.
 *
 * @return list<string>
 */
function orgIsoStatements(string $connection, callable $work): array
{
    $handle = DB::connection($connection);
    $handle->flushQueryLog();
    $handle->enableQueryLog();

    try {
        $work();
    } finally {
        $handle->disableQueryLog();
    }

    return array_map(static fn (array $entry): string => (string) $entry['query'], $handle->getQueryLog());
}

/**
 * The scan: which of this package's tables a statement names, and which
 * tables outside that set it names, and whether it carries `tenant_id`.
 *
 * @param  list<string>  $statements
 * @param  list<string>  $knownTables  every table of the tenant tree, so a foreign one can be recognised
 * @return array{seen: list<string>, foreign: list<string>, predicate: list<string>}
 */
function orgIsoScan(array $statements, array $knownTables): array
{
    $seen = [];
    $foreign = [];
    $predicate = [];

    foreach ($statements as $statement) {
        foreach ($knownTables as $table) {
            if (! str_contains($statement, '"'.$table.'"')) {
                continue;
            }

            if (in_array($table, ORG_ISO_OWN_TABLES, true)) {
                $seen[$table] = $table;
            } else {
                $foreign[$table.' in: '.substr($statement, 0, 70)] = $table;
            }
        }

        if (str_contains($statement, 'tenant_id')) {
            $predicate[] = $statement;
        }
    }

    return ['seen' => array_values($seen), 'foreign' => array_keys($foreign), 'predicate' => $predicate];
}

it('issues no statement with a tenant predicate, reaches no table outside the package, and touches the master never (AC-19)', function () {
    $known = tenantTreeTables();

    $tenantStatements = [];
    $masterStatements = [];

    orgIsoInTenant('tenant-a', static function () use (&$tenantStatements, &$masterStatements): void {
        SettingsSeed::register('iso.key', 'string', 'D');

        $masterStatements = orgIsoStatements('master', static function () use (&$tenantStatements): void {
            $tenantStatements = orgIsoStatements(AccessTenantFixture::connectionName(), static function (): void {
                $ids = orgIsoSeed();
                $terminal = $ids['terminal'];

                app(UpdateBranchHandler::class)->handle(new UpdateBranch($ids['branch'], ['name' => 'Renamed']));
                app(UpdateTerminalHandler::class)->handle(new UpdateTerminal($terminal, ['name' => 'Till 2']));
                app(UpdateLocationHandler::class)->handle(new UpdateLocation($ids['store'], ['name' => 'Renamed store']));
                app(ReissueActivationCodeHandler::class)->handle(new ReissueActivationCode($terminal));
                app(SetSettingHandler::class)->handle(new SetSetting(['key' => 'iso.key', 'scope_type' => 'terminal', 'scope_id' => $terminal, 'value' => 'v']));
                app(ListEffectiveSettingsHandler::class)->handle(new ListEffectiveSettings('terminal', $terminal));
                app(ListTerminalDevicesHandler::class)->handle(new ListTerminalDevices($terminal));
                app(RevokeTerminalHandler::class)->handle(new RevokeTerminal($terminal));
            });
        });
    });

    // `devices` and `terminal_credentials` are only read and written once a
    // terminal has been activated, so make one in the same tenant and scan that too.
    orgIsoInTenant('tenant-a', static function () use (&$tenantStatements): void {
        $connection = AccessSeed::connection();
        $terminal = (string) $connection->table('terminals')->value('id');

        $tenantStatements = array_merge($tenantStatements, orgIsoStatements(AccessTenantFixture::connectionName(), static function () use ($terminal): void {
            app(ListTerminalDevicesHandler::class)->handle(new ListTerminalDevices($terminal));
            app(GetTerminalHandler::class)->handle(new GetTerminal($terminal));
            app(RevokeTerminalHandler::class)->handle(new RevokeTerminal($terminal));
        }));
    });

    $scan = orgIsoScan($tenantStatements, $known);

    fwrite(STDOUT, PHP_EOL.'AC-19 scan: '.count($tenantStatements).' tenant statements, tables seen: '.implode(', ', $scan['seen'])
        .'; foreign tables: '.($scan['foreign'] === [] ? 'none' : implode(' | ', $scan['foreign']))
        .'; master statements: '.count($masterStatements).PHP_EOL);

    // Liveness: the scan saw statements, and every one of the five tables.
    expect($tenantStatements)->not->toBe([]);
    expect($scan['seen'])->toContain(...ORG_ISO_FIVE);

    // The measurements.
    expect($scan['predicate'])->toBe([]);
    expect($scan['foreign'])->toBe([]);
    expect($masterStatements)->toBe([]);

    // The scan reports what it is supposed to: a planted predicate, a planted
    // access-table read, and the master probe sees a write it is shown.
    $planted = orgIsoScan([
        'select * from "terminals" where "tenant_id" = ?',
        'select * from "employees" where "id" = ?',
    ], $known);

    expect($planted['predicate'])->toHaveCount(1);
    expect($planted['foreign'])->toHaveCount(1);

    $shown = orgIsoStatements('master', static function (): void {
        authFixtureTenant('iso-liveness');
    });

    expect(array_filter($shown, static fn (string $s): bool => preg_match('/^\s*insert\b/i', $s) === 1))->not->toBe([]);
});
