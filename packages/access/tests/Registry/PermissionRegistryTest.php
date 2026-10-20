<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Registry;

use Flexipos\Access\AccessServiceProvider;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Registry\AccessPermissions;
use Flexipos\Access\Registry\PermissionRegistry;
use Flexipos\Access\Tests\Support\FixturePermissionProvider;
use Flexipos\Access\Tests\Support\SecondFixturePermissionProvider;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Flexipos\Contracts\Access\RefusalReason;
use InvalidArgumentException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 6 - what a permission key is, and where the set comes from
|--------------------------------------------------------------------------
|
| AC-14's declaration half. The format rule is proved where it lives - on
| PermissionDeclaration, which refuses a malformed or over-long key at
| construction, so such a key never becomes a value and the sync, the sweep
| and every later module get the same answer without restating the regex.
|
| The registry's own job is narrower and is the part this file is really
| about: collect from the booted providers that declare, collapse identical
| duplicates, refuse a key declared two different ways, and **publish the
| domain it collected over**. The last one is D-27, and the mutation it has
| to survive is a declaration arriving from a provider no list in this file
| mentions - which is the final case here.
|
| Nothing in this file touches a database. The registry reads the container
| and nothing else, and the one thing that would make that false - a memo
| keyed on nothing - is what the last case would catch.
|
*/

uses(TestCase::class);

/**
 * A declaration with every attribute spelled out, so a case changing one of
 * them is visibly changing that one.
 */
function registryDeclaration(
    string $key,
    string $module = 'fixture',
    string $group = 'fixture',
    string $description = 'A fixture declaration.',
    bool $isBranchScoped = false,
): PermissionDeclaration {
    return new PermissionDeclaration($key, $module, $group, $description, $isBranchScoped);
}

function registry(): PermissionRegistry
{
    /** @var PermissionRegistry $registry */
    $registry = app(PermissionRegistry::class);

    return $registry;
}

/*
|--------------------------------------------------------------------------
| AC-14 - the format rule, refused at construction
|--------------------------------------------------------------------------
*/

it('refuses a key that is not lower-case dotted segments', function (string $key) {
    expect(fn () => registryDeclaration($key))->toThrow(InvalidArgumentException::class);
})->with([
    'no dot at all' => ['employees'],
    'upper case' => ['Employees.manage'],
    'a hyphen' => ['employees.set-pin'],
    'a trailing dot' => ['employees.'],
    'a leading digit in a segment' => ['employees.1manage'],
    'a space' => ['employees manage'],
    'empty' => [''],
]);

it('refuses a key longer than the column that stores it', function () {
    $tail = str_repeat('a', PermissionDeclaration::MAX_KEY_LENGTH);

    // Exactly at the column width is accepted, one over is not. The pair is
    // the point: a rule asserted only on an obviously-too-long key is also
    // satisfied by an off-by-one.
    $atTheLimit = 'fixture.'.substr($tail, 0, PermissionDeclaration::MAX_KEY_LENGTH - strlen('fixture.'));

    expect(strlen($atTheLimit))->toBe(PermissionDeclaration::MAX_KEY_LENGTH)
        ->and(registryDeclaration($atTheLimit)->key)->toBe($atTheLimit)
        ->and(fn () => registryDeclaration($atTheLimit.'a'))->toThrow(InvalidArgumentException::class);
});

it('refuses a declaration that names no module, group or description', function (string $module, string $group, string $description) {
    expect(fn () => registryDeclaration('fixture.key', $module, $group, $description))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'no module' => ['', 'fixture', 'A fixture declaration.'],
    'no group' => ['fixture', '  ', 'A fixture declaration.'],
    'no description' => ['fixture', 'fixture', ''],
]);

/*
|--------------------------------------------------------------------------
| AC-14 - this task's six
|--------------------------------------------------------------------------
*/

it('declares this task six keys, every one company-wide', function () {
    $declarations = AccessPermissions::declarations();

    $keys = array_map(static fn (PermissionDeclaration $d): string => $d->key, $declarations);
    $scoped = array_values(array_filter(
        $declarations,
        static fn (PermissionDeclaration $d): bool => $d->isBranchScoped
    ));

    expect($keys)->toBe([
        AccessPermissions::EMPLOYEES_VIEW,
        AccessPermissions::EMPLOYEES_MANAGE,
        AccessPermissions::EMPLOYEES_SET_PIN,
        AccessPermissions::ROLES_VIEW,
        AccessPermissions::ROLES_MANAGE,
        AccessPermissions::ACCESS_ASSIGN,
    ])
        // D-13: none of the six is branch-scoped, and that is a decision.
        // The mechanism is proved against fixture keys instead.
        ->and($scoped)->toBe([]);
});

