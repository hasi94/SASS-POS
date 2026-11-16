<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Shifts;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Shifts\MovementType;
use Flexipos\Sales\Shifts\ShiftTotals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 9 - ShiftTotals' expected cash (AC-34; D-39/F-15)
|--------------------------------------------------------------------------
|
| `expected = opening + cash tenders + cash_in + float_in - cash_out - float_out
| - drop - petty_cash - bank_deposit`; a refund's tender is negative; voided and
| held sales, and every non-cash tender, are left out.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/sales-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    salesEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * A shift with a 100.0000 float, plus what a till needs to hang sales on it.
 *
 * @return array{shift: string, employee: string, branch: string, location: string, cash: string, card: string}
 */
function shiftWorld(): array
{
    $branch = SalesSeed::branch();
    $terminal = SalesSeed::terminal($branch);
    $employee = SalesSeed::employee('cashier');

    return [
        'shift' => SalesSeed::shift($terminal['id'], $branch, $employee, 'SH000001'),
        'employee' => $employee,
        'branch' => $branch,
        'location' => $terminal['location'],
        'cash' => SalesSeed::paymentMethod('CASH', ['type' => 'cash']),
        'card' => SalesSeed::paymentMethod('CARD', ['type' => 'card']),
    ];
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $overrides
 */
function shiftSale(array $w, string $status, array $overrides = []): string
{
    return SalesSeed::sale($w['branch'], $w['location'], $w['employee'], array_merge(['status' => $status, 'shift_id' => $w['shift']], $overrides));
}

function expectedCash(string $shiftId): string
{
    return ShiftTotals::expectedCash(SalesSeed::connection(), $shiftId);
}

it('is the opening cash on a shift with no activity', function () {
    $w = shiftWorld();

    expect(expectedCash($w['shift']))->toBe('100.0000');
});

it('adds and subtracts every movement type by the sign table', function (string $type, string $expected) {
    $w = shiftWorld();

    SalesSeed::movement($w['shift'], $w['employee'], $type, '30.0000');

    expect(expectedCash($w['shift']))->toBe($expected);
})->with([
    'cash_in adds' => ['cash_in', '130.0000'],
    'float_in adds' => ['float_in', '130.0000'],
    'cash_out subtracts' => ['cash_out', '70.0000'],
    'float_out subtracts' => ['float_out', '70.0000'],
    'drop subtracts' => ['drop', '70.0000'],
    'petty_cash subtracts' => ['petty_cash', '70.0000'],
    'bank_deposit subtracts' => ['bank_deposit', '70.0000'],
]);

it('puts every movement type on one side of the sign table', function () {
    $adding = array_filter(MovementType::cases(), static fn (MovementType $type): bool => $type->addsToDrawer());

    expect(array_map(static fn (MovementType $type): string => $type->value, array_values($adding)))->toBe(['cash_in', 'float_in'])
        ->and(MovementType::values())->toHaveCount(7);
});

it('adds the cash tenders of a completed sale, by amount and never by tendered', function () {
    $w = shiftWorld();
    $sale = shiftSale($w, 'completed');

    // 45 settles the sale; 50 was handed over and 5 went back out of the drawer.
    SalesSeed::tender($sale, $w['cash'], 'cash', '45.0000', ['tendered' => '50.0000', 'change_given' => '5.0000']);

    expect(expectedCash($w['shift']))->toBe('145.0000');
});

it('leaves out a card tender, which never touches the drawer', function () {
    $w = shiftWorld();

    SalesSeed::tender(shiftSale($w, 'completed'), $w['card'], 'card', '45.0000');

    expect(expectedCash($w['shift']))->toBe('100.0000');
});

it('subtracts a refund, whose tender is stored negative', function () {
    $w = shiftWorld();

    SalesSeed::tender(shiftSale($w, 'completed'), $w['cash'], 'cash', '45.0000');
    SalesSeed::tender(shiftSale($w, 'completed', ['type' => 'refund']), $w['cash'], 'cash', '-20.0000');

    expect(expectedCash($w['shift']))->toBe('125.0000');
});

it('counts a refunded and a partially refunded sale, which took the money', function () {
    $w = shiftWorld();

    SalesSeed::tender(shiftSale($w, 'refunded'), $w['cash'], 'cash', '10.0000');
    SalesSeed::tender(shiftSale($w, 'partially_refunded'), $w['cash'], 'cash', '5.0000');

    expect(expectedCash($w['shift']))->toBe('115.0000');
});

it('leaves out voided, held, draft and pending sales (the held row is mutation M2)', function (string $status) {
    $w = shiftWorld();

    SalesSeed::tender(shiftSale($w, $status), $w['cash'], 'cash', '45.0000');

    expect(expectedCash($w['shift']))->toBe('100.0000');
})->with(['voided' => ['voided'], 'held' => ['held'], 'draft' => ['draft'], 'pending_payment' => ['pending_payment']]);

it('counts only the named shift', function () {
    $w = shiftWorld();
    $terminal = SalesSeed::terminal($w['branch'], 'T2');
    $otherShift = SalesSeed::shift($terminal['id'], $w['branch'], $w['employee'], 'SH000002');

    SalesSeed::tender(SalesSeed::sale($w['branch'], $w['location'], $w['employee'], ['status' => 'completed', 'shift_id' => $otherShift]), $w['cash'], 'cash', '77.0000');
    SalesSeed::movement($otherShift, $w['employee'], 'cash_in', '9.0000');

    expect(expectedCash($w['shift']))->toBe('100.0000')
        ->and(expectedCash($otherShift))->toBe('186.0000');
});

it('is every part at once', function () {
    $w = shiftWorld();

    SalesSeed::tender(shiftSale($w, 'completed'), $w['cash'], 'cash', '45.0000');
    SalesSeed::tender(shiftSale($w, 'completed', ['type' => 'refund']), $w['cash'], 'cash', '-5.0000');
    SalesSeed::movement($w['shift'], $w['employee'], 'cash_in', '20.0000');
    SalesSeed::movement($w['shift'], $w['employee'], 'drop', '50.0000');

    // 100 + 45 - 5 + 20 - 50
    expect(expectedCash($w['shift']))->toBe('110.0000');
});
