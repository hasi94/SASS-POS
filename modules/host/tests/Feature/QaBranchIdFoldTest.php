<?php

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\AssignRole\AssignRole;
use Flexipos\Access\Features\AssignRole\AssignRoleHandler;
use Flexipos\Access\Features\AttachBranch\AttachBranch;
use Flexipos\Access\Features\AttachBranch\AttachBranchHandler;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermission;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermissionHandler;
use Flexipos\Access\Support\BranchScope;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Organization\Tests\Support\SettingsSeed;

/*
|--------------------------------------------------------------------------
| T-014 step 16 - the upper-case fold of a branch id, everywhere (AC-61)
|--------------------------------------------------------------------------
|
| Under `ascii_bin` a lower- and an upper-case spelling of one ULID are two
| strings, so every boundary folds before it compares or stores. This sweep
| submits the lower-case spelling across a **path**, a **body field**, an
| **attachment**, an **assignment scope**, a **grant scope**, a **setting
| scope**, `BranchScope` construction and the permission check's `covers()`,
| and then scans **every `branch_id` column of the tenant database, found
| live**, plus the settings scope ids of branch rows, for a stored value that
| is not upper-case.
|
| Each spelling pair is compared: the lower-case operation gives the identical
| result to the upper-case one, and never a second row. A malformed id (25
| characters, not a ULID) is `validation` on every boundary. The control: the
| scan is run over a database where one stored id was lower-cased by hand (the
| fixture that skips the fold), and reports it.
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
 * Every stored branch id (a `branch_id` column anywhere, or the scope id of a
 * branch-scoped setting) that is not upper-case, and how many were examined.
 *
 * @return array{violations: list<string>, examined: int, columns: list<string>}
 */
function branchFoldScan(): array
{
    $connection = AccessSeed::connection();
    $violations = [];
    $examined = 0;
    $columns = [];

    /** @var list<object{name: string}> $tables */
    $tables = $connection->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name");

    foreach ($tables as $table) {
        /** @var list<object{name: string}> $info */
        $info = $connection->select('pragma table_info("'.$table->name.'")');
        $names = array_map(static fn (object $c): string => $c->name, $info);

        $targets = [];

        if (in_array('branch_id', $names, true)) {
            $targets[] = ['branch_id', null];
        }

        if ($table->name === 'settings') {
            $targets[] = ['scope_id', "scope_type = 'branch'"];
        }

        foreach ($targets as [$column, $where]) {
            $columns[] = $table->name.'.'.$column;
            $filter = ' where "'.$column.'" is not null'.($where === null ? '' : ' and '.$where);

            $examined += (int) $connection->selectOne('select count(*) as n from "'.$table->name.'"'.$filter)->n;

            foreach ($connection->select('select "'.$column.'" as v from "'.$table->name.'"'.$filter) as $row) {
                if ($row->v !== strtoupper((string) $row->v)) {
                    $violations[] = $table->name.'.'.$column.'='.$row->v;
                }
            }
        }
    }

    return ['violations' => $violations, 'examined' => $examined, 'columns' => $columns];
}

