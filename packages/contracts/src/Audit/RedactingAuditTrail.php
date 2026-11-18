<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Audit;

/**
 * The audit writer, with masking done by the writer (T-038 plan Ruling 8,
 * D-42).
 *
 * A separate interface rather than a parameter on {@see AuditTrail::record()},
 * so no existing implementer breaks. Behaviour, in order: the catalogue check,
 * `changed_fields` derived from the **real** `before` and `after`, masking of
 * the two sides, then the insert.
 *
 * Masking: for each key in `$redact` present on a side, a `null` value stays
 * `null` and any other value becomes the literal `"[redacted]"` as a whole; a
 * key absent from a side stays absent; a side that is `null` stays `null`.
 */
interface RedactingAuditTrail
{
    /** The fixed marker that replaces a redacted non-null value. */
    public const MARKER = '[redacted]';

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  list<string>  $redact
     */
    public function recordRedacted(
        string $module,
        ?string $actorId,
        string $action,
        string $subjectType,
        string $subjectId,
        ?array $before,
        ?array $after,
        array $redact,
        ?string $branchId = null,
    ): void;
}
