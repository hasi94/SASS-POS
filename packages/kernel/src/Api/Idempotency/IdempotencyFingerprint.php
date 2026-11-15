<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Idempotency;

/**
 * The hash that proves two requests under the same key are really the same
 * request (T-021 D-8, AC-20, AC-54).
 *
 * It covers the method, the path, the actor and the raw body, and
 * deliberately excludes the query string (D-22, AC-54): the spec lists only
 * those four inputs.
 */
final class IdempotencyFingerprint
{
    public static function of(string $method, string $path, string $actor, string $rawBody): string
    {
        return hash('sha256', json_encode([
            strtoupper($method),
            $path,
            $actor,
            $rawBody,
        ], JSON_THROW_ON_ERROR));
    }
}
