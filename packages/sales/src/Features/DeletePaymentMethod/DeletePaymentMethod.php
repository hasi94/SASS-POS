<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\DeletePaymentMethod;

/**
 * Soft-delete a payment method (T-030 AC-16).
 */
final class DeletePaymentMethod
{
    public function __construct(
        public readonly string $paymentMethodId,
        public readonly ?string $actorId = null,
    ) {}
}
