<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Contracts;

use Flexipos\Contracts\Access\PermissionDeclaration;
use InvalidArgumentException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 1, AC-14 - the key format rule, in one place
|--------------------------------------------------------------------------
|
| The sync (step 6) fails as a whole on a malformed or over-length key. That
| criterion is only honest if "malformed" has one definition: validating at
| construction means the sync, the registry sweep and a later module all read
| the same rule, and a second copy of the regex would be FP-181's shape.
|
| The length bound is the column, not a style choice: `permissions.key` is
| VARCHAR(80) and is that table's primary key, so the boundary is asserted on
| both sides - 80 accepted, 81 refused - rather than somewhere comfortably
| inside it.
|
| `agreesWith()` is asserted because AC-14's duplicate-with-different-
| attributes case is decided by it; a comparison written inside the sync
| instead would silently stop covering a field the day one is added here.
|
*/

uses(TestCase::class);

it('accepts the six keys this task declares', function (string $key) {
    expect((new PermissionDeclaration($key, 'access', 'Access', 'A key.', false))->key)->toBe($key);
})->with([
    ['employees.view'],
    ['employees.manage'],
    ['employees.set_pin'],
    ['roles.view'],
    ['roles.manage'],
    ['access.assign'],
]);

it('refuses a key that is not lower-case dotted segments', function (string $key) {
    expect(static fn () => new PermissionDeclaration($key, 'access', 'Access', 'A key.', false))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'upper case' => ['Employees.manage'],
    'one segment' => ['employees'],
    'a trailing dot' => ['employees.'],
    'a leading dot' => ['.manage'],
    'a double dot' => ['employees..manage'],
    'a hyphen' => ['employees.set-pin'],
    'a space' => ['employees manage'],
    'empty' => [''],
    'a segment starting with a digit' => ['employees.2fa'],
]);

it('bounds the key at the column width, on both sides', function () {
    $prefix = 'employees.';
    $accepted = $prefix.str_repeat('a', PermissionDeclaration::MAX_KEY_LENGTH - strlen($prefix));

    expect(strlen($accepted))->toBe(PermissionDeclaration::MAX_KEY_LENGTH)
        ->and((new PermissionDeclaration($accepted, 'access', 'Access', 'A key.', false))->key)->toBe($accepted)
        ->and(static fn () => new PermissionDeclaration($accepted.'a', 'access', 'Access', 'A key.', false))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a declaration missing its module, group or description', function (string $module, string $group, string $description) {
    expect(static fn () => new PermissionDeclaration('employees.view', $module, $group, $description, false))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'no module' => ['', 'Access', 'A key.'],
    'no group' => ['access', '', 'A key.'],
    'no description' => ['access', 'Access', '   '],
]);

it('tells two declarations of one key apart by every attribute', function () {
    $declaration = new PermissionDeclaration('employees.view', 'access', 'Access', 'See employees.', false);

    expect($declaration->agreesWith(new PermissionDeclaration('employees.view', 'access', 'Access', 'See employees.', false)))->toBeTrue()
        ->and($declaration->agreesWith(new PermissionDeclaration('employees.view', 'access', 'Access', 'See employees.', true)))->toBeFalse()
        ->and($declaration->agreesWith(new PermissionDeclaration('employees.view', 'access', 'Staff', 'See employees.', false)))->toBeFalse()
        ->and($declaration->agreesWith(new PermissionDeclaration('employees.view', 'hr', 'Access', 'See employees.', false)))->toBeFalse()
        ->and($declaration->agreesWith(new PermissionDeclaration('employees.view', 'access', 'Access', 'Other.', false)))->toBeFalse()
        ->and($declaration->agreesWith(new PermissionDeclaration('employees.manage', 'access', 'Access', 'See employees.', false)))->toBeFalse();
});
