<?php

declare(strict_types=1);

namespace Flexipos\Hr\Support;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Exceptions\HrRefused;

/**
 * The opaque keyset cursor of every HR list (T-038 plan section 2, D-25).
 *
 * Unsigned base64url of the JSON `{"l": <list name>, "k": [<key parts>]}`.
 * Unsigned is enough: visibility is in the `WHERE` clause, so a forged cursor
 * can only reposition inside rows the caller may already see. What it must
 * never do is be mistaken for another list's cursor, or carry half a key, so
 * decoding refuses anything that is not exactly the canonical encoding of a
 * cursor for **this** list with **this many** string parts. Every refusal is
 * `cursor_invalid` and never echoes the cursor.
 */
final class Cursor
{
    /** A cursor longer than this is never one this class produced. */
    private const MAX_LENGTH = 1024;

    /**
     * @param  list<string>  $key  the sort-key parts of the last row of the page
     */
    public static function encode(string $list, array $key): string
    {
        $json = json_encode(['l' => $list, 'k' => $key], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return list<string> the key parts
     *
     * @throws HrRefused cursor_invalid
     */
    public static function decode(mixed $cursor, string $list, int $parts): array
    {
        if (! is_string($cursor) || $cursor === '' || strlen($cursor) > self::MAX_LENGTH
            || preg_match('/\A[A-Za-z0-9_\-]+\z/', $cursor) !== 1) {
            throw self::invalid();
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data) || array_keys($data) !== ['l', 'k'] || $data['l'] !== $list
            || ! is_array($data['k']) || ! array_is_list($data['k']) || count($data['k']) !== $parts) {
            throw self::invalid();
        }

        foreach ($data['k'] as $part) {
            if (! is_string($part)) {
                throw self::invalid();
            }
        }

        /** @var list<string> $key */
        $key = $data['k'];

        // Only the canonical spelling is a cursor: padding, alternate base64
        // forms and re-ordered JSON all decode to the same data but were never issued.
        if (self::encode($list, $key) !== $cursor) {
            throw self::invalid();
        }

        return $key;
    }

    private static function invalid(): HrRefused
    {
        return HrRefused::because(HrRefusal::CursorInvalid, 'The cursor is not valid for this list.', 'cursor');
    }
}
