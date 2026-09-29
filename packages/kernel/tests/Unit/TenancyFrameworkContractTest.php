<?php

use Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword;
use Illuminate\Database\Connectors\Connector;

/*
 * Plan step 13.1 - the framework contract ConnectionPassword depends on.
 *
 * The tenant connection stanza stores its credential as a ConnectionPassword
 * rather than a string, so that PHP's record of the framework's own
 * `call_user_func($this->extensions[$name], $config, $name)` frame cannot carry
 * the value into a flattened trace. That only works because the database
 * connector is weak-typed: it does not declare strict_types and does not type
 * the password it hands to `new PDO(...)`, so a Stringable coerces.
 *
 * IF THIS FILE FAILS, THE FRAMEWORK CHANGED THE CONTRACT WE DEPEND ON - tenancy
 * itself is not broken. The dependency fails closed: with strict types or a
 * `string` type hint, `new PDO(...)` raises a TypeError and no tenant connection
 * opens at all, so the two-tenant tests go red on the same run. Fixing it means
 * choosing a different way to keep the credential out of that frame, not
 * loosening anything here.
 */

it('still lets the database connector accept a Stringable password', function () {
    $parameter = null;

    foreach ((new ReflectionMethod(Connector::class, 'createPdoConnection'))->getParameters() as $candidate) {
        if ($candidate->getName() === 'password') {
            $parameter = $candidate;
        }
    }

    expect($parameter)->not->toBeNull('Connector::createPdoConnection() no longer has a $password parameter');

    $type = $parameter?->getType();

    // Untyped is what ships today. A string-compatible union would also be fine;
    // a bare `string` would not, and neither would anything else.
    $accepted = $type === null
        || ($type instanceof ReflectionNamedType && $type->getName() === 'mixed')
        || ($type instanceof ReflectionUnionType);

    expect($accepted)->toBeTrue(sprintf(
        'Connector::createPdoConnection() now types $password as [%s]; a Stringable credential can no longer be '.
        'coerced, so ConnectionPassword needs a different mechanism.',
        (string) $type
    ));
});

it('still has a weak-typed database connector, so coercion applies at all', function () {
    $connector = (string) file_get_contents((new ReflectionClass(Connector::class))->getFileName() ?: '');

    expect($connector)->not->toBe('')
        ->and($connector)->not->toContain('declare(strict_types=1)');
});

it('coerces a Stringable credential through PDO exactly as the connector does', function () {
    // The end-to-end fact the two previous assertions only describe: this is the
    // same call shape as Connector::createPdoConnection().
    $password = new ConnectionPassword('s3cret-DO-NOT-LEAK-7f3a');

    $pdo = new PDO('sqlite::memory:', null, $password);

    expect($pdo)->toBeInstanceOf(PDO::class)
        ->and((string) $password)->toBe('s3cret-DO-NOT-LEAK-7f3a');
});
