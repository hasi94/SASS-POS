<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\PublishCatalogue;

/**
 * Publish the catalogue of one tenant migration tree into `schema_versions`.
 *
 * The command object carries an **absolute** tree directory rather than the
 * configuration key it came from, so the handler is testable against a scratch
 * tree and the one authority for the shipped location stays
 * `config('schema.tenant_migration_path')` - read once, by the artisan entry
 * point (`PublishCatalogueCommand`), and never repeated as a literal anywhere
 * else.
 */
final readonly class PublishCatalogue
{
    public function __construct(
        public string $treeDirectory,
    ) {}
}
