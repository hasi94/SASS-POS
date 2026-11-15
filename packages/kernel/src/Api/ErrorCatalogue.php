<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;

/**
 * Every error code any booted provider has declared, validated at
 * registration (T-021 Ruling 2, AC-9 to AC-13).
 *
 * **Lazy and memoised against the list of provider classes it has seen,**
 * not against a boolean "built yet": a module booted later, as
 * `ModuleRegistry::bootAll()` does inside tests, is picked up on the next
 * read (AC-13), while a read that sees the same provider classes as before
 * reuses the already-validated table rather than re-validating on every
 * call.
 *
 * **It validates while building**, so a malformed or duplicate code is
 * refused the first time this catalogue is ever read after that provider
 * registers, with the offending code named in the exception message - never
 * silently dropped and never silently overwritten.
 */
final class ErrorCatalogue
{
    /**
     * @var array<string, ErrorCode>|null
     */
    private ?array $codes = null;

    /**
     * @var array<string, string>|null module keyed by code
     */
    private ?array $moduleByCode = null;

    /**
     * @var list<class-string>|null the provider classes the memo was built from
     */
    private ?array $builtFrom = null;

    public function __construct(private readonly Application $app) {}

    /**
     * The entry for `$code`, or the degraded answer (AC-12).
     */
    public function resolve(string $code): ErrorCode
    {
        $table = $this->table();

        if (isset($table[$code])) {
            return $table[$code];
        }

        if (! $this->app->environment('production')) {
            throw new UnknownErrorCode("Unknown error code [{$code}].");
        }

        Log::error('Unknown error code.', ['code' => $code]);

        return $table[KernelErrorCodes::SERVER_ERROR];
    }

    public function has(string $code): bool
    {
        return isset($this->table()[$code]);
    }

    /**
     * The module that registered `$code`, or null when it is unknown.
     */
    public function moduleOf(string $code): ?string
    {
        $this->table();

        return $this->moduleByCode[$code] ?? null;
    }

    /**
     * Every entry, sorted by code.
     *
     * @return list<ErrorCode>
     */
    public function all(): array
    {
        $table = $this->table();
        ksort($table);

        return array_values($table);
    }

    /**
     * @return array<string, ErrorCode>
     */
    private function table(): array
    {
        $providerClasses = $this->providerClasses();

        if ($this->codes !== null && $this->builtFrom === $providerClasses) {
            return $this->codes;
        }

        $codes = [];
        $moduleByCode = [];

        foreach ($this->providers() as $provider) {
            foreach ($provider->errorCodes() as $module => $declarations) {
                if (! preg_match('/^[a-z0-9_]+$/', $module)) {
                    throw new ErrorCatalogueRefused("Error code module key [{$module}] is malformed.");
                }

                foreach ($declarations as $declaration) {
                    $this->register($codes, $moduleByCode, $module, $declaration);
                }
            }
        }

        $this->codes = $codes;
        $this->moduleByCode = $moduleByCode;
        $this->builtFrom = $providerClasses;

        return $codes;
    }

    /**
     * @param  array<string, ErrorCode>  $codes
     * @param  array<string, string>  $moduleByCode
     */
    private function register(array &$codes, array &$moduleByCode, string $module, ErrorCode $declaration): void
    {
        $code = $declaration->code;

        if (! preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $code)) {
            throw new ErrorCatalogueRefused("Error code [{$code}] does not match <module>.<snake_case>.");
        }

        $prefix = substr($code, 0, strpos($code, '.'));

        if ($prefix !== $module) {
            throw new ErrorCatalogueRefused("Error code [{$code}] does not belong to the registering module [{$module}].");
        }

        if (isset($codes[$code])) {
            throw new ErrorCatalogueRefused("Error code [{$code}] is already registered.");
        }

        if ($declaration->status < 400 || $declaration->status > 599) {
            throw new ErrorCatalogueRefused("Error code [{$code}] has an out-of-range status [{$declaration->status}].");
        }

        if ($declaration->message === '') {
            throw new ErrorCatalogueRefused("Error code [{$code}] has an empty message.");
        }

        $codes[$code] = $declaration;
        $moduleByCode[$code] = $module;
    }

    /**
     * @return list<DeclaresErrorCodes>
     */
    private function providers(): array
    {
        $providers = [];

        foreach ($this->app->getProviders(DeclaresErrorCodes::class) as $provider) {
            if ($provider instanceof DeclaresErrorCodes) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * @return list<class-string>
     */
    private function providerClasses(): array
    {
        $classes = [];

        foreach ($this->app->getProviders(DeclaresErrorCodes::class) as $provider) {
            $classes[] = $provider::class;
        }

        return $classes;
    }
}
