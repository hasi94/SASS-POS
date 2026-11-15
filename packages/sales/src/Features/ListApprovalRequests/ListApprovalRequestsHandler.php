<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ListApprovalRequests;

use Flexipos\Sales\Approvals\ApprovalStatus;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Page;
use Flexipos\Sales\Support\Pager;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ListApprovalRequestsHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(ListApprovalRequests $query): Page
    {
        $builder = $this->tenant->get()->table('approval_requests');

        if ($query->branchId !== null) {
            $builder->where('branch_id', Identifier::fold($query->branchId, 'branch_id'));
        }

        if ($query->status !== null) {
            if (! in_array($query->status, ApprovalStatus::values(), true)) {
                throw SalesRefused::validation('The status must be one of: '.implode(', ', ApprovalStatus::values()).'.');
            }

            $builder->where('status', $query->status);
        }

        return Pager::page($builder, $this->config, $query->pageSize, $query->beforeId);
    }
}
