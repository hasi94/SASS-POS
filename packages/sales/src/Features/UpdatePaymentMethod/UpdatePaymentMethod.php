<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\UpdatePaymentMethod;

/**
 * Update a payment method (T-030 AC-16).
 */
final class UpdatePaymentMethod
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $paymentMethodId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
