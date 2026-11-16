<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\RemoveTaxGroupTax;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\TenantConnection;

/**
 * Unlink a tax from a tax group (T-029 AC-9). Removing the last member is
 * accepted: an empty group computes zero tax, which is a legitimate tax-exempt
 * group and not an error.
 */
final class RemoveTaxGroupTaxHandler
{
    public const ACTION = 'pricing_tax_group_tax_removed';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(RemoveTaxGroupTax $command): void
    {
        $groupId = Identifier::fold($command->taxGroupId, 'taxGroupId');
        $taxId = Identifier::fold($command->taxId, 'taxId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $groupId, $taxId): void {
            $before = $connection->table('tax_group_taxes')->where('tax_group_id', $groupId)->where('tax_id', $taxId)->lockForUpdate()->first();

            if ($before === null) {
                throw PricingAdminRefused::notFound("Tax [{$taxId}] is not in group [{$groupId}].");
            }

            $connection->table('tax_group_taxes')->where('tax_group_id', $groupId)->where('tax_id', $taxId)->delete();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'tax_group_tax', "{$groupId}:{$taxId}", (array) $before, null);
        });
    }
}
