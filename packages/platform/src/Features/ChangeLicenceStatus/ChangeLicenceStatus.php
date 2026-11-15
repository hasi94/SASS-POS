<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ChangeLicenceStatus;

final class ChangeLicenceStatus
{
    public function __construct(
        public readonly string $licenceId,
        public readonly string $status,
        public readonly ?string $revokedReason = null,
        public readonly ?string $actorUserId = null,
    ) {}
}
