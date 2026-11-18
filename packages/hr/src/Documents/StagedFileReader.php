<?php

declare(strict_types=1);

namespace Flexipos\Hr\Documents;

/**
 * Every way the add-document operation touches a staged file's bytes
 * (T-038 plan Ruling 11, AC-67, D-38).
 *
 * One seam, so a test binds a counting reader and "no byte read" is an
 * observed zero. **`size()` is the only method that may be called before the
 * size decision**, and it must not open the file: the size is what the
 * filesystem reports. `open`, `hash` and `detect` read content, so a file over
 * the maximum, or an empty one, never reaches them.
 */
interface StagedFileReader
{
    /**
     * The size in bytes the filesystem reports, obtained without opening the
     * file.
     */
    public function size(StagedFile $file): int;

    /**
     * @return resource a readable stream the caller closes
     */
    public function open(StagedFile $file);

    /**
     * The lower-case SHA-256 hex digest of the content, streamed.
     */
    public function hash(StagedFile $file): string;

    /**
     * The content type detected from the bytes (never from the name or a
     * declared header), or null when it cannot be determined.
     */
    public function detect(StagedFile $file): ?string;
}
