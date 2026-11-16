<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UpdateDiscount;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\DiscountFields;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TenantConnection;
use Flexipos\Pricing\Support\Uniqueness;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Update a discount (T-029 AC-19). `used_count` is refused whole: the row stays
 * byte-identical and no audit row is written. The cross-field rules run against
 * the row as it will be after the update.
 */
final class UpdateDiscountHandler
{
    public const ACTION = 'pricing_discount_updated';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateDiscount $command): void
    {
        DiscountFields::refuseMaintained($command->fields);

        Fields::only($command->fields, DiscountFields::FIELDS);

        if ($command->fields === []) {
            throw PricingAdminRefused::validation('An update must name at least one field.');
        }

        $id = Identifier::fold($command->discountId, 'discountId');
        $changes = DiscountFields::read($command->fields);

        foreach (['code', 'name', 'type', 'value', 'scope', 'requires_approval', 'is_active'] as $notNull) {
            if (array_key_exists($notNull, $changes) && $changes[$notNull] === null) {
                throw PricingAdminRefused::validation("The {$notNull} may not be null.");
            }
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $id, $changes): void {
            $before = Lookup::live($connection, 'discounts', $id, 'discount', lock: true);

            DiscountFields::assertCoherent($changes + (array) $before);

            if (isset($changes['code']) && $changes['code'] !== $before->code) {
                Uniqueness::assertCodeFree($connection, 'discounts', (string) $changes['code'], $id);
            }

            if (($changes['workflow_definition_id'] ?? null) !== null) {
                Lookup::live($connection, 'workflow_definitions', $changes['workflow_definition_id'], 'workflow definition');
            }

            try {
                $connection->table('discounts')->where('id', $id)->update($changes + [
                    'updated_by' => $command->actorId,
                    'updated_at' => $this->clock->timestamp(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate('The code is already in use.');
            }

            $after = $connection->table('discounts')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'discount', $id, (array) $before, $after === null ? null : (array) $after);
        });
    }
}
