<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\SetTenantFeatureFlag;

final class SetTenantFeatureFlag
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $flagKey,
        public readonly ?bool $enabled,
        public readonly ?string $reason = null,
        public readonly ?string $actorUserId = null,
    ) {}
}
