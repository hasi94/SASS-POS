<?php

use Flexipos\Kernel\Tenancy\Provisioning\ProvisionerStatements;
use Flexipos\Kernel\Tenancy\Provisioning\TenantGrant;
use Flexipos\Kernel\Tenancy\Secrets\Secret;

it('builds the exact CREATE DATABASE with the configured charset and collation', function () {
    expect(ProvisionerStatements::createDatabase('flx_t_000001', 'utf8mb4', 'utf8mb4_unicode_ci'))
        ->toBe('create database `flx_t_000001` character set `utf8mb4` collate `utf8mb4_unicode_ci`');
});

it('builds the exact GRANT list for app and migrate', function () {
    expect(ProvisionerStatements::grant('flx_t000001_app', '%', 'flx_t_000001', TenantGrant::App))
        ->toBe('grant SELECT, INSERT, UPDATE, DELETE, EXECUTE on `flx_t_000001`.* to `flx_t000001_app`@\'%\'')
        ->and(ProvisionerStatements::grant('flx_t000001_mig', '%', 'flx_t_000001', TenantGrant::Migrate))
        ->toBe(
            'grant SELECT, INSERT, UPDATE, DELETE, EXECUTE, CREATE, ALTER, DROP, INDEX, REFERENCES, '
            .'CREATE VIEW, TRIGGER on `flx_t_000001`.* to `flx_t000001_mig`@\'%\''
        );
});

it('builds DROP USER IF EXISTS', function () {
    expect(ProvisionerStatements::dropUser('flx_t000001_app', '%'))
        ->toBe('drop user if exists `flx_t000001_app`@\'%\'');
});

it('builds CREATE USER IF NOT EXISTS and ALTER USER as SecretStatements that show no password', function () {
    $password = new Secret('s3cret-DO-NOT-LEAK');

    $create = ProvisionerStatements::createUserIfNotExists('flx_t000001_app', '%', $password);
    $alter = ProvisionerStatements::alterUser('flx_t000001_app', '%', $password);

    expect($create->sql())->toBe("create user if not exists `flx_t000001_app`@'%' identified by 's3cret-DO-NOT-LEAK'")
        ->and($alter->sql())->toBe("alter user `flx_t000001_app`@'%' identified by 's3cret-DO-NOT-LEAK'");

    foreach ([$create, $alter] as $statement) {
        expect((string) $statement)->not->toContain('s3cret-DO-NOT-LEAK')
            ->and(json_encode($statement))->not->toContain('s3cret-DO-NOT-LEAK')
            ->and(var_export($statement, true))->not->toContain('s3cret-DO-NOT-LEAK')
            ->and(print_r($statement, true))->not->toContain('s3cret-DO-NOT-LEAK');
    }
});
