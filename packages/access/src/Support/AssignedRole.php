<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

/**
 * One role an employee holds, and the scope it was granted at (AC-29).
 *
 * **The scope travels with the key and is never dropped.** "Holds
 * `shift_lead`" and "holds `shift_lead` at branch X" are different facts
 * about what somebody may do (D-8 (b)), and a list that rendered only the
 * key would present the second as the first - which is the claim a reader
 * then acts on. `null` means every branch and is written as null here
 * rather than as the `*` marker: `*` is the canonical **hash** form
 * ({@see ScopeKey}) and reusing it in a rendered list would be a second
 * meaning for a symbol the till reproduces byte for byte.
 */
final class AssignedRole
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $branchId,
    ) {}
}
