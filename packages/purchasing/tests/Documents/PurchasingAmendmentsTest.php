<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Documents;

use Flexipos\Purchasing\Documents\Amendments;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/*
|--------------------------------------------------------------------------
| T-034 step 7 - Amendments (AC-60, AC-61, AC-63, AC-66)
|--------------------------------------------------------------------------
|
| A full field-by-status dataset: the answer for every order status and every
| field, written out here independently of the class.
*/

/**
 * The refusal code a call ends in, or `applied` when it returns.
 */
function puAmendmentOutcome(callable $call): string
{
    try {
        $call();
    } catch (PurchasingRefused $refused) {
        return $refused->code();
    }

    return 'applied';
}

it('answers every header field in every order status (AC-60, AC-61, AC-63, AC-66)', function (string $status, string $field, string $expected) {
    expect(puAmendmentOutcome(fn () => Amendments::assertHeader(OrderStatus::from($status), [$field])))->toBe($expected);
})->with(function () {
    $fields = ['supplier_id', 'location_id', 'order_date', 'currency', 'exchange_rate', 'shipping', 'notes', 'terms', 'expected_date'];
    $amendable = ['notes', 'terms', 'expected_date'];

    foreach ($fields as $field) {
        yield "draft: {$field}" => ['draft', $field, 'applied'];
        yield "pending_approval: {$field}" => ['pending_approval', $field, 'locked'];
        yield "sent: {$field}" => ['sent', $field, 'locked'];

        foreach (['approved', 'partially_received'] as $status) {
            yield "{$status}: {$field}" => [$status, $field, in_array($field, $amendable, true) ? 'applied' : 'locked'];
        }

        foreach (['received', 'closed', 'cancelled'] as $status) {
            yield "{$status}: {$field}" => [$status, $field, 'order_closed'];
        }
    }
});

it('answers every line field, in every status, for an incomplete and a complete line (AC-60, AC-61, AC-63, AC-66)', function (string $status, string $field, bool $complete, string $expected) {
    expect(puAmendmentOutcome(fn () => Amendments::assertLine(OrderStatus::from($status), [$field], $complete)))->toBe($expected);
})->with(function () {
    $fields = ['quantity', 'unit_id', 'unit_cost', 'discount_percent', 'tax_group_id', 'description', 'expected_date'];

    foreach ($fields as $field) {
        foreach ([false, true] as $complete) {
            $tag = $complete ? 'complete' : 'open';

            yield "draft: {$field}, {$tag}" => ['draft', $field, $complete, 'applied'];
            yield "pending_approval: {$field}, {$tag}" => ['pending_approval', $field, $complete, 'locked'];
            yield "approved: {$field}, {$tag}" => ['approved', $field, $complete, $field === 'expected_date' ? 'applied' : 'locked'];
            yield "partially_received: {$field}, {$tag}" => ['partially_received', $field, $complete, $field === 'expected_date' && ! $complete ? 'applied' : 'locked'];

            foreach (['received', 'closed', 'cancelled'] as $status) {
                yield "{$status}: {$field}, {$tag}" => [$status, $field, $complete, 'order_closed'];
            }
        }
    }
});

it('allows adding or removing a line in a draft only (AC-61, AC-49)', function (string $status, string $expected) {
    expect(puAmendmentOutcome(fn () => Amendments::assertLineSetChange(OrderStatus::from($status))))->toBe($expected);
})->with([
    'draft' => ['draft', 'applied'],
    'pending_approval' => ['pending_approval', 'locked'],
    'approved' => ['approved', 'locked'],
    'sent' => ['sent', 'locked'],
    'partially_received' => ['partially_received', 'locked'],
    'received' => ['received', 'order_closed'],
    'closed' => ['closed', 'order_closed'],
    'cancelled' => ['cancelled', 'order_closed'],
]);

it('refuses a mixed edit when any one field is not amendable, naming it and, when approved, reopen (AC-61)', function () {
    try {
        Amendments::assertHeader(OrderStatus::Approved, ['notes', 'shipping', 'currency']);
        $refused = null;
    } catch (PurchasingRefused $refused) {
    }

    expect($refused)->toBeInstanceOf(PurchasingRefused::class)
        ->and($refused->code())->toBe('locked')
        ->and($refused->getMessage())->toContain('shipping, currency')
        ->and($refused->getMessage())->not->toContain('changed: notes')
        ->and($refused->getMessage())->toContain('Reopen');
});

it('names no reopen for a partly received order, which cannot be reopened (AC-62, AC-63)', function () {
    try {
        Amendments::assertHeader(OrderStatus::PartiallyReceived, ['shipping']);
        $refused = null;
    } catch (PurchasingRefused $refused) {
    }

    expect($refused)->toBeInstanceOf(PurchasingRefused::class)
        ->and($refused->code())->toBe('locked')
        ->and($refused->getMessage())->toContain('shipping')
        ->and($refused->getMessage())->not->toContain('Reopen');
});

it('lets notes, terms and the header expected_date change together on an approved order (AC-61)', function () {
    Amendments::assertHeader(OrderStatus::Approved, ['notes', 'terms', 'expected_date']);
    Amendments::assertHeader(OrderStatus::PartiallyReceived, ['notes', 'terms', 'expected_date']);

    expect(true)->toBeTrue();
});
