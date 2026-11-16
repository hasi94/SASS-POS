<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\UpdateSupplier;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\CreateSupplier\CreateSupplier;
use Flexipos\Purchasing\Features\CreateSupplier\CreateSupplierHandler;
use Flexipos\Purchasing\Features\UpdateSupplier\UpdateSupplier;
use Flexipos\Purchasing\Features\UpdateSupplier\UpdateSupplierHandler;
use Flexipos\Purchasing\Features\UpdateSupplier\UpdateSupplierResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 9 - UpdateSupplier (AC-4 to AC-6, AC-10 to AC-12, AC-17)
|--------------------------------------------------------------------------
|
| A subset update writes only what differs and a call that changes nothing writes
| nothing at all; the code is immutable; bank details are redacted in the audit row
| but the field is still listed as changed.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/purchasing-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    puEnterTenant();
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
function puUpdateSupplier(array $fields, ?string $actorId): UpdateSupplierResult
{
    /** @var UpdateSupplierHandler $handler */
    $handler = app(UpdateSupplierHandler::class);

    return $handler->handle(new UpdateSupplier($fields, $actorId));
}

/**
 * A supplier made through the real create slice, so its stored shape is the real one.
 *
 * @param  array<string, mixed>  $extra
 * @return array{id: string, actor: string}
 */
function puMadeSupplier(array $extra = []): array
{
    $actor = puActor('maker', [PurchasingPermissions::SUPPLIERS_MANAGE]);

    /** @var CreateSupplierHandler $create */
    $create = app(CreateSupplierHandler::class);
    $result = $create->handle(new CreateSupplier(array_merge(['code' => 'ACME-1', 'name' => 'Acme'], $extra), $actor));

    // The create audit row is the setup's, not the subject's.
    PurchasingSeed::connection()->table('audit_logs')->delete();

    return ['id' => $result->supplierId, 'actor' => $actor];
}

it('AC-10: changes only the submitted fields, moves updated_by and updated_at, and writes one audit row with before, after and the changed fields', function () {
    $made = puMadeSupplier(['phone' => '011', 'tax_no' => 'T1']);
    $editor = puActor('editor', [PurchasingPermissions::SUPPLIERS_MANAGE]);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puUpdateSupplier(['supplier_id' => strtolower($made['id']), 'name' => 'Acme Holdings', 'payment_terms_days' => 45], $editor);

    $row = PurchasingSeed::row('suppliers', $made['id']);

    expect($result->changed())->toBeTrue()
        ->and($result->changedColumns)->toBe(['name', 'payment_terms_days'])
        ->and($row)->toMatchArray([
            'name' => 'Acme Holdings',
            'payment_terms_days' => 45,
            'phone' => '011',
            'tax_no' => 'T1',
            'code' => 'ACME-1',
            'created_by' => $made['actor'],
            'updated_by' => $editor,
            'created_at' => PurchasingSeed::NOW,
            'updated_at' => '2026-11-17 10:00:00.000',
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $editor,
        'module' => PurchasingAuditActions::MODULE,
        'action' => PurchasingAuditActions::SUPPLIER_UPDATED,
        'subject_type' => 'supplier',
        'subject_id' => $made['id'],
    ])
        ->and($audit['before']['name'])->toBe('Acme')
        ->and($audit['after']['name'])->toBe('Acme Holdings')
        ->and($audit['changed_fields'])->toBe(['name', 'payment_terms_days', 'updated_at', 'updated_by']);
});

