<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RemoveTender;

final class RemoveTenderResult
{
    public function __construct(
        public readonly string $tenderId,
        public readonly string $saleStatus,
    ) {}
}
