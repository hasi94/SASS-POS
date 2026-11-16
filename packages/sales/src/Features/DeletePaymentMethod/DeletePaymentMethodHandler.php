<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\DeletePaymentMethod;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\TenantConnection;
use Flexipos\Sales\Tenders\PaymentMethodFields;

/**
 * Soft-delete a payment method (T-030 AC-16; Ruling 7, row 4).
 *
 * The row is locked `FOR UPDATE` as the first statement, then the tenders on
 * open sales are counted: refused `in_use`, naming the count, while a `draft`,
 * `held` or `pending_payment` sale holds one, accepted once that sale completes.
 * The row stays in place, and so does its code: a deleted method still owns the
 * unique key. Deleting an already deleted method is `not_found`.
 */
final class DeletePaymentMethodHandler
{
    public const ACTION = 'sales_payment_method_deleted';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(DeletePaymentMethod $command): void
    {
        $id = Identifier::fold($command->paymentMethodId, 'paymentMethodId');
        $actorId = Identifier::foldNullable($command->actorId, 'actor');
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $id, $actorId): void {
            $before = Lookup::live($connection, 'payment_methods', $id, 'payment method', true);

            $open = PaymentMethodFields::openTenders($connection, $id);

            if ($open > 0) {
                throw SalesRefused::inUse("Payment method [{$id}] holds {$open} tender(s) on sales that are still open.");
            }

            $now = $this->clock->timestamp();

            $connection->table('payment_methods')->where('id', $id)->update(['deleted_at' => $now, 'updated_at' => $now]);

            $after = $connection->table('payment_methods')->where('id', $id)->first();

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'payment_method', $id, (array) $before, $after === null ? null : (array) $after);
        });
    }
}
