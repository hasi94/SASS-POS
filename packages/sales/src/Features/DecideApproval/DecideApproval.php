<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\DecideApproval;

use SensitiveParameter;

/**
 * Approve or reject a pending approval request, as the employee whose code and
 * PIN are given (T-030 AC-23, D-12, D-30).
 *
 * The decider is whoever the PIN proves, not an actor handed in by the caller:
 * the PIN is the authentication, which is why this carries no `actorId`.
 *
 * **The PIN is a secret.** The array is marked sensitive so that a stack trace
 * prints `SensitiveParameterValue` for it, and `__debugInfo()` leaves it out of
 * a `var_dump`. It is verified and then forgotten: it is written to no table, no
 * audit row, no log line and no result (R-24).
 */
final class DecideApproval
{
    /**
     * @param  array<string, mixed>  $fields  `approval_id`, `employee_code`, `pin`, `decision` (`approved` or `rejected`), and optionally `reason`
     */
    public function __construct(
        #[SensitiveParameter]
        public readonly array $fields,
        public readonly ?string $ip = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['fields' => array_diff_key($this->fields, ['pin' => true]), 'ip' => $this->ip];
    }
}
