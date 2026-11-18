<?php

namespace Flexipos\Hr\Tests\Profiles;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Profiles\DateRules;
use Flexipos\Hr\Tests\Support\HrRefusals;

/*
|--------------------------------------------------------------------------
| Date order and the date of birth (T-038 plan step 7; AC-46, D-28)
|--------------------------------------------------------------------------
*/

it('accepts dates in order and equal dates', function () {
    DateRules::assertOrder([
        'joining_date' => '2024-01-10',
        'confirmation_date' => '2024-01-10',
        'probation_end_date' => '2024-04-10',
        'resignation_date' => '2025-01-01',
        'last_working_date' => '2025-01-01',
    ]);

    DateRules::assertOrder(['joining_date' => '2024-01-10']);
    DateRules::assertOrder([]);

    expect(true)->toBeTrue();
});

it('refuses each date one day before joining as date_order naming the pair', function () {
    foreach (DateRules::AFTER_JOINING as $field) {
        $refused = HrRefusals::expect(
            HrRefusal::DateOrder,
            fn () => DateRules::assertOrder(['joining_date' => '2024-01-10', $field => '2024-01-09']),
            $field
        );

        expect($refused->field)->toBe($field)
            ->and($refused->context)->toBe(['earlier' => 'joining_date', 'later' => $field]);
    }

    expect(DateRules::AFTER_JOINING)->toHaveCount(4);
});

it('refuses a last working date before the resignation date', function () {
    $refused = HrRefusals::expect(HrRefusal::DateOrder, fn () => DateRules::assertOrder([
        'joining_date' => '2024-01-10',
        'resignation_date' => '2025-03-02',
        'last_working_date' => '2025-03-01',
    ]));

    expect($refused->context)->toBe(['earlier' => 'resignation_date', 'later' => 'last_working_date']);
});

it('does not compare a pair when either side is absent', function () {
    DateRules::assertOrder(['joining_date' => null, 'confirmation_date' => '1999-01-01']);
    DateRules::assertOrder(['joining_date' => '2024-01-10', 'resignation_date' => null, 'last_working_date' => '2024-01-10']);
    DateRules::assertOrder(['confirmation_date' => '1999-01-01']);

    expect(true)->toBeTrue();
});

it('accepts a date of birth today and refuses tomorrow as validation', function () {
    DateRules::assertNotFuture('2026-11-17', '2026-11-17');
    DateRules::assertNotFuture('1990-05-05', '2026-11-17');

    $refused = HrRefusals::expect(HrRefusal::Validation, fn () => DateRules::assertNotFuture('2026-11-18', '2026-11-17'));

    expect($refused->field)->toBe('dob')
        ->and($refused->getMessage())->not->toContain('2026-11-18');
});

it('moves the date-of-birth window with the clock it is given', function () {
    DateRules::assertNotFuture('2026-11-18', '2026-11-18');

    HrRefusals::expect(HrRefusal::Validation, fn () => DateRules::assertNotFuture('2026-11-18', '2026-11-17'));

    expect(true)->toBeTrue();
});
