<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?string $originalServices = null;

    private bool $servicesOverridden = false;

    /**
     * Pin SERVICES to the value phpunit.xml declares, before the application is
     * created.
     *
     * PHPUnit's `<env name="SERVICES" value="demo" force="true"/>` writes
     * putenv() and $_ENV but never $_SERVER, and Laravel's Env repository reads
     * $_SERVER first. Without this, a SERVICES variable exported by a developer
     * or a CI runner beats phpunit.xml and every test dies inside setUp() with
     * an UnknownServiceException, before any test body can correct it. The suite
     * must depend on the repository, never on the machine it runs on.
     */
    protected function setUp(): void
    {
        $this->writeServicesEnvironment(
            array_key_exists('SERVICES', $_ENV) ? (string) $_ENV['SERVICES'] : null
        );

        parent::setUp();
    }

    /**
     * Rebuild the application as if the host process had been started with the
     * given SERVICES value. Passing null boots the host with SERVICES unset.
     *
     * The value is restored after the test, so overriding it cannot leak into
     * the next test in the same process.
     */
    protected function bootHostWith(?string $services): void
    {
        if (! $this->servicesOverridden) {
            $this->originalServices = $this->readServicesEnvironment();
            $this->servicesOverridden = true;
        }

        $this->writeServicesEnvironment($services);

        $this->refreshApplication();
    }

    protected function tearDown(): void
    {
        if ($this->servicesOverridden) {
            $this->writeServicesEnvironment($this->originalServices);
            $this->servicesOverridden = false;
        }

        parent::tearDown();
    }

    private function readServicesEnvironment(): ?string
    {
        foreach ([$_SERVER['SERVICES'] ?? null, $_ENV['SERVICES'] ?? null, getenv('SERVICES')] as $value) {
            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    private function writeServicesEnvironment(?string $services): void
    {
        if ($services === null) {
            unset($_ENV['SERVICES'], $_SERVER['SERVICES']);
            putenv('SERVICES');

            return;
        }

        $_ENV['SERVICES'] = $services;
        $_SERVER['SERVICES'] = $services;
        putenv('SERVICES='.$services);
    }
}
