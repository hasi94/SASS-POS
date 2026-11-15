<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Bus;

/**
 * A PHPStan-only description of predis' `executeRaw()` shape (T-018 review
 * round 1, R-1) - never implemented, never instantiated, and never a
 * native type hint anywhere: predis is not installed in this repository
 * (D-1), so a real `Predis\Client` could not satisfy this interface at
 * the language level even if it wanted to.
 *
 * {@see RedisStreamsEventBus::callExecuteRaw()} accepts its client as a
 * plain `object` natively and only narrows it to this shape in a `@param`
 * docblock - a real PHPStan type boundary, one call frame away from the
 * `method_exists()` guard that justifies trusting it, so PHPStan can model
 * `$error`'s by-reference mutation through a declared signature instead of
 * an opaque call on `mixed` (which it never invalidates).
 */
interface PredisRawClient
{
    /**
     * @param  list<string>  $args
     */
    public function executeRaw(array $args, mixed &$error = null): mixed;
}
