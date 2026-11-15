<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ListSaleLines;

use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\Page;
use Flexipos\Sales\Support\Pager;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The lines of one sale, newest first by the server-generated id (so the reverse
 * of the order they were added in), keyset-paged. A sale that does not exist is
 * `not_found`, so an empty page always means "no lines".
 */
final class ListSaleLinesHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(ListSaleLines $query): Page
    {
        $connection = $this->tenant->get();
        $saleId = Identifier::fold($query->saleId, 'sale_id');

        Lookup::any($connection, 'sales', $saleId, 'sale');

        return Pager::page($connection->table('sale_items')->where('sale_id', $saleId), $this->config, $query->pageSize, $query->beforeId);
    }
}
