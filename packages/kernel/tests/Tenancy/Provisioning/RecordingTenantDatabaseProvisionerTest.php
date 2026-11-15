<?php

use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\DatabaseState;
use Flexipos\Kernel\Tenancy\Provisioning\RecordingTenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\TenantGrant;
use Flexipos\Kernel\Tenancy\Secrets\Secret;

function recordingProvisionerSqliteServer(): ProvisioningServer
{
    return new ProvisioningServer('srv1', ':memory:', 0, 'sqlite', 'none', 'env://X');
}

it('reports Absent, then Empty after createDatabase, and records the call with no password', function () {
    // Isolated cwd: createDatabase() on a sqlite-engine server touches a
    // file named exactly the database name in the current working
    // directory (Ruling 4), so this runs inside its own throwaway
    // directory rather than leaving a stray `flx_t_000001` file behind in
    // the repository for another test (or another run) to trip over.
    $root = sys_get_temp_dir().'/flexipos-t026-recording-state-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    $cwd = getcwd();

    try {
        chdir($root);

        $provisioner = new RecordingTenantDatabaseProvisioner;
        $server = recordingProvisionerSqliteServer();

        expect($provisioner->databaseState($server, 'flx_t_000001'))->toBe(DatabaseState::Absent);

        $provisioner->createDatabase($server, 'flx_t_000001', 'utf8mb4', 'utf8mb4_unicode_ci');

        expect($provisioner->databaseState($server, 'flx_t_000001'))->toBe(DatabaseState::Empty);

        $calls = $provisioner->calls();
        expect($calls)->toContain(['method' => 'createDatabase', 'server' => 'srv1', 'name' => 'flx_t_000001', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
    } finally {
        chdir((string) $cwd);
        @unlink($root.'/flx_t_000001');
        @rmdir($root);
    }
});

it('creates an empty sqlite file named exactly the database name, for a sqlite-engine server', function () {
    $root = sys_get_temp_dir().'/flexipos-t026-recording-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    $cwd = getcwd();

    try {
        chdir($root);

        $provisioner = new RecordingTenantDatabaseProvisioner;
        $provisioner->createDatabase(recordingProvisionerSqliteServer(), 'flx_t_000002', 'utf8mb4', 'utf8mb4_unicode_ci');

        expect(is_file($root.'/flx_t_000002'))->toBeTrue();
    } finally {
        chdir((string) $cwd);
        @unlink($root.'/flx_t_000002');
        @rmdir($root);
    }
});

it('records createUser with a password marker, never the real value', function () {
    $provisioner = new RecordingTenantDatabaseProvisioner;
    $server = recordingProvisionerSqliteServer();

    $provisioner->createUser($server, 'flx_t000001_app', new Secret('s3cret-DO-NOT-LEAK'), TenantGrant::App, 'flx_t_000001');

    expect($provisioner->userExists($server, 'flx_t000001_app'))->toBeTrue();

    $serialised = json_encode($provisioner->calls());
    expect($serialised)->not->toContain('s3cret-DO-NOT-LEAK')
        ->and($serialised)->toContain('[recorded]');
});

it('refuses a foreign occupant rather than silently adopting it (AC-5 negative)', function () {
    $provisioner = new RecordingTenantDatabaseProvisioner;
    $provisioner->seedExistingDatabase('flx_t_000003', DatabaseState::Occupied);

    expect($provisioner->databaseState(recordingProvisionerSqliteServer(), 'flx_t_000003'))
        ->toBe(DatabaseState::Occupied);
});
