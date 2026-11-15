<?php

use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\Exceptions\ProvisioningNotConfigured;
use Flexipos\Kernel\Tenancy\Provisioning\MySqlTenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\RecordingTenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\RefusingTenantDatabaseProvisioner;
use Tests\TestCase;

uses(TestCase::class);

it('resolves the refusing implementation by default, which throws', function () {
    expect(app(TenantDatabaseProvisioner::class))->toBeInstanceOf(RefusingTenantDatabaseProvisioner::class);

    expect(fn () => app(TenantDatabaseProvisioner::class)->databaseState(
        new ProvisioningServer('srv1', '127.0.0.1', 3306, 'mysql', 'required', 'env://X'),
        'flx_t_000001',
    ))->toThrow(ProvisioningNotConfigured::class);
});

it('resolves the MySQL implementation only when driver=mysql is set explicitly', function () {
    config(['tenancy.provisioning.driver' => 'mysql']);
    app()->forgetInstance(TenantDatabaseProvisioner::class);

    expect(app(TenantDatabaseProvisioner::class))->toBeInstanceOf(MySqlTenantDatabaseProvisioner::class);
});

it('resolves the recording stand-in when a test explicitly asks for it', function () {
    config(['tenancy.provisioning.driver' => 'recording']);
    app()->forgetInstance(TenantDatabaseProvisioner::class);

    expect(app(TenantDatabaseProvisioner::class))->toBeInstanceOf(RecordingTenantDatabaseProvisioner::class);
});

it('never binds the MySQL implementation under the suite\'s own default configuration', function () {
    // The control for ENGINE_HOLD: phpunit.xml never sets
    // tenancy.provisioning.driver, so the default binding - resolved with
    // no test having touched the config at all - must not be the real one.
    expect(config('tenancy.provisioning.driver'))->toBe('refusing');
});

it('has TENANCY_PROVISIONING_DRIVER pinned to refusing by phpunit.xml itself (review round 1 W-6)', function () {
    // Distinct from the test above: this one fails if phpunit.xml's own
    // <env>/<server> pin for TENANCY_PROVISIONING_DRIVER were ever removed,
    // even though config('tenancy.provisioning.driver') would still read
    // "refusing" from config/tenancy.php's own unset-env default in that
    // case - this assertion is on the environment variable itself, the same
    // way ENGINE_HOLD's own pin is proven by EngineHoldIgnoresTheEnvironmentTest.
    expect($_SERVER['TENANCY_PROVISIONING_DRIVER'] ?? null)->toBe('refusing')
        ->and($_ENV['TENANCY_PROVISIONING_DRIVER'] ?? null)->toBe('refusing');
});
