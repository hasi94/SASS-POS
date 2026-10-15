<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\PublishCatalogue;

use Flexipos\Kernel\Schema\Exceptions\CataloguePublishRefused;
use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Write the catalogue of a tenant migration tree into `schema_versions`
 * (AC-31).
 *
 * Three properties, and the order the method does things in is what makes two
 * of them true rather than likely:
 *
 * 1. **Every refusal is decided before the first insert.** The whole tree is
 *    read, compared with what is already published, and only then written. So
 *    "nothing is written" is structural: there is no path on which a row is
 *    inserted and a later file turns out to be a refusal. A transaction would
 *    give the same end state but a worse proof - a rolled-back insert still
 *    consumed an auto-increment on some engines, and the version column here is
 *    deliberately not one.
 * 2. **A published row is never updated and never deleted.** A version is a
 *    number tenants already carry in `tenant_databases.schema_version`;
 *    rewriting the row underneath them would move every tenant's position
 *    without touching a single tenant database. The only write this handler
 *    performs is an insert of a version that is not yet published.
 * 3. **Idempotence is by migration name, not by row count.** The second run
 *    inserts nothing and reports an empty `inserted` list, which is the
 *    observable "the second run changes nothing" is asserted on.
 *
 * It writes to the **master** connection, which in the suite is in-memory
 * SQLite, so the whole slice is provable with no engine at all.
 */
final class PublishCatalogueHandler
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @throws CataloguePublishRefused when the tree disagrees with what is
     *                                 already published; nothing is written
     */
    public function handle(PublishCatalogue $command): PublishCatalogueResult
    {
        $catalogue = MigrationCatalogue::forTree($command->treeDirectory);

        if ($catalogue === []) {
            throw CataloguePublishRefused::emptyTree($command->treeDirectory);
        }

        $published = $this->published();

        // Every refusal first, over the whole tree, before any insert.
        foreach ($published as $migration => $row) {
            $entry = $this->entryNamed($catalogue, $migration);

            if (! $entry instanceof MigrationCatalogue) {
                throw CataloguePublishRefused::publishedMigrationMissing($migration, (int) $row['version']);
            }

            if ($entry->version !== (int) $row['version']) {
                throw CataloguePublishRefused::versionMoved($migration, (int) $row['version'], $entry->version);
            }

            if ($entry->checksum !== (string) $row['checksum']) {
                throw CataloguePublishRefused::publishedContentChanged($migration, (int) $row['version']);
            }
        }

        $inserted = [];

        foreach ($catalogue as $entry) {
            if (array_key_exists($entry->migration, $published)) {
                continue;
            }

            $this->table()->insert($entry->toRow());
            $inserted[] = $entry->migration;
        }

        return new PublishCatalogueResult(
            inserted: $inserted,
            rows: array_map(static fn (MigrationCatalogue $entry): array => $entry->toRow(), $catalogue),
        );
    }

    /**
     * The catalogue entry for one migration name, or null when the tree no
     * longer holds it.
     *
     * @param  list<MigrationCatalogue>  $catalogue
     */
    private function entryNamed(array $catalogue, string $migration): ?MigrationCatalogue
    {
        foreach ($catalogue as $entry) {
            if ($entry->migration === $migration) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * What `schema_versions` already holds, keyed by migration name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function published(): array
    {
        $rows = [];

        foreach ($this->table()->orderBy('version')->get() as $row) {
            $row = (array) $row;
            $rows[(string) $row['migration']] = $row;
        }

        return $rows;
    }

    private function table(): Builder
    {
        return $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table('schema_versions');
    }
}
