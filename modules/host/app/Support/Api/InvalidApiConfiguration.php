<?php

declare(strict_types=1);

namespace App\Support\Api;

use LogicException;

/**
 * A `config/api.php` limit failed {@see ApiLimits::fromConfig()}'s
 * validation (T-021 AC-33): a typo must fail at boot with the offending key
 * named, rather than silently disable throttling.
 */
final class InvalidApiConfiguration extends LogicException {}
