<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Support;

use Flexipos\Kernel\Schema\Exceptions\MigrationSetRefused;

/**
 * A named, ordered set of tenant migration directories, and the catalogue it
 * produces (T-008 step 8 - the FX seam).
 *
 * The runner is told *which set* to apply rather than being pointed at the one
 * shipped tree. Without that seam every runner test would run the baseline and
 * nothing else, and the baseline is applied to an empty database exactly once:
 * nothing about **upgrades** - a later migration reaching a tenant that already
 * holds earlier ones, a failure on one tenant only, a contract step that
 * depends on an expand version - could be observed at all. The fixture sets of
 * `modules/host/tests/Fixtures/TenantMigrations/` are what the runner is tested
 * against, and this is what lets them be supplied without the shipped tree
 * moving.
 *
 * Three properties, and each is a decision rather than an implementation
 * detail:
 *
 * 1. **A version is a position in the set.** Numbering runs from one across the
 *    whole set, in the order the directories were named, and in file-name order
 *    inside each directory. It is not merged-sorted across directories: a
 *    fixture file called `0001_fx1_adds_a_table.php` must land *after* the
 *    baseline's six, because it is a later migration, and a sort over the
 *    combined names would put it first and silently renumber the baseline
 *    underneath every tenant that already carries those numbers.
 * 2. **Appending never moves an existing version.** Because the baseline is
 *    named first and numbering is positional, `baseline().with('fx1', …)` gives
 *    the baseline's files the same numbers they had alone. That is the property
 *    the FX seam rests on and it is asserted, not assumed.
 * 3. **A set that cannot be the size it claims refuses.** A missing directory
 *    and a repeated directory both throw {@see MigrationSetRefused} rather than
 *    yielding a shorter or longer catalogue, because the set's highest version
 *    is the number a tenant ends up carrying.
 *
 * Directories are **absolute**, like the publish slice's command object: the one
 * authority for where the shipped tree lives is
 * `config('schema.tenant_migration_path')`, read by the entry point that builds
 * the set, and never repeated inside the kernel.
 */
final readonly class MigrationSet
{
    /**
     * The name of the set the shipped tenant tree alone makes up.
     */
    public const BASELINE = 'baseline';

    /**
     * @param  list<string>  $directories  absolute, in application order
     */
    private function __construct(
        public string $name,
        public array $directories,
    ) {}

    /**
     * A set of one or more directories, in the order they are applied.
     */
    public static function named(string $name, string ...$directories): self
    {
        if ($directories === []) {
            throw MigrationSetRefused::noDirectories($name);
        }

        $normalised = [];

        foreach ($directories as $directory) {
            $directory = self::normalise($directory);

            if (! is_dir($directory)) {
                throw MigrationSetRefused::directoryMissing($name, $directory);
            }

            if (in_array($directory, $normalised, true)) {
                throw MigrationSetRefused::directoryRepeated($name, $directory);
            }

            $normalised[] = $directory;
        }

        return new self($name, $normalised);
    }

    /**
     * The shipped tenant baseline, as a set of one directory.
     *
     * Takes the directory rather than reading configuration: the kernel is told
     * where the tree is by the caller that read the key, which is what keeps
     * `config('schema.tenant_migration_path')` the only site that knows.
     */
    public static function baseline(string $treeDirectory): self
    {
        return self::named(self::BASELINE, $treeDirectory);
    }

    /**
     * This set with one more directory applied after it.
     *
     * The new name is the two names joined, so a failure report says which set
     * was running - `baseline+fx1` rather than `baseline`.
     */
    public function with(string $name, string $directory): self
    {
        return self::named($this->name.'+'.$name, ...[...$this->directories, $directory]);
    }

    /**
     * Every migration file in the set, in application order.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->directories as $directory) {
            $inside = glob($directory.DIRECTORY_SEPARATOR.'*.php') ?: [];

            // Sorted inside the directory and never across the set: see
            // property 1 in the class docblock.
            sort($inside);

            foreach ($inside as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * The catalogue this set declares, numbered from one across the whole set.
     *
     * Built from {@see MigrationCatalogue::forFile()} rather than
     * `forTree()`, which numbers each directory from one on its own - correct
     * for the single shipped tree and wrong for a set, where the second
     * directory continues the first.
     *
     * @return list<MigrationCatalogue>
     */
    public function catalogue(): array
    {
        $catalogue = [];
        $version = 0;

        foreach ($this->paths() as $path) {
            $catalogue[] = MigrationCatalogue::forFile(++$version, $path);
        }

        return $catalogue;
    }

    /**
     * The highest version this set can produce; zero for an empty set.
     *
     * An empty set is possible and is not refused - a directory may exist and
     * hold no migrations - while a *missing* directory is refused, because the
     * two say different things about whether anybody meant it.
     */
    public function highestVersion(): int
    {
        return count($this->paths());
    }

    /**
     * The catalogue entry at one version, or null when the set does not reach
     * it.
     */
    public function entryFor(int $version): ?MigrationCatalogue
    {
        foreach ($this->catalogue() as $entry) {
            if ($entry->version === $version) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The file at one version, or null when the set does not reach it.
     *
     * A version **is** the position in {@see paths()}, so this is an index and
     * not a search: anything that re-derived the file from the catalogue's
     * `migration` name would be a second rule about what a version means, and
     * the two could disagree the day two directories hold a file of the same
     * name.
     */
    public function pathFor(int $version): ?string
    {
        return $this->paths()[$version - 1] ?? null;
    }

    /**
     * The version one named migration has in this set, or null when the set
     * does not hold it.
     *
     * The name is the file's base name without its extension - what Laravel's
     * own `migrations` table records - so a tenant's applied rows can be
     * compared with a set without a translation step.
     */
    public function versionOf(string $migration): ?int
    {
        foreach ($this->catalogue() as $entry) {
            if ($entry->migration === $migration) {
                return $entry->version;
            }
        }

        return null;
    }

    /**
     * A directory with a trailing separator removed and separators in this
     * platform's form, so two spellings of one directory compare equal.
     */
    private static function normalise(string $directory): string
    {
        $directory = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory);

        return rtrim($directory, DIRECTORY_SEPARATOR);
    }
}
