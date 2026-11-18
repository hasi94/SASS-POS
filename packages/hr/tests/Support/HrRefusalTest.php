<?php

namespace Flexipos\Hr\Tests\Support;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Exceptions\HrRefused;

/*
|--------------------------------------------------------------------------
| The closed refusal vocabulary (T-038 plan step 5, Ruling 9, D-20)
|--------------------------------------------------------------------------
|
| Pinned as a set in both directions: a code added without a decision fails
| here, and a code removed fails here too.
*/

it('has exactly the twenty-seven codes of the spec, as a closed set', function () {
    $values = array_map(static fn (HrRefusal $case): string => $case->value, HrRefusal::cases());

    sort($values);

    expect($values)->toBe([
        'already_active',
        'already_inactive',
        'code_in_use',
        'cursor_invalid',
        'cycle',
        'date_order',
        'depth_exceeded',
        'document_corrupt',
        'document_limit',
        'file_empty',
        'file_too_large',
        'file_type_not_allowed',
        'has_children',
        'has_dependents',
        'in_use',
        'name_in_use',
        'not_found',
        'not_permitted',
        'not_updatable_here',
        'page_size_invalid',
        'profile_exists',
        'profile_not_found',
        'reference_inactive',
        'reference_not_found',
        'self_reference',
        'storage_unavailable',
        'validation',
    ])->and($values)->toHaveCount(27);
});

it('is only constructible through a cause, and carries reason, field and context', function () {
    $refused = HrRefused::because(HrRefusal::CodeInUse, 'The code is in use.', 'code', ['held_by' => 'X']);

    expect($refused->reason)->toBe(HrRefusal::CodeInUse)
        ->and($refused->field)->toBe('code')
        ->and($refused->context)->toBe(['held_by' => 'X'])
        ->and($refused->getMessage())->toBe('The code is in use.')
        ->and((new \ReflectionClass(HrRefused::class))->getConstructor()?->isPrivate())->toBeTrue()
        ->and(HrRefused::validation('name', 'm')->reason)->toBe(HrRefusal::Validation);
});
