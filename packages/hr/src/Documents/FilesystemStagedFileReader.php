<?php

declare(strict_types=1);

namespace Flexipos\Hr\Documents;

use finfo;
use Flexipos\Hr\Exceptions\HrRefused;

/**
 * The real {@see StagedFileReader}: the local filesystem, `hash_file` (which
 * streams) and `finfo` (T-038 plan Ruling 11).
 *
 * A staged file that cannot be read is a `validation` refusal naming the
 * `file` field and nothing about the path.
 */
final class FilesystemStagedFileReader implements StagedFileReader
{
    public function size(StagedFile $file): int
    {
        clearstatcache(true, $file->path);

        $size = is_file($file->path) ? @filesize($file->path) : false;

        if ($size === false) {
            throw self::unreadable();
        }

        return $size;
    }

    public function open(StagedFile $file)
    {
        $stream = is_file($file->path) ? @fopen($file->path, 'rb') : false;

        if ($stream === false) {
            throw self::unreadable();
        }

        return $stream;
    }

    public function hash(StagedFile $file): string
    {
        $digest = is_file($file->path) ? @hash_file('sha256', $file->path) : false;

        if ($digest === false) {
            throw self::unreadable();
        }

        return $digest;
    }

    public function detect(StagedFile $file): ?string
    {
        if (! is_file($file->path)) {
            throw self::unreadable();
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file->path);

        return $mime === false || $mime === '' ? null : $mime;
    }

    private static function unreadable(): HrRefused
    {
        return HrRefused::validation('file', 'The uploaded file cannot be read.');
    }
}
