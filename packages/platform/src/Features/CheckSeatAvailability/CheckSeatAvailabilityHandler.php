<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\CheckSeatAvailability;

use Flexipos\Contracts\Platform\LimitKey;
use Flexipos\Contracts\Platform\TenantSeats;
use Flexipos\Platform\Support\EntitlementSource;
use Flexipos\Platform\Support\MasterConnection;

/**
 * Implements `TenantSeats` (T-016 Ruling 9, AC-8).
 *
 * The count is a master-local query: `tenant_users` where `tenant_id` = ?,
 * `status` = `active` and `deleted_at` is null. No tenant database is
 * touched (AC-32).
 */
final class CheckSeatAvailabilityHandler implements TenantSeats
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly EntitlementSource $source,
    ) {}

    public function ensureRoomForOneMore(string $tenantId): void
    {
        $this->handle(new CheckSeatAvailability($tenantId));
    }

    public function handle(CheckSeatAvailability $command): void
    {
        $count = $this->master->get()->table('tenant_users')
            ->where('tenant_id', $command->tenantId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->count();

        $this->source->for($command->tenantId)->limit(LimitKey::Users)->assertRoomFor($count);
    }
}
