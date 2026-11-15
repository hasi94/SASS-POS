<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ClearSetting;

/**
 * Remove one setting's override at a scope, so the next layer down applies.
 *
 * `$fields` is the whole submitted body (AC-12).
 */
final class ClearSetting
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
