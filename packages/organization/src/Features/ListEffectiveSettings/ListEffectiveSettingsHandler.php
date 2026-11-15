<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ListEffectiveSettings;

use Flexipos\Organization\Support\BranchLookup;
use Flexipos\Organization\Support\SettingScope;
use Flexipos\Organization\Support\SettingsResolver;
use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Support\TerminalLookup;

/**
 * Every registered setting's effective value for a company, a branch or a
 * terminal, with the layer it came from (T-014 AC-44, AC-48).
 *
 * **A scope that does not exist is `not_found`** (an inactive branch or a
 * revoked terminal still exists, and is readable: reading never mutates). The
 * employee scope is `scope_not_supported`, as on the write side. A secret is
 * listed as `is_set` with no value (AC-47). Ordered by key, no page: the
 * registry is a code-authored set, not data that grows with the business.
 */
final class ListEffectiveSettingsHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ListEffectiveSettings $query): array
    {
        $scope = SettingScope::parse($query->scopeType, $query->scopeId);
        $connection = $this->tenant->get();

        $branchId = null;
        $terminalId = null;

        if ($scope->type === SettingScope::BRANCH) {
            $branchId = (string) BranchLookup::find($connection, $scope->id)->id;
        }

        if ($scope->type === SettingScope::TERMINAL) {
            $terminal = TerminalLookup::find($connection, $scope->id);
            $terminalId = (string) $terminal->id;
            $branchId = (string) $terminal->branch_id;
        }

        return SettingsResolver::effective($connection, $branchId, $terminalId);
    }
}
