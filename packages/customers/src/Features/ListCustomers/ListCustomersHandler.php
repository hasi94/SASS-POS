<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\ListCustomers;

use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\Page;
use Flexipos\Customers\Support\Pager;
use Flexipos\Customers\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ListCustomersHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(ListCustomers $query): Page
    {
        $builder = $this->tenant->get()->table('customers')->whereNull('deleted_at');

        if ($query->groupId !== null) {
            $builder->where('group_id', Identifier::fold($query->groupId, 'group_id'));
        }

        if ($query->status !== null) {
            $builder->where('status', $query->status);
        }

        return Pager::page($builder, $this->config, $query->pageSize, $query->beforeId);
    }
}
