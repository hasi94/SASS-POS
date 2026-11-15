<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SyncPermissionRegistry;

/**
 * One request to reconcile the tenant's `permissions` table with what the
 * booted application declares (AC-15).
 *
 * **It carries nothing, and the emptiness is the design.** A field naming
 * which keys to sync, or which module's, would make the operation's result
 * depend on the caller rather than on the registry, and "the table holds
 * exactly what the application declares" would stop being checkable. The
 * whole declared set is the only input, and the registry is its source.
 */
final class SyncPermissionRegistry {}
