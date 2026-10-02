<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?string $originalModules = null;

    private bool $modulesOverridden = false;

    /**
     * Pin MODULES to the value phpunit.xml declares, before the application is
     * created.
     *
     * PHPUnit's `<env name="MODULES" value="demo" force="true"/>` writes
     * putenv() and $_ENV but never $_SERVER, and Laravel's Env repository reads
     * $_SERVER first. Without this, a MODULES variable exported by a developer
     * or a CI runner beats phpunit.xml and every test dies inside setUp() with
     * an UnknownModuleException, before any test body can correct it. The suite
     * must depend on the repository, never on the machine it runs on.
     */
    protected function setUp(): void
    {
        $this->writeModulesEnvironment(
            array_key_exists('MODULES', $_ENV) ? (string) $_ENV['MODULES'] : null
        );

        parent::setUp();
    }

    /**
     * Rebuild the application as if the host process had been started with the
     * given MODULES value. Passing null boots the host with MODULES unset.
     *
     * The value is restored after the test, so overriding it cannot leak into
     * the next test in the same process.
     */
    protected function bootHostWith(?string $modules): void
    {
        if (! $this->modulesOverridden) {
            $this->originalModules = $this->readModulesEnvironment();
            $this->modulesOverridden = true;
        }

        $this->writeModulesEnvironment($modules);

        $this->refreshApplication();
    }

    protected function tearDown(): void
    {
        if ($this->modulesOverridden) {
            $this->writeModulesEnvironment($this->originalModules);
            $this->modulesOverridden = false;
        }

        parent::tearDown();
    }

    private function readModulesEnvironment(): ?string
    {
        foreach ([$_SERVER['MODULES'] ?? null, $_ENV['MODULES'] ?? null, getenv('MODULES')] as $value) {
            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    private function writeModulesEnvironment(?string $modules): void
    {
        if ($modules === null) {
            unset($_ENV['MODULES'], $_SERVER['MODULES']);
            putenv('MODULES');

            return;
        }

        $_ENV['MODULES'] = $modules;
        $_SERVER['MODULES'] = $modules;
        putenv('MODULES='.$modules);
    }
}
