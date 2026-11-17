<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ConvertRequestToOrder;

final class ConvertRequestToOrderResult
{
    /**
     * @param  bool  $replayed  true when the request had already been converted for this supplier: nothing was written
     * @param  array<string, mixed>  $order  the order header
     * @param  list<array<string, mixed>>  $lines  the order lines, by line number
     * @param  array<string, mixed>  $request  the request header, `converted`
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $number,
        public readonly bool $replayed,
        public readonly array $order,
        public readonly array $lines,
        public readonly array $request,
    ) {}
}
