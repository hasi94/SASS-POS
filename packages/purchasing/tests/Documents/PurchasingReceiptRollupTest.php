<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Documents;

use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\ReceiptRollup;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/*
|--------------------------------------------------------------------------
| T-034 step 7 - ReceiptRollup (AC-69 to AC-74)
|--------------------------------------------------------------------------
|
| Base to base on the stored four-decimal values, never on `received_quantity`.
| Rows carry the line's full column set so a rollup that read the wrong column
| (the display quantity) gives a different answer from the right one.
*/

/**
 * A line row as the table holds it.
 *
 * @return array<string, mixed>
 */
function puLine(string $quantity, string $factor, string $receivedQuantity, string $receivedBase): array
{
    return [
        'quantity' => $quantity,
        'unit_factor' => $factor,
        'base_quantity' => bcmul($quantity, $factor, 4),
        'received_quantity' => $receivedQuantity,
        'received_base_quantity' => $receivedBase,
    ];
}

function puOutOfRange(callable $call): PurchasingRefused
{
    try {
        $call();
    } catch (PurchasingRefused $refused) {
        return $refused;
    }

    throw new \LogicException('The call was expected to refuse and did not.');
}

it('is partially_received, not received, when ten cartons are counted against ten cases ordered in base units (AC-70)', function () {
    // 10 cases x 24 = 240 base. A delivery of 10 cartons of 12 is 120 base; the
    // display column, mistakenly holding the carton count, already equals the
    // ordered 10, which is exactly what a rollup reading received_quantity would
    // call complete.
    $line = puLine('10.0000', '24.000000', '10.0000', '120.0000');

    expect(ReceiptRollup::status([$line]))->toBe(OrderStatus::PartiallyReceived);

    $line['received_base_quantity'] = '240.0000';

    expect(ReceiptRollup::status([$line]))->toBe(OrderStatus::Received);
});

it('is partially_received with one complete line and one incomplete, whichever comes last (AC-71)', function () {
    $complete = puLine('1.0000', '1.000000', '1.0000', '1.0000');
    $open = puLine('4.0000', '1.000000', '1.0000', '1.0000');

    expect(ReceiptRollup::status([$complete, $open]))->toBe(OrderStatus::PartiallyReceived)
        ->and(ReceiptRollup::status([$open, $complete]))->toBe(OrderStatus::PartiallyReceived)
        ->and(ReceiptRollup::status([$complete, $complete]))->toBe(OrderStatus::Received);
});

it('treats 239.9999 of 240.0000 as incomplete and 240.0000 as complete, exactly (AC-72)', function () {
    expect(ReceiptRollup::isComplete('240.0000', '239.9999'))->toBeFalse()
        ->and(ReceiptRollup::isComplete('240.0000', '240.0000'))->toBeTrue()
        ->and(ReceiptRollup::isComplete('240.0000', '240.0001'))->toBeTrue()
        ->and(ReceiptRollup::status([puLine('240.0000', '1.000000', '240.0000', '239.9999')]))->toBe(OrderStatus::PartiallyReceived)
        ->and(ReceiptRollup::status([puLine('240.0000', '1.000000', '240.0000', '240.0000')]))->toBe(OrderStatus::Received);
});

it('counts an over-receipt as complete (AC-73)', function () {
    expect(ReceiptRollup::status([puLine('10.0000', '1.000000', '12.0000', '12.0000')]))->toBe(OrderStatus::Received);
});

it('is approved when every line is at zero, including after a reversal (AC-69, AC-74)', function () {
    $lines = [puLine('10.0000', '1.000000', '0.0000', '0.0000'), puLine('5.0000', '2.000000', '0.0000', '0.0000')];

    expect(ReceiptRollup::status($lines))->toBe(OrderStatus::Approved)
        ->and(ReceiptRollup::status([]))->toBe(OrderStatus::Approved);
});

it('reads the stand-in s int and float spellings of a stored column the same as the string', function () {
    $line = ['base_quantity' => 240, 'received_base_quantity' => 239.9999];

    expect(ReceiptRollup::status([$line]))->toBe(OrderStatus::PartiallyReceived)
        ->and(ReceiptRollup::status([['base_quantity' => 240.0, 'received_base_quantity' => 240]]))->toBe(OrderStatus::Received);
});

it('refuses a row with no usable column as validation rather than guessing a status', function () {
    $refused = puOutOfRange(fn () => ReceiptRollup::status([['base_quantity' => '1.0000']]));

    expect($refused->code())->toBe('validation');
});

it('applies a delta to a received column, up and down, and refuses a result below zero (AC-74)', function () {
    expect(ReceiptRollup::apply('120.0000', '120.0000'))->toBe('240.0000')
        ->and(ReceiptRollup::apply('240.0000', '-120.0000'))->toBe('120.0000')
        ->and(ReceiptRollup::apply('120.0000', '-120.0000'))->toBe('0.0000')
        ->and(ReceiptRollup::apply(0, '0.0001'))->toBe('0.0001');

    $refused = puOutOfRange(fn () => ReceiptRollup::apply('120.0000', '-120.0001'));

    expect($refused->code())->toBe('out_of_range');
});

it('refuses a delta that would take a received column past DECIMAL(18,4)', function () {
    $refused = puOutOfRange(fn () => ReceiptRollup::apply('99999999999999.9999', '0.0001'));

    expect($refused->code())->toBe('out_of_range');
});
