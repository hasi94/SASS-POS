<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\PublishCatalogue;

use Flexipos\Kernel\Schema\Exceptions\CataloguePublishRefused;
use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * `php artisan schema:publish` - the slice's entry point, and the only place
 * the shipped tree's location is read.
 *
 * The directory comes from `config('schema.tenant_migration_path')`, which is
 * the one authority for it: the runner, the baseline test and the static tree
 * sweep all read the same key, so moving the tree is one edit rather than a
 * search. Nothing here repeats the directory name.
 *
 * Two jobs, in this order:
 *
 * 1. publish the catalogue into `schema_versions` on the master, which is the
 *    handler's work and refuses rather than renumbers (AC-31);
 * 2. compare the result with the **committed** fixture, and exit non-zero when
 *    they differ. That exit code is what makes AC-32 mechanical: a tenant
 *    migration added without publishing, or a published migration whose content
 *    changed, is a difference in a file a reviewer sees rather than a
 *    divergence nobody notices until a tenant is migrated.
 *
 * `--write-fixture` rewrites the committed fixture from the tree **and
 * publishes nothing**. Two reasons it returns before the handler rather than
 * after it:
 *
 * - the fixture is a function of the branch alone, so writing it must not
 *   require a database. While the engine is on hold, publishing is reachable
 *   only in the suite's in-memory master, and a fixture that could only be
 *   regenerated with a live master would not be regenerable at all;
 * - it is a separate flag and not the default, because silently rewriting the
 *   file the comparison is against would make the comparison incapable of
 *   failing.
 */
final class PublishCatalogueCommand extends Command
{
    protected $signature = 'schema:publish
        {--write-fixture : Rewrite the committed catalogue fixture from the tree}';

    protected $description = 'Publish the tenant migration catalogue into schema_versions and check the committed fixture';

    public function handle(PublishCatalogueHandler $handler, ConfigRepository $config): int
    {
        $tree = $this->laravel->basePath((string) $config->get('schema.tenant_migration_path'));
        $fixture = $this->laravel->basePath((string) $config->get('schema.catalogue_fixture'));

        if ($this->option('write-fixture')) {
            file_put_contents($fixture, MigrationCatalogue::encodeFixture(MigrationCatalogue::forTree($tree)));
            $this->components->info('Wrote the catalogue fixture at '.$fixture.'. Nothing was published.');

            return self::SUCCESS;
        }

        try {
            $result = $handler->handle(new PublishCatalogue($tree));
        } catch (CataloguePublishRefused $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        foreach ($result->inserted as $migration) {
            $this->components->twoColumnDetail($migration, 'published');
        }

        if ($result->inserted === []) {
            $this->components->info('The catalogue was already published; nothing was written.');
        }

        $catalogue = MigrationCatalogue::forTree($tree);

        $committed = is_file($fixture) ? MigrationCatalogue::decodeFixture((string) file_get_contents($fixture)) : [];
        $actual = array_map(static fn (MigrationCatalogue $entry): array => $entry->toRow(), $catalogue);

        if ($committed !== $actual) {
            $this->components->error(
                'The committed catalogue fixture at '.$fixture.' does not match the tenant migration tree.'
                .' Re-run with --write-fixture and commit the result, or restore the migration whose checksum moved.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
