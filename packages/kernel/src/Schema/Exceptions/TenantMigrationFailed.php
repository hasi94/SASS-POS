<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Exceptions;

use RuntimeException;
use Throwable;

/**
 * One tenant's migration did not complete.
 *
 * It is the runner's own named failure, distinct from whatever the migration
 * threw: the cause is kept as `previous` so a developer still has the trace,
 * while `migration()` is the one fact the job row has a column for
 * (`error_migration`) and an operator reads first.
 *
 * **It carries no credential.** Nothing here is built from a mapping's
 * credential reference or from a resolved secret, and the one constructor that
 * takes a foreign message - {@see whileApplying()} - stores the cause rather
 * than copying its text into this message. The text that reaches
 * `tenant_migration_jobs.error_message` and
 * `tenant_databases.last_migration_error` goes through
 * the MigrateTenant slice's MigrationErrorText first (AC-45).
 */
final class TenantMigrationFailed extends RuntimeException
{
    private function __construct(string $message, private readonly ?string $migration = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The migration that was being applied, or null when the failure happened
     * outside any one file.
     */
    public function migration(): ?string
    {
        return $this->migration;
    }

    /**
     * A migration threw while it was being applied.
     */
    public static function whileApplying(string $migration, Throwable $cause): self
    {
        return new self(
            'The migration ['.$migration.'] failed on this tenant; the run continues with the other'
            .' tenants and this one is left at the version it already carried.',
            $migration,
            $cause
        );
    }

    /**
     * The migrator reported a non-zero exit rather than throwing.
     */
    public static function exitedNonZero(string $migration, int $exit, string $output): self
    {
        return new self(
            'The migration ['.$migration.'] exited '.$exit.' on this tenant: '.trim($output),
            $migration
        );
    }

    /**
     * The catalogue names a version the set has no file for.
     *
     * A set numbers its versions by position, so this is a set whose directory
     * changed under it rather than a catalogue defect.
     */
    public static function migrationFileMissing(string $migration, int $version): self
    {
        return new self(
            'The migration ['.$migration.'] (version '.$version.') has no file in the set any more,'
            .' so nothing was applied to this tenant.',
            $migration
        );
    }

    /**
     * The job this worker was handed is not there, or is not its job any more.
     */
    public static function jobNotHeld(string $jobId, string $workerId): self
    {
        return new self(
            'The job ['.$jobId.'] is not held as [running] by the worker ['.$workerId.'], so there is'
            .' nothing for it to execute. Nothing was migrated.'
        );
    }

    /**
     * The job names a tenant with no mapping row.
     */
    public static function tenantNotMapped(string $tenantId): self
    {
        return new self(
            'The tenant ['.$tenantId.'] has no row in tenant_databases, so there is no database to'
            .' migrate. Nothing was migrated.'
        );
    }
}
