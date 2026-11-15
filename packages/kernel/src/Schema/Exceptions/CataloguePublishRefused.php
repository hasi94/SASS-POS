<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Exceptions;

use RuntimeException;

/**
 * `schema:publish` refused to write, and nothing was written.
 *
 * Every refusal here is raised **before** the first insert, so "nothing was
 * written" is a property of the order the handler does things in rather than of
 * a transaction that happened to roll back (AC-31). Each message names the
 * migration, because the only useful next action is to look at that file.
 *
 * A refusal is never a reason to delete a published row. A published version is
 * a number tenants already hold in `tenant_databases.schema_version`, and
 * renumbering it would move every tenant's position without touching a single
 * tenant database.
 */
final class CataloguePublishRefused extends RuntimeException
{
    public static function emptyTree(string $directory): self
    {
        return new self(
            'The tenant migration tree at ['.$directory.'] holds no migration, so there is nothing to publish.'
        );
    }

    /**
     * A file was inserted ahead of an already-published one, so publishing
     * would renumber it.
     */
    public static function versionMoved(string $migration, int $published, int $wouldBecome): self
    {
        return new self(
            'The published migration ['.$migration.'] is version '.$published
            .' in the catalogue and would become version '.$wouldBecome
            .'. A migration file whose name sorts before an already-published one is refused:'
            .' tenants already record the published number as their schema version.'
        );
    }

    /**
     * A published migration is no longer in the tree at all.
     */
    public static function publishedMigrationMissing(string $migration, int $version): self
    {
        return new self(
            'The published migration ['.$migration.'] (version '.$version.') is not in the tenant'
            .' migration tree. A published migration is never removed; expand and contract adds a'
            .' further migration instead.'
        );
    }

    /**
     * A published migration's content changed, which moves its checksum and
     * makes every tenant's recorded version a statement about a file that no
     * longer exists.
     */
    public static function publishedContentChanged(string $migration, int $version): self
    {
        return new self(
            'The published migration ['.$migration.'] (version '.$version.') has a different checksum'
            .' from the published one. A released migration is never rewritten; the change belongs in a'
            .' new migration file.'
        );
    }
}
