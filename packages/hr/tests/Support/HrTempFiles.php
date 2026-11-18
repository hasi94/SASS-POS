<?php

declare(strict_types=1);

namespace Flexipos\Hr\Tests\Support;

use Flexipos\Hr\Documents\StagedFile;

/**
 * Real files in the system temp directory for a test to stage, and their
 * removal (T-038 plan step 8).
 *
 * `stage()` writes the bytes to a fresh file and remembers it; `cleanup()`
 * (called from `afterEach`) removes everything staged since the last call.
 * Nothing is written into the repository.
 */
final class HrTempFiles
{
    /** @var list<string> */
    private static array $paths = [];

    public static function stage(string $bytes, string $originalName): StagedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 't038');

        file_put_contents($path, $bytes);

        self::$paths[] = $path;

        return new StagedFile($path, $originalName);
    }

    public static function cleanup(): void
    {
        foreach (self::$paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        self::$paths = [];
    }
}
