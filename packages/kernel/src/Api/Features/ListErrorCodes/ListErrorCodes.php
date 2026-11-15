<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Features\ListErrorCodes;

/**
 * List every registered error code (T-021 AC-14). It carries nothing: the
 * catalogue it reads is a singleton every booted provider has already
 * contributed to.
 */
final class ListErrorCodes {}
