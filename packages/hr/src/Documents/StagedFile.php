<?php

declare(strict_types=1);

namespace Flexipos\Hr\Documents;

/**
 * An uploaded file the caller has already placed on local disk, waiting to be
 * added as a document (T-038 plan Ruling 11, D-38).
 *
 * `path` is where the bytes are; it is never stored, logged or returned.
 * `originalName` is what the uploader called it: display text only, cleaned
 * by {@see FileRules::originalName()} before it is stored, and never used to
 * build a path.
 */
final readonly class StagedFile
{
    public function __construct(
        public string $path,
        public string $originalName,
    ) {}
}
