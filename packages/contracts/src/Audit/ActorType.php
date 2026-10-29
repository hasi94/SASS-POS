<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Audit;

/**
 * The closed actor-type vocabulary `audit_logs.actor_type` documents
 * (`docs/database.md` section 6.21), as a code enum (T-018 Ruling 4).
 *
 * **Measured, not assumed.** Before this task the five-value set existed only
 * in that document: `Flexipos\Access\Support\AuditWriter::ACTOR_TYPE` held one
 * literal (`employee`), and `Flexipos\Platform\Support\PlatformAudit` writes a
 * different, two-value vocabulary (`user`/`system`) for `platform_audit_logs` -
 * a different table with its own documented shape, untouched by this enum.
 *
 * It lives in `packages/contracts` because both `packages/access`
 * (`audit_logs.actor_type`) and `packages/eventbus` (`outbox_events.actor`)
 * need the same five values, and neither package may depend on the other.
 *
 * The order of the cases is the document's own order, so a test asserting
 * "the five values, in the docs/database.md order" has one list to read them
 * off against.
 */
enum ActorType: string
{
    case Employee = 'employee';
    case Terminal = 'terminal';
    case System = 'system';
    case Plugin = 'plugin';
    case PlatformStaff = 'platform_staff';
}
