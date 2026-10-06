<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

use RuntimeException;

/**
 * Base class for every refusal the tenancy plumbing can raise.
 *
 * Two invariants hold for every subclass, and they are the reason the messages
 * are built by named constructors instead of by callers:
 *
 * 1. the offending tenant identifier is always in the message, because a
 *    refusal nobody can attribute to a tenant is not actionable (the same
 *    convention App\Support\ModuleHost\UnknownModuleException follows);
 * 2. a tenant database credential is never in the message, in a property, or in
 *    any argument of any frame of the trace.
 */
abstract class TenancyException extends RuntimeException
{
    /**
     * The stand-in every redacted value is rendered as.
     */
    public const REDACTED = '[redacted]';
}
