<?php

declare(strict_types=1);

namespace Flexipos\Access\Pins;

use Flexipos\Access\Features\VerifyPin\VerifyPin;
use Flexipos\Access\Features\VerifyPin\VerifyPinHandler;
use Flexipos\Contracts\Access\PinVerifier;

/**
 * {@see PinVerifier}, by delegating to `VerifyPinHandler` (T-030 AC-39, D-30).
 *
 * **A delegate and nothing more.** The handler owns the lockout, the hasher
 * count, the five causes and the `access_log` row, and this class adds none of
 * them: it narrows the handler's `VerifyPinResult` to the one thing the
 * published contract promises, an employee id or null. Because the handler's
 * result already carries no cause, the narrowing cannot leak one.
 *
 * It is bound with `bind()`, not `singleton()`, because the handler it wraps
 * is container-built per resolution: a singleton here would freeze the
 * handler, and the hasher the handler holds, at the first resolution.
 */
final class HandlerPinVerifier implements PinVerifier
{
    public function __construct(
        private readonly VerifyPinHandler $handler,
    ) {}

    public function verify(string $employeeCode, string $pin, ?string $ip = null): ?string
    {
        return $this->handler->handle(new VerifyPin($employeeCode, $pin, $ip))->employeeId;
    }
}
