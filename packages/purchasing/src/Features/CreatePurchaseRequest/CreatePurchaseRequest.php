<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CreatePurchaseRequest;

/**
 * Raise a draft purchase request (T-034 AC-19 to AC-21, AC-36, AC-39).
 */
final class CreatePurchaseRequest
{
    /**
     * @param  array<string, mixed>  $fields  `id` (the caller's ULID, which is also the replay key), `branch_id`, `location_id`, and optionally `required_date` and `notes`; the requester, the number, the status and the date raised are the server's
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
