<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\RemovePurchaseRequestLine;

/**
 * Remove a line from a draft purchase request (T-034 AC-25, AC-39).
 */
final class RemovePurchaseRequestLine
{
    /**
     * @param  array<string, mixed>  $fields  `request_id` and `line_id`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
