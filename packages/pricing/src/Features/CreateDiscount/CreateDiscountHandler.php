<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateDiscount;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\DiscountFields;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TenantConnection;
use Flexipos\Pricing\Support\Uniqueness;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a discount (T-029 AC-19).
 *
 * `used_count` is refused whole, before anything else is read: only the usage
 * recording path increments it. `requires_approval` and the threshold are
 * stored and never evaluated here (D-8).
 */
final class CreateDiscountHandler
{
    public const ACTION = 'pricing_discount_created';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateDiscount $command): CreateDiscountResult
    {
        DiscountFields::refuseMaintained($command->fields);

        Fields::only($command->fields, DiscountFields::FIELDS);

        $read = DiscountFields::read($command->fields);

        foreach (['code', 'name', 'type', 'value', 'scope'] as $required) {
            if (($read[$required] ?? null) === null) {
                throw PricingAdminRefused::validation("The {$required} is required.");
            }
        }

        DiscountFields::assertCoherent($read);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $read): CreateDiscountResult {
            Uniqueness::assertCodeFree($connection, 'discounts', (string) $read['code']);

            if (($read['workflow_definition_id'] ?? null) !== null) {
                Lookup::live($connection, 'workflow_definitions', $read['workflow_definition_id'], 'workflow definition');
            }

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            try {
                $connection->table('discounts')->insert([
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'created_by' => $command->actorId,
                    'updated_by' => $command->actorId,
                    'code' => $read['code'],
                    'name' => $read['name'],
                    'type' => $read['type'],
                    'value' => $read['value'],
                    'scope' => $read['scope'],
                    'applies_to' => $read['applies_to'] ?? null,
                    'min_amount' => $read['min_amount'] ?? null,
                    'max_discount' => $read['max_discount'] ?? null,
                    'requires_approval' => $read['requires_approval'] ?? false,
                    'approval_threshold' => $read['approval_threshold'] ?? null,
                    'workflow_definition_id' => $read['workflow_definition_id'] ?? null,
                    'starts_at' => $read['starts_at'] ?? null,
                    'ends_at' => $read['ends_at'] ?? null,
                    'usage_limit' => $read['usage_limit'] ?? null,
                    'used_count' => 0,
                    'is_active' => $read['is_active'] ?? true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate("The code [{$read['code']}] is already in use.");
            }

            $row = $connection->table('discounts')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'discount', $id, null, $row === null ? null : (array) $row);

            return new CreateDiscountResult($id);
        });
    }
}
