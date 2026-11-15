<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Events;

/**
 * Implemented by a module's service provider to declare the event consumers
 * it runs (T-018 Ruling 7).
 *
 * **The same shape as `Flexipos\Contracts\Audit\DeclaresAuditActions`, for
 * the same reason.** Collected through `$app->getProviders()`, exactly as
 * `Flexipos\Access\Support\AuditCatalogue` collects
 * `DeclaresAuditActions` - so a module declares its own consumers rather than
 * this registry restating a list that would go out of step with it.
 */
interface DeclaresEventConsumers
{
    /**
     * @return list<class-string<EventConsumer>>
     */
    public function eventConsumers(): array;
}