it('AC-10: a call that changes nothing writes no audit row and does not move updated_at', function () {
    $made = puMadeSupplier(['phone' => '011', 'currency' => 'LKR', 'address' => ['city' => 'Colombo', 'zip' => '00100']]);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $before = PurchasingRefusals::state();

    $result = puUpdateSupplier([
        'supplier_id' => $made['id'],
        'code' => 'acme-1',
        'name' => ' Acme ',
        'phone' => '011',
        'currency' => 'lkr',
        // The same object with its keys in another order is no change.
        'address' => ['zip' => '00100', 'city' => 'Colombo'],
        'credit_limit' => '0',
    ], $made['actor']);

    expect($result->changed())->toBeFalse()
        ->and($result->changedColumns)->toBe([])
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-10: an empty update body changes nothing', function () {
    $made = puMadeSupplier();
    $before = PurchasingRefusals::state();

    expect(puUpdateSupplier(['supplier_id' => $made['id']], $made['actor'])->changed())->toBeFalse()
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-10: a field set to null clears it, and a field left out keeps it', function () {
    $made = puMadeSupplier(['phone' => '011', 'email' => 'a@b.com', 'address' => ['city' => 'Colombo']]);

    $result = puUpdateSupplier(['supplier_id' => $made['id'], 'phone' => null, 'address' => null], $made['actor']);

    $row = PurchasingSeed::row('suppliers', $made['id']);

    expect($result->changedColumns)->toBe(['phone', 'address'])
        ->and($row['phone'])->toBeNull()
        ->and($row['address'])->toBeNull()
        ->and($row['email'])->toBe('a@b.com');
});

it('AC-10: an unknown, malformed or soft-deleted id is not_found or validation, and writes nothing', function () {
    $made = puMadeSupplier();
    $deleted = PurchasingSeed::supplier('GONE', ['deleted_at' => PurchasingSeed::NOW]);

    foreach ([
        [PurchasingRefusal::NotFound, (string) Str::ulid()],
        [PurchasingRefusal::NotFound, $deleted],
        [PurchasingRefusal::Validation, 'not-an-id'],
        [PurchasingRefusal::Validation, null],
        [PurchasingRefusal::Validation, 12],
    ] as [$reason, $id]) {
        $before = PurchasingRefusals::state();

        PurchasingRefusals::expect($reason, fn () => puUpdateSupplier(['supplier_id' => $id, 'name' => 'X'], $made['actor']), var_export($id, true));

        expect(PurchasingRefusals::state())->toBe($before);
    }
});

it('AC-11: refuses a different code as code_immutable and accepts the same code in any case', function () {
    $made = puMadeSupplier();

    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::CodeImmutable, fn () => puUpdateSupplier(['supplier_id' => $made['id'], 'code' => 'ACME-2', 'name' => 'Renamed'], $made['actor']));

    expect(PurchasingRefusals::state())->toBe($before)
        ->and(puUpdateSupplier(['supplier_id' => $made['id'], 'code' => 'Acme-1'], $made['actor'])->changed())->toBeFalse();

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puUpdateSupplier(['supplier_id' => $made['id'], 'code' => '!!'], $made['actor']));
});

it('AC-4 to AC-6: an update is held to the create s field rules', function (string $field, mixed $value, PurchasingRefusal $reason) {
    $made = puMadeSupplier();
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puUpdateSupplier(['supplier_id' => $made['id'], $field => $value], $made['actor']), $field);

    expect(PurchasingRefusals::state())->toBe($before);
})->with([
    'an empty name' => ['name', '', PurchasingRefusal::Validation],
    'a null name' => ['name', null, PurchasingRefusal::Validation],
    'an email that is not one' => ['email', 'nobody', PurchasingRefusal::Validation],
    'a currency of two letters' => ['currency', 'LK', PurchasingRefusal::Validation],
    'terms of -1' => ['payment_terms_days', -1, PurchasingRefusal::Validation],
    'terms of 3651' => ['payment_terms_days', 3651, PurchasingRefusal::Validation],
    'a null limit' => ['credit_limit', null, PurchasingRefusal::Validation],
    'a float limit' => ['credit_limit', 10.5, PurchasingRefusal::Validation],
    'five decimals' => ['credit_limit', '1.00001', PurchasingRefusal::Validation],
    'a limit of 15 digits' => ['credit_limit', '100000000000000', PurchasingRefusal::OutOfRange],
    'an id field' => ['id', 'x', PurchasingRefusal::Validation],
    'a deferred column' => ['payable_account_id', 'x', PurchasingRefusal::Validation],
]);

it('AC-6: changes the credit limit, stored as a four-decimal string', function () {
    $made = puMadeSupplier();

    $result = puUpdateSupplier(['supplier_id' => $made['id'], 'credit_limit' => '2500'], $made['actor']);

    expect($result->supplier['credit_limit'])->toBe('2500.0000')
        ->and($result->changedColumns)->toBe(['credit_limit']);
});

