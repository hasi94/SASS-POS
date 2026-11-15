<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api;

/**
 * Implemented by a module's service provider to declare the error codes that
 * module's responses may carry (T-021 Ruling 2, AC-9 to AC-13).
 *
 * The same shape as `Flexipos\Contracts\Audit\DeclaresAuditActions` and
 * `Flexipos\Contracts\Access\DeclaresPermissions`, for the same reason: a
 * catalogue built from declarations, not from scanning responses that have
 * already been sent.
 */
interface DeclaresErrorCodes
{
    /**
     * Every error code this provider's package registers, keyed by module.
     *
     * @return array<string, list<ErrorCode>>
     */
    public function errorCodes(): array;
}
