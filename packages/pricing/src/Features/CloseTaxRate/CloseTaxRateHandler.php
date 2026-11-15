<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CloseTaxRate;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\RateRanges;
use Flexipos\Pricing\Support\TenantConnection;

/**
 * Close a rate: set `effective_to` and optionally `note`, nothing else
 * (T-029 AC-6, AC-7).
 *
 * `rate` and `effective_from` are immutable once written, because documents
 * were resolved against them, so naming either is refused as an unknown field.
 * The new end may not precede the start and may not make the range overlap
 * another rate of the same tax.
 */
final class CloseTaxRateHandler
{
    public const ACTION = 'pricing_tax_rate_closed';

    public const FIELDS = ['effective_to', 'note'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CloseTaxRate $command): void
    {
        Fields::only($command->fields, self::FIELDS);

        $id = Identifier::fold($command->taxRateId, 'taxRateId');
        $to = (string) Fields::date($command->fields, 'effective_to', true);

        $changes = ['effective_to' => $to];

        if (Fields::has($command->fields, 'note')) {
            $changes['note'] = Fields::optionalText($command->fields, 'note', 255);
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $id, $changes, $to): void {
            $before = Lookup::any($connection, 'tax_rates', $id, 'tax rate');

            // Lock the parent first: the overlap check below reads its siblings.
            Lookup::any($connection, 'taxes', $before->tax_id, 'tax', lock: true);

            $from = (string) $before->effective_from;

            if ($to < $from) {
                throw PricingAdminRefused::validation('The effective_to may not be before the effective_from.');
            }

            RateRanges::assertNoOverlap($connection, (string) $before->tax_id, $from, $to, $id);

            $connection->table('tax_rates')->where('id', $id)->update($changes + ['updated_at' => $this->clock->timestamp()]);

            $after = $connection->table('tax_rates')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'tax_rate', $id, (array) $before, $after === null ? null : (array) $after);
        });
    }
}
