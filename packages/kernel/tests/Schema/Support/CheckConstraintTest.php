<?php

use Flexipos\Kernel\Schema\Support\CheckConstraint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/**
 * T-027 plan step 2: the `CheckConstraint` helper, ahead of its first real
 * caller (AC-11's `precision` clause).
 */
it('builds the exact statement text, named after the table and column', function () {
    expect(CheckConstraint::statement('units', 'precision', 0, 4))
        ->toBe('ALTER TABLE `units` ADD CONSTRAINT `units_precision_range` CHECK (`precision` BETWEEN 0 AND 4)');
});

it('is a no-op on SQLite, the suite`s own driver', function () {
    expect(Schema::getConnection()->getDriverName())->toBe('sqlite');

    // No exception, and no statement issued: SQLite has no `ADD CONSTRAINT`
    // at all, so attempting it would be a syntax error rather than a no-op.
    CheckConstraint::between('units', 'precision', 0, 4);
});

it('mutation: removing the driver guard makes the SQLite no-op case throw', function () {
    expect(Schema::getConnection()->getDriverName())->toBe('sqlite');

    // This is the mutation the plan names, run directly rather than by
    // editing the source: calling DB::statement() with the raw ALTER on
    // SQLite is exactly what the guard exists to prevent, and it throws.
    expect(fn () => Schema::getConnection()->statement(CheckConstraint::statement('units', 'precision', 0, 4)))
        ->toThrow(Exception::class);
});

it('refuses a table or column name that is not [a-z_]+', function () {
    expect(fn () => CheckConstraint::statement('Units', 'precision', 0, 4))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CheckConstraint::statement('units', 'preci-sion', 0, 4))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CheckConstraint::statement('units; drop table units', 'precision', 0, 4))->toThrow(InvalidArgumentException::class);
});
