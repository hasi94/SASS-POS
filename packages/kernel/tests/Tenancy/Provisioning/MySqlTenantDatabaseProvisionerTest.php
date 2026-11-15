<?php

use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\Exceptions\TenantNameRejected;
use Flexipos\Kernel\Tenancy\Provisioning\MySqlTenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\SecretStatement;
use Flexipos\Kernel\Tenancy\Provisioning\TenantGrant;
use Flexipos\Kernel\Tenancy\Provisioning\TenantStatementExecutor;
use Flexipos\Kernel\Tenancy\Secrets\EnvSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\SchemeSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| T-026 step 4: the real implementation, proven with no socket opened
|--------------------------------------------------------------------------
|
| The recording statement-runner seam: MySqlTenantDatabaseProvisioner is
| constructed with its own $executorFactory, which this file hands a
| recording double instead of PdoTenantStatementExecutor. Nothing here ever
| builds a PDO, so this is structurally incapable of reaching
| 127.0.0.1:3306 - the same shape AC-16 stays NOT VERIFIED behind.
*/

/**
 * A recording TenantStatementExecutor: never opens a socket, records every
 * call with the password already resolved to a marker.
 */
final class RecordingStatementExecutor implements TenantStatementExecutor
{
    /** @var list<string> */
    public array $exec = [];

    /** @var list<array<string, mixed>> */
    public array $queryResults = [];

    public function exec(string $sql): void
    {
        $this->exec[] = $sql;
    }

    public function execSecret(SecretStatement $statement): void
    {
        $this->exec[] = $statement->sql();
    }

    public function query(string $sql): array
    {
        /** @var list<array<string, mixed>> $result */
        $result = array_shift($this->queryResults) ?? [];

        return $result;
    }
}

function mysqlProvisionerServer(): ProvisioningServer
{
    return new ProvisioningServer('srv1', '127.0.0.1', 3306, 'mysql', 'required', 'env://PROVISIONER_CRED');
}

function mysqlProvisioner(RecordingStatementExecutor $executor): MySqlTenantDatabaseProvisioner
{
    Config::set('tenancy.provisioning.user_host', '%');

    return new MySqlTenantDatabaseProvisioner(
        new SchemeSecretResolver([EnvSecretResolver::SCHEME => new EnvSecretResolver(app())]),
        app('config'),
        fn () => $executor,
    );
}

it('issues createUser as createUserIfNotExists, alterUser, then grant, with the password taking only the raw path', function () {
    $executor = new RecordingStatementExecutor;
    $provisioner = mysqlProvisioner($executor);

    $provisioner->createUser(
        mysqlProvisionerServer(),
        'flx_t000001_app',
        new Secret('s3cret-DO-NOT-LEAK'),
        TenantGrant::App,
        'flx_t_000001',
    );

    expect($executor->exec)->toHaveCount(3)
        ->and($executor->exec[0])->toContain('create user if not exists')
        ->and($executor->exec[0])->toContain("'s3cret-DO-NOT-LEAK'")
        ->and($executor->exec[1])->toContain('alter user')
        ->and($executor->exec[1])->toContain("'s3cret-DO-NOT-LEAK'")
        ->and($executor->exec[2])->toContain('grant SELECT, INSERT, UPDATE, DELETE, EXECUTE on');
});

it('never lets a bad identifier reach the executor', function () {
    $executor = new RecordingStatementExecutor;
    $provisioner = mysqlProvisioner($executor);

    expect(fn () => $provisioner->createDatabase(mysqlProvisionerServer(), 'DROP_TABLE_users', 'utf8mb4', 'utf8mb4_unicode_ci'))
        ->toThrow(TenantNameRejected::class);

    expect($executor->exec)->toBe([]);
});

it('accepts a mechanically produced identifier through both checks at once', function () {
    $executor = new RecordingStatementExecutor;
    $provisioner = mysqlProvisioner($executor);

    $provisioner->dropUser(mysqlProvisionerServer(), 'flx_t000001_app');

    expect($executor->exec)->toHaveCount(1)
        ->and($executor->exec[0])->toContain('drop user if exists');
});
