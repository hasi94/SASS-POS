<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\BootstrapOwner;

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;

/**
 * This slice's `<X>.php` (`docs/patterns.md` §3, enforced by
 * `VerticalSliceLayoutTest`).
 *
 * **It carries nothing, and the emptiness is the design**, the same shape
 * {@see SyncPermissionRegistry}
 * takes. `BootstrapOwnerHandler` implements the published, multi-method
 * `TenantAccessBootstrap` contract directly rather than taking one request
 * object: its seven operations (T-026 Ruling 5) are called independently by
 * platform's provisioning steps, each at its own point in a resumable run,
 * so a single combined command would not describe what any one call does. This
 * class is never constructed; it exists only so the slice holds the file the
 * layout convention requires.
 */
final class BootstrapOwner {}
