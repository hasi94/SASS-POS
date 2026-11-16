<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\CreateSupplier;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\CreateSupplier\CreateSupplier;
use Flexipos\Purchasing\Features\CreateSupplier\CreateSupplierHandler;
use Flexipos\Purchasing\Features\CreateSupplier\CreateSupplierResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Money;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 9 - CreateSupplier (AC-1 to AC-9, AC-17, AC-18)
|--------------------------------------------------------------------------
|
| Server-generated id; every boundary of AC-2, AC-4, AC-5 and AC-6; the duplicate
| code against an active, an inactive and a soft-deleted holder; a retried create
| is a duplicate, never a replay; bank details never reach the audit row. Every
| refusal row compares the whole of every table a write can touch.
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
function puCreateSupplier(array $fields, ?string $actorId): CreateSupplierResult
{
    /** @var CreateSupplierHandler $handler */
    $handler = app(CreateSupplierHandler::class);

    return $handler->handle(new CreateSupplier($fields, $actorId));
}

function puSupplierManager(string $code = 'manager'): string
{
    return puActor($code, [PurchasingPermissions::SUPPLIERS_MANAGE]);
}

/**
 * Refuse the create with this reason, and prove nothing changed anywhere.
 *
 * @param  array<string, mixed>  $fields
 */
function puCreateRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puCreateSupplier($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-1: creates a supplier with a server id, the code folded to upper case, the defaults, the actor and one audit row', function () {
    $actor = puSupplierManager();

    $result = puCreateSupplier(['code' => 'acme-1', 'name' => '  Acme Ltd '], strtolower($actor));

    $row = PurchasingSeed::row('suppliers', $result->supplierId);

    expect($result->supplierId)->toMatch('/^[0-9A-Z]{26}$/D')
        ->and($row)->toMatchArray([
            'id' => $result->supplierId,
            'code' => 'ACME-1',
            'name' => 'Acme Ltd',
            'is_active' => 1,
            'payment_terms_days' => 0,
            'deleted_at' => null,
            'created_by' => $actor,
            'updated_by' => $actor,
            'created_at' => PurchasingSeed::NOW,
            'updated_at' => PurchasingSeed::NOW,
            'legal_name' => null,
            'email' => null,
            'address' => null,
            'bank_details' => null,
        ])
        ->and(Money::of($row['credit_limit']))->toBe('0.0000')
        ->and($result->supplier['code'])->toBe('ACME-1')
        ->and($result->supplier['credit_limit'])->toBe('0.0000')
        ->and($result->supplier['is_active'])->toBeTrue();

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $actor,
        'branch_id' => null,
        'module' => PurchasingAuditActions::MODULE,
        'action' => PurchasingAuditActions::SUPPLIER_CREATED,
        'subject_type' => 'supplier',
        'subject_id' => $result->supplierId,
        'before' => null,
    ])
        ->and($audit['after']['code'])->toBe('ACME-1')
        ->and($audit['changed_fields'])->toContain('code');
});

it('Q-B3 and AC-1: refuses a caller-supplied id, and every deferred column, as an unknown field', function (string $field, mixed $value) {
    puCreateRefused(PurchasingRefusal::Validation, ['code' => 'A1', 'name' => 'A', $field => $value], puSupplierManager(), $field);
})->with([
    'id' => ['id', '01JABCDEFGHJKMNPQRSTVWXYZ0'],
    'opening_balance' => ['opening_balance', '5.0000'],
    'custom_fields' => ['custom_fields', []],
    'is_active' => ['is_active', false],
    'tenant_id' => ['tenant_id', 'x'],
]);

