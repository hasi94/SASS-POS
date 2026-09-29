<?php

use Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword;
use Flexipos\Kernel\Tenancy\Secrets\Secret;

/*
 * Plan step 12. ConnectionPassword's whole job is to be a credential that the
 * database connector can still coerce, while a flattened exception trace records
 * it as ['object', get_debug_type($value)] and discards the value.
 *
 * Like Secret, it carries **no state at all**: the value lives in
 * CredentialValues, a WeakMap keyed by the instance, so `(array) $password` is
 * empty and every generic inspector has nothing to render but the virtual key
 * __debugInfo() contributes. This is the class the original dump() leak was
 * found on (QA BUG-1) - a private property was rendered by Symfony's cloner
 * right next to the redacted key - so the no-state fact is asserted here
 * directly rather than inferred.
 *
 * The installed dump()/dd() handler is asserted in QaCredentialSurfaceSweepTest,
 * through a child process, because it writes to stdout and needs a booted
 * application. These cases need neither.
 *
 * No TestCase binding: nothing here needs an application.
 */

function connectionPasswordTestValue(): string
{
    return 's3cret-DO-NOT-LEAK-7f3a';
}

it('coerces to the real value, which is the entire point of the class', function () {
    $password = new ConnectionPassword(connectionPasswordTestValue());

    expect((string) $password)->toBe(connectionPasswordTestValue())
        ->and($password)->toBeInstanceOf(Stringable::class);
});

it('is deliberately not a Secret, because the two have opposite __toString jobs', function () {
    // Collapsing them would make every accidental interpolation of a Secret
    // anywhere start leaking, which is exactly what Secret exists to prevent.
    expect((string) new Secret(connectionPasswordTestValue()))->toBe('[redacted]')
        ->and((string) new ConnectionPassword(connectionPasswordTestValue()))
        ->toBe(connectionPasswordTestValue())
        ->and(new ConnectionPassword(connectionPasswordTestValue()))->not->toBeInstanceOf(Secret::class);
});

it('redacts under the string, JSON and language-level debug representations', function () {
    // Named for what it actually covers, which is not an enumeration of every
    // surface. QaCredentialSurfaceSweepTest is the authority on the full list,
    // because it measures each surface by running it - including the installed
    // dump() handler, which none of the hooks below reach.
    $password = new ConnectionPassword(connectionPasswordTestValue());

    expect($password->__debugInfo())->toBe(['value' => '[redacted]'])
        ->and(json_encode($password))->toBe('"[redacted]"')
        ->and(print_r($password, true))->not->toContain(connectionPasswordTestValue());

    ob_start();
    var_dump($password);
    $dumped = (string) ob_get_clean();

    expect($dumped)->not->toContain(connectionPasswordTestValue())
        ->and($dumped)->toContain('[redacted]');
});

it('refuses to be serialised through either hook', function () {
    $password = new ConnectionPassword(connectionPasswordTestValue());

    // __serialize() is the one PHP actually calls; __sleep() throws as well so
    // that removing either leaves the other refusing.
    expect(fn (): string => serialize($password))->toThrow(LogicException::class);

    expect(fn (): array => $password->__sleep())->toThrow(LogicException::class);
});

it('carries no state of its own for any inspector to reach', function () {
    // The structural guarantee, and the one that does not depend on any
    // inspector honouring any hook. This is the exact fact whose absence was
    // QA BUG-1: a private property held the value, and Symfony's cloner renders
    // an object by casting it to an array, so the credential printed next to the
    // redacted key in every dd() and dump(). Casting to an array is what every
    // generic inspector ultimately does, so the only safe amount of state is
    // none - a captured closure is no better, as review R-1 showed on Secret.
    $password = new ConnectionPassword(connectionPasswordTestValue());

    expect((new ReflectionClass($password))->getProperties())->toBe([])
        ->and((array) $password)->toBe([])
        ->and((string) $password)->toBe(connectionPasswordTestValue());
});

it('refuses to be cloned, because a clone would carry no value', function () {
    // __clone() only ever sees the copy, so the value cannot be carried across.
    // Refusing beats a silent clone that coerces to '' and opens a connection
    // with an empty password.
    $password = new ConnectionPassword(connectionPasswordTestValue());

    expect(function () use ($password): void {
        $clone = clone $password;
    })->toThrow(LogicException::class);
});

it('shows nothing in a var_export, and declares no __set_state to load one back', function () {
    // var_export() honours no redaction hook - but it walks properties, and
    // there are none, so it now has nothing to show at all. That is stronger
    // than the original claim here, which only asserted that __set_state() was
    // absent; the export residual the plan accepted at step 12 is gone.
    //
    // The missing __set_state() still matters as the second line: an accidental
    // export of a tenant stanza cannot even be required back in, so a mistake
    // fails loudly instead of silently working. config:cache is safe anyway,
    // because no tenant is ever entered in a config-caching process.
    $exported = var_export(new ConnectionPassword(connectionPasswordTestValue()), true);

    expect($exported)->not->toContain(connectionPasswordTestValue())
        ->and((new ReflectionClass(ConnectionPassword::class))->hasMethod('__set_state'))->toBeFalse();
});

it('is recorded by a flattened trace as an object with no value', function () {
    // The mechanism the whole ruling rests on, asserted directly rather than
    // inferred: Symfony's flattener copies string argument values verbatim but
    // reduces an object to its type. Built through the same call_user_func shape
    // DatabaseManager::makeConnection() uses.
    $needle = connectionPasswordTestValue();

    $thrower = static function (array $config): void {
        throw new RuntimeException('refused with '.count($config).' keys');
    };

    // Called directly, never through a wrapper closure: PHP 8.2's print_r()
    // renders a closure's captured [static] variables, so a helper that closed
    // over $needle would report a leak that has nothing to do with the argument
    // under test. The real extension closure captures the container, never a
    // credential.
    $asString = '';
    $asObject = '';

    try {
        $thrower(['password' => $needle]);
    } catch (Throwable $exception) {
        $asString = print_r($exception->getTrace(), true);
    }

    try {
        $thrower(['password' => new ConnectionPassword($needle)]);
    } catch (Throwable $exception) {
        $asObject = print_r($exception->getTrace(), true);
    }

    // The control half: a plain string argument is what used to leak.
    expect($asString)->toContain($needle)
        // And the object does not, because the trace holds only its type.
        ->and($asObject)->not->toContain($needle)
        ->and($asObject)->toContain(ConnectionPassword::class);
});
