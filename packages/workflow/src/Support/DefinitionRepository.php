<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Support;

use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Contracts\Workflow\WorkflowOnReject;
use Flexipos\Workflow\Conditions\Condition;
use Flexipos\Workflow\Conditions\ConditionOperator;
use Flexipos\Workflow\Definitions\ApproverType;
use Flexipos\Workflow\Definitions\Quorum;
use Flexipos\Workflow\Definitions\ValidatedStep;
use Throwable;
use ValueError;

/**
 * Reads `workflow_definitions`/`workflow_steps` back into the same value
 * types a fresh `DefinitionValidator::validate()` would build.
 *
 * **This decodes and enum-checks on read; it does not re-run the
 * validator.** `approver_type`, `quorum`, `operator` and `on_reject` are
 * checked against their enums, and `conditions` is checked to be valid
 * JSON - any failure throws `definitionCorrupt` (T-020 Ruling 3). The
 * validator's value rules, the `approver_ref` shape checks, and the
 * `branch_ids` list-shape check are **not** re-run here; those stay
 * write-time-only, via `DefinitionValidator`, which is fine because
 * nothing else writes these tables (T-008/T-019 precedent).
 */
final class DefinitionRepository
{
    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * Active, non-deleted definitions for one entity type, ordered by
     * priority ascending and then by key (Ruling 5).
     *
     * @return list<object>
     */
    public function candidatesFor(string $entityType): array
    {
        return $this->tenant->get()->table('workflow_definitions')
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('priority')
            ->orderBy('key')
            ->get()
            ->all();
    }

    /**
     * @return list<Condition>
     *
     * @throws WorkflowEvaluationFailed `definitionCorrupt`
     */
    public function conditionsOf(object $definitionRow): array
    {
        return $this->decodeConditions((string) $definitionRow->key, (string) $definitionRow->conditions);
    }

    /**
     * @return list<string>|null
     */
    public function branchIdsOf(object $definitionRow): ?array
    {
        if ($definitionRow->branch_ids === null) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $definitionRow->branch_ids, true);

        return is_array($decoded) ? array_map('strval', $decoded) : null;
    }

    /**
     * @return list<ValidatedStep>
     *
     * @throws WorkflowEvaluationFailed `definitionCorrupt`
     */
    public function stepsOf(string $definitionId, string $definitionKey): array
    {
        $rows = $this->tenant->get()->table('workflow_steps')
            ->where('definition_id', $definitionId)
            ->orderBy('sequence')
            ->get();

        $steps = [];

        foreach ($rows as $row) {
            try {
                $approverType = ApproverType::from((string) $row->approver_type);
                $quorum = Quorum::from((string) $row->quorum);
            } catch (ValueError $error) {
                throw WorkflowEvaluationFailed::definitionCorrupt($definitionKey, $error->getMessage());
            }

            /** @var array{roles?: list<string>, employees?: list<string>} $approverRef */
            $approverRef = (array) json_decode((string) $row->approver_ref, true);

            // AC-22: the escalation/SLA columns are read back by nothing -
            // this engine stores them and nothing more, so they are
            // deliberately not reconstructed here even though the row
            // carries them.
            $steps[] = new ValidatedStep(
                sequence: (int) $row->sequence,
                name: (string) $row->name,
                approverType: $approverType,
                approverRef: $approverRef,
                quorum: $quorum,
                canEdit: (bool) $row->can_edit,
            );
        }

        return $steps;
    }

    public function onRejectOf(object $definitionRow): WorkflowOnReject
    {
        try {
            return WorkflowOnReject::from((string) $definitionRow->on_reject);
        } catch (ValueError $error) {
            throw WorkflowEvaluationFailed::definitionCorrupt((string) $definitionRow->key, $error->getMessage());
        }
    }

    /**
     * @return list<Condition>
     */
    private function decodeConditions(string $definitionKey, string $json): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw WorkflowEvaluationFailed::definitionCorrupt($definitionKey, $error->getMessage());
        }

        if (! is_array($decoded)) {
            throw WorkflowEvaluationFailed::definitionCorrupt($definitionKey, 'conditions is not a list');
        }

        $conditions = [];

        foreach ($decoded as $raw) {
            $raw = (array) $raw;

            try {
                $operator = ConditionOperator::from((string) ($raw['operator'] ?? ''));
            } catch (ValueError $error) {
                throw WorkflowEvaluationFailed::definitionCorrupt($definitionKey, $error->getMessage());
            }

            $conditions[] = new Condition((string) ($raw['field'] ?? ''), $operator, $raw['value'] ?? '');
        }

        return $conditions;
    }
}
