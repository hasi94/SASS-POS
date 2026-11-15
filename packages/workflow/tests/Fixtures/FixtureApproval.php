<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Tests\Fixtures;

/**
 * The one proof-of-pipeline definition this task ships (T-020 AC-27,
 * AC-28, D-11).
 *
 * **This is explicitly a fixture, not a business workflow.** It proves the
 * generic engine end to end on `entity_type='custom'`; no discount,
 * expense, purchase-order, payroll or leave approval rule is designed or
 * shipped anywhere in this task.
 */
final class FixtureApproval
{
    public const KEY = 'fixture-approval';

    public static function json(string $roleOneId, string $roleTwoId, string $threshold = '1000'): string
    {
        return json_encode([
            'key' => self::KEY,
            'name' => 'Proof-of-pipeline fixture - not a business workflow',
            'entity_type' => 'custom',
            'priority' => 10,
            'on_reject' => 'stop',
            'is_active' => true,
            'branch_ids' => null,
            'escalation_hours' => null,
            'conditions' => [
                ['field' => 'amount', 'operator' => '>', 'value' => $threshold],
            ],
            'steps' => [
                [
                    'sequence' => 1,
                    'name' => 'Fixture step one',
                    'approver_type' => 'role',
                    'approver_ref' => ['roles' => [$roleOneId]],
                    'quorum' => 'any',
                ],
                [
                    'sequence' => 2,
                    'name' => 'Fixture step two',
                    'approver_type' => 'role',
                    'approver_ref' => ['roles' => [$roleTwoId]],
                    'quorum' => 'all',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
