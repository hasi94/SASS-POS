<?php

namespace Flexipos\Hr\Tests\Departments;

use Flexipos\Hr\Departments\DepartmentRules;
use Flexipos\Hr\Departments\NameKey;
use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Tests\Support\HrRefusals;

/*
|--------------------------------------------------------------------------
| The department tree rules and the name key (T-038 plan step 6; AC-18, AC-19,
| AC-23, AC-33)
|--------------------------------------------------------------------------
|
| Pure: the rules take a snapshot of the live tree, so every case is an array.
*/

/**
 * A chain of `$levels` departments D1 (top) to Dn, all active.
 *
 * @return array<string, array{parent_id: ?string, is_active: bool}>
 */
function hrDepartmentChain(int $levels): array
{
    $live = [];

    for ($i = 1; $i <= $levels; $i++) {
        $live['D'.$i] = ['parent_id' => $i === 1 ? null : 'D'.($i - 1), 'is_active' => true];
    }

    return $live;
}

// ---- AC-19: the order, each failure alone ----------------------------------

it('refuses a missing or soft-deleted parent as reference_not_found', function () {
    HrRefusals::expect(HrRefusal::ReferenceNotFound, fn () => DepartmentRules::checkParent(null, 'GONE', hrDepartmentChain(2)));
});

it('refuses an inactive parent as reference_inactive', function () {
    $live = hrDepartmentChain(2);
    $live['D2']['is_active'] = false;

    HrRefusals::expect(HrRefusal::ReferenceInactive, fn () => DepartmentRules::checkParent(null, 'D2', $live));
});

it('refuses a department as its own parent as self_reference', function () {
    HrRefusals::expect(HrRefusal::SelfReference, fn () => DepartmentRules::checkParent('D2', 'D2', hrDepartmentChain(3)));
});

it('refuses a move under a child or a grandchild as cycle', function () {
    $live = hrDepartmentChain(4);

    HrRefusals::expect(HrRefusal::Cycle, fn () => DepartmentRules::checkParent('D1', 'D2', $live), 'child');
    HrRefusals::expect(HrRefusal::Cycle, fn () => DepartmentRules::checkParent('D1', 'D3', $live), 'grandchild');
    HrRefusals::expect(HrRefusal::Cycle, fn () => DepartmentRules::checkParent('D2', 'D4', $live), 'great-grandchild');
});

it('accepts a move to a sibling, to the top level and under an unrelated branch', function () {
    $live = hrDepartmentChain(3) + [
        'S1' => ['parent_id' => 'D1', 'is_active' => true],
        'X1' => ['parent_id' => null, 'is_active' => true],
    ];

    DepartmentRules::checkParent('D2', 'S1', $live);
    DepartmentRules::checkParent('D2', null, $live);
    DepartmentRules::checkParent('D2', 'X1', $live);
    DepartmentRules::checkParent(null, 'D3', $live);

    expect(true)->toBeTrue();
});

it('reports the first failing rule when two apply at once', function () {
    // Missing beats everything: the parent is not in the snapshot at all.
    HrRefusals::expect(HrRefusal::ReferenceNotFound, fn () => DepartmentRules::checkParent('GONE', 'GONE', hrDepartmentChain(1)));

    // Inactive beats self-reference: D2 is its own parent and inactive.
    $live = hrDepartmentChain(2);
    $live['D2']['is_active'] = false;
    HrRefusals::expect(HrRefusal::ReferenceInactive, fn () => DepartmentRules::checkParent('D2', 'D2', $live));

    // Self-reference beats cycle: itself is also "inside" itself.
    HrRefusals::expect(HrRefusal::SelfReference, fn () => DepartmentRules::checkParent('D1', 'D1', hrDepartmentChain(2)));

    // Cycle beats depth: a chain at the limit, moving the top under the bottom.
    HrRefusals::expect(HrRefusal::Cycle, fn () => DepartmentRules::checkParent('D1', 'D10', hrDepartmentChain(10)));
});

