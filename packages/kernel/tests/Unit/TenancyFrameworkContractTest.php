<?php

use Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\DatabaseManager;

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

/*
 * T-007 FP-160 - the framework contract TenantConnectionFactory depends on.
 *
 * The choke point is a decoration of the `db.factory` binding, and that is only
 * a choke point while three things stay true of DatabaseManager: it is handed
 * its factory at construction (so the decoration must be a ConnectionFactory,
 * and must be registered before `db` resolves), every construction route calls
 * `$this->factory->make()`, and a configuration retained by build() is still
 * preferred over config() (so a retained stanza is refused by the factory
 * rather than quietly rebuilt).
 *
 * IF THIS CASE FAILS, THE FRAMEWORK MOVED ONE OF THOSE - the claim "every route
 * passes one mechanism" is then false, and this names it in one read instead of
 * leaving a silent hole somewhere in a route table.
 */

it('still builds every connection through the factory it was constructed with', function () {
    $parameters = (new ReflectionMethod(DatabaseManager::class, '__construct'))->getParameters();
    $factoryParameter = null;

    foreach ($parameters as $parameter) {
        if ($parameter->getName() === 'factory') {
            $factoryParameter = $parameter;
        }
    }

    $type = $factoryParameter?->getType();

    // 1. Constructor injection, typed as ConnectionFactory. This is why
    //    TenantConnectionFactory has to extend that class rather than merely
    //    implement the same shape, and why it is registered in register().
    expect($factoryParameter)->not->toBeNull('DatabaseManager::__construct() no longer takes a $factory')
        ->and($type)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($type instanceof ReflectionNamedType ? $type->getName() : '')->toBe(ConnectionFactory::class);

    // 2. Both construction routes still go through that property. Read from the
    //    method bodies rather than from the whole file, so an occurrence
    //    somewhere else cannot stand in for one of these.
    $reflected = new ReflectionClass(DatabaseManager::class);
    $lines = (array) file((string) $reflected->getFileName());

    $bodyOf = static function (string $method) use ($reflected, $lines): string {
        $method = $reflected->getMethod($method);
        $start = (int) $method->getStartLine() - 1;
        $length = (int) $method->getEndLine() - $start;

        return (string) preg_replace('/\s+/', ' ', implode('', array_slice($lines, $start, $length)));
    };

    expect($bodyOf('makeConnection'))->toContain('$this->factory->make($config, $name)')
        ->and($bodyOf('connectUsing'))->toContain('$this->factory->make($config, $name)')
        // 3. And a stanza retained by build() is still preferred over the one in
        //    config(), which is what makes a retained configuration fail closed
        //    through the factory instead of rebuilding the previous tenant.
        ->and($bodyOf('configuration'))
        ->toContain('$this->dynamicConnectionConfigurations[$name] ?? Arr::get($connections, $name)')
        ->and($bodyOf('purge'))->not->toContain('dynamicConnectionConfigurations');
});

/*
 * T-007 R12 - the framework contract TenantConnectionAuthority::digest()'s
 * `name` exclusion depends on.
 *
 * The digest covers the whole stanza less two keys, and `name` is excluded
 * because the framework writes it before the choke point looks: build() puts it
 * into the stanza and hands that stanza to connectUsing() and so to the
 * factory. It writes it whether the caller supplied one or not, which is what
 * makes the connection-name residual a *property* of an authorisation rather
 * than an exception a caller opts into.
 *
 * IF THIS CASE FAILS, THE EXCLUSION IS NO LONGER FORCED, and nothing else says
 * so: a framework that stopped writing `name` before the factory leaves every
 * tenancy test green while the digest goes on excluding a key it no longer has
 * to, widening the residual for nothing. The opposite direction needs no pin -
 * a key written *earlier* than today (as `prefix` would have to be) refuses a
 * legitimate stanza and reddens the two-tenant tests on the same run. This is
 * the quiet direction, so it is pinned here and the loud one is not.
 */

it('still writes a connection name into the stanza build() hands the factory', function () {
    $build = new ReflectionMethod(DatabaseManager::class, 'build');
    $lines = (array) file((string) $build->getFileName());
    $body = (string) preg_replace('/\s+/', ' ', implode('', array_slice(
        $lines,
        (int) $build->getStartLine() - 1,
        (int) $build->getEndLine() - (int) $build->getStartLine() + 1
    )));

    // 1. The key is written, and defaulted rather than required, so the stanza
    //    carries it whether or not a name was passed in...
    expect($body)->toContain("\$config['name'] ??= static::calculateDynamicConnectionName(\$config)")
        // ...and that same stanza is what goes on to connectUsing(), whose call
        // to $this->factory->make($config, $name) the case above pins.
        ->and($body)->toContain("\$this->connectUsing(\$config['name'], \$config, true)");

    // 2. The default is computed, not asked of the caller. Only that a name can
    //    be produced without one is depended on; its format is not.
    $invented = DatabaseManager::calculateDynamicConnectionName([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    expect($invented)->toBeString()
        ->and($invented)->not->toBe('');
});
