<?php

declare(strict_types=1);

namespace Flexipos\Customers\Loyalty;

use Flexipos\Contracts\Customers\LoyaltyLedger;
use Flexipos\Contracts\Customers\LoyaltyLedgerRefused;
use Flexipos\Contracts\Customers\LoyaltyPosting;
use Flexipos\Contracts\Customers\LoyaltyTransaction;
use Flexipos\Customers\Support\TenantConnection;

/**
 * The published door onto {@see LoyaltyWriter} (T-028 plan Ruling 2,
 * Ruling 6). `CustomersServiceProvider` binds `LoyaltyLedger` to this class -
 * the slice (`RecordLoyaltyTransaction`) and this adapter both build their
 * call from the same request shape, so the slice is the single
 * implementation and the contract is a door onto it.
 */
final class ContractLoyaltyLedger implements LoyaltyLedger
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly LoyaltyWriter $writer,
    ) {}

    /**
     * @throws LoyaltyLedgerRefused
     */
    public function record(LoyaltyTransaction $tx): LoyaltyPosting
    {
        return $this->writer->post(
            $this->tenant->get(),
            $tx->id,
            $tx->customerId,
            $tx->programId,
            $tx->type,
            $tx->points,
            $tx->value,
            $tx->expiresAt,
            $tx->employeeId,
            $tx->notes,
            $tx->actorId,
        );
    }
}
