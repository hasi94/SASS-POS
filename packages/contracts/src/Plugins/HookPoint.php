<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * One named extension point, and the modes it accepts (T-019 plan Ruling 1).
 *
 * `name` must match `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$` and be at most 60
 * characters, because `plugin_execution_logs.point` is VARCHAR(60).
 *
 * A point declares the modes it accepts; listing {@see HookMode::Override}
 * among them is what "explicitly marked overridable" means (AC-3). A plugin
 * can never claim overridability for a point it does not own.
 */
final readonly class HookPoint
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /**
     * @param  list<HookMode>  $modes
     */
    public function __construct(
        public string $name,
        public array $modes,
    ) {}

    public function isOverridable(): bool
    {
        return in_array(HookMode::Override, $this->modes, true);
    }

    public function accepts(HookMode $mode): bool
    {
        return in_array($mode, $this->modes, true);
    }
}