// ---- depth ------------------------------------------------------------------

it('accepts exactly ten levels and refuses an eleventh', function () {
    // A tenth level under D9 is accepted; an eleventh under D10 is refused.
    DepartmentRules::checkParent(null, 'D9', hrDepartmentChain(9));
    HrRefusals::expect(HrRefusal::DepthExceeded, fn () => DepartmentRules::checkParent(null, 'D10', hrDepartmentChain(10)));

    expect(DepartmentRules::MAX_DEPTH)->toBe(10);
});

it('accepts a top-level department whatever else exists', function () {
    DepartmentRules::checkParent(null, null, hrDepartmentChain(10));

    expect(true)->toBeTrue();
});

it('checks the depth against the deepest descendant after a move, not the moved node alone', function () {
    // M is a department with a child and a grandchild: a three-level subtree.
    $live = hrDepartmentChain(8) + [
        'M' => ['parent_id' => null, 'is_active' => true],
        'M2' => ['parent_id' => 'M', 'is_active' => true],
        'M3' => ['parent_id' => 'M2', 'is_active' => true],
    ];

    // Under D7: 7 levels above, M's subtree spans 3, so 10 levels. Accepted.
    DepartmentRules::checkParent('M', 'D7', $live);

    // Under D8: 8 + 3 = 11. Refused, although M alone would sit at level 9.
    HrRefusals::expect(HrRefusal::DepthExceeded, fn () => DepartmentRules::checkParent('M', 'D8', $live));
});

it('measures levels and subtree height', function () {
    $live = hrDepartmentChain(4) + ['S' => ['parent_id' => 'D1', 'is_active' => true]];

    expect(DepartmentRules::levelOf('D1', $live))->toBe(1)
        ->and(DepartmentRules::levelOf('D4', $live))->toBe(4)
        ->and(DepartmentRules::levelOf('S', $live))->toBe(2)
        ->and(DepartmentRules::levelOf('MISSING', $live))->toBe(0)
        ->and(DepartmentRules::height('D1', $live))->toBe(4)
        ->and(DepartmentRules::height('D4', $live))->toBe(1)
        ->and(DepartmentRules::height('S', $live))->toBe(1);
});

it('does not loop on a corrupt snapshot', function () {
    $loop = [
        'A' => ['parent_id' => 'B', 'is_active' => true],
        'B' => ['parent_id' => 'A', 'is_active' => true],
    ];

    expect(DepartmentRules::levelOf('A', $loop))->toBe(2)
        ->and(DepartmentRules::height('A', $loop))->toBe(2)
        ->and(DepartmentRules::reaches('A', 'ZZ', $loop))->toBeTrue();
});

// ---- AC-18, AC-33: the name key ---------------------------------------------

it('folds a name by trimming and lower-casing', function () {
    expect(NameKey::of('Sales'))->toBe('sales')
        ->and(NameKey::of('sales '))->toBe('sales')
        ->and(NameKey::of('  SALES'))->toBe('sales')
        ->and(NameKey::of('ÉCOLE'))->toBe('école');
});

it('finds a case-insensitive trimmed collision among siblings', function () {
    expect(NameKey::taken('Sales', ['sales ']))->toBeTrue()
        ->and(NameKey::taken('sales ', ['Finance', 'SALES']))->toBeTrue()
        ->and(NameKey::taken('Sales', ['Sales Ops']))->toBeFalse()
        ->and(NameKey::taken('Sales', []))->toBeFalse();

    HrRefusals::expect(HrRefusal::NameInUse, fn () => NameKey::assertFree('Sales', ['sales ']));
});

it('accepts the same name when the caller offers a different set of siblings', function () {
    // Another parent, another designation group, or a soft-deleted holder:
    // the caller simply does not pass that row, so the name is free.
    NameKey::assertFree('Sales', ['Finance']);
    NameKey::assertFree('Sales', []);

    expect(true)->toBeTrue();
});
