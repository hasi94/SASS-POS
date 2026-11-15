<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\CreatePaymentMethod;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\TenantConnection;
use Flexipos\Sales\Support\Uniqueness;
use Flexipos\Sales\Tenders\PaymentMethodFields;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a payment method (T-030 AC-16).
 *
 * `code`, `name` and `type` are required; the booleans default as the schema
 * does (`is_active`, `allows_refund` and `allows_partial` true, the rest false).
 * A code differing from an existing one only by case is a duplicate: the
 * `lower(code)` pre-check gives one answer on every engine, and the unique index
 * is the caught backstop. The row's `version` starts at 1.
 */
final class CreatePaymentMethodHandler
{
    public const ACTION = 'sales_payment_method_created';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreatePaymentMethod $command): CreatePaymentMethodResult
    {
        $read = PaymentMethodFields::read($command->fields);

        foreach (['code', 'name', 'type'] as $required) {
            if (($read[$required] ?? null) === null) {
                throw SalesRefused::validation("The {$required} is required.");
            }
        }

        $actorId = Identifier::foldNullable($command->actorId, 'actor');
        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $read, $actorId): CreatePaymentMethodResult {
            Uniqueness::assertCodeFree($connection, 'payment_methods', (string) $read['code']);

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            try {
                $connection->table('payment_methods')->insert($read + [
                    'is_active' => true,
                    'opens_drawer' => false,
                    'requires_reference' => false,
                    'allows_change' => false,
                    'allows_refund' => true,
                    'allows_partial' => true,
                    'rounding_rule' => null,
                    'surcharge_percent' => null,
                    'sort_order' => 0,
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'version' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw SalesRefused::duplicate("The code [{$read['code']}] is already in use.");
            }

            $row = $connection->table('payment_methods')->where('id', $id)->first();

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'payment_method', $id, null, $row === null ? null : (array) $row);

            return new CreatePaymentMethodResult($id);
        });
    }
}
