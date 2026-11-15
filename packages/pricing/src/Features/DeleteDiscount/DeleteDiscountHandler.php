<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\DeleteDiscount;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TenantConnection;

/**
 * Soft-delete a discount (T-029 AC-21). The row stays in place with
 * `deleted_at` set and nothing cascades: no history of use exists yet to check,
 * and a total already computed against it is not recomputed.
 */
final class DeleteDiscountHandler
{
    public const ACTION = 'pricing_discount_deleted';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(DeleteDiscount $command): void
    {
        $id = Identifier::fold($command->discountId, 'discountId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $id): void {
            $before = Lookup::live($connection, 'discounts', $id, 'discount', lock: true);

            $now = $this->clock->timestamp();

            $connection->table('discounts')->where('id', $id)->update(['deleted_at' => $now, 'updated_at' => $now, 'updated_by' => $command->actorId]);

            $after = $connection->table('discounts')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'discount', $id, (array) $before, $after === null ? null : (array) $after);
        });
    }
}
