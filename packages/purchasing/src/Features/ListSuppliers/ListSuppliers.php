<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ListSuppliers;

/**
 * List suppliers by code, keyset-paged (T-034 AC-16, D-34).
 */
final class ListSuppliers
{
    /**
     * @param  mixed  $isActive  null for both, otherwise a bool; anything else is `validation`
     * @param  mixed  $query  a case-insensitive fragment of the code or the name; blank means no filter
     * @param  mixed  $pageSize  null for the default, otherwise an int of at least 1 (above the maximum is clamped)
     * @param  mixed  $afterCode  the last code of the previous page
     */
    public function __construct(
        public readonly ?string $actorId = null,
        public readonly mixed $isActive = null,
        public readonly mixed $query = null,
        public readonly mixed $pageSize = null,
        public readonly mixed $afterCode = null,
    ) {}
}
