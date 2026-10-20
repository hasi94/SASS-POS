<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ListEmployees;

use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\EmployeeView;
use Flexipos\Access\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The tenant's live employees, by code, one page at a time (AC-29).
 *
 * **Clamped, not refused.** A request for more rows than the maximum
 * returns the maximum. A caller asking for a thousand wants the data, not
 * an error, and a refusal would make the limit a thing every client
 * discovers by failing. A size of zero or less is nonsense rather than a
 * request for nothing, so it falls back to the configured default.
 *
 * **Both figures are read from configuration on every call**, never cached
 * into a property and never written as literals: `config/access.php` is
 * their one home, and AC-29's test moves them and watches the result
 * follow. A handler holding `50` and `100` passes every fixed-number
 * assertion and fails that one.
 *
 * Soft-deleted employees are excluded, and the fields a reader sees are
 * whatever {@see EmployeeView} carries - which deliberately does not
 * include `pin_hash`, `pin_failed_attempts` or `perm_version`.
 */
final class ListEmployeesHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return list<EmployeeView>
     */
    public function handle(ListEmployees $query): array
    {
        $default = (int) $this->config->get('access.employees.page_size', 50);
        $maximum = (int) $this->config->get('access.employees.max_page_size', 100);

        $size = $query->pageSize === null || $query->pageSize < 1 ? $default : $query->pageSize;
        $size = min($size, $maximum);

        $connection = $this->tenant->get();

        $rows = $connection->table('employees')
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->limit($size)
            ->get(['id', 'code', 'first_name', 'last_name', 'display_name', 'status', 'pin_hash']);

        return EmployeeLookup::render($connection, $rows);
    }
}
