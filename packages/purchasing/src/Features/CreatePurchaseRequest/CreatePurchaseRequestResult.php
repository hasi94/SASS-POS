<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CreatePurchaseRequest;

final class CreatePurchaseRequestResult
{
    /**
     * @param  bool  $replayed  true when the id already existed with identical input: nothing was written
     * @param  array<string, mixed>  $request  the stored header
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $number,
        public readonly bool $replayed,
        public readonly array $request,
    ) {}
}
