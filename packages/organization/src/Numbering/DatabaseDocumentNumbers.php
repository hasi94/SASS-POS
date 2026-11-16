<?php

declare(strict_types=1);

namespace Flexipos\Organization\Numbering;

use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Support\TenantConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * {@see DocumentNumbers}, over `number_sequences` (T-030 AC-40, D-31, F-6,
 * R-27; `docs/database.md` section 6.1).
 *
 * **Portable lock-and-increment, not MySQL's `LAST_INSERT_ID()` trick**, which
 * is what the table's own migration docblock describes. The suite runs on
 * SQLite, and a row lock plus an `UPDATE` is correct on both engines. It is
 * meant to run inside the caller's transaction: the increment commits or rolls
 * back with the document that took the number, so nothing is skipped.
 *
 * **Which row.** The branch's own row for the document type when the caller
 * names a branch and one exists, otherwise the company-wide row, which this
 * class creates the first time it is needed. Both are read through the stored
 * `branch_id_key` sentinel (section 3.3), the one key the table is unique on.
 *
 * **Every read of the sequence is `FOR UPDATE`, including the one after a lost
 * race.** Two first uses of a type can both find no company row and both try to
 * insert it; the unique key refuses the second, which then reads the winner's
 * row again. That second read must be a locking read: a plain read after the
 * failed insert would see the old snapshot on MySQL (T-028 review W-1) and find
 * nothing. The retry happens once; a row that is still missing after it is an
 * invariant failure and is thrown as one.
 *
 * **Not proved on the stand-in:** under InnoDB's REPEATABLE READ a locking read
 * of an absent key takes a gap lock, and two concurrent first uses can deadlock
 * rather than reach the unique key. The engine suite owes that measurement
 * (engine hold, NOT VERIFIED).
 *
 * The inserted default carries `reset_period = never` and `padding = 6`
 * **explicitly**: the first column has no default at all and the second
 * defaults to 0 (R-27). A document type this class has no prefix for gets none.
 */
final class DatabaseDocumentNumbers implements DocumentNumbers
{
    /** The company-wide row's `branch_id_key`: a one-character sentinel no 26-character ULID can equal. */
    private const COMPANY_KEY = '-';

    private const DEFAULT_PADDING = 6;

    /** `sales.number` and `cash_shifts.number` are VARCHAR(30). */
    private const WIDTH = 30;

    /**
     * The default prefix per document type. The sale and refund prefixes must
     * differ, because `sales.number` is unique across both.
     *
     * @var array<string, string>
     */
    private const DEFAULT_PREFIXES = [
        'sale' => 'S',
        'refund' => 'R',
        'cash_shift' => 'SH',
        'purchase_request' => 'PR',
        'purchase_order' => 'PO',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
    ) {}

    public function next(string $documentType, ?string $branchId): string
    {
        $connection = $this->tenant->get();

        $row = $branchId === null ? null : $this->locked($connection, strtoupper($branchId), $documentType);

        $row ??= $this->locked($connection, self::COMPANY_KEY, $documentType)
            ?? $this->createCompanyDefault($connection, $documentType);

        if ((string) $row->reset_period !== 'never') {
            throw DocumentNumbersRefused::unsupportedResetPeriod(
                "The [{$documentType}] sequence resets [{$row->reset_period}], and only [never] is supported."
            );
        }

        $next = (int) $row->next_number;
        $number = (string) $row->prefix.str_pad((string) $next, (int) $row->padding, '0', STR_PAD_LEFT).(string) $row->suffix;

        if (strlen($number) > self::WIDTH) {
            throw DocumentNumbersRefused::numberTooLong(
                "The [{$documentType}] number [{$number}] would be ".strlen($number).' characters, and the column holds '.self::WIDTH.'.'
            );
        }

        $connection->table('number_sequences')->where('id', $row->id)->update([
            'next_number' => $next + 1,
            'updated_at' => $this->clock->timestamp(),
        ]);

        return $number;
    }

    /**
     * The sequence row for this key, read `FOR UPDATE`.
     */
    private function locked(Connection $connection, string $branchKey, string $documentType): ?object
    {
        return $connection->table('number_sequences')
            ->where('branch_id_key', $branchKey)
            ->where('document_type', $documentType)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Insert the company-wide default, or - when another transaction inserted it
     * first - read that one under a lock.
     */
    private function createCompanyDefault(Connection $connection, string $documentType): object
    {
        $now = $this->clock->timestamp();

        try {
            $id = strtoupper((string) Str::ulid());

            $connection->table('number_sequences')->insert([
                'id' => $id,
                'branch_id' => null,
                'document_type' => $documentType,
                'prefix' => self::DEFAULT_PREFIXES[$documentType] ?? null,
                'next_number' => 1,
                'padding' => self::DEFAULT_PADDING,
                'suffix' => null,
                'reset_period' => 'never',
                'last_reset_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->locked($connection, self::COMPANY_KEY, $documentType)
                ?? throw new RuntimeException("The [{$documentType}] number sequence refused its own insert and then could not be read.");
        }

        return $this->locked($connection, self::COMPANY_KEY, $documentType)
            ?? throw new RuntimeException("The [{$documentType}] number sequence was inserted and could not be read.");
    }
}
