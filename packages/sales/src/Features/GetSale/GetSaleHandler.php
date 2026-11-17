<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\GetSale;

use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\TenantConnection;

/**
 * One sale's header and its tenders (T-030 D-26). A sale that is not there is
 * `not_found`. The tenders are one sale's own handful, so they are not paged; a
 * sale's lines are listed (and paged) by `ListSaleLines`.
 */
final class GetSaleHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function handle(GetSale $query): GetSaleResult
    {
        $connection = $this->tenant->get();
        $saleId = Identifier::fold($query->saleId, 'sale_id');

        $sale = Lookup::any($connection, 'sales', $saleId, 'sale');

        $tenders = $connection->table('sale_tenders')->where('sale_id', $saleId)->orderBy('paid_at')->orderBy('id')->get();

        return new GetSaleResult(
            (array) $sale,
            $tenders->map(static fn (object $tender): array => (array) $tender)->all(),
        );
    }
}
