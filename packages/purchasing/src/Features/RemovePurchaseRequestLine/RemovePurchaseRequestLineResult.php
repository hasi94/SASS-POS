<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\RemovePurchaseRequestLine;

final class RemovePurchaseRequestLineResult
{
    /**
     * @param  array<string, mixed>  $removed  the line as it was, which is also what the audit row's before image holds
     */
    public function __construct(
        public readonly string $lineId,
        public readonly string $requestId,
        public readonly array $removed,
    ) {}
}
