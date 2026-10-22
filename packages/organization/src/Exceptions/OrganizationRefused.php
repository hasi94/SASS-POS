<?php

declare(strict_types=1);

namespace Flexipos\Organization\Exceptions;

use Flexipos\Organization\Support\OrganizationRefusal;
use RuntimeException;

/**
 * The one exception this package throws when it refuses to do something.
 *
 * **Not constructible without a cause**, in the shape of `AccessRefused`: the
 * constructor is private and every way in is a named constructor carrying
 * exactly one {@see OrganizationRefusal}, so there is no "refused" that means
 * nothing more. The message is operator-facing and may name ids; it never
 * carries an activation code, a hash or a secret value, and the host never
 * renders it.
 */
final class OrganizationRefused extends RuntimeException
{
    private function __construct(
        public readonly OrganizationRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function validation(string $message): self
    {
        return new self(OrganizationRefusal::Validation, $message);
    }

    public static function notFound(string $message): self
    {
        return new self(OrganizationRefusal::NotFound, $message);
    }

    public static function unknownBranch(string $message): self
    {
        return new self(OrganizationRefusal::UnknownBranch, $message);
    }

    public static function unknownTerminal(string $message): self
    {
        return new self(OrganizationRefusal::UnknownTerminal, $message);
    }

    public static function branchInactive(string $message): self
    {
        return new self(OrganizationRefusal::BranchInactive, $message);
    }

    public static function hasActiveTerminals(string $message): self
    {
        return new self(OrganizationRefusal::HasActiveTerminals, $message);
    }

    public static function lastBranch(string $message): self
    {
        return new self(OrganizationRefusal::LastBranch, $message);
    }

    public static function locationInUse(string $message): self
    {
        return new self(OrganizationRefusal::LocationInUse, $message);
    }

    public static function terminalNotPending(string $message): self
    {
        return new self(OrganizationRefusal::TerminalNotPending, $message);
    }

    public static function terminalNotActive(string $message): self
    {
        return new self(OrganizationRefusal::TerminalNotActive, $message);
    }

    public static function terminalRevoked(string $message): self
    {
        return new self(OrganizationRefusal::TerminalRevoked, $message);
    }

    public static function scopeNotSupported(string $message): self
    {
        return new self(OrganizationRefusal::ScopeNotSupported, $message);
    }

    public static function actorRequired(string $message): self
    {
        return new self(OrganizationRefusal::ActorRequired, $message);
    }
}
