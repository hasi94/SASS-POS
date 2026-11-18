<?php

namespace Flexipos\Hr\Tests\Contracts;

use Flexipos\Contracts\Access\EmployeeIdentity;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Hr\DirectReports;
use Flexipos\Contracts\Hr\HrReferrer;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| The value classes of the published HR contracts (T-038 plan step 4)
|--------------------------------------------------------------------------
|
| Pure. Each accepts a good value and refuses a malformed one, so a consumer
| holding one can rely on its shape without re-checking it.
*/

it('builds a direct-reports answer and refuses a malformed one', function () {
    $good = new DirectReports(['A', 'B'], false);

    expect($good->ids)->toBe(['A', 'B'])
        ->and($good->truncated)->toBeFalse()
        ->and(DirectReports::MAXIMUM)->toBe(1000);

    expect(fn () => new DirectReports([1 => 'A'], false))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DirectReports([''], false))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DirectReports([5], false))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DirectReports(array_fill(0, 1001, 'A'), true))->toThrow(InvalidArgumentException::class);
});

it('accepts exactly the maximum and ties the truncated flag to it', function () {
    $full = new DirectReports(array_fill(0, 1000, 'A'), true);

    expect($full->truncated)->toBeTrue()
        ->and(count((new DirectReports(array_fill(0, 1000, 'A'), false))->ids))->toBe(1000);

    // Truncated with room to spare is a lie about the list.
    expect(fn () => new DirectReports(['A'], true))->toThrow(InvalidArgumentException::class);
});

it('builds a referrer and refuses an empty kind or a negative count', function () {
    $good = new HrReferrer('expense_items.department_id', 0);

    expect($good->kind)->toBe('expense_items.department_id')
        ->and($good->count)->toBe(0)
        ->and((new HrReferrer('k', 3))->count)->toBe(3);

    expect(fn () => new HrReferrer('', 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new HrReferrer('k', -1))->toThrow(InvalidArgumentException::class);
});

it('builds an employee identity and refuses an empty required field', function () {
    $good = new EmployeeIdentity('ID1', 'E-1', 'Asha', null, 'Asha', 'active');

    expect($good->id)->toBe('ID1')
        ->and($good->lastName)->toBeNull()
        ->and((new EmployeeIdentity('ID1', 'E-1', 'Asha', 'Perera', 'Asha P', 'active'))->lastName)->toBe('Perera');

    foreach ([
        ['', 'E-1', 'Asha', null, 'Asha', 'active'],
        ['ID1', '', 'Asha', null, 'Asha', 'active'],
        ['ID1', 'E-1', '', null, 'Asha', 'active'],
        ['ID1', 'E-1', 'Asha', '', 'Asha', 'active'],
        ['ID1', 'E-1', 'Asha', null, '', 'active'],
        ['ID1', 'E-1', 'Asha', null, 'Asha', ''],
    ] as $args) {
        expect(fn () => new EmployeeIdentity(...$args))->toThrow(InvalidArgumentException::class);
    }
});

it('adds has_dependents to the access refusal vocabulary', function () {
    expect(RefusalReason::from('has_dependents'))->toBe(RefusalReason::HasDependents);
});

it('keeps every new contract free of imports outside contracts and PHP core', function () {
    $dirs = [
        __DIR__.'/../../../contracts/src/Hr',
        __DIR__.'/../../../contracts/src/Audit/RedactingAuditTrail.php',
        __DIR__.'/../../../contracts/src/Access/EmployeeIdentity.php',
        __DIR__.'/../../../contracts/src/Access/EmployeeIdentities.php',
        __DIR__.'/../../../contracts/src/Access/EmployeeDependents.php',
        __DIR__.'/../../../contracts/src/Access/DeclaresEmployeeDependents.php',
    ];

    $files = [];
    foreach ($dirs as $path) {
        $files = array_merge($files, is_dir($path) ? glob($path.'/*.php') : [$path]);
    }

    expect(count($files))->toBe(11);

    foreach ($files as $file) {
        preg_match_all('/^use\s+([^;]+);/m', (string) file_get_contents($file), $uses);

        foreach ($uses[1] as $imported) {
            expect(in_array($imported, ['InvalidArgumentException', 'LogicException'], true))->toBeTrue(basename($file).' imports '.$imported);
        }
    }
});
