<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\GetTerminal;

use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Support\TerminalLookup;

/**
 * One terminal (T-014 AC-42): `has_pending_code` and never the hash.
 */
final class GetTerminalHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetTerminal $query): array
    {
        return TerminalLookup::view(TerminalLookup::find($this->tenant->get(), $query->terminalId));
    }
}
