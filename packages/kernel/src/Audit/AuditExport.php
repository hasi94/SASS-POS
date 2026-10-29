<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Audit;

use LogicException;

/**
 * Write one audit page to a local file, as JSON or CSV (T-017 Ruling 11,
 * AC-18, D-13).
 *
 * **It lives in the kernel because both sides use it and neither may
 * import the other.** `packages/access` and `packages/platform` each build
 * an {@see AuditPage} from their own query and hand it here; this class
 * reads nothing but the page.
 *
 * **It refuses to overwrite an existing file.** That is the safest
 * direction to be wrong in: nothing already on disk is ever lost.
 *
 * **It writes exactly one page.** The bound is "the same maximum page
 * size" by construction - callers print `nextBeforeId` so an operator can
 * continue from where the page ended, and this class has no loop of its
 * own to continue.
 */
final class AuditExport
{
    /**
     * @throws LogicException when the file already exists
     */
    public static function write(AuditPage $page, string $path, ExportFormat $format): void
    {
        if (is_file($path)) {
            throw new LogicException("Refusing to overwrite the existing file [{$path}].");
        }

        match ($format) {
            ExportFormat::Json => self::writeJson($page, $path),
            ExportFormat::Csv => self::writeCsv($page, $path),
        };
    }

    /**
     * An array of row objects.
     */
    private static function writeJson(AuditPage $page, string $path): void
    {
        file_put_contents($path, (string) json_encode($page->rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * A header row from the page's own column list, then one line per row -
     * including when there are no rows at all, so the header still names
     * every column a row would have carried.
     */
    private static function writeCsv(AuditPage $page, string $path): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new LogicException("Could not open [{$path}] for writing.");
        }

        try {
            fputcsv($handle, $page->columns);

            foreach ($page->rows as $row) {
                $cells = [];

                foreach ($page->columns as $column) {
                    $cells[] = self::cell($row[$column] ?? null);
                }

                fputcsv($handle, $cells);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * One CSV cell: a JSON column's value, or any other scalar.
     *
     * The JSON columns (`before`, `after`, `changed_fields`) arrive already
     * as JSON text from the database; a non-string value (an array a
     * caller decoded before building the page) is re-encoded, so either
     * shape survives into one text cell either way.
     */
    private static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_THROW_ON_ERROR);
        }

        return (string) $value;
    }
}
