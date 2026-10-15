<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema;

use Flexipos\Kernel\Schema\Features\PublishCatalogue\PublishCatalogueCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the schema slices' entry points.
 *
 * Commands are registered inside `$this->app->runningInConsole()`, so an HTTP
 * request never constructs them. Nothing here binds a handler: every handler's
 * constructor takes framework contracts only, so the container builds them by
 * reflection and a binding would be a second place for the same wiring to be
 * wrong.
 *
 * One command so far (`schema:publish`, step 5); `tenant:migrate` and
 * `tenant:schema-status` are added by the steps that ship them.
 */
final class SchemaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PublishCatalogueCommand::class,
            ]);
        }
    }
}
