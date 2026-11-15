<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ActivateTerminal;

use Flexipos\Contracts\Organization\ActivationAttempt;

/**
 * The tenant side of one activation (T-014 AC-29): the request auth has
 * already parsed and shape-checked, as the published value object.
 *
 * It exists because every slice has a command object and the layout rule
 * checks for one; the contract method `activate()` wraps its argument in it
 * and the handler's `handle()` takes it, so the slice reads like the others
 * while the contract keeps its published signature.
 */
final class ActivateTerminal
{
    public function __construct(public readonly ActivationAttempt $attempt) {}
}
