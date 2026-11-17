<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\GetPurchaseOrder;

final class GetPurchaseOrderResult
{
    /**
     * @param  array<string, mixed>  $order  the header: number, status, the three supplier snapshots, branch, location, request, dates, currency, exchange rate, every total, `workflow_instance_id`, terms and notes
     * @param  list<array<string, mixed>>  $lines  by line number: the stored unit quad, description, cost, discount, group, tax, line total and the received columns, every amount a four-decimal string
     */
    public function __construct(
        public readonly array $order,
        public readonly array $lines,
    ) {}
}
