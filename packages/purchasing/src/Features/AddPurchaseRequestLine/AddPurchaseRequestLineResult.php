<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\AddPurchaseRequestLine;

final class AddPurchaseRequestLineResult
{
    /**
     * @param  bool  $replayed  true when the line id already existed with identical input: nothing was written
     * @param  array<string, mixed>  $line  the stored line
     */
    public function __construct(
        public readonly string $lineId,
        public readonly string $requestId,
        public readonly bool $replayed,
        public readonly array $line,
    ) {}
}
