<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\GetAccountTree;

/**
 * Read the whole live chart, parents before children (T-032 AC-61, AC-64).
 */
final class GetAccountTree
{
    public function __construct(public readonly ?string $actorId = null) {}
}