it('treats the two spellings of a branch id as one branch on a path, in a body, in an attachment, an assignment, a grant and a setting scope (AC-61)', function () {
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($owner);
    $branch = $at['branch'];
    $lower = strtolower($branch);

    expect($lower)->not->toBe($branch);

    // A path: the detail route and a status route, in both spellings.
    $upperRead = orgHttpCall('GET', '/api/v1/organization/branches/'.$branch, $owner);
    $lowerRead = orgHttpCall('GET', '/api/v1/organization/branches/'.$lower, $owner);

    expect($lowerRead->getStatusCode())->toBe(200);
    expect($lowerRead->json())->toBe($upperRead->json());

    // A body: a terminal and a warehouse created with the lower-case branch id are stored upper-case.
    $terminal = orgHttpCall('POST', '/api/v1/organization/terminals', $owner, ['branch_id' => $lower, 'location_id' => strtolower($at['store']), 'code' => 'f1', 'name' => 'Fold']);
    $warehouse = orgHttpCall('POST', '/api/v1/organization/locations', $owner, ['branch_id' => $lower, 'code' => 'w1', 'name' => 'Fold warehouse']);

    expect([$terminal->getStatusCode(), $warehouse->getStatusCode()])->toBe([201, 201]);

    // A setting scope: the same triple in both spellings is one row, replaced.
    orgHttpRead(static fn () => SettingsSeed::register('fold.key', 'string', 'D'));
    $firstSet = orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'fold.key', 'scope_type' => 'branch', 'scope_id' => $lower, 'value' => 'one']);
    $secondSet = orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'fold.key', 'scope_type' => 'branch', 'scope_id' => $branch, 'value' => 'two']);

    expect([$firstSet->getStatusCode(), $secondSet->getStatusCode()])->toBe([200, 200]);
    expect($firstSet->json('setting_id'))->toBe($secondSet->json('setting_id'));

    // Attachment, assignment and grant scopes, through the slices, with the permission check after.
    $result = orgHttpRead(static function () use ($branch, $lower): array {
        $key = AccessSeed::permission('fold.scoped', true);
        // Holding the scoped key itself, company-wide, so granting it is not an escalation.
        $actor = AccessSeed::actorHolding('fold-actor', ['access.assign', 'fold.scoped']);
        $role = AccessSeed::role('fold_role', ['fold.scoped']);
        $subject = AccessSeed::employee('fold-subject');

        app(AttachBranchHandler::class)->handle(new AttachBranch($subject, $lower, false, $actor));
        app(AssignRoleHandler::class)->handle(new AssignRole($subject, $role, $lower, $actor));
        app(SetEmployeePermissionHandler::class)->handle(new SetEmployeePermission($subject, $key, true, $lower, $actor));

        $connection = AccessSeed::connection();
        $resolver = app(PermissionResolver::class);

        return [
            'attached' => $connection->table('employee_branches')->where('employee_id', $subject)->pluck('branch_id')->all(),
            'assigned' => $connection->table('employee_roles')->where('employee_id', $subject)->pluck('branch_id')->all(),
            'granted' => $connection->table('employee_permissions')->where('employee_id', $subject)->pluck('branch_id')->all(),
            'covers lower' => $resolver->decide($subject, 'fold.scoped', $lower)->allowed,
            'covers upper' => $resolver->decide($subject, 'fold.scoped', $branch)->allowed,
        ];
    });

    expect($result)->toBe([
        'attached' => [$branch],
        'assigned' => [$branch],
        'granted' => [$branch],
        'covers lower' => true,
        'covers upper' => true,
    ]);

    // BranchScope construction folds, and passes null through.
    expect(BranchScope::requireWellFormed($lower))->toBe($branch);
    expect(BranchScope::requireWellFormed($branch))->toBe($branch);
    expect(BranchScope::requireWellFormed(null))->toBeNull();

    // The scan: every stored branch id is upper-case, over a non-empty domain that includes these tables.
    $scan = orgHttpRead(static fn (): array => branchFoldScan());

    fwrite(STDOUT, PHP_EOL.'AC-61 scanned '.$scan['examined'].' stored branch ids in: '.implode(', ', $scan['columns']).PHP_EOL);

    expect($scan['examined'])->toBeGreaterThan(8);

    foreach (['employee_branches.branch_id', 'employee_roles.branch_id', 'employee_permissions.branch_id', 'locations.branch_id', 'terminals.branch_id', 'audit_logs.branch_id', 'settings.scope_id'] as $column) {
        expect(in_array($column, $scan['columns'], true))->toBeTrue("the scan does not cover {$column}");
    }

    expect($scan['violations'])->toBe([]);

    // Control (the fixture that skips the fold): lower-case one stored id and the same scan reports it.
    orgHttpRead(static function (): void {
        AccessSeed::connection()->statement('update "audit_logs" set "branch_id" = lower("branch_id") where "branch_id" is not null');
    });

    $reported = orgHttpRead(static fn (): array => branchFoldScan()['violations']);

    expect($reported)->not->toBe([]);
    expect(str_starts_with($reported[0], 'audit_logs.branch_id='))->toBeTrue();
});

it('refuses a branch id that is not a 26-character ULID as validation on every boundary (AC-61)', function () {
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($owner);
    $short = substr($at['branch'], 0, 25);

    $responses = [
        'path' => orgHttpCall('GET', '/api/v1/organization/branches/'.$short, $owner),
        'body terminal' => orgHttpCall('POST', '/api/v1/organization/terminals', $owner, ['branch_id' => $short, 'location_id' => $at['store'], 'code' => 'z1', 'name' => 'Z']),
        'body warehouse' => orgHttpCall('POST', '/api/v1/organization/locations', $owner, ['branch_id' => 'not-a-ulid', 'code' => 'w9', 'name' => 'Z']),
        'setting scope' => orgHttpCall('PUT', '/api/v1/organization/settings', $owner, ['key' => 'k', 'scope_type' => 'branch', 'scope_id' => $short, 'value' => 'v']),
    ];

    foreach ($responses as $label => $response) {
        expect($response->getStatusCode())->toBe(422, $label);
        expect($response->json('error'))->toBe('validation', $label);
    }

    expect(fn () => BranchScope::requireWellFormed($short))->toThrow(AccessRefused::class);

    // Control: the full-length spelling of the same id is accepted on the path.
    expect(orgHttpCall('GET', '/api/v1/organization/branches/'.$at['branch'], $owner)->getStatusCode())->toBe(200);
});
