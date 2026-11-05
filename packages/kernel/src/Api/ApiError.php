<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api;

/**
 * One entry of the envelope's `errors` list (T-021 AC-3).
 */
final readonly class ApiError
{
    public function __construct(
        public string $code,
        public ?string $detail = null,
        public ?string $field = null,
    ) {}
}
