<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ListCashShifts;

use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Shifts\ShiftStatus;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Page;
use Flexipos\Sales\Support\Pager;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ListCashShiftsHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(ListCashShifts $query): Page
    {
        $builder = $this->tenant->get()->table('cash_shifts');

        if ($query->branchId !== null) {
            $builder->where('branch_id', Identifier::fold($query->branchId, 'branch_id'));
        }

        if ($query->terminalId !== null) {
            $builder->where('terminal_id', Identifier::fold($query->terminalId, 'terminal_id'));
        }

        if ($query->status !== null) {
            if (! in_array($query->status, ShiftStatus::values(), true)) {
                throw SalesRefused::validation('The status must be one of: '.implode(', ', ShiftStatus::values()).'.');
            }

            $builder->where('status', $query->status);
        }

        return Pager::page($builder, $this->config, $query->pageSize, $query->beforeId);
    }
}
