<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\UpdatePaymentMethod;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\TenantConnection;
use Flexipos\Sales\Support\Uniqueness;
use Flexipos\Sales\Tenders\PaymentMethodFields;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Update a payment method (T-030 AC-16; Ruling 7, row 4).
 *
 * **The method row is locked first**, as the transaction's first statement, so
 * that this update and a concurrent `AddTender` (which takes the same row under a
 * shared lock) cannot both succeed: without it a method could be deactivated
 * while a tender lands on it (T-028 review W-4's class). The tenders on open
 * sales are then counted with a plain read, which is current because the lock is
 * already held. An update of a soft-deleted method is `not_found`.
 *
 * Deactivating (`is_active` going from true to false) is refused `in_use`,
 * naming the count, while a `draft`, `held` or `pending_payment` sale has a
 * tender on the method. Anything else about the method may change under a tender.
 */
final class UpdatePaymentMethodHandler
{
    public const ACTION = 'sales_payment_method_updated';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdatePaymentMethod $command): void
    {
        $changes = PaymentMethodFields::read($command->fields);

        if ($changes === []) {
            throw SalesRefused::validation('An update must name at least one field.');
        }

        $id = Identifier::fold($command->paymentMethodId, 'paymentMethodId');
        $actorId = Identifier::foldNullable($command->actorId, 'actor');
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $id, $changes, $actorId): void {
            $before = Lookup::live($connection, 'payment_methods', $id, 'payment method', true);

            if (isset($changes['code']) && $changes['code'] !== $before->code) {
                Uniqueness::assertCodeFree($connection, 'payment_methods', (string) $changes['code'], $id);
            }

            if (array_key_exists('is_active', $changes) && $changes['is_active'] === false && (bool) $before->is_active) {
                $open = PaymentMethodFields::openTenders($connection, $id);

                if ($open > 0) {
                    throw SalesRefused::inUse("Payment method [{$id}] holds {$open} tender(s) on sales that are still open.");
                }
            }

            try {
                $connection->table('payment_methods')->where('id', $id)->update($changes + [
                    'updated_at' => $this->clock->timestamp(),
                    'version' => (int) $before->version + 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw SalesRefused::duplicate('The code is already in use.');
            }

            $after = $connection->table('payment_methods')->where('id', $id)->first();

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'payment_method', $id, (array) $before, $after === null ? null : (array) $after);
        });
    }
}
