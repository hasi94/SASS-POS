<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ReportOutboxAge;

use Carbon\CarbonImmutable;
use Flexipos\Eventbus\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * AC-32: `min(occurred_at) where status='pending'` for the currently
 * entered tenant, as seconds before now - or null when nothing is pending.
 */
final class ReportOutboxAgeHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(ReportOutboxAge $command): ?int
    {
        $oldest = $this->tenant->get()->table('outbox_events')
            ->where('status', 'pending')
            ->min('occurred_at');

        if ($oldest === null) {
            return null;
        }

        // diffInSeconds() is signed by default here (the sign follows the
        // argument's position, not which instant is earlier), so the
        // absolute flag is explicit rather than relying on operand order.
        return max(0, (int) $this->clock->now()->diffInSeconds(CarbonImmutable::parse((string) $oldest), true));
    }
}
