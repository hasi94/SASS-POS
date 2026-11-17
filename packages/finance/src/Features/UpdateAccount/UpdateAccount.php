<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\UpdateAccount;

/**
 * Update an account's own fields (T-032 AC-14, AC-15, AC-18 to AC-22).
 *
 * `parent_id` and `is_active` are not updatable here: they change only
 * through re-parent, deactivate and reactivate (AC-8, D-24).
 */
final class UpdateAccount
{
    /**
     * @param  array<array-key, mixed>  $fields  the request body, validated by `AccountInput::forUpdate`
     */
    public function __construct(
        public readonly string $accountId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
