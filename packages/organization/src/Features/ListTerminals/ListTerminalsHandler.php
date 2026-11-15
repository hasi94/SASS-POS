<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ListTerminals;

use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Support\Identifier;
use Flexipos\Organization\Support\PageSize;
use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Support\TerminalLookup;
use Flexipos\Organization\Support\TerminalStatus;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The terminals ordered by `code`, filterable by branch and status, one page
 * (T-014 AC-42). **No item carries the activation hash**: the items are
 * {@see TerminalLookup::view()}, whose keys are enumerated by a test. An
 * unknown status filter is `validation`, not an empty list.
 */
final class ListTerminalsHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ListTerminals $query): array
    {
        $builder = $this->tenant->get()->table('terminals')->whereNull('deleted_at');

        if ($query->branchId !== null) {
            $builder->where('branch_id', Identifier::fold($query->branchId, 'branch_id filter'));
        }

        if ($query->status !== null) {
            if (! is_string($query->status) || ! in_array($query->status, TerminalStatus::ALL, true)) {
                throw OrganizationRefused::validation('The status filter is not a terminal status.');
            }

            $builder->where('status', $query->status);
        }

        $views = [];

        foreach ($builder->orderBy('code')->orderBy('id')->limit(PageSize::resolve($query->pageSize, $this->config))->get() as $row) {
            $views[] = TerminalLookup::view($row);
        }

        return $views;
    }
}
