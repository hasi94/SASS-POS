<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

/**
 * One request to activate a terminal, after auth has parsed and shape-checked
 * it (T-014 AC-29).
 *
 * Every field has already passed the shape rules of AC-30: `secret` is the
 * upper-cased `XXXX-XXXX`, `fingerprint` is exactly 64 lower-case hex
 * characters, and each device field is null or within its column's width. The
 * tenant is not here: auth entered it before it called.
 */
final class ActivationAttempt
{
    public function __construct(
        public readonly string $secret,
        public readonly string $fingerprint,
        public readonly ?string $platform = null,
        public readonly ?string $browser = null,
        public readonly ?string $os = null,
        public readonly ?string $screen = null,
        public readonly ?string $ip = null,
    ) {}
}
