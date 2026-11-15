<?php

use Flexipos\Kernel\Tenancy\CachingTenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Flexipos\Kernel\Tenancy\Exceptions\MappingSourceNotPermitted;
use Flexipos\Kernel\Tenancy\InMemoryTenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\InMemoryTenantMigrationTargetRepository;
use Flexipos\Kernel\Tenancy\MappingSource;
use Flexipos\Kernel\Tenancy\Secrets\EnvSecretResolver;
use Tests\TestCase;

/*
 * Review S-4: the configuration mapping stand-in is refused outside local
 * development.
 *
 * `TENANCY_MAPPING_SOURCE=config` makes the process answer "where does this
 * tenant's database live" out of a configuration array. In a deployment that
 * is tenants served from a file - no status, no schema version, no licence.
 * It was already blocked *incidentally*, because the only credential scheme
 * the shipped container resolves is env:// and EnvSecretResolver refuses that
 * outside local development; the first task that registers file:// or vault://
 * removes that block without reading the tenancy bindings at all. These cases
 * pin the refusal to the place the source is chosen instead.
 *
 * Nothing here is an [E] criterion: no engine is touched, and the stand-in's
 * own behaviour is unchanged in the environments that may use it.
 */

uses(TestCase::class);

/**
 * Run a closure with the application reporting a given environment, with both
 * repository bindings forgotten before and after so each case resolves them
 * fresh.
 */
function mappingSourceInEnvironment(string $environment, Closure $case): void
{
    $ambient = app()->environment();

    app()->instance('env', $environment);
    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);

    try {
        $case();
    } finally {
        app()->instance('env', $ambient);
        app()->forgetInstance(TenantDatabaseRepository::class);
        app()->forgetInstance(TenantMigrationTargetRepository::class);
    }
}

it('refuses the configuration stand-in outside local development, from both bindings', function () {
    config()->set(MappingSource::KEY, MappingSource::CONFIG);

    mappingSourceInEnvironment('production', function () {
        // The serving mapping, and the runner's. They are separate bindings and
        // a refusal in only one of them would leave the other serving a
        // deployment from a file.
        expect(fn () => app(TenantDatabaseRepository::class))
            ->toThrow(MappingSourceNotPermitted::class, 'refused in the [production] environment');

        expect(fn () => app(TenantMigrationTargetRepository::class))
            ->toThrow(MappingSourceNotPermitted::class, 'refused in the [production] environment');

        // The operator is told which key to change, because a refusal naming
        // only the environment leaves them hunting for the switch.
        try {
            app(TenantDatabaseRepository::class);
        } catch (MappingSourceNotPermitted $refusal) {
            expect($refusal->getMessage())->toContain(MappingSource::KEY)
                ->and($refusal->getMessage())->toContain('[master]');
        }
    });
});

it('refuses it in an environment nobody anticipated, not only one called production', function () {
    // The R-6 shape: a deny-list on the name "production" protects only
    // deployments that happen to use that name. This is the case a
    // `!== 'production'` test would pass.
    config()->set(MappingSource::KEY, MappingSource::CONFIG);

    mappingSourceInEnvironment('live-eu-1', function () {
        expect(fn () => app(TenantDatabaseRepository::class))
            ->toThrow(MappingSourceNotPermitted::class, 'refused in the [live-eu-1] environment');
    });
});

it('still hands the stand-in to every environment that may use it', function () {
    // The fixtures of T-003 and T-007 run on these, and the refusal must not
    // have cost them their no-master-schema proofs.
    config()->set(MappingSource::KEY, MappingSource::CONFIG);

    foreach (EnvSecretResolver::LOCAL_ENVIRONMENTS as $environment) {
        mappingSourceInEnvironment($environment, function () use ($environment) {
            expect(app(TenantDatabaseRepository::class))
                ->toBeInstanceOf(InMemoryTenantDatabaseRepository::class)
                ->and(app(TenantMigrationTargetRepository::class))
                ->toBeInstanceOf(InMemoryTenantMigrationTargetRepository::class)
                ->and(app()->environment())->toBe($environment);
        });
    }
});

it('leaves the shipped source alone in production', function () {
    // The refusal is about one value of the key. `master` is what a deployment
    // runs on and nothing here may stand in its way.
    config()->set(MappingSource::KEY, MappingSource::MASTER);

    mappingSourceInEnvironment('production', function () {
        expect(app(TenantDatabaseRepository::class))
            ->toBeInstanceOf(CachingTenantDatabaseRepository::class);
    });
});
