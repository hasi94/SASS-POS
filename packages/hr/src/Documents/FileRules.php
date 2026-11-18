<?php

declare(strict_types=1);

namespace Flexipos\Hr\Documents;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Exceptions\HrRefused;
use Flexipos\Hr\Support\Limits;

/**
 * What a staged file must be to become a document (T-038 AC-67, D-9, D-51).
 *
 * **One list in one place**: {@see self::TYPES} maps each allowed type to the
 * content type detection must report and the file-name extensions that agree
 * with it. The configuration (`hr.documents.allowed_types`) only chooses a
 * subset by key; it cannot add a type this list lacks.
 *
 * The order of the checks is the point. The size is read first, from
 * {@see StagedFileReader::size()}, and an empty or over-large file is refused
 * **before any byte is read**: `detect` (and, later, `open` and `hash`) are
 * never called for it. Only then is the content type detected, from the bytes
 * and never the name or a declared header, and the name's extension must
 * agree with it.
 */
final class FileRules
{
    /**
     * Allowed type key => the detected content type and its extensions.
     *
     * @var array<string, array{mime: string, extensions: list<string>}>
     */
    public const TYPES = [
        'pdf' => ['mime' => 'application/pdf', 'extensions' => ['pdf']],
        'jpeg' => ['mime' => 'image/jpeg', 'extensions' => ['jpg', 'jpeg']],
        'png' => ['mime' => 'image/png', 'extensions' => ['png']],
    ];

    /**
     * Check a staged file and report what it is.
     *
     * @param  list<string>  $allowedTypes  keys of {@see self::TYPES}, from `config('hr.documents.allowed_types')`
     * @return array{mime: string, size: int}
     *
     * @throws HrRefused file_empty, file_too_large or file_type_not_allowed
     */
    public static function check(StagedFile $file, StagedFileReader $reader, int $maxBytes, array $allowedTypes): array
    {
        $size = $reader->size($file);

        if ($size <= 0) {
            throw HrRefused::because(HrRefusal::FileEmpty, 'The file is empty.', 'file');
        }

        if ($size > $maxBytes) {
            throw HrRefused::because(HrRefusal::FileTooLarge, 'The file is larger than the maximum of '.$maxBytes.' bytes.', 'file');
        }

        $detected = $reader->detect($file);
        $extensions = null;

        foreach ($allowedTypes as $type) {
            if (isset(self::TYPES[$type]) && self::TYPES[$type]['mime'] === $detected) {
                $extensions = self::TYPES[$type]['extensions'];

                break;
            }
        }

        if ($detected === null || $extensions === null) {
            throw self::notAllowed('The type of the file is not allowed.');
        }

        $extension = self::extensionOf(self::originalName($file->originalName));

        // No extension, or one outside the detected type's own, is refused (D-51).
        if ($extension === null || ! in_array($extension, $extensions, true)) {
            throw self::notAllowed('The file name does not match the type of the file.');
        }

        return ['mime' => $detected, 'size' => $size];
    }

    /**
     * The display name to store (AC-67): path separators and control
     * characters removed, invalid UTF-8 scrubbed, truncated to 255 characters.
     * It is text for a person to read, never part of a path.
     */
    public static function originalName(string $name): string
    {
        $clean = preg_replace('#[/\\\\\p{Cc}]#u', '', mb_scrub($name, 'UTF-8')) ?? '';

        return mb_substr($clean, 0, Limits::ORIGINAL_NAME);
    }

    /**
     * The lower-cased text after the last dot, or null for a name with no dot
     * or one that ends in a dot (`scan`, `scan.`).
     */
    public static function extensionOf(string $name): ?string
    {
        $dot = strrpos($name, '.');

        if ($dot === false || $dot === strlen($name) - 1) {
            return null;
        }

        return mb_strtolower(substr($name, $dot + 1));
    }

    private static function notAllowed(string $message): HrRefused
    {
        return HrRefused::because(HrRefusal::FileTypeNotAllowed, $message, 'file');
    }
}
