<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdatePurchaseRequest;

/**
 * Edit the header of a draft purchase request (T-034 AC-20, AC-21, AC-25).
 */
final class UpdatePurchaseRequest
{
    /**
     * @param  array<string, mixed>  $fields  `request_id`, and any of `location_id`, `required_date` (null clears it) and `notes`; the branch never changes
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
