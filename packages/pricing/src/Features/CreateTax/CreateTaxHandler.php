<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateTax;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TaxFields;
use Flexipos\Pricing\Support\TenantConnection;
use Flexipos\Pricing\Support\Uniqueness;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a tax (T-029 AC-5, F-9). `authority_id`, `code`, `name`, `type` and
 * `applies_to` are required; the three flags default to false and `is_active`
 * to true; a `fixed` tax that is also compound is refused.
 */
final class CreateTaxHandler
{
    public const ACTION = 'pricing_tax_created';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateTax $command): CreateTaxResult
    {
        Fields::only($command->fields, TaxFields::CREATE_FIELDS);

        $read = TaxFields::read($command->fields);

        foreach (['authority_id', 'code', 'name', 'type', 'applies_to'] as $required) {
            if (($read[$required] ?? null) === null) {
                throw PricingAdminRefused::validation("The {$required} is required.");
            }
        }

        $isCompound = (bool) ($read['is_compound'] ?? false);

        TaxFields::assertCoherent((string) $read['type'], $isCompound);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $read, $isCompound): CreateTaxResult {
            Lookup::any($connection, 'tax_authorities', $read['authority_id'], 'tax authority');
            Uniqueness::assertCodeFree($connection, 'taxes', (string) $read['code']);

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            try {
                $connection->table('taxes')->insert([
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'authority_id' => $read['authority_id'],
                    'code' => $read['code'],
                    'name' => $read['name'],
                    'tax_code' => $read['tax_code'] ?? null,
                    'type' => $read['type'],
                    'is_compound' => $isCompound,
                    'applies_to' => $read['applies_to'],
                    'is_recoverable' => $read['is_recoverable'] ?? false,
                    'is_withholding' => $read['is_withholding'] ?? false,
                    'is_active' => $read['is_active'] ?? true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate("The code [{$read['code']}] is already in use.");
            }

            $row = $connection->table('taxes')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'tax', $id, null, $row === null ? null : (array) $row);

            return new CreateTaxResult($id);
        });
    }
}
