<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\MigrateTenant;

use Flexipos\Kernel\Schema\Exceptions\TenantMigrationFailed;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * The shipped applier: the framework's own migrator, one file at a time.
 *
 * **One file per call, and that is the design rather than a loop that could
 * have been one call.** `migrate --path=<directory>` would apply everything in
 * the directory and report a failure without saying which file failed, and
 * AC-44 wants `error_migration` populated. Called per file, the name of the
 * file being applied is known before the call, so a failure names it without
 * parsing console output.
 *
 * It never re-applies: the migrator's own repository holds the tenant's
 * `migrations` table, and a file already listed there is skipped before this
 * class asks for it, which is what AC-46's "the tenants already done have
 * unchanged migrations tables" rests on.
 *
 * `--force`, because a runner is non-interactive; `--realpath`, because the
 * set's paths are absolute - the caller read the tree's location from
 * `config('schema.tenant_migration_path')` and nothing here rebuilds it. Never
 * a hand-built PDO and never `--pretend`: the connection is the one
 * `Tenancy::enter()` wrote, which is the only path that sets strict mode
 * (AC-68) and the only one `TenantConnectionAuthority` vouches for (AC-60).
 */
final class ArtisanTenantMigrationApplier implements TenantMigrationApplier
{
    public function __construct(
        private readonly ConsoleKernel $console,
        private readonly DatabaseManager $database,
    ) {}

    public function apply(string $connection, MigrationSet $set, int $target): array
    {
        $present = $this->appliedMigrations($connection);
        $applied = [];

        foreach ($set->catalogue() as $entry) {
            if ($entry->version > $target || in_array($entry->migration, $present, true)) {
                continue;
            }

            $path = $set->pathFor($entry->version);

            if ($path === null) {
                throw TenantMigrationFailed::migrationFileMissing($entry->migration, $entry->version);
            }

            $this->applyFile($connection, $path, $entry->migration);

            $applied[] = $entry->migration;
        }

        return $applied;
    }

    public function appliedMigrations(string $connection): array
    {
        $handle = $this->database->connection($connection);

        if (! $handle->getSchemaBuilder()->hasTable('migrations')) {
            return [];
        }

        $names = [];

        foreach ($handle->table('migrations')->orderBy('id')->get() as $row) {
            $names[] = (string) ((array) $row)['migration'];
        }

        return $names;
    }

    /**
     * One file, through the console kernel.
     *
     * A non-zero exit and a thrown exception are the same event to the caller
     * and both carry the migration's name, because the job row has one column
     * for it and an operator reading `error_migration` needs it filled in
     * whichever way the migrator reported the failure.
     */
    private function applyFile(string $connection, string $path, string $migration): void
    {
        try {
            $exit = $this->console->call('migrate', [
                '--database' => $connection,
                '--path' => $path,
                '--realpath' => true,
                '--force' => true,
            ]);
        } catch (Throwable $exception) {
            throw TenantMigrationFailed::whileApplying($migration, $exception);
        }

        if ($exit !== 0) {
            throw TenantMigrationFailed::exitedNonZero($migration, $exit, $this->console->output());
        }
    }
}
