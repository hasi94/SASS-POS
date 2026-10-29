<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Exceptions;

use Flexipos\PluginSdk\Migrations\PluginMigrationRules;
use RuntimeException;

/**
 * A plugin's migration set failed {@see PluginMigrationRules}
 * (T-019 AC-19, plan Ruling 6). Nothing in the set runs.
 */
final class PluginMigrationRefused extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, list<array{rule: string, found: string}>>  $violationsByFile
     */
    public static function violatesRules(string $pluginName, array $violationsByFile): self
    {
        return new self(
            'violatesRules',
            "Plugin [{$pluginName}]'s migrations violate the tenant source rules: ".json_encode($violationsByFile)
        );
    }
}
