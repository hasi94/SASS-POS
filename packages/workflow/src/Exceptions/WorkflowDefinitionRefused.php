<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Exceptions;

use RuntimeException;

/**
 * Every refusal `workflow:define` can give (T-020 Ruling 10).
 *
 * Not constructible without a reason: the constructor is private and every
 * way in is a named constructor.
 */
final class WorkflowDefinitionRefused extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function malformedJson(string $why): self
    {
        return new self('malformedJson', "The definition file is not valid JSON: {$why}.");
    }

    public static function missingField(string $path): self
    {
        return new self('missingField', "Missing required field [{$path}].");
    }

    public static function unknownField(string $path): self
    {
        return new self('unknownField', "Unknown field [{$path}].");
    }

    public static function invalidField(string $path, string $why): self
    {
        return new self('invalidField', "Field [{$path}] is invalid: {$why}.");
    }

    public static function unknownEntityType(string $value): self
    {
        return new self('unknownEntityType', "Unknown entity_type [{$value}].");
    }

    public static function unknownValue(string $path, string $value): self
    {
        return new self('unknownValue', "Unknown value [{$value}] for [{$path}].");
    }

    public static function unknownOperator(string $value): self
    {
        return new self('unknownOperator', "Unknown operator [{$value}].");
    }

    public static function listValueRequired(string $path): self
    {
        return new self('listValueRequired', "[{$path}] requires a non-empty list of scalars.");
    }

    public static function scalarValueRequired(string $path): self
    {
        return new self('scalarValueRequired', "[{$path}] requires a scalar value.");
    }

    public static function numericValueRequired(string $path): self
    {
        return new self('numericValueRequired', "[{$path}] requires a numeric value.");
    }

    public static function noSteps(): self
    {
        return new self('noSteps', 'A definition must have at least one step.');
    }

    public static function duplicateSequence(int $sequence): self
    {
        return new self('duplicateSequence', "Duplicate step sequence [{$sequence}].");
    }

    public static function sequenceGap(): self
    {
        return new self('sequenceGap', 'Step sequences must run 1..n with no gap.');
    }

    public static function quorumContradictsApproverType(): self
    {
        return new self('quorumContradictsApproverType', 'quorum must agree with any_of/all_of.');
    }

    public static function approverTypeUnavailable(string $type): self
    {
        return new self('approverTypeUnavailable', "The approver type [{$type}] has no data source in this codebase today.");
    }

    public static function unknownEmployee(string $employeeId): self
    {
        return new self('unknownEmployee', "Unknown or not-live employee [{$employeeId}].");
    }

    public static function keyAlreadyRegistered(string $key): self
    {
        return new self('keyAlreadyRegistered', "A definition with key [{$key}] is already registered.");
    }
}