it('AC-2: accepts a 30-character code and refuses 31, an empty, a missing and a malformed one', function (mixed $code, bool $accepted) {
    $actor = puSupplierManager();

    if ($accepted) {
        $result = puCreateSupplier(['code' => $code, 'name' => 'N'], $actor);

        expect($result->supplier['code'])->toBe(strtoupper((string) $code));

        return;
    }

    puCreateRefused(PurchasingRefusal::Validation, ['code' => $code, 'name' => 'N'], $actor);
})->with([
    '30 characters' => [str_repeat('a', 30), true],
    'dot, underscore and hyphen' => ['a.b_c-d', true],
    'starts with a digit' => ['9abc', true],
    '31 characters' => [str_repeat('a', 31), false],
    'empty' => ['', false],
    'blank' => ['   ', false],
    'null' => [null, false],
    'an int' => [5, false],
    'starts with a hyphen' => ['-abc', false],
    'starts with a dot' => ['.abc', false],
    'a space inside' => ['ab c', false],
    'a slash' => ['ab/c', false],
    'a non-ASCII letter' => ['abé', false],
]);

it('AC-2: refuses a missing code', function () {
    puCreateRefused(PurchasingRefusal::Validation, ['name' => 'N'], puSupplierManager());
});

it('AC-3: refuses a duplicate code against an active, an inactive and a soft-deleted holder, in either case, naming the holder', function (array $holder) {
    $actor = puSupplierManager();
    $holderId = PurchasingSeed::supplier('H', array_merge(['code' => 'ACME-1'], $holder));

    foreach (['ACME-1', 'acme-1', 'Acme-1'] as $submitted) {
        $before = PurchasingRefusals::state();

        $refused = PurchasingRefusals::expect(PurchasingRefusal::DuplicateCode, fn () => puCreateSupplier(['code' => $submitted, 'name' => 'Second'], $actor), $submitted);

        expect($refused->getMessage())->toContain($holderId)
            ->and(PurchasingRefusals::state())->toBe($before);
    }
})->with([
    'an active holder' => [[]],
    'an inactive holder' => [['is_active' => false]],
    'a soft-deleted holder' => [['deleted_at' => PurchasingSeed::NOW]],
]);

it('AC-4: accepts the boundary lengths, folds the case and trims', function () {
    $actor = puSupplierManager();
    // 64 + 1 + 126 = 191 characters: the longest address the column holds.
    $email = str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 62);

    expect(strlen($email))->toBe(191);

    $result = puCreateSupplier([
        'code' => 'B1',
        'name' => str_repeat('n', 150),
        'legal_name' => str_repeat('l', 150),
        'contact_name' => str_repeat('c', 100),
        'phone' => str_repeat('1', 30),
        'email' => strtoupper($email),
        'tax_no' => ' vat-9 ',
        'currency' => 'lkr',
        'notes' => str_repeat('x', 2000),
    ], $actor);

    expect($result->supplier)->toMatchArray([
        'name' => str_repeat('n', 150),
        'email' => $email,
        'tax_no' => 'VAT-9',
        'currency' => 'LKR',
    ])
        ->and(strlen((string) $result->supplier['notes']))->toBe(2000);
});

it('AC-4: refuses each over-long or malformed field naming nothing written', function (string $field, mixed $value) {
    puCreateRefused(PurchasingRefusal::Validation, ['code' => 'V1', 'name' => 'N', $field => $value], puSupplierManager(), $field);
})->with([
    'a name over 150' => ['name', str_repeat('n', 151)],
    'an empty name' => ['name', ''],
    'a legal_name over 150' => ['legal_name', str_repeat('l', 151)],
    'a contact_name over 100' => ['contact_name', str_repeat('c', 101)],
    'a phone over 30' => ['phone', str_repeat('1', 31)],
    'an email of 192' => ['email', str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63)],
    'an email with no at' => ['email', 'nobody.example.com'],
    'an email with two ats' => ['email', 'a@@b.com'],
    'a tax_no over 50' => ['tax_no', str_repeat('t', 51)],
    'a two-letter currency' => ['currency', 'LK'],
    'a four-letter currency' => ['currency', 'LKRR'],
    'a numeric currency' => ['currency', '123'],
    'notes over 2000' => ['notes', str_repeat('x', 2001)],
    'an address that is a list' => ['address', ['a', 'b']],
    'an address that is a string' => ['address', 'Colombo'],
    'bank_details that is a list' => ['bank_details', [1, 2]],
    'bank_details that is a number' => ['bank_details', 12],
    'a name that is an array' => ['name', ['x']],
]);

