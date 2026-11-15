<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Exceptions;

use RuntimeException;

/**
 * A manifest validation failure, with one named constructor per reason
 * (T-019 plan Ruling 2).
 *
 * `$reason` is the name of the constructor that built this instance -
 * every test here asserts that, never just "it throws".
 */
final class ManifestRefused extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function malformedJson(): self
    {
        return new self('malformedJson', 'The manifest is not valid JSON, or its top level is not an object.');
    }

    public static function missingField(string $path): self
    {
        return new self('missingField', "The manifest is missing the required field [{$path}].");
    }

    public static function invalidField(string $path, string $why): self
    {
        return new self('invalidField', "The manifest field [{$path}] is invalid: {$why}");
    }

    public static function unknownField(string $key): self
    {
        return new self('unknownField', "The manifest declares an unknown field [{$key}].");
    }

    public static function coreVersionMismatch(string $constraint, string $core): self
    {
        return new self(
            'coreVersionMismatch',
            "The manifest's core_constraint [{$constraint}] does not match the running core version [{$core}]."
        );
    }

    public static function unknownMode(string $mode): self
    {
        return new self('unknownMode', "The manifest declares an unknown hook mode [{$mode}].");
    }

    public static function unknownPoint(string $point): self
    {
        return new self('unknownPoint', "The manifest declares an unknown hook point [{$point}].");
    }

    public static function overrideNotPermitted(string $point): self
    {
        return new self(
            'overrideNotPermitted',
            "Hook point [{$point}] is not marked overridable, so mode=override is not permitted against it."
        );
    }

    public static function modeNotAccepted(string $point, string $mode): self
    {
        return new self('modeNotAccepted', "Hook point [{$point}] does not accept mode [{$mode}].");
    }

    public static function duplicateRegistration(string $point, string $mode): self
    {
        return new self(
            'duplicateRegistration',
            "Hook point [{$point}] already has a mode=[{$mode}] registration in this manifest."
        );
    }

    public static function handlerNotFound(string $class): self
    {
        return new self('handlerNotFound', "Handler class [{$class}] cannot be resolved.");
    }

    public static function handlerWrongContract(string $class, string $mode): self
    {
        return new self(
            'handlerWrongContract',
            "Handler class [{$class}] does not implement the contract mode [{$mode}] requires."
        );
    }
}
