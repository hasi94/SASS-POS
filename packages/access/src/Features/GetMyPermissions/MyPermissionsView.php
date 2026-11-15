<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\GetMyPermissions;

/**
 * What a caller is told about their own access (AC-60).
 *
 * **The `perm_*` values are nullable together, and that is the
 * no-employee case.** A token with no `employee` claim gets an empty
 * contribution list and no version and no hash - not a version of zero
 * and not an empty hash, either of which a client would compare against
 * its own and believe. Null is the one answer that cannot be mistaken for
 * a value.
 */
final class MyPermissionsView
{
    /**
     * @param  list<string>  $branchIds
     * @param  list<array{key: string, scope: string, allowed: bool}>  $contributions
     */
    public function __construct(
        public readonly ?int $permVersion,
        public readonly ?string $permHash,
        public readonly array $branchIds,
        public readonly bool $allBranches,
        public readonly array $contributions,
    ) {}

    /**
     * The empty answer, for a caller whose token names no employee.
     */
    public static function none(): self
    {
        return new self(null, null, [], false, []);
    }
}
