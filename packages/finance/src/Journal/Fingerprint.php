<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal;

use Flexipos\Contracts\Finance\JournalDimension;

/**
 * The payload fingerprint that tells a replay from a conflicting reuse of a
 * key (T-033 Ruling 5, AC-35, AC-36, D-6, D-30).
 *
 * Lower-case hex SHA-256 of a canonical JSON document built from the **stored
 * form** of the payload, so `"12.5"` and `"12.5000"`, or `"x"` and `" x "`,
 * hash alike. Line order is significant. The actor and the clock are not part
 * of it, and neither are the source type, id and purpose: those are the key
 * the fingerprint is compared under.
 *
 * Changing the shape below changes the fingerprint of every stored entry, so
 * a replay of an old document would read as a conflict. Do not.
 */
final class Fingerprint
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    private function __construct() {}

    /**
     * `[entry_date, branch_id, description, [[account_id, debit, credit, memo, ...seven dimensions], ...]]`
     */
    public static function ofPosting(ParsedPosting $posting): string
    {
        $lines = [];

        foreach ($posting->lines as $line) {
            $row = [$line['account_id'], $line['debit'], $line['credit'], $line['memo']];

            foreach (JournalDimension::cases() as $dimension) {
                $row[] = $line['dimensions'][$dimension->value] ?? null;
            }

            $lines[] = $row;
        }

        return self::hash([$posting->entryDate, $posting->branchId, $posting->description, $lines]);
    }

    /**
     * `[entry_date, reason]`, the reason as stored (trimmed).
     */
    public static function ofReversal(string $entryDate, string $reason): string
    {
        return self::hash([$entryDate, $reason]);
    }

    /**
     * @param  list<mixed>  $document
     */
    private static function hash(array $document): string
    {
        return hash('sha256', json_encode($document, self::FLAGS));
    }
}
