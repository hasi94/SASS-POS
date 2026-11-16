<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\GetPurchaseRequest;

final class GetPurchaseRequestResult
{
    /**
     * @param  array<string, mixed>  $request  the header: number, status, branch, location, requester, required date, notes and `workflow_instance_id`
     * @param  list<array<string, mixed>>  $lines  by line number: the stored unit quad, `estimated_cost` as a four-decimal string or null, the supplier and the order line pointer
     */
    public function __construct(
        public readonly array $request,
        public readonly array $lines,
    ) {}
}
