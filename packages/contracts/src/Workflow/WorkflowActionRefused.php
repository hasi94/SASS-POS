<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Workflow;

use RuntimeException;

/**
 * A refusal from `decide()`, `cancel()` or an instance read (T-020 Ruling
 * 8, Ruling 9).
 *
 * Not constructible without a reason: the constructor is private and every
 * way in is a named constructor carrying one of the reasons below.
 */
final class WorkflowActionRefused extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknownTask(string $taskId): self
    {
        return new self('unknownTask', "No workflow task [{$taskId}] exists.");
    }

    public static function unknownInstance(string $instanceId): self
    {
        return new self('unknownInstance', "No workflow instance [{$instanceId}] exists.");
    }

    public static function instanceClosed(string $status): self
    {
        return new self('instanceClosed', "The instance is already [{$status}].");
    }

    public static function taskNotPending(string $status): self
    {
        return new self('taskNotPending', "The task is already [{$status}].");
    }

    public static function notAssignee(string $actorEmployeeId): self
    {
        return new self('notAssignee', "[{$actorEmployeeId}] is not this task's assignee.");
    }
}
