<?php

use Flexipos\Kernel\Tenancy\CachingTenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionFailed;
use Flexipos\Kernel\Tenancy\Exceptions\TenantCredentialRejected;
use Flexipos\Kernel\Tenancy\TenantDatabase;
use Illuminate\Database\Connection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| AC-16: a rotated credential is re-read, and the retry happens once
|--------------------------------------------------------------------------
|
| T-008 BUG-4. The branch had the pieces - a caching reader with evict(), a
| mapping source, one connection choke point - and no path that joined them:
| Tenancy::enter() made one attempt and nothing under src/Tenancy evicted or
| re-read. qa's wiring check found the absence; this file is the behaviour.
|
| **The engine half stays NOT VERIFIED (ENGINE_HOLD, G-1).** Whether a real
| server refuses the old credential, and whether the retry then succeeds
| against it, is a question for an engine. What is asserted here is the
| control flow the criterion is about, and the mutation inputs the plan names
| for AC-16 are all present: the mapping is cached **before** the reference is
| rotated, the first attempt fails on **authentication specifically**, and the
| count asserted is **exactly two attempts** - never "at least one retry".
|
| The server's refusal is produced by a connection resolver registered for a
| driver that exists only here: its getPdo() raises MySQL's access-denied
| error for the first N attempts and opens the SQLite fixture afterwards. That
| is the one thing the stand-in cannot do on its own, and faking it at the
| driver seam leaves everything above it - the choke point, the cache, the
| secret resolver, the stanza - exactly as it ships.
|
| The third case is the one that keeps the second honest: a failure that is
| **not** a credential rejection is not retried. Without it, "retry on
| anything" passes every assertion above.
*/

require_once __DIR__.'/../Support/tenancy-fixture.php';

uses(TestCase::class);

/**
 * The driver name the rejecting resolver is registered under. It is not in the
 * shipped allowed_drivers list, so no other test or deployment can reach it.
 */
const ROTATION_DRIVER = 'flexipos-rejecting';

/**
 * MySQL's and MariaDB's refusal, as PDO reports it.
 */
function rotationAccessDenied(string $username): PDOException
{
    $exception = new PDOException(sprintf(
        "SQLSTATE[28000] [1045] Access denied for user '%s'@'localhost' (using password: YES)",
        $username
    ));

    $exception->errorInfo = ['28000', 1045, 'Access denied for user'];

    return $exception;
}

/**
 * The test's view of the server: how many attempts it refuses, and how many
 * connections were actually opened - the number AC-16 is about.
 */
final class RotationServerState
{
    public int $attempts = 0;

    public function __construct(public readonly int $rejections) {}
}

/**
 * A mapping source whose record can be rotated between reads, counting them.
 */
final class RotationMappingSource implements TenantDatabaseRepository
{
    public int $reads = 0;

    public function __construct(public string $credentialRef) {}

    public function find(string $tenantId): ?TenantDatabase
    {
        $this->reads++;

        if ($tenantId !== 'tenant-a') {
            return null;
        }

        return new TenantDatabase(
            tenantId: $tenantId,
            driver: ROTATION_DRIVER,
            host: 'db-fixture-a',
            port: 3307,
            database: tenancyFixtureDatabasePath('tenant-a'),
            username: 'flx_ta_app',
            credentialRef: $this->credentialRef,
            status: 'active',
            schemaVersion: 0,
            credentialVersion: 1,
        );
    }
}

/**
 * Register the rejecting driver and return the counter it increments.
 */
function rotationServer(int $rejections): RotationServerState
{
    $state = new RotationServerState($rejections);

    Connection::resolverFor(ROTATION_DRIVER, function ($pdo, $database, $prefix, $config) use ($state): Connection {
        return new class($pdo, $database, $prefix, $config, $state) extends Connection
        {
            /**
             * @param  array<string, mixed>  $config
             */
            public function __construct(
                $pdo,
                string $database,
                string $prefix,
                array $config,
                private readonly RotationServerState $state,
            ) {
                parent::__construct($pdo, $database, $prefix, $config);
            }

            public function getPdo()
            {
                $this->state->attempts++;

                if ($this->state->attempts <= $this->state->rejections) {
                    throw rotationAccessDenied((string) ($this->getConfig('username') ?? ''));
                }

                return new PDO('sqlite:'.$this->getDatabaseName());
            }
        };
    });

    return $state;
}

/**
 * Bind the caching reader in front of the given source and warm it, exactly as
 * a request that ran before the rotation would have.
 */
function rotationWarmCache(RotationMappingSource $source): CachingTenantDatabaseRepository
{
    $caching = new CachingTenantDatabaseRepository($source, app('cache')->store('array'), 300);

    app()->instance(TenantDatabaseRepository::class, $caching);
    app()->forgetScopedInstances();

    $caching->find('tenant-a');

    return $caching;
}

