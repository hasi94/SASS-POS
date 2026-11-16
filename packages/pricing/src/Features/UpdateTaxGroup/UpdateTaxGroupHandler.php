<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UpdateTaxGroup;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\DefaultTaxGroup;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TenantConnection;
use Flexipos\Pricing\Support\Uniqueness;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Update a tax group (T-029 AC-8, AC-10, F-6).
 *
 * A tax group has no soft delete: it is retired with `is_active = false`, and
 * that is refused while a non-deleted product still references it through
 * either `tax_group_id` or `purchase_tax_group_id`, naming the count.
 * Deactivating the default group clears `is_default` in the same transaction, so
 * the directory never names an inactive default. Making a group the default
 * clears the previous one.
 */
final class UpdateTaxGroupHandler
{
    public const ACTION = 'pricing_tax_group_updated';

    public const FIELDS = ['code', 'name', 'is_inclusive', 'is_default', 'is_active'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateTaxGroup $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($fields === []) {
            throw PricingAdminRefused::validation('An update must name at least one field.');
        }

        $id = Identifier::fold($command->taxGroupId, 'taxGroupId');

        $changes = [];

        if (Fields::has($fields, 'code')) {
            $changes['code'] = Fields::requiredText($fields, 'code', 50);
        }

        if (Fields::has($fields, 'name')) {
            $changes['name'] = Fields::requiredText($fields, 'name', 150);
        }

        foreach (['is_inclusive', 'is_default', 'is_active'] as $flag) {
            if (Fields::has($fields, $flag)) {
                $changes[$flag] = Fields::flag($fields, $flag, false);
            }
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $id, $changes): void {
            $before = Lookup::any($connection, 'tax_groups', $id, 'tax group', lock: true);

            $wasActive = (bool) $before->is_active;
            $isActive = (bool) ($changes['is_active'] ?? $wasActive);

            if (($changes['is_default'] ?? false) === true && ! $isActive) {
                throw PricingAdminRefused::validation('An inactive tax group cannot be the default.');
            }

            if (isset($changes['code']) && $changes['code'] !== $before->code) {
                Uniqueness::assertCodeFree($connection, 'tax_groups', (string) $changes['code'], $id);
            }

            if ($wasActive && ! $isActive) {
                $blocking = $connection->table('products')
                    ->whereNull('deleted_at')
                    ->where(static function ($query) use ($id): void {
                        $query->where('tax_group_id', $id)->orWhere('purchase_tax_group_id', $id);
                    })
                    ->count();

                if ($blocking > 0) {
                    throw PricingAdminRefused::inUse("Tax group [{$id}] is still referenced by {$blocking} product(s).");
                }

                // The retired group may not stay the default (AC-10).
                $changes['is_default'] = false;
            }

            $now = $this->clock->timestamp();

            if (($changes['is_default'] ?? false) === true) {
                DefaultTaxGroup::clearOthers($connection, $id, $now);
            }

            try {
                $connection->table('tax_groups')->where('id', $id)->update($changes + ['updated_at' => $now]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate('The code or the default flag is already taken.');
            }

            $after = $connection->table('tax_groups')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'tax_group', $id, (array) $before, $after === null ? null : (array) $after);
        });
    }
}
