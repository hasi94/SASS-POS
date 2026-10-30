<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Workflow;

use RuntimeException;
use Throwable;

/**
 * The fail-closed signal `WorkflowGate::evaluate()` throws (T-020 D-3,
 * Ruling 3).
 *
 * **A caller must treat any exception from `evaluate()` as "do not
 * proceed".** It must never catch this exception and carry on - that is
 * the explicit opposite of T-019's `before`-mode fail-open, and the whole
 * reason this engine does not reuse that mechanism (D-2).
 *
 * Not constructible without a cause: the constructor is private and every
 * way in is a named constructor carrying one of the causes below.
 */
final class WorkflowEvaluationFailed extends RuntimeException
{
    private function __construct(
        public readonly string $cause,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * AC-6: a missing or null field; an ordering operator on a value that
     * is not numeric; a type mismatch.
     */
    public static function conditionNotEvaluable(string $definitionKey, string $field, string $why): self
    {
        return new self(
            'conditionNotEvaluable',
            "Definition [{$definitionKey}] could not evaluate its condition on field [{$field}]: {$why}.",
        );
    }

    /**
     * AC-15's tie case: two or more active definitions match at the same
     * priority.
     *
     * @param  list<string>  $keys
     */
    public static function ambiguousDefinitions(string $entityType, int $priority, array $keys): self
    {
        $list = implode(', ', $keys);

        return new self(
            'ambiguousDefinitions',
            "More than one active definition for [{$entityType}] matches at priority [{$priority}]: {$list}.",
        );
    }

    /**
     * A stored definition fails re-validation on read.
     */
    public static function definitionCorrupt(string $definitionKey, string $why): self
    {
        return new self(
            'definitionCorrupt',
            "Definition [{$definitionKey}] is corrupt: {$why}.",
        );
    }

    /**
     * Ruling 6c: a step names one of the three `approver_type` values this
     * codebase cannot resolve.
     */
    public static function approverTypeUnavailable(string $type): self
    {
        return new self(
            'approverTypeUnavailable',
            "The approver type [{$type}] has no data source in this codebase today.",
        );
    }

    /**
     * Ruling 9: a second matching evaluate while an instance for the same
     * entity is still pending.
     */
    public static function alreadyPending(string $instanceId): self
    {
        return new self(
            'alreadyPending',
            "An instance [{$instanceId}] for this entity is already pending.",
        );
    }

    /**
     * Anything else: a lost tenant connection, a missing table, and so on.
     */
    public static function engineError(Throwable $previous): self
    {
        if ($previous instanceof self) {
            return $previous;
        }

        return new self('engineError', 'The workflow engine failed: '.$previous->getMessage(), $previous);
    }
}
