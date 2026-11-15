<?php

declare(strict_types=1);

namespace Flexipos\Workflow;

use Flexipos\Contracts\Workflow\WorkflowApprovals;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Workflow\Engine\WorkflowEngine;
use Flexipos\Workflow\Features\CancelWorkflowInstance\CancelWorkflowInstanceCommand;
use Flexipos\Workflow\Features\DecideWorkflowTask\DecideWorkflowTaskCommand;
use Flexipos\Workflow\Features\ListPendingWorkflowTasks\ListPendingWorkflowTasksCommand;
use Flexipos\Workflow\Features\ListWorkflowDefinitions\ListWorkflowDefinitionsCommand;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinitionCommand;
use Flexipos\Workflow\Features\ShowWorkflowHistory\ShowWorkflowHistoryCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The workflow engine's entry point into the host application (T-020
 * Ruling 1).
 *
 * Both published contracts resolve to the same engine instance's methods,
 * through one binding each, because `WorkflowEngine` implements both
 * interfaces. **No schedule entry**: escalation and SLA handling are
 * schema-only in this task (D-9).
 */
final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkflowEngine::class);
        $this->app->singleton(WorkflowGate::class, WorkflowEngine::class);
        $this->app->singleton(WorkflowApprovals::class, WorkflowEngine::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RegisterWorkflowDefinitionCommand::class,
                ListWorkflowDefinitionsCommand::class,
                ListPendingWorkflowTasksCommand::class,
                DecideWorkflowTaskCommand::class,
                CancelWorkflowInstanceCommand::class,
                ShowWorkflowHistoryCommand::class,
            ]);
        }
    }
}
