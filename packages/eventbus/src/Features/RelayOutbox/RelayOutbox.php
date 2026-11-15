<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\RelayOutbox;

/**
 * One bounded relay pass for the tenant currently entered (T-018 Ruling 6).
 *
 * Carries nothing: which tenant is relayed is a property of which tenant
 * connection is entered when this is handled, the same shape
 * `Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogs` already uses.
 */
final class RelayOutbox {}