it('AC-4: stores an address and bank details as JSON objects, and an empty object as {}', function () {
    $actor = puSupplierManager();

    $result = puCreateSupplier([
        'code' => 'J1',
        'name' => 'Json',
        'address' => ['line1' => '1 Galle Road', 'city' => 'Colombo'],
        'bank_details' => [],
    ], $actor);

    $row = PurchasingSeed::row('suppliers', $result->supplierId);

    expect(json_decode((string) $row['address'], true))->toBe(['line1' => '1 Galle Road', 'city' => 'Colombo'])
        ->and(json_decode((string) $row['bank_details'], false))->toEqual(new \stdClass)
        ->and($result->supplier['bank_details'])->toBe([]);
});

it('AC-5: accepts 0 and 3650 and defaults to 0, and refuses -1, 3651, a decimal, a string and null', function () {
    $actor = puSupplierManager();

    $zero = puCreateSupplier(['code' => 'T0', 'name' => 'N', 'payment_terms_days' => 0], $actor);
    $max = puCreateSupplier(['code' => 'T1', 'name' => 'N', 'payment_terms_days' => 3650], $actor);
    $omitted = puCreateSupplier(['code' => 'T2', 'name' => 'N'], $actor);

    expect($zero->supplier['payment_terms_days'])->toBe(0)
        ->and($max->supplier['payment_terms_days'])->toBe(3650)
        ->and($omitted->supplier['payment_terms_days'])->toBe(0);

    foreach ([-1, 3651, 2.5, '30', 'abc', null, true] as $bad) {
        puCreateRefused(PurchasingRefusal::Validation, ['code' => 'T3', 'name' => 'N', 'payment_terms_days' => $bad], $actor, var_export($bad, true));
    }
});

it('AC-6: stores credit_limit as DECIMAL(18,4), "2500" as "2500.0000", and omitted as "0.0000"', function () {
    $actor = puSupplierManager();

    $whole = puCreateSupplier(['code' => 'C1', 'name' => 'N', 'credit_limit' => '2500'], $actor);
    $int = puCreateSupplier(['code' => 'C2', 'name' => 'N', 'credit_limit' => 7], $actor);
    $fraction = puCreateSupplier(['code' => 'C3', 'name' => 'N', 'credit_limit' => '0.5'], $actor);
    $widest = puCreateSupplier(['code' => 'C4', 'name' => 'N', 'credit_limit' => '99999999999999'], $actor);
    $omitted = puCreateSupplier(['code' => 'C5', 'name' => 'N'], $actor);

    expect($whole->supplier['credit_limit'])->toBe('2500.0000')
        ->and($int->supplier['credit_limit'])->toBe('7.0000')
        ->and($fraction->supplier['credit_limit'])->toBe('0.5000')
        ->and($widest->supplier['credit_limit'])->toBe('99999999999999.0000')
        ->and($omitted->supplier['credit_limit'])->toBe('0.0000')
        ->and(Money::of(PurchasingSeed::row('suppliers', $whole->supplierId)['credit_limit']))->toBe('2500.0000');
});

it('AC-6: refuses an explicit null, a float, a negative, five decimals and text as validation, and 15 integer digits as out_of_range', function () {
    $actor = puSupplierManager();

    foreach ([null, 2500.5, -1, '-1', '1.00001', 'abc', '', '1e3', [], true] as $bad) {
        puCreateRefused(PurchasingRefusal::Validation, ['code' => 'C9', 'name' => 'N', 'credit_limit' => $bad], $actor, var_export($bad, true));
    }

    puCreateRefused(PurchasingRefusal::OutOfRange, ['code' => 'C9', 'name' => 'N', 'credit_limit' => '100000000000000'], $actor, '15 digits');
    puCreateRefused(PurchasingRefusal::OutOfRange, ['code' => 'C9', 'name' => 'N', 'credit_limit' => '100000000000000.0001'], $actor, '15 digits and a fraction');
});

