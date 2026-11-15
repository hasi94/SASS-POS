<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\UpdateEmployee;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\UpdateEmployee\UpdateEmployee;
use Flexipos\Access\Features\UpdateEmployee\UpdateEmployeeHandler;
use Flexipos\Access\Support\EmployeeView;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 12 - updating an employee (AC-26, D-32)
|--------------------------------------------------------------------------
|
| D-32's refusal is the whole point of this file and it is asserted as a
| refusal, never as "the value did not change". A silent ignore is
| indistinguishable from a field that was never wired: both leave the stored
| value alone, so a test of the stored value alone cannot tell "protected"
| from "forgotten". Each protected field therefore gets the pair - the
| request is refused with `validation`, AND the stored value is unchanged.
|
| The protected list is read from the handler's own constant rather than
| retyped here, so a sixth protected field added there is covered by this
| test on the day it is added, and a field quietly removed from the list
| fails it (D-27: enumerate from the source of truth, print the domain).
|
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
 */
function updateEmployee(string $employeeId, array $fields): EmployeeView
{
    /** @var UpdateEmployeeHandler $handler */
    $handler = app(UpdateEmployeeHandler::class);

    return $handler->handle(new UpdateEmployee($employeeId, $fields));
}

it('changes the three name fields and bumps nothing (AC-26, AC-52)', function () {
    $actorId = AccessSeed::employee('actor');
    $employeeId = AccessSeed::employee('C001');

    $before = AccessSeed::permVersion($employeeId);

    $view = updateEmployee($employeeId, [
        'first_name' => ' Kamal ',
        'last_name' => ' Silva ',
        'display_name' => 'Kamal S.',
    ]);

    $row = AccessSeed::employeeRow($employeeId);

    expect($view->firstName)->toBe('Kamal')
        ->and($row['first_name'])->toBe('Kamal')
        ->and($row['last_name'])->toBe('Silva')
        ->and($row['display_name'])->toBe('Kamal S.')
        ->and(AccessSeed::permVersion($employeeId))->toBe($before)
        ->and($actorId)->not->toBe($employeeId);
});

it('refuses every protected field by name, leaves the stored value alone, and the list is the handler own (AC-26, D-32)', function () {
    $employeeId = AccessSeed::employee('C001', ['user_id' => (string) Str::ulid()]);
    $before = AccessSeed::employeeRow($employeeId);

    $values = [
        'code' => 'C999',
        'user_id' => (string) Str::ulid(),
        'pin_hash' => 'whatever',
        'perm_version' => 99,
        'status' => 'terminated',
    ];

    // The domain, enumerated from the handler rather than from this file.
    expect(UpdateEmployeeHandler::PROTECTED_FIELDS)->toEqualCanonicalizing(array_keys($values))
        ->and(UpdateEmployeeHandler::PROTECTED_FIELDS)->not->toBeEmpty();

    fwrite(STDOUT, PHP_EOL.'AC-26 protected fields examined: '
        .implode(', ', UpdateEmployeeHandler::PROTECTED_FIELDS).PHP_EOL);

    foreach (UpdateEmployeeHandler::PROTECTED_FIELDS as $field) {
        try {
            updateEmployee($employeeId, ['first_name' => 'Kamal', $field => $values[$field]]);

            expect(false)->toBeTrue("[{$field}] was accepted in an update");
        } catch (AccessRefused $refused) {
            expect($refused->reason)->toBe(RefusalReason::Validation, $field);
        }

        // Nothing from that request was stored - including the first_name
        // that WAS allowed, because the whole request is refused (D-32).
        expect(AccessSeed::employeeRow($employeeId))->toBe($before, $field);
    }

    // The control: the identical request without the protected field.
    expect(updateEmployee($employeeId, ['first_name' => 'Kamal'])->firstName)->toBe('Kamal');
});

it('refuses a field nobody has heard of, a blank first name, and an employee that is not there (AC-26)', function () {
    $employeeId = AccessSeed::employee('C001');
    $deletedId = AccessSeed::employee('C002', ['deleted_at' => now()->format('Y-m-d H:i:s.v')]);

    foreach ([
        [$employeeId, ['nickname' => 'Nim'], 'a field that is not a field'],
        [$employeeId, ['first_name' => '   '], 'a blank first name'],
        [$employeeId, [], 'an update that changes nothing'],
        [$deletedId, ['first_name' => 'Kamal'], 'a soft-deleted employee'],
        [(string) Str::ulid(), ['first_name' => 'Kamal'], 'an employee that never existed'],
    ] as [$id, $fields, $why]) {
        try {
            updateEmployee((string) $id, $fields);

            expect(false)->toBeTrue("[{$why}] was accepted");
        } catch (AccessRefused $refused) {
            expect($refused->reason)->toBe(RefusalReason::Validation, $why);
        }
    }
});

it('clears a last name when it is given as null, which is not the same as omitting it (AC-26)', function () {
    $employeeId = AccessSeed::employee('C001', ['last_name' => 'Silva']);

    updateEmployee($employeeId, ['last_name' => null]);

    expect(AccessSeed::employeeRow($employeeId)['last_name'])->toBeNull();

    updateEmployee($employeeId, ['first_name' => 'Kamal']);

    // Omitted, so untouched: the display name is still whatever it was.
    expect(AccessSeed::employeeRow($employeeId)['last_name'])->toBeNull();
});
