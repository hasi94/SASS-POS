<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdatePurchaseRequestLine;

final class UpdatePurchaseRequestLineResult
{
    /**
     * @param  array<string, mixed>  $line  the stored line
     * @param  list<string>  $changedColumns  what this call changed; empty when it changed nothing
     */
    public function __construct(
        public readonly string $lineId,
        public readonly string $requestId,
        public readonly array $line,
        public readonly array $changedColumns,
    ) {}

    public function changed(): bool
    {
        return $this->changedColumns !== [];
    }
}
