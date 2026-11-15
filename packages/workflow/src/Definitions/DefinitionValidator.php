<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Definitions;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowOnReject;
use Flexipos\Workflow\Conditions\Condition;
use Flexipos\Workflow\Conditions\ConditionOperator;
use Flexipos\Workflow\Exceptions\WorkflowDefinitionRefused;
use JsonException;

/**
 * `workflow:define {file}`'s validation, run before any write (T-020
 * Ruling 10).
 *
 * Every refusal is a named `WorkflowDefinitionRefused` constructor - the
 * test suite asserts the name, never just "throws".
 */
final class DefinitionValidator
{
    private const TOP_REQUIRED = ['key', 'name', 'entity_type', 'priority', 'on_reject', 'conditions', 'steps'];

    private const TOP_OPTIONAL = ['is_active', 'branch_ids', 'escalation_hours'];

    private const STEP_REQUIRED = ['sequence', 'name', 'approver_type', 'approver_ref', 'quorum'];

    private const STEP_OPTIONAL = ['can_edit', 'can_skip_if', 'sla_hours', 'escalate_to'];

    public function __construct(private readonly EmployeeDirectory $employees) {}

    public function validate(string $json): ValidatedDefinition
    {
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw WorkflowDefinitionRefused::malformedJson($exception->getMessage());
        }

        if (! is_array($data)) {
            throw WorkflowDefinitionRefused::malformedJson('the document is not a JSON object');
        }

        $this->checkRequired($data, self::TOP_REQUIRED, '');
        $this->checkUnknown($data, array_merge(self::TOP_REQUIRED, self::TOP_OPTIONAL), '');

        $key = $this->validateKey($data['key']);
        $name = $this->validateName($data['name']);
        $entityType = $this->validateEntityType($data['entity_type']);
        $priority = $this->validateIntRange($data['priority'], 0, 32767, 'priority');
        $onReject = WorkflowOnReject::tryFrom((string) $data['on_reject'])
            ?? throw WorkflowDefinitionRefused::unknownValue('on_reject', (string) $data['on_reject']);
        $isActive = $this->validateBool($data['is_active'] ?? true, 'is_active');
        $branchIds = $this->validateBranchIds($data['branch_ids'] ?? null);
        $escalationHours = $this->validateNullableIntRange($data['escalation_hours'] ?? null, 1, 32767, 'escalation_hours');
        $conditions = $this->validateConditions($data['conditions'], 'conditions');

        $rawSteps = $data['steps'];

        if (! is_array($rawSteps) || $rawSteps === []) {
            throw WorkflowDefinitionRefused::noSteps();
        }

        $steps = [];
        $sequences = [];

        foreach (array_values($rawSteps) as $index => $rawStep) {
            $path = "steps[{$index}]";

            if (! is_array($rawStep)) {
                throw WorkflowDefinitionRefused::invalidField($path, 'must be an object');
            }

            $this->checkRequired($rawStep, self::STEP_REQUIRED, $path.'.');
            $this->checkUnknown($rawStep, array_merge(self::STEP_REQUIRED, self::STEP_OPTIONAL), $path.'.');

            $sequence = $this->validateIntRange($rawStep['sequence'], 1, 32767, $path.'.sequence');
            $sequences[] = $sequence;

            $stepName = $this->validateName($rawStep['name'], $path.'.name');
            $approverType = ApproverType::tryFrom((string) $rawStep['approver_type'])
                ?? throw WorkflowDefinitionRefused::unknownValue($path.'.approver_type', (string) $rawStep['approver_type']);
            $quorum = Quorum::tryFrom((string) $rawStep['quorum'])
                ?? throw WorkflowDefinitionRefused::unknownValue($path.'.quorum', (string) $rawStep['quorum']);

            if (! $approverType->isResolvable()) {
                throw WorkflowDefinitionRefused::approverTypeUnavailable($approverType->value);
            }

            $approverRef = $this->validateApproverRef($rawStep['approver_ref'], $approverType, $quorum, $path.'.approver_ref');

            $canEdit = $this->validateBool($rawStep['can_edit'] ?? false, $path.'.can_edit');
            $canSkipIf = ($rawStep['can_skip_if'] ?? null) === null
                ? null
                : $this->rawConditionsToArray($this->validateConditions($rawStep['can_skip_if'], $path.'.can_skip_if'));
            $slaHours = $this->validateNullableIntRange($rawStep['sla_hours'] ?? null, 1, 32767, $path.'.sla_hours');
            $escalateTo = $rawStep['escalate_to'] ?? null;

            if ($escalateTo !== null && ! is_array($escalateTo)) {
                throw WorkflowDefinitionRefused::invalidField($path.'.escalate_to', 'must be null, or a JSON object or list');
            }

            $steps[] = new ValidatedStep(
                sequence: $sequence,
                name: $stepName,
                approverType: $approverType,
                approverRef: $approverRef,
                quorum: $quorum,
                canEdit: $canEdit,
                canSkipIf: $canSkipIf,
                slaHours: $slaHours,
                escalateTo: $escalateTo,
            );
        }

