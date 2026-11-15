<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Platform;

/**
 * One key's resolved answer: either a numeric cap or uncapped (T-016 AC-3).
 *
 * `$value === null` means the tenant's plan carries no `plan_limits` row for
 * this key, which AC-3 reads as "never refused on plan-limit grounds, however
 * many already exist" - not as zero, and not as the loosest number anyone
 * could imagine.
 *
 * {@see self::assertRoomFor()} is **the one comparison** this task makes
 * against a limit (plan section 3): `count >= value` refuses, matching AC-1's
 * own worked example (5 existing refuses a 6th).
 */
final class PlanLimit
{
    public function __construct(
        public readonly LimitKey $key,
        public readonly ?int $value,
    ) {}

    /**
     * @throws PlanLimitReached
     */
    public function assertRoomFor(int $currentCount): void
    {
        if ($this->value !== null && $currentCount >= $this->value) {
            throw PlanLimitReached::for($this->key, $this->value);
        }
    }
}
