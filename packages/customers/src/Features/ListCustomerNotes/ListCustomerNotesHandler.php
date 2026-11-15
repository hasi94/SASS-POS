<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\ListCustomerNotes;

use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\Page;
use Flexipos\Customers\Support\Pager;
use Flexipos\Customers\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ListCustomerNotesHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(ListCustomerNotes $query): Page
    {
        $customerId = Identifier::fold($query->customerId, 'customerId');

        $builder = $this->tenant->get()->table('customer_notes')->where('customer_id', $customerId);

        return Pager::page($builder, $this->config, $query->pageSize, $query->beforeId);
    }
}
