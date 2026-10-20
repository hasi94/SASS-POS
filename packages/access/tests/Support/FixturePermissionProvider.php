<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Contracts\Access\DeclaresPermissions;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * A service provider that declares whatever a test hands it.
 *
 * **It exists so that "a key nobody listed" is a thing a test can produce**
 * (D-27, shape 7). The registry's domain is the booted providers that
 * declare permissions; the only honest mutation against an instrument that
 * sweeps that domain is a declaration arriving from outside every list the
 * test wrote, and that needs a provider the test can register at runtime.
 *
 * It declares no key by default and binds nothing, so registering it changes
 * the application in exactly one way: the registry sees one more source.
 *
 * @phpstan-consistent-constructor the one subclass adds no constructor, which
 * is what makes `new static` in declaring() safe
 */
class FixturePermissionProvider extends ServiceProvider implements DeclaresPermissions
{
    /** @var list<PermissionDeclaration> */
    protected array $declarations = [];

    /**
     * Register an instance declaring these keys and return it.
     *
     * **A case needing two disagreeing providers needs two provider
     * *classes*, and that is measured rather than assumed.** Laravel keys
     * `Application::$serviceProviders` by class name
     * (`Application::markAsRegistered()`), so a second instance of one class
     * replaces the first instead of joining it, with or without `force`.
     * {@see SecondFixturePermissionProvider} exists for exactly that, and
     * this method is `new static` so it serves both.
     *
     * `force: true` because `register()` otherwise returns the instance
     * already registered for the class and ignores the declarations handed
     * in here.
     */
    public static function declaring(Application $app, PermissionDeclaration ...$declarations): static
    {
        $provider = new static($app);
        $provider->declarations = array_values($declarations);

        $app->register($provider, true);

        return $provider;
    }

    public function register(): void
    {
        //
    }

    /**
     * @return list<PermissionDeclaration>
     */
    public function permissionDeclarations(): array
    {
        return $this->declarations;
    }
}
