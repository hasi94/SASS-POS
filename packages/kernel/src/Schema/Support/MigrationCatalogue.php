<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Support;

/**
 * One catalogue row: what the branch declares about one tenant migration file.
 *
 * `docs/database.md` section 4.5 makes the catalogue the thing a tenant's
 * `schema_version` is a number *in*. This class is the branch's side of it - the
 * file read - and `schema_versions` is the database's side; `schema:publish`
 * copies the first into the second and the committed fixture is the copy a
 * reviewer sees (AC-31).
 *
 * Four of the six fields are derived from the file and two are declared in it:
 *
 * - **`version`** is the ordinal of the file in **file-name order**, counted
 *   from one. It is not read from the file name, because the file names are
 *   timestamps and a timestamp is not a version anyone can hold in their head;
 *   and it is not auto-incremented by the database, because the same file must
 *   have the same number on every installation for a tenant's
 *   `schema_version` to mean anything.
 * - **`migration`** is the file's base name without its extension, which is
 *   what Laravel's own `migrations` table records, so the two sides can be
 *   compared without a translation step.
 * - **`checksum`** is SHA-256 of the file's content with CRLF and CR
 *   normalised to LF. The normalisation is not cosmetic: this repository is
 *   developed on Windows with core.autocrlf on, so a checksum of the raw bytes
 *   would make the committed fixture disagree with itself between a Windows
 *   working tree and CI.
 * - **`isDestructive`** is read from the code, never declared: a file says what
 *   phase it is in, but whether it *drops* something is a property of its
 *   statements, and a migration that lies about it would be the one case where
 *   the lie is expensive (D-10's refusal is keyed on this flag).
 * - **`phase`** and **`requiresOffline`** are declared in a comment at the top
 *   of the file (`@phase`, `@requires-offline`), and both default to the
 *   strictest reading: a file that declares nothing is `expand`, which may do
 *   the least, and is not offline, which is the plan the runner may apply
 *   without stopping the shop.
 */
final readonly class MigrationCatalogue
{
    /**
     * The tag a migration uses to declare that it cannot be applied while the
     * shop is serving. Read from a comment, like `@phase`, because it is
     * metadata about the file rather than a statement the file executes.
     */
    public const OFFLINE_TAG = '@requires-offline';

    public function __construct(
        public int $version,
        public string $migration,
        public MigrationPhase $phase,
        public string $checksum,
        public bool $isDestructive,
        public bool $requiresOffline,
    ) {}

    /**
     * Every migration in a tree, in file-name order, numbered from one.
     *
     * `glob()` plus `sort()` rather than a directory iterator, so the order is
     * the byte order of the names on every platform. A directory that does not
     * exist yields an empty catalogue rather than an error: the caller that
     * cares - `schema:publish` - says so with a message naming the path.
     *
     * @return list<self>
     */
    public static function forTree(string $directory): array
    {
        $paths = glob(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'*.php') ?: [];

        sort($paths);

        $catalogue = [];
        $version = 0;

        foreach ($paths as $path) {
            $catalogue[] = self::forFile(++$version, $path);
        }

        return $catalogue;
    }

    /**
     * One file, at the version the caller assigns it.
     */
    public static function forFile(int $version, string $path): self
    {
        $source = (string) file_get_contents($path);

        return new self(
            version: $version,
            migration: pathinfo($path, PATHINFO_FILENAME),
            phase: MigrationPhase::fromSource($source),
            checksum: self::checksumOf($source),
            isDestructive: SourceRules::isDestructive($source),
            requiresOffline: self::declaresOffline($source),
        );
    }

    /**
     * SHA-256 of the source with every line ending normalised to LF.
     *
     * CRLF first: replacing CR before CRLF would turn one CRLF into two LFs.
     */
    public static function checksumOf(string $source): string
    {
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $source));
    }

    /**
     * The row shape both sides of the comparison use.
     *
     * The keys are the `schema_versions` column names, so this is what is
     * inserted, what the fixture holds, and what a difference is reported in -
     * one shape, so a comparison cannot be between two spellings of the same
     * row.
     *
     * @return array{version: int, migration: string, phase: string, checksum: string, is_destructive: bool, requires_offline: bool}
     */
    public function toRow(): array
    {
        return [
            'version' => $this->version,
            'migration' => $this->migration,
            'phase' => $this->phase->value,
            'checksum' => $this->checksum,
            'is_destructive' => $this->isDestructive,
            'requires_offline' => $this->requiresOffline,
        ];
    }

    /**
     * The committed fixture's text for a whole catalogue.
     *
     * Pretty-printed with a trailing newline and unescaped slashes, so a
     * difference in a review is one line per changed field rather than one line
     * for the file. The row shape is `toRow()`'s, so the fixture, the insert and
     * the comparison are all the same three-way-identical shape.
     *
     * @param  list<self>  $catalogue
     */
    public static function encodeFixture(array $catalogue): string
    {
        $rows = array_map(static fn (self $entry): array => $entry->toRow(), $catalogue);

        return (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * The rows a committed fixture declares, in the shape `toRow()` produces.
     *
     * The booleans and the version are cast rather than trusted, so a fixture
     * written by hand with `"is_destructive": 0` is still comparable with a
     * catalogue read from the tree; a malformed file yields an empty list, and
     * the caller reports the difference rather than guessing at a repair.
     *
     * @return list<array{version: int, migration: string, phase: string, checksum: string, is_destructive: bool, requires_offline: bool}>
     */
    public static function decodeFixture(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                'version' => (int) ($row['version'] ?? 0),
                'migration' => (string) ($row['migration'] ?? ''),
                'phase' => (string) ($row['phase'] ?? ''),
                'checksum' => (string) ($row['checksum'] ?? ''),
                'is_destructive' => (bool) ($row['is_destructive'] ?? false),
                'requires_offline' => (bool) ($row['requires_offline'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * Whether the file declares that it needs the shop offline.
     *
     * Only a comment is consulted, and the tag has to be the whole word: a
     * `@requires-offline-never` tag is not a declaration of anything, and
     * reading it as one would be a licence granted by a typo - in the direction
     * that stops a till.
     *
     * Public, like MigrationPhase::fromSource() and checksumOf(), so the
     * default and the typo case are provable over source text with no file and
     * no application in the way.
     */
    public static function declaresOffline(string $source): bool
    {
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (preg_match('/'.preg_quote(self::OFFLINE_TAG, '/').'(?![-\w])/', (string) $token[1]) === 1) {
                return true;
            }
        }

        return false;
    }
}
