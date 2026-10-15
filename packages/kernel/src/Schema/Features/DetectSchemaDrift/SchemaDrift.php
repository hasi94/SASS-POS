<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\DetectSchemaDrift;

/**
 * One tenant's answer to "does the mirror still match the database".
 *
 * The direction is a value rather than a boolean, because the two directions
 * mean opposite things and call for opposite actions: **behind** is a crash
 * between two writes and is repaired, **ahead** is a database that has lost
 * migrations and is refused (see `SchemaRepairRefused`). A single `drifted`
 * flag would let a caller treat them the same, which is the one mistake with
 * a cost.
 *
 * `unreachable` is a third outcome and not an exception: the drift report is
 * run across the fleet and one tenant whose server is down must not stop the
 * other answers. The reason is stored sanitised by the caller.
 */
final readonly class SchemaDrift
{
    public const BEHIND = 'behind';

    public const AHEAD = 'ahead';

    public const ALIGNED = 'aligned';

    public const UNREACHABLE = 'unreachable';

    public function __construct(
        public string $tenantId,
        public string $direction,
        public int $mappingVersion,
        public int $appliedVersion,
        public ?string $reason = null,
    ) {}

    /**
     * Whether this tenant needs somebody to do something.
     */
    public function drifted(): bool
    {
        return $this->direction === self::BEHIND || $this->direction === self::AHEAD;
    }

    /**
     * The direction implied by two versions.
     */
    public static function directionFor(int $mappingVersion, int $appliedVersion): string
    {
        if ($mappingVersion < $appliedVersion) {
            return self::BEHIND;
        }

        return $mappingVersion > $appliedVersion ? self::AHEAD : self::ALIGNED;
    }
}
