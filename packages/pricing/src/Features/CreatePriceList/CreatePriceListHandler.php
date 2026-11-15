<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreatePriceList;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\PriceListFields;
use Flexipos\Pricing\Support\TenantConnection;
use Flexipos\Pricing\Support\Uniqueness;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a price list (T-029 AC-12). `code`, `name`, `type` and `currency` are
 * required; the window must not end before it starts. A code differing from an
 * existing one only by case is a duplicate, which is what keeps the engine's
 * bytewise code tiebreak (D-29) a total order over live lists.
 */
final class CreatePriceListHandler
{
    public const ACTION = 'pricing_price_list_created';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreatePriceList $command): CreatePriceListResult
    {
        Fields::only($command->fields, PriceListFields::FIELDS);

        $read = PriceListFields::read($command->fields);

        foreach (['code', 'name', 'type', 'currency'] as $required) {
            if (($read[$required] ?? null) === null) {
                throw PricingAdminRefused::validation("The {$required} is required.");
            }
        }

        $startsAt = isset($read['starts_at']) ? (string) $read['starts_at'] : null;
        $endsAt = isset($read['ends_at']) ? (string) $read['ends_at'] : null;

        PriceListFields::assertWindow($startsAt, $endsAt);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $read, $startsAt, $endsAt): CreatePriceListResult {
            Uniqueness::assertCodeFree($connection, 'price_lists', (string) $read['code']);

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            try {
                $connection->table('price_lists')->insert([
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'code' => $read['code'],
                    'name' => $read['name'],
                    'type' => $read['type'],
                    'currency' => $read['currency'],
                    'priority' => $read['priority'] ?? 0,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'is_active' => $read['is_active'] ?? true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate("The code [{$read['code']}] is already in use.");
            }

            $row = $connection->table('price_lists')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'price_list', $id, null, $row === null ? null : (array) $row);

            return new CreatePriceListResult($id);
        });
    }
}
