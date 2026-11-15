<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\VerifyPin;

/**
 * One in-process attempt to verify a cashier's PIN (AC-49, D-16).
 *
 * **There is no route to this slice and that is the decision, not an
 * omission** (D-16, AC-49). A till has no terminal identity yet (FP-209),
 * and a public endpoint over at most 10^8 values with no per-IP throttling
 * (FP-199 covers login only) is a guessing surface this task will not open.
 * The caller is the future till task, in process.
 *
 * `$code` is the employee's code, not their id: a till's operator types a
 * short number, and the PIN is not unique across employees, so identity
 * comes first and the credential second (D-16).
 *
 * `$ip` is the printed address of whoever is attempting, or null when there
 * is no request behind the call - an in-process verification run by a job
 * has no socket, and `access_log.ip` is nullable for exactly that reason.
 */
final class VerifyPin
{
    public function __construct(
        public readonly string $code,
        public readonly string $pin,
        public readonly ?string $ip = null,
    ) {}
}
