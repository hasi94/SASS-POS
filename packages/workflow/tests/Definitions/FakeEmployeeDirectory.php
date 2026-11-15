<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Tests\Definitions;

use Flexipos\Contracts\Access\EmployeeDirectory;

/**
 * A fake {@see EmployeeDirectory} for `DefinitionValidatorTest`, so that
 * suite stays a pure unit test over the validator (T-020 plan section 7).
 */
final class FakeEmployeeDirectory implements EmployeeDirectory
{
    /**
     * @param  list<string>  $liveIds
     */
    public function __construct(private readonly array $liveIds) {}

    public function isLive(string $employeeId): bool
    {
        return in_array($employeeId, $this->liveIds, true);
    }
}
