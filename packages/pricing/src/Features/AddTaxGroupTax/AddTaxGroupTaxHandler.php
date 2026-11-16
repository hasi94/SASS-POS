<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\AddTaxGroupTax;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TenantConnection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Link a tax to a tax group at a `sequence` (T-029 AC-9).
 *
 * Both ids must exist, the `(tax_group_id, tax_id)` pair may not repeat (the
 * primary key), and the `sequence` may not repeat within the group (a unique key
 * this task adds, because compounding order would otherwise depend on row
 * order). `sequence` is 0 to 255, the `TINYINT UNSIGNED` column.
 */
final class AddTaxGroupTaxHandler
{
    public const ACTION = 'pricing_tax_group_tax_added';

    public const FIELDS = ['tax_group_id', 'tax_id', 'sequence'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(AddTaxGroupTax $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if (! Fields::has($fields, 'sequence')) {
            throw PricingAdminRefused::validation('The sequence is required.');
        }

        $sequence = Fields::integer($fields, 'sequence', 0, 0, 255);

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $fields, $sequence): void {
            $group = Lookup::any($connection, 'tax_groups', $fields['tax_group_id'] ?? null, 'tax group', lock: true);
            $tax = Lookup::live($connection, 'taxes', $fields['tax_id'] ?? null, 'tax');

            $groupId = (string) $group->id;
            $taxId = (string) $tax->id;

            if ($connection->table('tax_group_taxes')->where('tax_group_id', $groupId)->where('tax_id', $taxId)->exists()) {
                throw PricingAdminRefused::duplicate("Tax [{$taxId}] is already in group [{$groupId}].");
            }

            if ($connection->table('tax_group_taxes')->where('tax_group_id', $groupId)->where('sequence', $sequence)->exists()) {
                throw PricingAdminRefused::duplicate("Group [{$groupId}] already has a tax at sequence {$sequence}.");
            }

            try {
                $connection->table('tax_group_taxes')->insert([
                    'tax_group_id' => $groupId,
                    'tax_id' => $taxId,
                    'sequence' => $sequence,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate("Tax [{$taxId}] or sequence {$sequence} is already in group [{$groupId}].");
            }

            $row = $connection->table('tax_group_taxes')->where('tax_group_id', $groupId)->where('tax_id', $taxId)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'tax_group_tax', "{$groupId}:{$taxId}", null, $row === null ? null : (array) $row);
        });
    }
}
