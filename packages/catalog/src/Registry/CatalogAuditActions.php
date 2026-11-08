<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Registry;

use Flexipos\Catalog\Features\CreateUnit\CreateUnitHandler;
use Flexipos\Catalog\Features\UpdateUnit\UpdateUnitHandler;

/**
 * The audit actions this package's handlers write through
 * `AuditTrail::record()` (T-027 plan Ruling 11, T-017 Ruling 3).
 *
 * **Built from each handler's own `ACTION` constant, so no literal is
 * copied** (the organization precedent): this list cannot drift from what
 * the handlers actually pass.
 */
final class CatalogAuditActions
{
    /**
     * The module every action below belongs to.
     */
    public const MODULE = 'catalog';

    /**
     * @var list<string>
     */
    public const ALL = [
        CreateUnitHandler::ACTION,
        UpdateUnitHandler::ACTION,
    ];
}
