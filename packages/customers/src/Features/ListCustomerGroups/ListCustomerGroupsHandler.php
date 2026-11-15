<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\ListCustomerGroups;

use Flexipos\Customers\Support\Page;
use Flexipos\Customers\Support\Pager;
use Flexipos\Customers\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ListCustomerGroupsHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(ListCustomerGroups $query): Page
    {
        $builder = $this->tenant->get()->table('customer_groups')->whereNull('deleted_at');

        return Pager::page($builder, $this->config, $query->pageSize, $query->beforeId);
    }
}
