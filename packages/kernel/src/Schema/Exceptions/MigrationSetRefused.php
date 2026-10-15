<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Exceptions;

use RuntimeException;

/**
 * A migration set was asked for that cannot be a set (T-008 step 8).
 *
 * Both refusals exist because the alternative is a set that is quietly the
 * wrong size, and a set's size *is* its highest version - the number a tenant
 * carries in `tenant_databases.schema_version`. A set one file short is a
 * runner that believes it migrated a tenant to a version it never applied, and
 * nothing downstream can notice.
 */
final class MigrationSetRefused extends RuntimeException
{
    /**
     * A named directory is not on disk.
     *
     * Silently yielding an empty catalogue for it is what this refuses: a
     * mistyped fixture path would otherwise shrink the set by exactly the
     * migrations the test was about.
     */
    public static function directoryMissing(string $name, string $directory): self
    {
        return new self(sprintf(
            'The migration set [%s] names a directory that does not exist: %s.',
            $name,
            $directory
        ));
    }

    /**
     * The same directory was named twice.
     *
     * Versions are positions in the set, so a repeated directory applies every
     * one of its files a second time under a second number - which renumbers
     * nothing that came before it and numbers everything after it wrongly.
     */
    public static function directoryRepeated(string $name, string $directory): self
    {
        return new self(sprintf(
            'The migration set [%s] names the directory %s twice; every file in it would be counted as two versions.',
            $name,
            $directory
        ));
    }

    /**
     * A set with no directories at all.
     */
    public static function noDirectories(string $name): self
    {
        return new self(sprintf('The migration set [%s] names no directory.', $name));
    }
}
