<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Features\PruneIdempotencyKeys;

/**
 * One request to delete this tenant's aged `idempotency_keys` rows (T-021
 * AC-23). It carries nothing: the retention threshold is operational
 * configuration and which tenant is pruned is a property of which
 * connection is entered when this is handled.
 */
final class PruneIdempotencyKeys {}
