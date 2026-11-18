<?php

declare(strict_types=1);

namespace Flexipos\Hr\Tests\Support;

use Flexipos\Hr\Documents\FilesystemStagedFileReader;
use Flexipos\Hr\Documents\StagedFile;
use Flexipos\Hr\Documents\StagedFileReader;

/**
 * A {@see StagedFileReader} that counts every call (T-038 plan Ruling 11,
 * AC-67).
 *
 * "No byte of the file was read" is an observed zero on `opens`, `hashes`
 * and `detections`, not an argument. It delegates to the real filesystem
 * reader so a counted run is also a real one. Calls are counted **before**
 * they can fail.
 */
final class CountingStagedFileReader implements StagedFileReader
{
    public int $sizes = 0;

    public int $opens = 0;

    public int $hashes = 0;

    public int $detections = 0;

    private readonly StagedFileReader $inner;

    public function __construct(?StagedFileReader $inner = null)
    {
        $this->inner = $inner ?? new FilesystemStagedFileReader;
    }

    public function size(StagedFile $file): int
    {
        $this->sizes++;

        return $this->inner->size($file);
    }

    public function open(StagedFile $file)
    {
        $this->opens++;

        return $this->inner->open($file);
    }

    public function hash(StagedFile $file): string
    {
        $this->hashes++;

        return $this->inner->hash($file);
    }

    public function detect(StagedFile $file): ?string
    {
        $this->detections++;

        return $this->inner->detect($file);
    }

    /**
     * Calls that read content, as opposed to asking the filesystem for a size.
     */
    public function contentReads(): int
    {
        return $this->opens + $this->hashes + $this->detections;
    }
}