it('AC-7 and AC-8: credit terms are only data, and two suppliers may share a name and a tax number', function () {
    $actor = puSupplierManager();

    $first = puCreateSupplier(['code' => 'D1', 'name' => 'Same', 'tax_no' => 'T-1', 'credit_limit' => '10', 'payment_terms_days' => 30], $actor);
    $second = puCreateSupplier(['code' => 'D2', 'name' => 'Same', 'tax_no' => 't-1'], $actor);

    expect($first->supplierId)->not->toBe($second->supplierId)
        ->and($second->supplier['tax_no'])->toBe('T-1');
});

it('AC-9: a retried create is a duplicate_code naming the first row, with one audit row in total', function () {
    $actor = puSupplierManager();
    $fields = ['code' => 'RETRY', 'name' => 'Once'];

    $first = puCreateSupplier($fields, $actor);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::DuplicateCode, fn () => puCreateSupplier($fields, $actor));

    expect($refused->getMessage())->toContain($first->supplierId)
        ->and(PurchasingSeed::connection()->table('suppliers')->count())->toBe(1)
        ->and(PurchasingSeed::audit())->toHaveCount(1);
});

it('AC-17: bank details reach neither the audit row nor the refusal, and the row still lists the field as changed', function () {
    $actor = puSupplierManager();

    $result = puCreateSupplier([
        'code' => 'BANK',
        'name' => 'Bank',
        'bank_details' => ['account' => '1234567890', 'iban' => 'LK00SECRET'],
    ], $actor);

    $audit = PurchasingSeed::onlyAuditRow();
    $encoded = (string) json_encode($audit);

    expect($audit['after']['bank_details'])->toBe('[redacted, changed]')
        ->and($audit['changed_fields'])->toContain('bank_details')
        ->and(str_contains($encoded, '1234567890'))->toBeFalse()
        ->and(str_contains($encoded, 'SECRET'))->toBeFalse()
        ->and($result->supplier['bank_details'])->toBe(['account' => '1234567890', 'iban' => 'LK00SECRET']);

    $refused = PurchasingRefusals::capture(fn () => puCreateSupplier(['code' => 'BANK', 'name' => 'Again', 'bank_details' => ['account' => '1234567890']], $actor));

    expect(str_contains($refused->getMessage(), '1234567890'))->toBeFalse();
});

it('AC-18: refuses a caller without the manage key, with the resolver s reason as the cause, and writes nothing', function (string $who) {
    $actor = match ($who) {
        'view only' => puActor('viewer', [PurchasingPermissions::SUPPLIERS_VIEW]),
        'an order key only' => puActor('orders', [PurchasingPermissions::ORDERS_MANAGE]),
        default => puActor('nobody', []),
    };

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCreateSupplier(['code' => 'N1', 'name' => 'N'], $actor));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'view only' => ['view only'],
    'an order key only' => ['an order key only'],
    'no role' => ['no role'],
]);

it('AC-18: the supplier key is asked with no branch, so a company-wide holder is never refused branch_required', function () {
    $result = puCreateSupplier(['code' => 'NB', 'name' => 'N'], puSupplierManager());

    expect($result->supplier['code'])->toBe('NB');
});

it('AC-18 and AC-86: refuses a missing actor and an unavailable employee, and writes nothing', function () {
    puCreateRefused(PurchasingRefusal::Validation, ['code' => 'X1', 'name' => 'N'], null);
    puCreateRefused(PurchasingRefusal::Validation, ['code' => 'X1', 'name' => 'N'], 'not-a-ulid');
    puCreateRefused(PurchasingRefusal::NotAuthorised, ['code' => 'X1', 'name' => 'N'], '01JZZZZZZZZZZZZZZZZZZZZZZZ');
});
