<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\RecordCreditLedgerEntry;

use Flexipos\Contracts\Customers\CreditEntryType;
use Flexipos\Contracts\Customers\CreditLedgerPosting;
use Flexipos\Contracts\Customers\CreditLedgerRefused;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Ledger\CreditLedgerCalculator;
use Flexipos\Customers\Ledger\CreditLedgerWriter;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\TenantConnection;

/**
 * Record one `customer_ledger` entry (T-028 plan Ruling 6, AC-19 to AC-24).
 *
 * The shape of the submitted fields is checked here (AC-20's non-ledger
 * refusals: an unknown customer, a missing reference, an unknown branch or
 * employee are all caught by the writer itself); amounts and sides are
 * {@see CreditLedgerCalculator}'s own job.
 */
final class RecordCreditLedgerEntryHandler
{
    public const FIELDS = [
        'customer_id', 'type', 'reference_type', 'reference_id', 'debit', 'credit',
        'currency', 'branch_id', 'employee_id', 'notes',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly CreditLedgerWriter $writer,
    ) {}

    /**
     * @throws CreditLedgerRefused
     */
    public function handle(RecordCreditLedgerEntry $command): CreditLedgerPosting
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $customerId = Identifier::fold(Fields::requiredText($fields, 'customer_id', 26), 'customer_id');
        $type = self::type($fields);
        $referenceType = Fields::requiredText($fields, 'reference_type', 50);

        if (in_array($referenceType, CreditLedgerWriter::RESERVED_REFERENCE_TYPES, true)) {
            throw CustomersRefused::validation("The reference type [{$referenceType}] is reserved for internal use.");
        }

        $referenceId = Identifier::fold(Fields::requiredText($fields, 'reference_id', 26), 'reference_id');
        $debit = $fields['debit'] ?? 0;
        $credit = $fields['credit'] ?? 0;

        if (! is_int($debit) && ! is_string($debit)) {
            throw CustomersRefused::validation('The debit must be an int or a string.');
        }

        if (! is_int($credit) && ! is_string($credit)) {
            throw CustomersRefused::validation('The credit must be an int or a string.');
        }

        $currency = Fields::has($fields, 'currency') ? self::currency($fields) : null;
        $branchId = Identifier::foldNullable(Fields::has($fields, 'branch_id') ? $fields['branch_id'] : null, 'branch_id');
        $employeeId = Identifier::foldNullable(Fields::has($fields, 'employee_id') ? $fields['employee_id'] : null, 'employee_id');
        $notes = Fields::optionalText($fields, 'notes', 65535);

        return $this->writer->post(
            $this->tenant->get(),
            $customerId,
            $type,
            $referenceType,
            $referenceId,
            $debit,
            $credit,
            $currency,
            $branchId,
            $employeeId,
            $notes,
            $command->actorId,
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function type(array $fields): CreditEntryType
    {
        $value = $fields['type'] ?? null;

        if (! is_string($value) || CreditEntryType::tryFrom($value) === null) {
            throw CustomersRefused::validation('The type must be one of: '.implode(', ', array_map(static fn (CreditEntryType $case): string => $case->value, CreditEntryType::cases())).'.');
        }

        return CreditEntryType::from($value);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function currency(array $fields): ?string
    {
        $value = $fields['currency'];

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/\A[A-Za-z]{3}\z/', $value) !== 1) {
            throw CustomersRefused::validation('The currency must be three letters.');
        }

        return strtoupper($value);
    }
}
