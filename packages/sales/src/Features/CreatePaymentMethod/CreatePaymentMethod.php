<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\CreatePaymentMethod;

/**
 * Create a payment method (T-030 AC-16).
 */
final class CreatePaymentMethod
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