beforeEach(function () {
    tenancyBootFixture();

    config()->set('tenancy.allowed_drivers', ['sqlite', ROTATION_DRIVER]);

    tenancyWriteEnvironment('TENANT_A_ROTATED_PASSWORD', 'rotated-'.tenancyFixturePassword());
});

afterEach(function () {
    tenancyWriteEnvironment('TENANT_A_ROTATED_PASSWORD', null);
    tenancyForgetFixture();
});

afterAll(function () {
    tenancyDeleteFixtureRoot();
});

it('evicts, re-reads and retries exactly once when the cached credential is refused', function () {
    $server = rotationServer(rejections: 1);
    $source = new RotationMappingSource('env://TENANT_A_APP_PASSWORD');
    rotationWarmCache($source);

    // The precondition the plan names: the mapping is cached *before* the
    // reference is rotated, so the first attempt uses the stale one.
    expect($source->reads)->toBe(1);

    $source->credentialRef = 'env://TENANT_A_ROTATED_PASSWORD';

    tenancy()->enter('tenant-a');

    $stanza = tenancyTenantStanza();

    expect($server->attempts)->toBe(2)
        // Two reads: the warm-up, and the one after the eviction. A retry that
        // reused the cached record would leave this at one and still connect.
        ->and($source->reads)->toBe(2)
        ->and((string) $stanza['password'])->toBe('rotated-'.tenancyFixturePassword())
        ->and(tenancy()->current()->tenantId())->toBe('tenant-a');

    tenancy()->leave();
});

it('surfaces TenantConnectionFailed after exactly two attempts when the new credential is refused too', function () {
    $server = rotationServer(rejections: 2);
    $source = new RotationMappingSource('env://TENANT_A_APP_PASSWORD');
    rotationWarmCache($source);

    $before = tenancyTenantStanza();

    expect(static fn () => tenancy()->enter('tenant-a'))
        ->toThrow(TenantConnectionFailed::class);

    // Exactly two: not three, and not one. A loop would make this the
    // rejection count plus one, and no retry at all would make it one.
    expect($server->attempts)->toBe(2)
        ->and($source->reads)->toBe(2)
        ->and(tenancy()->current()->hasTenant())->toBeFalse()
        ->and(tenancyTenantStanza())->toBe($before);

    try {
        tenancy()->enter('tenant-a');
    } catch (TenantConnectionFailed $failed) {
        expect($failed->getMessage())->not->toContain(tenancyFixturePassword())
            ->and($failed->getMessage())->toContain('tenant-a');
    }
});

it('does not retry a failure a re-read cannot fix', function () {
    // The control. The server is unreachable rather than refusing the
    // credential, so the mapping is not re-read and there is one attempt.
    $server = rotationServer(rejections: 0);
    $source = new RotationMappingSource('env://TENANT_A_APP_PASSWORD');
    rotationWarmCache($source);

    Connection::resolverFor(ROTATION_DRIVER, function ($pdo, $database, $prefix, $config) use ($server): Connection {
        return new class($pdo, $database, $prefix, $config, $server) extends Connection
        {
            /**
             * @param  array<string, mixed>  $config
             */
            public function __construct(
                $pdo,
                string $database,
                string $prefix,
                array $config,
                private readonly RotationServerState $state,
            ) {
                parent::__construct($pdo, $database, $prefix, $config);
            }

            public function getPdo()
            {
                $this->state->attempts++;

                throw new PDOException('SQLSTATE[HY000] [2002] No such host is known');
            }
        };
    });

    expect(static fn () => tenancy()->enter('tenant-a'))->toThrow(TenantConnectionFailed::class);

    expect($server->attempts)->toBe(1)
        ->and($source->reads)->toBe(1);
});

it('recognises the server refusing a credential, and nothing else', function () {
    $denied = rotationAccessDenied('flx_ta_app');
    $unreachable = new PDOException('SQLSTATE[HY000] [2002] No such host is known');
    $missingDatabase = new PDOException('SQLSTATE[42000] [1049] Unknown database \'shop_x\'');

    expect(TenantCredentialRejected::describes($denied))->toBeTrue()
        ->and(TenantCredentialRejected::describes($unreachable))->toBeFalse()
        ->and(TenantCredentialRejected::describes($missingDatabase))->toBeFalse()
        // A rejection is a connection failure, so every caller that already
        // handles one keeps working. Read from the class hierarchy rather
        // than asserted as a literal, so a future sibling - one that no
        // longer extends it - makes this red.
        ->and(class_parents(TenantCredentialRejected::class))
        ->toHaveKey(TenantConnectionFailed::class);
});
