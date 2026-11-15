<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\CreateBranch;

/**
 * Create a branch and its store location (T-014 AC-10).
 *
 * The whole submitted body travels in `$fields`, untouched, because the
 * handler refuses fields the operation does not take (`is_active`,
 * `default_location_id`, `id`, `deleted_at`) and can only do so if it sees
 * them.
 */
final class CreateBranch
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
