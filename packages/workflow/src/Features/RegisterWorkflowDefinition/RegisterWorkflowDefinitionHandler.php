<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\RegisterWorkflowDefinition;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Workflow\Definitions\DefinitionValidator;
use Flexipos\Workflow\Exceptions\WorkflowDefinitionRefused;
use Flexipos\Workflow\Support\TenantConnection;
use Illuminate\Support\Str;

/**
 * `workflow:define {file}` (T-020 Ruling 10, AC-2, AC-3, AC-5, AC-7, AC-8,
 * AC-22, AC-23).
 *
 * **Validate fully before any write.** `DefinitionValidator::validate()`
 * is the only way any of this handler's writes happen, and
 * `keyAlreadyRegistered` is checked before the insert too - a refusal at
 * any stage writes nothing.
 */
final class RegisterWorkflowDefinitionHandler
{
    public function __construct(
        private readonly DefinitionValidator $validator,
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(RegisterWorkflowDefinition $command): RegisterWorkflowDefinitionResult
    {
        $validated = $this->validator->validate($command->json);

        // AC-2: counts soft-deleted and inactive rows too.
        $exists = $this->tenant->get()->table('workflow_definitions')
            ->where('key', $validated->key)
            ->exists();

        if ($exists) {
            throw WorkflowDefinitionRefused::keyAlreadyRegistered($validated->key);
        }

        return $this->tenant->get()->transaction(function () use ($validated, $command): RegisterWorkflowDefinitionResult {
            $now = $this->clock->timestamp();
            $id = (string) Str::ulid();

            $this->tenant->get()->table('workflow_definitions')->insert([
                'id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
                'created_by' => $command->registeredBy,
                'updated_by' => $command->registeredBy,
                'key' => $validated->key,
                'name' => $validated->name,
                'entity_type' => $validated->entityType,
                'conditions' => json_encode(array_map(
                    static fn ($c) => ['field' => $c->field, 'operator' => $c->operator->value, 'value' => $c->value],
                    $validated->conditions
                )),
                'branch_ids' => $validated->branchIds === null ? null : json_encode($validated->branchIds),
                'priority' => $validated->priority,
                'on_reject' => $validated->onReject->value,
                'escalation_hours' => $validated->escalationHours,
                'is_active' => $validated->isActive,
                'plugin_name' => null,
                'version' => 1,
            ]);

            foreach ($validated->steps as $step) {
                $this->tenant->get()->table('workflow_steps')->insert([
                    'id' => (string) Str::ulid(),
                    'definition_id' => $id,
                    'sequence' => $step->sequence,
                    'name' => $step->name,
                    'approver_type' => $step->approverType->value,
                    'approver_ref' => json_encode($step->approverRef),
                    'quorum' => $step->quorum->value,
                    'can_edit' => $step->canEdit,
                    'can_skip_if' => $step->canSkipIf === null ? null : json_encode($step->canSkipIf),
                    'sla_hours' => $step->slaHours,
                    'escalate_to' => $step->escalateTo === null ? null : json_encode($step->escalateTo),
                ]);
            }

            return new RegisterWorkflowDefinitionResult($id, $validated->key, count($validated->steps));
        });
    }
}
