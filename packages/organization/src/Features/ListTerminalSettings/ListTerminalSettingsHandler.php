<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ListTerminalSettings;

use Flexipos\Organization\Support\SettingsResolver;
use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Support\TerminalLookup;

/**
 * What a terminal is sent about its own configuration (T-014 AC-50).
 *
 * **Exactly the keys whose registry row has `sync_to_pos` and not
 * `is_secret`**, resolved for this terminal at its branch **as the tenant
 * database records them now**, not as the token claims (the middleware has
 * already refused a terminal that is not active at that branch). Nothing
 * about another terminal or branch is read into the answer: the resolver is
 * given this terminal and its own branch and no other id.
 */
final class ListTerminalSettingsHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ListTerminalSettings $query): array
    {
        $connection = $this->tenant->get();
        $terminal = TerminalLookup::find($connection, $query->terminalId);

        return SettingsResolver::effective($connection, (string) $terminal->branch_id, (string) $terminal->id, posOnly: true);
    }
}
