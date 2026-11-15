<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ReportOutboxAge;

/**
 * Report the age in seconds of the current tenant's oldest `pending`
 * outbox_events row (T-018 Ruling 10, AC-32) - the "outbox age" signal
 * `docs/architecture.md` section 18 names as something to alert on. This
 * produces the number; it builds no alerting mechanism.
 *
 * Carries nothing: which tenant is reported on is a property of which
 * tenant connection is entered when this is handled.
 */
final class ReportOutboxAge {}