        $sorted = $sequences;
        sort($sorted);

        if (count(array_unique($sorted)) !== count($sorted)) {
            $counts = array_count_values($sorted);

            foreach ($counts as $value => $count) {
                if ($count > 1) {
                    throw WorkflowDefinitionRefused::duplicateSequence((int) $value);
                }
            }
        }

        if ($sorted !== range(1, count($sorted))) {
            throw WorkflowDefinitionRefused::sequenceGap();
        }

        usort($steps, static fn (ValidatedStep $a, ValidatedStep $b): int => $a->sequence <=> $b->sequence);

        return new ValidatedDefinition(
            key: $key,
            name: $name,
            entityType: $entityType->value,
            priority: $priority,
            onReject: $onReject,
            conditions: $conditions,
            steps: $steps,
            isActive: $isActive,
            branchIds: $branchIds,
            escalationHours: $escalationHours,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $required
     */
    private function checkRequired(array $data, array $required, string $prefix): void
    {
        foreach ($required as $field) {
            if (! array_key_exists($field, $data)) {
                throw WorkflowDefinitionRefused::missingField($prefix.$field);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private function checkUnknown(array $data, array $allowed, string $prefix): void
    {
        foreach (array_keys($data) as $field) {
            if (! in_array($field, $allowed, true)) {
                throw WorkflowDefinitionRefused::unknownField($prefix.$field);
            }
        }
    }

    private function validateKey(mixed $value): string
    {
        if (! is_string($value) || mb_strlen($value) > 60 || preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $value) !== 1) {
            throw WorkflowDefinitionRefused::invalidField('key', 'must match ^[a-z][a-z0-9]*(-[a-z0-9]+)*$, at most 60 characters');
        }

        return $value;
    }

    private function validateName(mixed $value, string $path = 'name'): string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > 100) {
            throw WorkflowDefinitionRefused::invalidField($path, 'must be non-empty, at most 100 characters');
        }

        return $value;
    }

    private function validateEntityType(mixed $value): WorkflowEntityType
    {
        if (! is_string($value)) {
            throw WorkflowDefinitionRefused::unknownEntityType((string) json_encode($value));
        }

        return WorkflowEntityType::tryFrom($value) ?? throw WorkflowDefinitionRefused::unknownEntityType($value);
    }

    private function validateIntRange(mixed $value, int $min, int $max, string $path): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw WorkflowDefinitionRefused::invalidField($path, "must be an int from {$min} to {$max}");
        }

