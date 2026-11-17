<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RemoveTender;

/**
 * Take back a mistakenly entered tender (T-030 AC-13).
 */
final class RemoveTender
{
    /**
     * @param  array<string, mixed>  $fields  `sale_id` and `tender_id`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
