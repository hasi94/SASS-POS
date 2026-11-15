<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Contracts\Audit\DeclaresAuditActions;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * A service provider that declares whatever actions a test hands it
 * (T-017 step 3, the sibling of {@see FixturePermissionProvider}).
 *
 * It declares nothing by default and binds nothing, so registering it
 * changes the application in exactly one way: `AuditCatalogue` sees one more
 * source.
 *
 * @phpstan-consistent-constructor the one subclass adds no constructor, which
 * is what makes `new static` in declaring() safe
 */
class FixtureAuditActionProvider extends ServiceProvider implements DeclaresAuditActions
{
    /** @var array<string, list<string>> */
    protected array $declarations = [];

    /**
     * Register an instance declaring these actions for this module and
     * return it.
     *
     * `force: true` for the reason {@see FixturePermissionProvider::declaring()}
     * gives: `register()` otherwise returns the instance already registered
     * for the class and ignores what is handed in here.
     *
     * @param  list<string>  $actions
     */
    public static function declaring(Application $app, string $module, array $actions): static
    {
        $provider = new static($app);
        $provider->declarations = [$module => $actions];

        $app->register($provider, true);

        return $provider;
    }

    public function register(): void
    {
        //
    }

    /**
     * @return array<string, list<string>>
     */
    public function auditActions(): array
    {
        return $this->declarations;
    }
}
