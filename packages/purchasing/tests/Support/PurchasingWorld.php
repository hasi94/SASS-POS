<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Support;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Contracts\Workflow\WorkflowApprovals;
use Flexipos\Contracts\Workflow\WorkflowVerdict;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use PHPUnit\Framework\Assert;

/**
 * The desk a buying test stands at: a branch with a delivery location, a buyer
 * holding the branch-scoped document keys, a unit, a stocked product and an active
 * supplier (T-034 chunks D to F).
 *
 * A class and not file-level functions, because Pest declares a test file's
 * functions globally and two files naming one function is a fatal redeclaration in
 * a single run. Everything is written through {@see PurchasingSeed}: direct
 * inserts, never another slice's handler.
 */
final class PurchasingWorld
{
    /**
     * An employee who approves for a role, and the role, for a workflow
     * definition written by {@see PurchasingSeed::workflowDefinition()}.
     *
     * @return array{employee: string, role: string}
     */
    public static function approver(string $code = 'apr'): array
    {
        $role = AccessSeed::role('role_'.$code);
        $employee = AccessSeed::employee($code);
        AccessSeed::assign($employee, $role);

        return ['employee' => $employee, 'role' => $role];
    }

    /**
     * Decide the instance's one pending task as its assignee, through the real
     * engine's published approvals contract.
     */
    public static function decide(string $instanceId, WorkflowVerdict $verdict): void
    {
        $task = PurchasingSeed::connection()->table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->where('status', 'pending')
            ->first();

        Assert::assertNotNull($task, "Instance [{$instanceId}] has no pending task.");

        app(WorkflowApprovals::class)->decide((string) $task->id, strtoupper((string) $task->assignee_employee_id), $verdict);
    }

    /**
     * @return array{branch: string, location: string, buyer: string, unit: string, product: string, supplier: string}
     */
    public static function desk(string $code = 'd1'): array
    {
        $branch = PurchasingSeed::branch();
        $unit = PricingSeed::unit('EA'.strtoupper($code));

        return [
            'branch' => $branch,
            'location' => PurchasingSeed::location($branch, 'L'.strtoupper($code)),
            'buyer' => AccessSeed::actorHolding('buyer'.$code, [
                PurchasingPermissions::REQUESTS_MANAGE,
                PurchasingPermissions::REQUESTS_VIEW,
                PurchasingPermissions::ORDERS_MANAGE,
                PurchasingPermissions::ORDERS_VIEW,
            ], $branch),
            'unit' => $unit,
            'product' => PurchasingSeed::product('P-'.strtoupper($code), $unit),
            'supplier' => PurchasingSeed::supplier('S'.strtoupper($code)),
        ];
    }
}
