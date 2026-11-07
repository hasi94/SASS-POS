<?php

use Flexipos\Kernel\Schema\Exceptions\TenantIdentityMismatch;
use Flexipos\Kernel\Schema\Features\MigrateTenant\SchemaMetaIdentity;
use Flexipos\Kernel\Tenancy\Provisioning\SchemaMetaStamp;
use Illuminate\Database\SQLiteConnection;

/**
 * A bare in-memory SQLite connection with schema_meta created, exactly the
 * shape the tenant baseline migration leaves it (empty) - no full Tenancy
 * fixture needed for a write confined to one table.
 */
function schemaMetaStampFixtureConnection(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $connection = new SQLiteConnection($pdo, 'schema-meta-fixture');

    $connection->getSchemaBuilder()->create('schema_meta', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->string('key', 60)->primary();
        $table->string('value', 255)->nullable();
    });

    return $connection;
}

it('writes the row when it is absent', function () {
    $connection = schemaMetaStampFixtureConnection();

    SchemaMetaStamp::stamp($connection, 'tenant-A');

    expect(SchemaMetaIdentity::of($connection))->toBe('tenant-A');
});

it('is a no-op when the row already names this tenant', function () {
    $connection = schemaMetaStampFixtureConnection();

    SchemaMetaStamp::stamp($connection, 'tenant-A');
    SchemaMetaStamp::stamp($connection, 'tenant-A');

    expect($connection->table(SchemaMetaIdentity::TABLE)->count())->toBe(1)
        ->and(SchemaMetaIdentity::of($connection))->toBe('tenant-A');
});

it('refuses, and writes nothing, when the row names another tenant', function () {
    $connection = schemaMetaStampFixtureConnection();

    SchemaMetaStamp::stamp($connection, 'tenant-A');

    expect(fn () => SchemaMetaStamp::stamp($connection, 'tenant-B'))->toThrow(TenantIdentityMismatch::class);

    // Nothing written: still names the original tenant.
    expect(SchemaMetaIdentity::of($connection))->toBe('tenant-A');
});
