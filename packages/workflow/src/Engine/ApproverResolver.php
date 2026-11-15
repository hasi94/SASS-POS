<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Engine;

use Flexipos\Contracts\Access\RoleHolders;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Workflow\Definitions\ApproverType;

/**
 * Resolve one step's approver set, at the moment the step becomes active
 * (T-020 AC-9, Ruling 6).
 *
 * Resolution happens here and nowhere else: never when a definition is
 * written.
 */
final class ApproverResolver
{
    public function __construct(private readonly RoleHolders $roleHolders) {}

    /**
     * @param  array{roles?: list<string>, employees?: list<string>}  $approverRef
     * @return list<array{employeeId: string, roleId: ?string}> the resolved set, the requester excluded
     *
     * @throws WorkflowEvaluationFailed `approverTypeUnavailable`
     */
    public function resolve(ApproverType $type, array $approverRef, ?string $branchId, string $requesterId): array
    {
        if (! $type->isResolvable()) {
            throw WorkflowEvaluationFailed::approverTypeUnavailable($type->value);
        }

        $roles = $approverRef['roles'] ?? [];
        $employees = $approverRef['employees'] ?? [];

        /** @var array<string, ?string> $byEmployee employee id => qualifying role id, or null */
        $byEmployee = [];

        if ($roles !== []) {
            foreach ($this->roleHolders->holdersOf($roles, $branchId) as $employeeId => $roleId) {
                $byEmployee[$employeeId] = $roleId;
            }
        }

        if ($employees !== []) {
            foreach ($this->roleHolders->actingAmong($employees) as $employeeId) {
                if (! array_key_exists($employeeId, $byEmployee)) {
                    $byEmployee[$employeeId] = null;
                }
            }
        }

        // No self-approval (Ruling 6, -> ba informational): the requester
        // is removed from the resolved set.
        unset($byEmployee[$requesterId]);

        ksort($byEmployee);

        $resolved = [];

        foreach ($byEmployee as $employeeId => $roleId) {
            $resolved[] = ['employeeId' => $employeeId, 'roleId' => $roleId];
        }

        return $resolved;
    }
}
