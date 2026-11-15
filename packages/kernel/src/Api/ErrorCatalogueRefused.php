<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api;

use LogicException;

/**
 * A code, a module key, a status or a message failed the catalogue's
 * registration rules (T-021 AC-10, AC-11). The offending code is always
 * named in the message, so a typo fails loud at boot rather than reaching a
 * client as an unmapped string.
 */
final class ErrorCatalogueRefused extends LogicException {}