        return $value;
    }

    private function validateNullableIntRange(mixed $value, int $min, int $max, string $path): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->validateIntRange($value, $min, $max, $path);
    }

    private function validateBool(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            throw WorkflowDefinitionRefused::invalidField($path, 'must be a real bool');
        }

        return $value;
    }

    /**
     * @return list<string>|null
     */
    private function validateBranchIds(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || $value === []) {
            throw WorkflowDefinitionRefused::invalidField('branch_ids', 'must be null or a non-empty list of ULIDs');
        }

        $ids = [];

        foreach (array_values($value) as $id) {
            if (! is_string($id) || mb_strlen($id) !== 26 || preg_match('/^[0-9A-Za-z]{26}$/', $id) !== 1) {
                throw WorkflowDefinitionRefused::invalidField('branch_ids', 'every id must be a 26-character ULID');
            }

            $ids[] = strtoupper($id);
        }

        return $ids;
    }

    /**
     * Ruling 4's rules, for `conditions` and for `can_skip_if` alike.
     *
     * @return list<Condition>
     */
    private function validateConditions(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            throw WorkflowDefinitionRefused::invalidField($path, 'must be a list');
        }

        $conditions = [];

        foreach (array_values($value) as $index => $raw) {
            $itemPath = "{$path}[{$index}]";

            if (! is_array($raw)) {
                throw WorkflowDefinitionRefused::invalidField($itemPath, 'must be an object');
            }

            $this->checkRequired($raw, ['field', 'operator', 'value'], $itemPath.'.');
            $this->checkUnknown($raw, ['field', 'operator', 'value'], $itemPath.'.');

            $field = $raw['field'];

            if (! is_string($field) || (preg_match('/^[a-z][a-z0-9_]{0,59}$/', $field) !== 1 && ! in_array($field, ['amount', 'branch_id'], true))) {
                throw WorkflowDefinitionRefused::invalidField($itemPath.'.field', 'is not a legal field key');
            }

            $operator = ConditionOperator::tryFrom((string) $raw['operator'])
                ?? throw WorkflowDefinitionRefused::unknownOperator((string) $raw['operator']);

            $rawValue = $raw['value'];

            if ($operator->isListOperator()) {
                if (! is_array($rawValue) || $rawValue === [] || ! array_is_list($rawValue)) {
                    throw WorkflowDefinitionRefused::listValueRequired($itemPath.'.value');
                }

                foreach ($rawValue as $element) {
                    if (! is_string($element) && ! is_int($element) && ! is_bool($element)) {
                        throw WorkflowDefinitionRefused::listValueRequired($itemPath.'.value');
                    }
                }

                $conditions[] = new Condition($field, $operator, $rawValue);

                continue;
            }

            if (is_float($rawValue) || $rawValue === null) {
                throw WorkflowDefinitionRefused::invalidField($itemPath.'.value', 'a float or null is refused');
            }

            if (! is_string($rawValue) && ! is_int($rawValue) && ! is_bool($rawValue)) {
                throw WorkflowDefinitionRefused::scalarValueRequired($itemPath.'.value');
            }

            if ($operator->isOrdering() && ! self::looksNumeric($rawValue)) {
                throw WorkflowDefinitionRefused::numericValueRequired($itemPath.'.value');
            }

            $conditions[] = new Condition($field, $operator, $rawValue);
        }

        return $conditions;
    }

    /**
     * @param  list<Condition>  $conditions
     * @return list<array{field: string, operator: string, value: mixed}>
     */
    private function rawConditionsToArray(array $conditions): array
    {
        return array_map(
            static fn (Condition $c): array => ['field' => $c->field, 'operator' => $c->operator->value, 'value' => $c->value],
            $conditions
        );
    }

    private static function looksNumeric(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1;
    }

    /**
     * Ruling 6b's `approver_ref` rules, Ruling 6b's quorum/type agreement,
     * and `unknownEmployee` through the directory.
     *
     * @return array{roles?: list<string>, employees?: list<string>}
     */
    private function validateApproverRef(mixed $value, ApproverType $type, Quorum $quorum, string $path): array
    {
        if (! is_array($value)) {
            throw WorkflowDefinitionRefused::invalidField($path, 'must be an object');
        }

        $this->checkUnknown($value, ['roles', 'employees'], $path.'.');

        $roles = $this->validateIdList($value['roles'] ?? null, $path.'.roles');
        $employees = $this->validateIdList($value['employees'] ?? null, $path.'.employees');

        // The shape rule for this approver_type comes before the
        // per-employee liveness check: "role with an employees ref" is
        // `invalidField`, not a detour through whether that employee
        // happens to exist.
        match ($type) {
            ApproverType::Role => ($roles === [] || $employees !== [])
                ? throw WorkflowDefinitionRefused::invalidField($path, 'role requires roles only, non-empty')
                : null,
            ApproverType::Employee => ($employees === [] || $roles !== [])
                ? throw WorkflowDefinitionRefused::invalidField($path, 'employee requires employees only, non-empty')
                : null,
            ApproverType::AnyOf, ApproverType::AllOf => ($roles === [] && $employees === [])
                ? throw WorkflowDefinitionRefused::invalidField($path, 'any_of/all_of requires at least one of roles or employees, non-empty')
                : null,
            default => null,
        };

        if ($type === ApproverType::AnyOf && $quorum !== Quorum::Any) {
            throw WorkflowDefinitionRefused::quorumContradictsApproverType();
        }

        if ($type === ApproverType::AllOf && $quorum !== Quorum::All) {
            throw WorkflowDefinitionRefused::quorumContradictsApproverType();
        }

        foreach ($employees as $employeeId) {
            if (! $this->employees->isLive($employeeId)) {
                throw WorkflowDefinitionRefused::unknownEmployee($employeeId);
            }
        }

        $ref = [];

        if ($roles !== []) {
            $ref['roles'] = $roles;
        }

        if ($employees !== []) {
            $ref['employees'] = $employees;
        }

        return $ref;
    }

    /**
     * @return list<string>
     */
    private function validateIdList(mixed $value, string $path): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw WorkflowDefinitionRefused::invalidField($path, 'must be a list of ULIDs');
        }

        $ids = [];

        foreach (array_values($value) as $id) {
            if (! is_string($id) || mb_strlen($id) !== 26 || preg_match('/^[0-9A-Za-z]{26}$/', $id) !== 1) {
                throw WorkflowDefinitionRefused::invalidField($path, 'every id must be a 26-character ULID');
            }

            $ids[] = strtoupper($id);
        }

        return $ids;
    }
}
