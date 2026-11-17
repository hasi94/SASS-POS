<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UnassignPriceList;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TenantConnection;

/**
 * Remove a price list assignment (T-029 AC-14, AC-18). A hard delete, and
 * removing the last assignment does not delete the list itself.
 */
final class UnassignPriceListHandler
{
    public const ACTION = 'pricing_price_list_unassigned';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UnassignPriceList $command): void
    {
        $id = Identifier::fold($command->priceListAssignmentId, 'priceListAssignmentId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $id): void {
            $before = Lookup::any($connection, 'price_list_assignments', $id, 'price list assignment', lock: true);

            $connection->table('price_list_assignments')->where('id', $id)->delete();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'price_list_assignment', $id, (array) $before, null);
        });
    }
}
