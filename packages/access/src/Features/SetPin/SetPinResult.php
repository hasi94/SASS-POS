<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SetPin;

/**
 * What setting a PIN did (AC-47).
 *
 * **It carries neither the PIN nor the hash, and that is the type doing the
 * work.** AC-48 names "every HTTP response body" as a sink, and the surest
 * way for a controller not to echo a secret is for the object it renders not
 * to hold one. A result exposing the digest would make every future caller -
 * a controller, a console command, a job - a place where the leak could be
 * reintroduced by one careless line.
 *
 * `$setAt` is the stored `pin_set_at`, so a caller can state when the
 * credential became current without reading the row again.
 */
final class SetPinResult
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $setAt,
    ) {}
}
