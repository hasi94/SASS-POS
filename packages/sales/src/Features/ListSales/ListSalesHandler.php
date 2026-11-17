<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ListSales;

use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Sales\SaleStatus;
use Flexipos\Sales\Sales\SaleType;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Page;
use Flexipos\Sales\Support\Pager;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * A branch's sales, newest first, keyset-paged (T-030 AC-20, D-26).
 *
 * **Scoped to the branch, never to a terminal**, which is what lets the recall
 * screen of any till in the branch list what any other till parked. The branch is
 * required, so a list can never read across branches by leaving it out.
 */
final class ListSalesHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(ListSales $query): Page
    {
        $builder = $this->tenant->get()->table('sales')->where('branch_id', Identifier::fold($query->branchId, 'branch_id'));

        if ($query->status !== null) {
            $statuses = array_map(static fn (SaleStatus $status): string => $status->value, SaleStatus::cases());

            if (! in_array($query->status, $statuses, true)) {
                throw SalesRefused::validation('The status must be one of: '.implode(', ', $statuses).'.');
            }

            $builder->where('status', $query->status);
        }

        if ($query->type !== null) {
            $types = array_map(static fn (SaleType $type): string => $type->value, SaleType::cases());

            if (! in_array($query->type, $types, true)) {
                throw SalesRefused::validation('The type must be one of: '.implode(', ', $types).'.');
            }

            $builder->where('type', $query->type);
        }

        return Pager::page($builder, $this->config, $query->pageSize, $query->beforeId);
    }
}
