<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateTaxGroup;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\DefaultTaxGroup;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\TenantConnection;
use Flexipos\Pricing\Support\Uniqueness;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a tax group (T-029 AC-8). Creating it as the default clears the
 * previous default in the same transaction, so exactly one remains. An inactive
 * group cannot be the default (AC-10), so that combination is refused.
 */
final class CreateTaxGroupHandler
{
    public const ACTION = 'pricing_tax_group_created';

    public const FIELDS = ['code', 'name', 'is_inclusive', 'is_default', 'is_active'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateTaxGroup $command): CreateTaxGroupResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $code = Fields::requiredText($fields, 'code', 50);
        $name = Fields::requiredText($fields, 'name', 150);
        $isInclusive = Fields::flag($fields, 'is_inclusive', false);
        $isDefault = Fields::flag($fields, 'is_default', false);
        $isActive = Fields::flag($fields, 'is_active', true);

        if ($isDefault && ! $isActive) {
            throw PricingAdminRefused::validation('An inactive tax group cannot be the default.');
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $code, $name, $isInclusive, $isDefault, $isActive): CreateTaxGroupResult {
            Uniqueness::assertCodeFree($connection, 'tax_groups', $code);

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            if ($isDefault) {
                DefaultTaxGroup::clearOthers($connection, null, $now);
            }

            try {
                $connection->table('tax_groups')->insert([
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'code' => $code,
                    'name' => $name,
                    'is_inclusive' => $isInclusive,
                    'is_default' => $isDefault,
                    'is_active' => $isActive,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate("The code [{$code}] is already in use.");
            }

            $row = $connection->table('tax_groups')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'tax_group', $id, null, $row === null ? null : (array) $row);

            return new CreateTaxGroupResult($id);
        });
    }
}
