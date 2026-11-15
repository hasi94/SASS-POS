<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UpdateTax;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TaxFields;
use Flexipos\Pricing\Support\TenantConnection;
use Flexipos\Pricing\Support\Uniqueness;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Update a tax (T-029 AC-5, F-9). The authority is not an update field. The
 * `fixed` + compound refusal is checked against the row as it would be after
 * the update, so changing only one of the two cannot slip past it.
 */
final class UpdateTaxHandler
{
    public const ACTION = 'pricing_tax_updated';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateTax $command): void
    {
        Fields::only($command->fields, TaxFields::UPDATE_FIELDS);

        if ($command->fields === []) {
            throw PricingAdminRefused::validation('An update must name at least one field.');
        }

        $id = Identifier::fold($command->taxId, 'taxId');
        $changes = TaxFields::read($command->fields);

        foreach (['type', 'applies_to'] as $notNull) {
            if (array_key_exists($notNull, $changes) && $changes[$notNull] === null) {
                throw PricingAdminRefused::validation("The {$notNull} may not be null.");
            }
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $id, $changes): void {
            $before = Lookup::live($connection, 'taxes', $id, 'tax', lock: true);

            TaxFields::assertCoherent(
                (string) ($changes['type'] ?? $before->type),
                (bool) ($changes['is_compound'] ?? $before->is_compound),
            );

            if (isset($changes['code']) && $changes['code'] !== $before->code) {
                Uniqueness::assertCodeFree($connection, 'taxes', (string) $changes['code'], $id);
            }

            try {
                $connection->table('taxes')->where('id', $id)->update($changes + ['updated_at' => $this->clock->timestamp()]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate('The code is already in use.');
            }

            $after = $connection->table('taxes')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'tax', $id, (array) $before, $after === null ? null : (array) $after);
        });
    }
}
