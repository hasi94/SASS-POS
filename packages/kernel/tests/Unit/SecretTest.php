<?php

use Flexipos\Kernel\Tenancy\Secrets\Secret;

/*
 * A resolved tenant credential must be impossible to print by accident. Each
 * case below is one of the ways PHP can be made to show the inside of an object.
 *
 * One mechanism does the work: the object carries **no state at all**. The value
 * lives in CredentialValues, a WeakMap keyed by the instance, so `(array)
 * $secret` is empty and every generic inspector - print_r(), var_export(),
 * reflection, a dumper - has nothing to render but the virtual key
 * __debugInfo() contributes.
 *
 * Two earlier designs were not enough, and both were measured failing rather
 * than argued about: a private property is rendered by Symfony's cloner
 * (QA BUG-1), and so is a captured closure's `use` binding (review R-1).
 *
 * The installed dump()/dd() handler is asserted in QaCredentialSurfaceSweepTest,
 * through a child process, because it writes to stdout and a booted application
 * is needed to install it. These cases need neither.
 *
 * No TestCase binding: nothing here needs an application.
 */

/**
 * The distinctive value every credential-hygiene test looks for.
 */
function secretTestValue(): string
{
    return 's3cret-DO-NOT-LEAK-7f3a';
}

it('hands the value out through reveal, and only through reveal', function () {
    expect((new Secret(secretTestValue()))->reveal())->toBe(secretTestValue());
});

it('redacts the value in every string and debug representation', function () {
    $secret = new Secret(secretTestValue());

    expect((string) $secret)->toBe('[redacted]')
        ->and($secret->__debugInfo())->toBe(['value' => '[redacted]'])
        ->and(json_encode($secret))->toBe('"[redacted]"')
        ->and(json_encode(['password' => $secret]))->toBe('{"password":"[redacted]"}');
});

it('shows nothing useful to print_r', function () {
    $printed = print_r(new Secret(secretTestValue()), true);

    expect($printed)->not->toContain(secretTestValue())
        ->and($printed)->toContain('[redacted]');
});

it('carries no state of its own for any inspector to reach', function () {
    // The structural guarantee, and the one that does not depend on any
    // inspector honouring any hook: the object has NO properties. The value
    // lives in CredentialValues, a WeakMap keyed by this instance.
    //
    // Two earlier designs failed here. A private property is rendered by
    // Symfony's cloner (QA BUG-1 on ConnectionPassword); a captured closure is
    // rendered too, because the cloner shows a closure's `use` bindings
    // (review R-1 on this class). Casting to an array is what every generic
    // inspector ultimately does, so the only safe amount of state is none.
    $secret = new Secret(secretTestValue());

    expect((new ReflectionClass($secret))->getProperties())->toBe([])
        ->and((array) $secret)->toBe([])
        ->and($secret->reveal())->toBe(secretTestValue());
});

it('refuses to be cloned, because a clone would carry no value', function () {
    // __clone() only ever sees the copy, so the value cannot be carried across.
    // Refusing beats a silent clone that reveals '' and opens a connection with
    // an empty password.
    $secret = new Secret(secretTestValue());

    expect(function () use ($secret): void {
        $clone = clone $secret;
    })->toThrow(LogicException::class);
});

it('redacts the value under var_dump, which honours __debugInfo', function () {
    // var_dump() only. dd() and dump() do NOT build on var_dump() - they build
    // on Symfony's VarCloner, and believing otherwise is exactly what produced
    // BUG-1. The installed dump() handler is asserted in
    // QaCredentialSurfaceSweepTest, through a child process, because it writes
    // to stdout and cannot be captured with an output buffer.
    ob_start();
    var_dump(new Secret(secretTestValue()));
    $dumped = (string) ob_get_clean();

    expect($dumped)->not->toContain(secretTestValue())
        ->and($dumped)->toContain('[redacted]');
});

it('keeps the value out of var_export, which honours no hook either', function () {
    expect(var_export(new Secret(secretTestValue()), true))->not->toContain(secretTestValue());
});

it('refuses to be serialised, so it cannot reach a queue payload or a cache entry', function () {
    $secret = new Secret(secretTestValue());

    expect(fn (): string => serialize($secret))->toThrow(LogicException::class);
});
