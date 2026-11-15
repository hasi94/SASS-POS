<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Events;

/**
 * The one outbox-writing capability (T-018 D-3, AC-5).
 *
 * Any module wanting an event published calls this, and nothing else: the
 * same "one inserter" rule T-017 enforces for `audit_logs`
 * (`Flexipos\Contracts\Audit\AuditTrail`), now for `outbox_events`.
 *
 * Implemented by `Flexipos\Eventbus\Support\OutboxWriter`, which validates
 * before touching any connection (AC-13) and inserts on the caller's tenant
 * connection **inside the caller's own transaction** (AC-6) - the writer
 * never opens one of its own.
 *
 * **Deliberately not `EventBus`.** The transport port stays internal to
 * `packages/eventbus`; a module that could publish directly would have a way
 * around the outbox the boundary check could never flag, because a published
 * contract is allowed everywhere.
 */
interface Outbox
{
    /**
     * Record one event. Returns the new row's id.
     */
    public function record(NewEvent $event): string;
}
