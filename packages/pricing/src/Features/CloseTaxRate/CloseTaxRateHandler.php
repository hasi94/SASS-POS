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
 * another rate of the same tax. A rate that already has an `effective_to` cannot
 * be closed again (AC-6a, D-37): moving the end of a closed rate would re-resolve
 * documents already priced against it, and closing is not an edit path. So this
 * only ever shortens an open-ended range.
 *
 * **Lock order (W-1, D-39): `taxes`, then `tax_rates`, the order `CreateTaxRate`
 * uses.** The rate's `tax_id` is read before the transaction, because no slice
 * ever changes it, so the value cannot go stale and the read does not fix the
 * transaction's snapshot. The first statement inside the transaction is then the
 * locking read of the parent tax, and the rate is read under a lock after it: a
 * locking read returns the latest committed row, so the AC-6a check and the
 * overlap check see what a concurrent writer committed. Locking the rate first
 * would deadlock against `CreateTaxRate`. On the SQLite stand-in the locks are
 * no-ops: this is NOT VERIFIED at run time until the engine hold lifts.
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

        // A plain key read, outside the transaction: `tax_id` is never updated.
        $taxId = $connection->table('tax_rates')->where('id', $id)->value('tax_id');

        if ($taxId === null) {
            throw PricingAdminRefused::notFound("No tax rate has id [{$id}].");
        }

        $connection->transaction(function () use ($connection, $command, $id, $taxId, $changes, $to): void {
            // Lock the parent first, before any plain read: the overlap check
            // below reads its siblings.
            Lookup::any($connection, 'taxes', $taxId, 'tax', lock: true);

            $before = Lookup::any($connection, 'tax_rates', $id, 'tax rate', lock: true);

            if ($before->effective_to !== null) {
                throw PricingAdminRefused::validation('This rate is already closed; closing is not an edit path.');
            }

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
