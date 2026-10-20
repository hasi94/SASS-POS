<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SetPin;

use Flexipos\Access\Support\PinPolicy;
use Flexipos\Access\Support\SelfChange;

/**
 * One request to give an employee a PIN (AC-47).
 *
 * **`$pin` is `mixed`, and that is the criterion's shape rather than
 * laxness.** AC-47 refuses "a non-string" and "an empty body" with
 * `validation`, so the submitted value has to survive as far as
 * {@see PinPolicy::accept()} to be judged there. A
 * `string` property would turn a submitted integer into a `TypeError` on the
 * way in - a 500 where the criterion asks for a 422 - and would put the type
 * rule in the constructor, away from the other four rules it belongs beside.
 *
 * **It is never printed, logged or re-emitted.** This object is built from
 * the request body, handed to the handler and dropped; nothing stringifies
 * it. AC-48's sinks are the places that must not hold the value, and a
 * command object that implemented `__toString()` or `jsonSerialize()` would
 * be a route into several of them at once.
 *
 * `$actorId` may name the subject themselves: setting one's own PIN is
 * explicitly allowed (AC-47), because a PIN is a credential rather than a
 * grant and changing it alters nothing about what its holder may do. That is
 * why no {@see SelfChange} call appears in this
 * slice, and why its absence is deliberate rather than forgotten.
 */
final class SetPin
{
    public function __construct(
        public readonly string $employeeId,
        public readonly mixed $pin,
        public readonly ?string $actorId = null,
    ) {}
}