it('AC-12: an order keeps its supplier snapshots when the supplier is edited', function () {
    $made = puMadeSupplier(['tax_no' => 'OLD-TAX', 'payment_terms_days' => 30]);
    $branch = PurchasingSeed::branch();
    $location = PurchasingSeed::location($branch);
    $order = PurchasingSeed::order($made['id'], $branch, $location, [
        'supplier_name' => 'Acme',
        'supplier_tax_no' => 'OLD-TAX',
        'payment_terms_days' => 30,
    ]);

    puUpdateSupplier(['supplier_id' => $made['id'], 'name' => 'Renamed', 'tax_no' => 'NEW-TAX', 'payment_terms_days' => 90], $made['actor']);

    expect(PurchasingSeed::row('purchase_orders', $order))->toMatchArray([
        'supplier_name' => 'Acme',
        'supplier_tax_no' => 'OLD-TAX',
        'payment_terms_days' => 30,
    ]);
});

it('AC-17: a bank details change is redacted in both images and the field is still listed as changed; the same value is no change', function () {
    $made = puMadeSupplier(['bank_details' => ['account' => '111222333']]);

    $same = puUpdateSupplier(['supplier_id' => $made['id'], 'bank_details' => ['account' => '111222333']], $made['actor']);

    expect($same->changed())->toBeFalse()
        ->and(PurchasingSeed::audit())->toBe([]);

    $result = puUpdateSupplier(['supplier_id' => $made['id'], 'bank_details' => ['account' => '999888777']], $made['actor']);

    $audit = PurchasingSeed::onlyAuditRow();
    $encoded = (string) json_encode($audit);

    expect($result->supplier['bank_details'])->toBe(['account' => '999888777'])
        ->and($audit['before']['bank_details'])->toBe('[redacted]')
        ->and($audit['after']['bank_details'])->toBe('[redacted, changed]')
        ->and($audit['changed_fields'])->toContain('bank_details')
        ->and(str_contains($encoded, '111222333'))->toBeFalse()
        ->and(str_contains($encoded, '999888777'))->toBeFalse();
});

it('AC-17: clearing and first setting bank details are changes, and an unrelated edit leaves it out of the changed fields', function () {
    $made = puMadeSupplier();

    puUpdateSupplier(['supplier_id' => $made['id'], 'bank_details' => ['account' => '5']], $made['actor']);
    $set = PurchasingSeed::onlyAuditRow();

    PurchasingSeed::connection()->table('audit_logs')->delete();

    puUpdateSupplier(['supplier_id' => $made['id'], 'name' => 'Other'], $made['actor']);
    $unrelated = PurchasingSeed::onlyAuditRow();

    PurchasingSeed::connection()->table('audit_logs')->delete();

    puUpdateSupplier(['supplier_id' => $made['id'], 'bank_details' => null], $made['actor']);
    $cleared = PurchasingSeed::onlyAuditRow();

    expect($set['before']['bank_details'])->toBeNull()
        ->and($set['after']['bank_details'])->toBe('[redacted, changed]')
        ->and($unrelated['changed_fields'])->not->toContain('bank_details')
        ->and($unrelated['after']['bank_details'])->toBe('[redacted]')
        ->and($cleared['before']['bank_details'])->toBe('[redacted]')
        ->and($cleared['after']['bank_details'])->toBeNull()
        ->and($cleared['changed_fields'])->toContain('bank_details');
});

it('AC-18: refuses a caller without the manage key with the resolver s reason, and writes nothing', function () {
    $made = puMadeSupplier();
    $viewer = puActor('viewer', [PurchasingPermissions::SUPPLIERS_VIEW]);
    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateSupplier(['supplier_id' => $made['id'], 'name' => 'X'], $viewer));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puUpdateSupplier(['supplier_id' => $made['id'], 'name' => 'X'], null));
});

it('AC-86: an employee who is suspended is refused with the resolver s reason', function () {
    $made = puMadeSupplier();
    $suspended = puActor('suspended', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    AccessSeed::connection()->table('employees')->where('id', $suspended)->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateSupplier(['supplier_id' => $made['id'], 'name' => 'X'], $suspended));

    expect($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});
