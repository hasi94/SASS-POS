<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateTaxRate;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\RateRanges;
use Flexipos\Pricing\Support\TenantConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create an effective-dated rate for a tax (T-029 AC-6).
 *
 * `rate` is a percentage stored as typed (`15.0000` is 15 %), never negative.
 * `effective_to`, when given, may not be before `effective_from`, and the range
 * may not overlap another rate of the same tax. The parent tax row is locked
 * for the check, so two writers for one tax serialise.
 */
final class CreateTaxRateHandler
{
    public const ACTION = 'pricing_tax_rate_created';

    public const FIELDS = ['tax_id', 'rate', 'effective_from', 'effective_to', 'note'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateTaxRate $command): CreateTaxRateResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $rate = Fields::rate($fields, 'rate');

        if ($rate === null) {
            throw PricingAdminRefused::validation('The rate is required.');
        }

        $from = (string) Fields::date($fields, 'effective_from', true);
        $to = Fields::date($fields, 'effective_to', false);
        $note = Fields::optionalText($fields, 'note', 255);

        if ($to !== null && $to < $from) {
            throw PricingAdminRefused::validation('The effective_to may not be before the effective_from.');
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $fields, $rate, $from, $to, $note): CreateTaxRateResult {
            $tax = Lookup::live($connection, 'taxes', $fields['tax_id'] ?? null, 'tax', lock: true);
            $taxId = (string) $tax->id;

            if ($connection->table('tax_rates')->where('tax_id', $taxId)->where('effective_from', $from)->exists()) {
                throw PricingAdminRefused::duplicate("Tax [{$taxId}] already has a rate effective from {$from}.");
            }

            RateRanges::assertNoOverlap($connection, $taxId, $from, $to);

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            try {
                $connection->table('tax_rates')->insert([
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'tax_id' => $taxId,
                    'rate' => $rate,
                    'effective_from' => $from,
                    'effective_to' => $to,
                    'note' => $note,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate("Tax [{$taxId}] already has a rate effective from {$from}.");
            }

            $row = $connection->table('tax_rates')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'tax_rate', $id, null, $row === null ? null : (array) $row);

            return new CreateTaxRateResult($id);
        });
    }
}
