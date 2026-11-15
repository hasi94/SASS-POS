<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api;

/**
 * One entry in the error-code catalogue (T-021 Ruling 2).
 *
 * `code` is the wire contract (D-4); `message` is a stable English default
 * for logs and for a client with no translation for the code yet.
 */
final readonly class ErrorCode
{
    public function __construct(
        public string $code,
        public int $status,
        public string $message,
    ) {}
}
