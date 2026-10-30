<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Engine;

use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Workflow\Conditions\ConditionEvaluator;
use Flexipos\Workflow\Support\DefinitionRepository;

/**
 * Choose the one active definition that matches, by priority group (T-020
 * Ruling 5, AC-14 to AC-16).
 */
final class DefinitionSelector
{
    public function __construct(private readonly DefinitionRepository $definitions) {}

    /**
     * The winning definition row, or null when nothing matches.
     *
     * @throws WorkflowEvaluationFailed `conditionNotEvaluable`, `ambiguousDefinitions`
     */
    public function select(WorkflowEntityType $entityType, WorkflowContext $context): ?object
    {
        $candidates = $this->definitions->candidatesFor($entityType->value);

        /** @var array<int, list<object>> $groups */
        $groups = [];

        foreach ($candidates as $candidate) {
            $groups[(int) $candidate->priority][] = $candidate;
        }

        foreach ($groups as $priority => $group) {
            $matches = [];

            foreach ($group as $candidate) {
                if ($this->matches($candidate, $context)) {
                    $matches[] = $candidate;
                }
            }

            if (count($matches) === 1) {
                return $matches[0];
            }

            if (count($matches) > 1) {
                throw WorkflowEvaluationFailed::ambiguousDefinitions(
                    $entityType->value,
                    $priority,
                    array_map(static fn (object $m): string => (string) $m->key, $matches),
                );
            }
        }

        return null;
    }

    private function matches(object $candidate, WorkflowContext $context): bool
    {
        $branchIds = $this->definitions->branchIdsOf($candidate);

        if ($branchIds !== null) {
            if ($context->branchId === null) {
                throw WorkflowEvaluationFailed::conditionNotEvaluable((string) $candidate->key, 'branch_id', 'missing');
            }

            if (! in_array($context->branchId, $branchIds, true)) {
                return false;
            }
        }

        $conditions = $this->definitions->conditionsOf($candidate);

        return ConditionEvaluator::matches($conditions, $context->values(), (string) $candidate->key);
    }
}
