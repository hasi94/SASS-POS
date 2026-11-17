<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal;

use Flexipos\Contracts\Finance\JournalDimension;

/**
 * A stored entry or line as a caller of the read slices sees it (T-033 AC-68,
 * AC-69), pure: no database, no clock.
 *
 * Amounts leave as four-decimal strings through {@see Amounts::fromStored}, so
 * a stand-in float and an engine decimal read the same. `is_auto` is a bool, a
 * nullable column is `null`, never an empty string, and every other value is a
 * string. The payload fingerprint and the generated key column are internal and
 * never leave.
 */
final class EntryView
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function summary(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'number' => (string) $row->number,
            'entry_date' => (string) $row->entry_date,
            'period_id' => (string) $row->period_id,
            'branch_id' => self::nullable($row->branch_id),
            'source_type' => (string) $row->source_type,
            'source_id' => self::nullable($row->source_id),
            'source_purpose' => self::nullable($row->source_purpose),
            'status' => (string) $row->status,
            'total_debit' => Amounts::fromStored($row->total_debit),
            'total_credit' => Amounts::fromStored($row->total_credit),
            'description' => self::nullable($row->description),
            'reversal_of_id' => self::nullable($row->reversal_of_id),
            'reversed_by_id' => self::nullable($row->reversed_by_id),
            'posted_at' => (string) $row->posted_at,
            'posted_by' => self::nullable($row->posted_by),
            'is_auto' => (bool) $row->is_auto,
            'created_by' => self::nullable($row->created_by),
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function line(object $row): array
    {
        $line = [
            'id' => (string) $row->id,
            'line_no' => (int) $row->line_no,
            'account_id' => (string) $row->account_id,
            'debit' => Amounts::fromStored($row->debit),
            'credit' => Amounts::fromStored($row->credit),
            'memo' => self::nullable($row->memo),
        ];

        foreach (JournalDimension::cases() as $dimension) {
            $line[$dimension->value] = self::nullable($row->{$dimension->value});
        }

        $line['created_at'] = (string) $row->created_at;

        return $line;
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
