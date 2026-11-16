<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\CreatePaymentMethod;

final class CreatePaymentMethodResult
{
    public function __construct(
        public readonly string $paymentMethodId,
    ) {}
}
