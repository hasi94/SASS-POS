<?php

declare(strict_types=1);

namespace Flexipos\Customers\Ledger;

use Flexipos\Contracts\Customers\CreditLedgerEntry;
use Flexipos\Contracts\Customers\CreditLedgerPosting;
use Flexipos\Contracts\Customers\CreditLedgerRefused;
use Flexipos\Contracts\Customers\CustomerCreditLedger;
use Flexipos\Customers\Support\TenantConnection;

/**
 * The published door onto {@see CreditLedgerWriter} (T-028 plan Ruling 2,
 * Ruling 6). `CustomersServiceProvider` binds `CustomerCreditLedger` to this
 * class - the slice (`RecordCreditLedgerEntry`) and this adapter both build
 * their call from the same request shape, so the slice is the single
 * implementation and the contract is a door onto it.
 */
final class ContractCreditLedger implements CustomerCreditLedger
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly CreditLedgerWriter $writer,
    ) {}

    /**
     * @throws CreditLedgerRefused
     */
    public function record(CreditLedgerEntry $entry): CreditLedgerPosting
    {
        if (in_array($entry->referenceType, CreditLedgerWriter::RESERVED_REFERENCE_TYPES, true)) {
            throw CreditLedgerRefused::reservedReferenceType("The reference type [{$entry->referenceType}] is reserved for internal use.");
        }

        return $this->writer->post(
            $this->tenant->get(),
            $entry->customerId,
            $entry->type,
            $entry->referenceType,
            $entry->referenceId,
            $entry->debit,
            $entry->credit,
            $entry->currency,
            $entry->branchId,
            $entry->employeeId,
            $entry->notes,
            $entry->actorId,
        );
    }
}
