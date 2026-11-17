<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal;

use Flexipos\Contracts\Finance\JournalDimension;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Finance\Support\TenantConnection;

/**
 * The liveness check of every branch and dimension id a posting names (T-033
 * Ruling 7 step 5, AC-15a, D-26).
 *
 * One `whereIn('id')->whereNull('deleted_at')` read per target table, then the
 * first missing reference **in request order** (the entry branch, then lines
 * ascending, dimensions in {@see JournalDimension} order) is refused
 * `unknown_reference` with its field path. `department_id` and `supplier_id`
 * have no table yet and are not checked (D-16, F-13).
 *
 * **Plain reads here, by design**: this check takes no lock of its own, and a
 * concurrent soft delete leaves a valid historical reference. It does not
 * avoid shared locks overall: the foreign-key check on the later
 * `journal_lines` insert takes shared record locks on the referenced branch,
 * location, employee, customer and product rows, after the sequence lock.
 * [ENGINE] NOT VERIFIED (ENGINE_HOLD).
 *
 * Only `post` calls this. `reverse` copies what the original stored and never
 * does (AC-55, D-35).
 */
final class JournalReferences
{
    /**
     * The dimension => target table, for the five dimensions whose table exists.
     */
    private const TABLES = [
        'branch_id' => 'branches',
        'location_id' => 'locations',
        'employee_id' => 'employees',
        'customer_id' => 'customers',
        'product_id' => 'products',
    ];

    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * @throws PostingRefused unknown_reference
     */
    public function check(ParsedPosting $posting): void
    {
        /** @var list<array{table: string, id: string, field: string, line: ?int}> $references */
        $references = [];

        if ($posting->branchId !== null) {
            $references[] = ['table' => self::TABLES['branch_id'], 'id' => $posting->branchId, 'field' => 'branch_id', 'line' => null];
        }

        foreach ($posting->lines as $index => $line) {
            foreach (JournalDimension::cases() as $dimension) {
                $table = self::TABLES[$dimension->value] ?? null;
                $id = $line['dimensions'][$dimension->value] ?? null;

                if ($table !== null && $id !== null) {
                    $references[] = ['table' => $table, 'id' => $id, 'field' => 'lines.'.$index.'.'.$dimension->value, 'line' => $index];
                }
            }
        }

        $wanted = [];

        foreach ($references as $reference) {
            $wanted[$reference['table']][$reference['id']] = true;
        }

        $live = [];

        foreach ($wanted as $table => $ids) {
            $found = $this->tenant->get()->table($table)
                ->whereIn('id', array_keys($ids))
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();

            $live[$table] = array_fill_keys(array_map('strval', $found), true);
        }

        foreach ($references as $reference) {
            if (! isset($live[$reference['table']][$reference['id']])) {
                throw PostingRefused::because(
                    PostingRefusal::UnknownReference,
                    'The '.$reference['field'].' names no live row.',
                    $reference['field'],
                    $reference['line'] === null ? [] : ['line_index' => $reference['line']],
                );
            }
        }
    }
}
