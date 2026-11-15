<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ListCashMovements;

use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\Page;
use Flexipos\Sales\Support\Pager;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * A shift's movements, ordered by `(created_at, id)`: the ids are supplied by the
 * caller, so an id on its own orders by whatever the client invented (R-17).
 */
final class ListCashMovementsHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(ListCashMovements $query): Page
    {
        $connection = $this->tenant->get();
        $shiftId = Identifier::fold($query->shiftId, 'shift_id');

        Lookup::any($connection, 'cash_shifts', $shiftId, 'cash shift');

        $builder = $connection->table('cash_movements')->where('shift_id', $shiftId);

        return Pager::pageByCreatedAt($builder, $this->config, $query->pageSize, $query->beforeId, 'cash_movements');
    }
}