it('reaches those six through the booted provider rather than through the class', function () {
    // The registry must find them the way it would find a module's: by
    // asking the container which registered providers declare. Reading
    // AccessPermissions directly here would prove the list and not the
    // wiring, and the wiring is what a new module relies on.
    expect(registry()->providerNames())->toContain(AccessServiceProvider::class)
        ->and(registry()->keysByProvider()[AccessServiceProvider::class] ?? [])
        ->toHaveCount(count(AccessPermissions::declarations()))
        ->and(registry()->keys())->toContain(AccessPermissions::ACCESS_ASSIGN);
});

/*
|--------------------------------------------------------------------------
| AC-14 - one key declared twice
|--------------------------------------------------------------------------
*/

it('collapses the same key declared identically by two providers', function () {
    $declaration = registryDeclaration('fixture.shared');

    FixturePermissionProvider::declaring(app(), $declaration);
    SecondFixturePermissionProvider::declaring(app(), registryDeclaration('fixture.shared'));

    $occurrences = array_values(array_filter(
        registry()->keys(),
        static fn (string $key): bool => $key === 'fixture.shared'
    ));

    expect($occurrences)->toBe(['fixture.shared']);
});

it('refuses one key declared two different ways and names both providers', function (PermissionDeclaration $second) {
    FixturePermissionProvider::declaring(app(), registryDeclaration('fixture.shared'));
    SecondFixturePermissionProvider::declaring(app(), $second);

    $refusal = null;

    try {
        registry()->declarations();
    } catch (AccessRefused $thrown) {
        $refusal = $thrown;
    }

    expect($refusal)->toBeInstanceOf(AccessRefused::class)
        // The cause travels with the refusal: fifteen reasons share a few
        // statuses, so "it threw" is not the assertion.
        ->and($refusal?->reason)->toBe(RefusalReason::Validation)
        ->and((string) $refusal?->getMessage())->toContain('fixture.shared')
        ->and((string) $refusal?->getMessage())->toContain(FixturePermissionProvider::class)
        ->and((string) $refusal?->getMessage())->toContain(SecondFixturePermissionProvider::class);
})->with([
    'a different description' => [fn () => registryDeclaration('fixture.shared', description: 'Something else.')],
    'a different module' => [fn () => registryDeclaration('fixture.shared', module: 'elsewhere')],
    'a different group' => [fn () => registryDeclaration('fixture.shared', group: 'elsewhere')],
    'a different scope flag' => [fn () => registryDeclaration('fixture.shared', isBranchScoped: true)],
]);

/*
|--------------------------------------------------------------------------
| D-27 - the domain, published, and the key nobody listed
|--------------------------------------------------------------------------
*/

it('publishes the providers it collected over and the keys each supplied', function () {
    FixturePermissionProvider::declaring(app(), registryDeclaration('fixture.published'));

    $byProvider = registry()->keysByProvider();

    // Printed, not merely asserted: a reader of a failure has to be able to
    // see which providers were booted when the keys were counted. The
    // registry's domain is this process's providers, which is narrower than
    // "every key the product has" - a module absent from MODULES declares
    // nothing here.
    foreach ($byProvider as $provider => $keys) {
        echo $provider.' => '.implode(', ', $keys)."\n";
    }

    expect($byProvider)->not->toBeEmpty()
        ->and(registry()->providerNames())->not->toBeEmpty()
        ->and(array_keys($byProvider))->toContain(AccessServiceProvider::class)
        ->and(array_keys($byProvider))->toContain(FixturePermissionProvider::class);
});

it('finds a key declared by a provider no list in this file mentions', function () {
    $before = registry()->keys();

    // The mandatory mutation for an enumerating instrument (D-27, shape 7):
    // the item nobody listed. If the registry read a list of its own - or
    // memoised the first answer - this key would be invisible and every
    // other case in this file would still pass.
    FixturePermissionProvider::declaring(app(), registryDeclaration('unlisted.capability'));

    expect($before)->not->toContain('unlisted.capability')
        ->and(registry()->keys())->toContain('unlisted.capability')
        ->and(count(registry()->keys()))->toBe(count($before) + 1);
});
