<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ListEffectiveSettings;

/**
 * The effective settings for a scope, as the admin sees them.
 */
final class ListEffectiveSettings
{
    public function __construct(
        public readonly mixed $scopeType,
        public readonly mixed $scopeId,
    ) {}
}
