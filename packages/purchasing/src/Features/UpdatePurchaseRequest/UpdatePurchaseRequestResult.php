<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdatePurchaseRequest;

final class UpdatePurchaseRequestResult
{
    /**
     * @param  array<string, mixed>  $request  the stored header
     * @param  list<string>  $changedColumns  what this call changed; empty when it changed nothing
     */
    public function __construct(
        public readonly string $requestId,
        public readonly array $request,
        public readonly array $changedColumns,
    ) {}

    public function changed(): bool
    {
        return $this->changedColumns !== [];
    }
}
