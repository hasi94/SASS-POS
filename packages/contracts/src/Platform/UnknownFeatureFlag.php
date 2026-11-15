<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Platform;

use LogicException;

/**
 * A `feature_flags` key that does not exist was resolved (T-016 AC-21).
 *
 * A programming error, not a disabled flag: distinguishing the two is the
 * whole point of this class existing beside `FeatureFlagValue`. A typo'd key
 * throws loudly instead of quietly behaving like an off switch.
 */
final class UnknownFeatureFlag extends LogicException {}
