<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\DeletePriceList;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TenantConnection;

/**
 * Soft-delete a price list (T-029 AC-18).
 *
 * Refused while any assignment still references the list, naming the count.
 * After the last assignment is removed the delete is accepted, and the list's
 * items stay in place: they become unreachable, not orphaned.
 */
final class DeletePriceListHandler
{
    public const ACTION = 'pricing_price_list_deleted';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(DeletePriceList $command): void
    {
        $id = Identifier::fold($command->priceListId, 'priceListId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $id): void {
            $before = Lookup::live($connection, 'price_lists', $id, 'price list', lock: true);

            $assignments = $connection->table('price_list_assignments')->where('price_list_id', $id)->count();

            if ($assignments > 0) {
                throw PricingAdminRefused::inUse("Price list [{$id}] still has {$assignments} assignment(s).");
            }

            $now = $this->clock->timestamp();

            $connection->table('price_lists')->where('id', $id)->update(['deleted_at' => $now, 'updated_at' => $now]);

            $after = $connection->table('price_lists')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'price_list', $id, (array) $before, $after === null ? null : (array) $after);
        });
    }
}
