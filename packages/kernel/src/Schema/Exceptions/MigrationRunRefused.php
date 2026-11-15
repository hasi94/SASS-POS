<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Exceptions;

use RuntimeException;

/**
 * The runner refused to plan a run, and nothing was written.
 *
 * Every refusal here is raised at **plan time**: before the run row, before the
 * first job row and before any tenant connection is opened. That is decision
 * D-10's shape and AC-59's whole content - "no tenant is altered" is a property
 * of *where* the refusal happens, not of a rollback that happened to work.
 *
 * Each message names the thing the operator has to go and look at - the
 * migration, the tenants, the status, the configuration key - because a
 * refusal that only says "refused" sends them to the source.
 */
final class MigrationRunRefused extends RuntimeException
{
    /**
     * The configuration key D-10 gates the destructive refusal on. Named in the
     * message so the operator is told which line flips, rather than hunting for
     * a thrown exception.
     */
    public const BACKUPS_KEY = 'schema.backups.available';

    /**
     * A migration the set would apply is destructive and no backup facility
     * exists (AC-59, D-10).
     *
     * Keyed on the catalogue's flag, so the message can name the version as
     * well as the file: the flag is read from the file's statements at publish
     * time and never from the body at run time.
     */
    public static function destructiveWithoutBackups(string $migration, int $version): self
    {
        return new self(
            'The migration ['.$migration.'] (version '.$version.') is destructive and no backup'
            .' facility exists, so no tenant may be migrated through it. Nothing was planned: no run'
            .' row, no job row and no tenant connection. Set ['.self::BACKUPS_KEY.'] once backups'
            .' exist.'
        );
    }

    /**
     * A contract step would be applied to tenants that have not reached the
     * version before it (AC-58).
     *
     * **It names the next action, not only the obstacle** (ruling 14.1, landed
     * with step 13a). The known false refusal of the V - 1 reading is a single
     * run that would apply several expands *and then* the contract: that run
     * is refused even though every tenant would pass through those expands
     * inside it, and the remedy is operational - `--all --to=<version - 1>`
     * first, then the run that includes the contract step. An operator meets
     * that remedy where they meet the refusal rather than in a document,
     * because the refusal is the only thing they are certain to read.
     *
     * **`--all` is part of the remedy, not decoration.** The command refuses
     * an invocation that names neither `--tenant` nor `--all` before it plans
     * anything, so `--to=<version - 1>` alone - what this message printed
     * until T-008 BUG-12 - was an instruction the printing application would
     * not run. It is `--all` rather than the blocking tenants because the
     * guard clears only when *every* tenant carries the expand: a run naming
     * just the names listed above leaves the next contract run refused by any
     * tenant that fell behind in between.
     *
     * @param  list<string>  $blockingTenants
     */
    public static function contractStepBlocked(string $migration, int $version, array $blockingTenants): self
    {
        return new self(
            'The contract migration ['.$migration.'] (version '.$version.') cannot be planned while'
            .' these tenants are below version '.($version - 1).': '.implode(', ', $blockingTenants)
            .'. Expand and contract requires every tenant to carry the expand step before the'
            .' contract step is applied to any of them. Run `tenant:migrate --all --to='.($version - 1).'`'
            .' first, then this run. Nothing was planned.'
        );
    }

    /**
     * A run naming one tenant named a tenant whose status forbids it (AC-51,
     * D-11).
     *
     * A *bulk* run does not raise this: a status outside the bulk set is simply
     * not selected, which is AC-40's "a tenant with status archived is not
     * planned". A run that names a tenant has to answer the operator who named
     * it, which is why only this direction is a refusal.
     *
     * @param  list<string>  $accepted
     */
    public static function tenantStatusRefused(string $tenantId, string $status, array $accepted): self
    {
        return new self(
            'The tenant ['.$tenantId.'] has status ['.$status.'], which a run naming one tenant does'
            .' not accept; it accepts '.implode(', ', $accepted).'. Nothing was planned and that'
            .' tenant database is unchanged.'
        );
    }

    /**
     * A run named a tenant with no mapping at all.
     */
    public static function tenantNotMapped(string $tenantId): self
    {
        return new self(
            'The tenant ['.$tenantId.'] has no row in tenant_databases, so there is no database to'
            .' migrate and no server to plan it on. Nothing was planned.'
        );
    }

    /**
     * The set declares a migration the catalogue has never published (AC-32).
     */
    public static function migrationNotPublished(string $migration, int $version): self
    {
        return new self(
            'The migration ['.$migration.'] (version '.$version.') is in the migration set but not in'
            .' the published catalogue, so the runner refuses to start. Publish the catalogue first:'
            .' a schema_version is a position in it. Nothing was planned.'
        );
    }

    /**
     * A published migration's content moved under the catalogue (AC-32).
     */
    public static function publishedContentChanged(string $migration, int $version): self
    {
        return new self(
            'The migration ['.$migration.'] (version '.$version.') has a different checksum from the'
            .' published catalogue row, so the runner refuses to start. A released migration is never'
            .' rewritten; the change belongs in a new migration file. Nothing was planned.'
        );
    }

    /**
     * The run asked for a version the set cannot reach.
     */
    public static function targetBeyondSet(string $set, int $target, int $highest): self
    {
        return new self(
            'The run asks for version '.$target.' but the migration set ['.$set.'] reaches only'
            .' version '.$highest.'. Nothing was planned.'
        );
    }
}
