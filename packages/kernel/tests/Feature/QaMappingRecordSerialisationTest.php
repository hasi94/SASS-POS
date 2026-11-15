<?php

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\TenantDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA, T-008, AC-13: the six serialising functions over a resolved mapping
|--------------------------------------------------------------------------
|
| AC-13 names json_encode, serialize, print_r, var_export, var_dump and an
| (array) cast. No shipped test runs them over a mapping read from the master:
| the older credential sweeps predate the master reader and the new reader's
| tests assert fields, not text.
|
| The password is put in play before the scan, not assumed: a tenant is
| entered through the shipped graph, which dereferences the reference and
| writes the secret into the connection stanza. Only then is the resolved
| record read and every function run over it, so a record that had kept the
| value it was resolved with would be seen.
|
| [E] in the spec because the engine is what shapes a real mapping row; this
| is the stand-in half and is recorded as such. No engine, no hold concern.
*/

require_once __DIR__.'/../Support/migrate-tenant-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    migrateFixtureBoot();
    migrateFixtureServer();
});

afterEach(function () {
    migrateFixtureTearDown();
});

/**
 * The control object: holds the password and implements __toString.
 */
final class QaPlantedPasswordRecord
{
    public function __construct(public string $password) {}

    public function __toString(): string
    {
        return 'record['.$this->password.']';
    }
}

/**
 * Every output AC-13 names, as text, for one value.
 *
 * @return array<string, string>
 */
function qaSerialisations(mixed $value): array
{
    ob_start();
    var_dump($value);
    $dump = (string) ob_get_clean();

    $outputs = [
        'json_encode' => (string) json_encode($value),
        'serialize' => serialize($value),
        'print_r' => print_r($value, true),
        'var_export' => var_export($value, true),
        'var_dump' => $dump,
        'array cast' => print_r((array) $value, true),
    ];

    if (is_object($value) && method_exists($value, '__toString')) {
        $outputs['string cast'] = (string) $value;
    }

    return $outputs;
}

/**
 * Which outputs contain the needle.
 *
 * @param  array<string, string>  $outputs
 * @return list<string>
 */
function qaOutputsContaining(array $outputs, string $needle): array
{
    return array_keys(array_filter($outputs, static fn (string $text): bool => str_contains($text, $needle)));
}

it('leaks the password through none of the six serialising functions', function () {
    $password = migrateFixturePassword();
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'), ['status' => 'active']);

    tenancy()->enter($tenant);

    // The password is genuinely in the process: the stanza the connection
    // was built from holds it.
    expect((string) config('database.connections.tenant.password'))->toBe($password);

    $record = app(TenantDatabaseRepository::class)->find($tenant);

    expect($record)->toBeInstanceOf(TenantDatabase::class)
        ->and($record->credentialRef)->toBe('env://MIGRATE_FIXTURE_PASSWORD');

    $outputs = qaSerialisations($record);

    // The scan reads real text: each output names the reference, which the
    // record is meant to carry.
    expect(qaOutputsContaining($outputs, 'MIGRATE_FIXTURE_PASSWORD'))->toHaveCount(count($outputs))
        ->and(qaOutputsContaining($outputs, $password))->toBe([]);

    tenancy()->leave();
});

it('reports a record that holds the password, including through a string cast', function () {
    // The control the criterion names: the same scan, over an object that
    // holds the password and implements __toString, finds it in every output.
    $planted = new QaPlantedPasswordRecord('m1grate-DO-NOT-LEAK-91bc');

    $outputs = qaSerialisations($planted);

    expect(array_keys($outputs))->toBe(['json_encode', 'serialize', 'print_r', 'var_export', 'var_dump', 'array cast', 'string cast'])
        ->and(qaOutputsContaining($outputs, 'm1grate-DO-NOT-LEAK-91bc'))->toBe(array_keys($outputs));
});

it('adds the string cast to the scan the day the record gains __toString', function () {
    // D-26: the real record has none, so a string cast would be a fatal error
    // and is not an output to scan. If that changes this case stays true only
    // because qaSerialisations() starts scanning it.
    $withoutString = qaSerialisations(new TenantDatabase(
        tenantId: 'T', driver: 'mysql', host: 'h', port: 1, database: 'd', username: 'u',
        credentialRef: 'env://X', status: 'active',
    ));

    expect((new ReflectionClass(TenantDatabase::class))->hasMethod('__toString'))
        ->toBe(array_key_exists('string cast', $withoutString));
});
