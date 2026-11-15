<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\ListLoyaltyTransactions;

use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\Page;
use Flexipos\Customers\Support\Pager;
use Flexipos\Customers\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ListLoyaltyTransactionsHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(ListLoyaltyTransactions $query): Page
    {
        $customerId = Identifier::fold($query->customerId, 'customerId');

        $builder = $this->tenant->get()->table('loyalty_transactions')->where('customer_id', $customerId);

        return Pager::page($builder, $this->config, $query->pageSize, $query->beforeId);
    }
}
