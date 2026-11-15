<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\SetSetting;

/**
 * Set (or replace) one setting's override at a scope.
 *
 * `$fields` is the whole submitted body, so a field the operation does not
 * take is refused rather than ignored (AC-12).
 */
final class SetSetting
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
