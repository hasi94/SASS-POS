<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateBrand;

/**
 * Create a product brand (T-027 AC-7, plan Ruling 10).
 */
final class CreateBrand
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
