<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Tenders;

use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Tenders\MethodType;
use Flexipos\Sales\Tenders\TenderRules;
use Flexipos\Sales\Tests\Support\SalesRefusals;

/*
|--------------------------------------------------------------------------
| T-030 step 9 - TenderRules (AC-11, AC-12, D-41, F-17)
|--------------------------------------------------------------------------
|
| Pure: the method's row, what was offered, and the balance. The row is a plain
| object with the five flags the rules read.
*/

/**
 * @param  array<string, mixed>  $overrides
 */
function tenderMethod(array $overrides = []): object
{
    return (object) array_merge([
        'type' => 'card',
        'is_active' => 1,
        'requires_reference' => 0,
        'allows_change' => 0,
        'allows_partial' => 1,
    ], $overrides);
}

it('gives change: cash tendered 50 against a balance of 45 settles 45 and returns 5', function () {
    $outcome = TenderRules::decide(tenderMethod(['type' => 'cash', 'allows_change' => 1]), '50', null, '45.0000');

    expect($outcome->amount)->toBe('45.0000')
        ->and($outcome->tendered)->toBe('50.0000')
        ->and($outcome->changeGiven)->toBe('5.0000');
});

it('records an exact cash tender with its tendered figure and no change', function () {
    $outcome = TenderRules::decide(tenderMethod(['type' => 'cash', 'allows_change' => 1]), '45', null, '45.0000');

    expect($outcome->amount)->toBe('45.0000')
        ->and($outcome->tendered)->toBe('45.0000')
        ->and($outcome->changeGiven)->toBe('0.0000');
});

it('records null tendered for a method that gives no change', function () {
    $outcome = TenderRules::decide(tenderMethod(), '20', null, '45.0000');

    expect($outcome->amount)->toBe('20.0000')
        ->and($outcome->tendered)->toBeNull()
        ->and($outcome->changeGiven)->toBe('0.0000');
});

it('refuses a card 50 against a balance of 45 as exceeds_balance', function () {
    SalesRefusals::expect(SalesRefusal::ExceedsBalance, fn () => TenderRules::decide(tenderMethod(), '50', null, '45.0000'));
});

it('takes no tender on a sale with nothing owing, even from a method that gives change', function () {
    SalesRefusals::expect(SalesRefusal::ExceedsBalance, fn () => TenderRules::decide(tenderMethod(['type' => 'cash', 'allows_change' => 1]), '5', null, '0.0000'));
});

it('refuses a short amount when the method does not allow part payment', function () {
    SalesRefusals::expect(SalesRefusal::PartialNotAllowed, fn () => TenderRules::decide(tenderMethod(['allows_partial' => 0]), '20', null, '45.0000'));

    // The control: the same method accepts the full balance.
    expect(TenderRules::decide(tenderMethod(['allows_partial' => 0]), '45', null, '45.0000')->amount)->toBe('45.0000');
});

it('refuses a missing or blank reference when the method requires one', function (?string $reference) {
    SalesRefusals::expect(SalesRefusal::ReferenceRequired, fn () => TenderRules::decide(tenderMethod(['requires_reference' => 1]), '10', $reference, '45.0000'));
})->with(['absent' => [null], 'empty' => [''], 'blank' => ['   ']]);

it('accepts the reference when one is given', function () {
    expect(TenderRules::decide(tenderMethod(['requires_reference' => 1]), '10', 'AUTH-1', '45.0000')->amount)->toBe('10.0000');
});

it('refuses loyalty and voucher as unsupported_method_type, and an inactive method as method_inactive', function (string $type) {
    SalesRefusals::expect(SalesRefusal::UnsupportedMethodType, fn () => TenderRules::decide(tenderMethod(['type' => $type]), '10', null, '45.0000'));
})->with(['loyalty' => ['loyalty'], 'voucher' => ['voucher']]);

it('refuses an inactive method', function () {
    SalesRefusals::expect(SalesRefusal::MethodInactive, fn () => TenderRules::decide(tenderMethod(['is_active' => 0]), '10', null, '45.0000'));
});

it('refuses a zero offer as validation', function () {
    SalesRefusals::expect(SalesRefusal::Validation, fn () => TenderRules::decide(tenderMethod(), '0', null, '45.0000'));
});

it('refuses a refund tender onto a method with allows_refund = false as unsupported_method_type (AC-12A)', function () {
    SalesRefusals::expect(SalesRefusal::UnsupportedMethodType, fn () => TenderRules::decide(tenderMethod(['allows_refund' => 0]), '10', null, '45.0000', true));
});

it('does not consult allows_refund on an ordinary sale tender (AC-12A, second clause)', function () {
    // The same method that a refund refuses takes an ordinary tender: a check that ignores the refund flag goes red here.
    expect(TenderRules::decide(tenderMethod(['allows_refund' => 0]), '10', null, '45.0000')->amount)->toBe('10.0000')
        ->and(TenderRules::decide(tenderMethod(['allows_refund' => 0]), '10', null, '45.0000', false)->amount)->toBe('10.0000');
});

it('accepts a refund tender onto a method with allows_refund = true', function () {
    $outcome = TenderRules::decide(tenderMethod(['allows_refund' => 1]), '10', null, '45.0000', true);

    expect($outcome->amount)->toBe('10.0000')
        ->and($outcome->tendered)->toBeNull()
        ->and($outcome->changeGiven)->toBe('0.0000');
});

it('refuses a refund onto an inactive, non-refundable method as unsupported_method_type: the type check comes first', function () {
    SalesRefusals::expect(SalesRefusal::UnsupportedMethodType, fn () => TenderRules::decide(tenderMethod(['allows_refund' => 0, 'is_active' => 0]), '10', null, '45.0000', true));

    // The control: an inactive method that is refundable is still method_inactive.
    SalesRefusals::expect(SalesRefusal::MethodInactive, fn () => TenderRules::decide(tenderMethod(['allows_refund' => 1, 'is_active' => 0]), '10', null, '45.0000', true));
});

it('gives no change on a refund: a cash payout over what is owed back is exceeds_balance, with tendered null', function () {
    $cash = tenderMethod(['type' => 'cash', 'allows_change' => 1, 'allows_refund' => 1]);

    SalesRefusals::expect(SalesRefusal::ExceedsBalance, fn () => TenderRules::decide($cash, '50', null, '45.0000', true));

    // The control: the same method on an ordinary tender gives change.
    expect(TenderRules::decide($cash, '50', null, '45.0000')->changeGiven)->toBe('5.0000');

    // An exact cash refund records no tendered figure, whatever allows_change says.
    $exact = TenderRules::decide($cash, '45', null, '45.0000', true);

    expect($exact->amount)->toBe('45.0000')->and($exact->tendered)->toBeNull()->and($exact->changeGiven)->toBe('0.0000');
});

it('says which method types may be tendered', function () {
    $tenderable = array_filter(MethodType::cases(), static fn (MethodType $type): bool => $type->isTenderable());

    expect(array_map(static fn (MethodType $type): string => $type->value, array_values($tenderable)))
        ->toBe(['cash', 'card', 'digital', 'credit', 'bank', 'other']);
});
