<?php

declare(strict_types=1);

namespace Flexipos\Access\Registry;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Contracts\Access\DeclaresPermissions;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Illuminate\Foundation\Application;

/**
 * Every permission key the booted application declares (AC-14, D-13).
 *
 * **It publishes its domain, and that is not decoration (D-27, shape 7).**
 * The set this class returns is "the declarations of the service providers
 * registered in *this* process", which is narrower than "every key the
 * product has": a module absent from `MODULES` declares nothing here. An
 * instrument that discriminated perfectly over the keys a test happened to
 * list would be invisible to the one mutation that matters - a key nobody
 * listed - so {@see self::keysByProvider()} and {@see self::providerNames()}
 * exist for a sweep to print what it examined and assert it non-empty.
 *
 * **Providers, not configuration, and not a list in this file.** A module
 * declares its keys by implementing {@see DeclaresPermissions} on its own
 * provider; this class asks the container which registered providers do. A
 * second list here would be the one that goes out of step the day a module is
 * added.
 *
 * **No memo.** The declarations are recomputed on every call: the set changes
 * when a provider registers, which is exactly what a test registering a
 * scratch declaration does, and a cache keyed on nothing would hand it the
 * previous answer. There is no measurement that says this read is expensive
 * (`docs/patterns.md` section 5), and it is performed once per sync, not per
 * request.
 */
final class PermissionRegistry
{
    public function __construct(private readonly Application $app) {}

    /**
     * The registered providers that declare permissions.
     *
     * @return list<DeclaresPermissions>
     */
    public function providers(): array
    {
        $providers = [];

        foreach ($this->app->getProviders(DeclaresPermissions::class) as $provider) {
            if ($provider instanceof DeclaresPermissions) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * The class names of those providers, in registration order.
     *
     * The published half of the domain: a sweep prints this so a reader can
     * see which modules were booted when the keys were counted.
     *
     * @return list<string>
     */
    public function providerNames(): array
    {
        return array_map(static fn (DeclaresPermissions $provider): string => $provider::class, $this->providers());
    }

    /**
     * Every declared key, by the provider that declared it.
     *
     * @return array<string, list<string>>
     */
    public function keysByProvider(): array
    {
        $keys = [];

        foreach ($this->providers() as $provider) {
            $declared = array_map(
                static fn (PermissionDeclaration $declaration): string => $declaration->key,
                $provider->permissionDeclarations()
            );

            sort($declared);

            $keys[$provider::class] = $declared;
        }

        return $keys;
    }

    /**
     * Every declaration, sorted by key, duplicates collapsed.
     *
     * Two providers declaring the same key **identically** is not an error -
     * a shared capability can legitimately be named by both - and the
     * declaration is collapsed to one. Two providers declaring it
     * **differently** is refused, because the sync would otherwise store
     * whichever provider happened to boot last, and `is_branch_scoped`
     * silently changing meaning is the specific harm AC-15 refuses a flip
     * for.
     *
     * The malformed and over-long cases cannot arrive here: a
     * {@see PermissionDeclaration} validates its own key at construction, so
     * such a declaration never becomes a value.
     *
     * @return list<PermissionDeclaration>
     *
     * @throws AccessRefused when one key is declared twice with different attributes
     */
    public function declarations(): array
    {
        /** @var array<string, PermissionDeclaration> $declarations */
        $declarations = [];
        /** @var array<string, string> $sources */
        $sources = [];

        foreach ($this->providers() as $provider) {
            foreach ($provider->permissionDeclarations() as $declaration) {
                $key = $declaration->key;

                if (isset($declarations[$key]) && ! $declarations[$key]->agreesWith($declaration)) {
                    throw AccessRefused::validation(
                        "Permission key [{$key}] is declared with different attributes by ["
                        .$sources[$key].'] and ['.$provider::class.'].'
                    );
                }

                $declarations[$key] = $declaration;
                $sources[$key] = $provider::class;
            }
        }

        ksort($declarations);

        return array_values($declarations);
    }

    /**
     * Every declared key, sorted.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(
            static fn (PermissionDeclaration $declaration): string => $declaration->key,
            $this->declarations()
        );
    }
}
