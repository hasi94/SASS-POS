<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ChangeLicenceStatus;

final class ChangeLicenceStatusResult
{
    public function __construct(
        public readonly string $licenceId,
        public readonly ?string $trigger,
    ) {}
}
