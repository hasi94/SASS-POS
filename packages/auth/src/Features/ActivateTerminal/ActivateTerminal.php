<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\ActivateTerminal;

/**
 * One request to activate a terminal, as the public route received it
 * (T-014 AC-29).
 *
 * Every field is `mixed` and unjudged: the handler is the one place that
 * decides what is well formed, because a wrong type is a refusal that must be
 * counted and logged like any other, not a `TypeError` that reveals a
 * different response. `ip` is the printed address.
 */
final class ActivateTerminal
{
    public function __construct(
        public readonly mixed $code,
        public readonly mixed $fingerprint,
        public readonly mixed $platform = null,
        public readonly mixed $browser = null,
        public readonly mixed $os = null,
        public readonly mixed $screen = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}
}
