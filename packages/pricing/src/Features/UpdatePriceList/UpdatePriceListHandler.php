<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UpdatePriceList;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\PriceListFields;
use Flexipos\Pricing\Support\TenantConnection;
use Flexipos\Pricing\Support\Uniqueness;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Update a price list (T-029 AC-12). The window is checked against the row as it
 * would be after the update, so moving only one end cannot invert it.
 */
final class UpdatePriceListHandler
{
    public const ACTION = 'pricing_price_list_updated';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdatePriceList $command): void
    {
        Fields::only($command->fields, PriceListFields::FIELDS);

        if ($command->fields === []) {
            throw PricingAdminRefused::validation('An update must name at least one field.');
        }

        $id = Identifier::fold($command->priceListId, 'priceListId');
        $changes = PriceListFields::read($command->fields);

        foreach (['type', 'currency', 'priority', 'is_active'] as $notNull) {
            if (array_key_exists($notNull, $changes) && $changes[$notNull] === null) {
                throw PricingAdminRefused::validation("The {$notNull} may not be null.");
            }
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $id, $changes): void {
            $before = Lookup::live($connection, 'price_lists', $id, 'price list', lock: true);

            PriceListFields::assertWindow(
                array_key_exists('starts_at', $changes) ? $changes['starts_at'] : ($before->starts_at === null ? null : (string) $before->starts_at),
                array_key_exists('ends_at', $changes) ? $changes['ends_at'] : ($before->ends_at === null ? null : (string) $before->ends_at),
            );

            if (isset($changes['code']) && $changes['code'] !== $before->code) {
                Uniqueness::assertCodeFree($connection, 'price_lists', (string) $changes['code'], $id);
            }

            try {
                $connection->table('price_lists')->where('id', $id)->update($changes + ['updated_at' => $this->clock->timestamp()]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate('The code is already in use.');
            }

            $after = $connection->table('price_lists')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'price_list', $id, (array) $before, $after === null ? null : (array) $after);
        });
    }
}
