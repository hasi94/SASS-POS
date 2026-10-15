<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema;

use Flexipos\Kernel\Schema\Features\MigrateTenant\ArtisanTenantMigrationApplier;
use Flexipos\Kernel\Schema\Features\MigrateTenant\TenantMigrationApplier;
use Flexipos\Kernel\Schema\Features\PublishCatalogue\PublishCatalogueCommand;
use Flexipos\Kernel\Schema\Features\ReportSchemaStatus\SchemaStatusCommand;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\TenantMigrateCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the schema slices' entry points.
 *
 * Commands are registered inside `$this->app->runningInConsole()`, so an HTTP
 * request never constructs them. Almost nothing here binds a handler: every
 * handler's constructor takes framework contracts only, so the container builds
 * them by reflection and a binding would be a second place for the same wiring
 * to be wrong.
 *
 * **The one binding is the applier, and it is an interface, which is why it
 * needs one.** `TenantMigrationApplier` is the seam the sqlite half of step 11
 * replaces with a fake; the container cannot guess an implementation for an
 * interface, and binding it here is what makes the shipped behaviour - the
 * framework's own migrator, through the tenant connection `Tenancy::enter()`
 * wrote - the default that a test has to deliberately override.
 */
final class SchemaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TenantMigrationApplier::class, ArtisanTenantMigrationApplier::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                PublishCatalogueCommand::class,
                SchemaStatusCommand::class,
                TenantMigrateCommand::class,
            ]);
        }
    }
}
