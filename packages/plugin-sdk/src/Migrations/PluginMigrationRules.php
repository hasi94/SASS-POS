<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Migrations;

use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;

/**
 * The static rules a plugin's own tenant migration file must follow (T-019
 * AC-19, plan Ruling 6), checked before any of a plugin's migrations run.
 *
 * A violation refuses the **whole set** ({@see PluginMigrationRefused}),
 * never just the one file - a plugin's migrations are inspected together
 * because a prefix mistake in file 2 should not let file 1's DDL through.
 */
final class PluginMigrationRules
{
    private const SCHEMA_PATTERN = '/Schema::(?:create|table|drop|dropIfExists|rename)\s*\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^,)]+)/';

    private const JSON_VALIDITY_PATTERN = '/JsonValidity::enforce\s*\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^,)]+)/';

    private const RAW_SQL_PATTERNS = [
        '/\bDB::/',
        '/->\s*statement\s*\(/',
        '/->\s*unprepared\s*\(/',
    ];

    /**
     * @return list<array{rule: string, found: string}>
     */
    public static function inspect(string $source, string $prefix): array
    {
        $violations = [];

        foreach (SourceRules::inspect($source, MigrationTree::Tenant) as $violation) {
            $violations[] = $violation;
        }

        foreach (SourceRules::importsOutsideAllowedNamespaces($source) as $violation) {
            $violations[] = $violation;
        }

        $code = SourceRules::codeOnly($source);

        foreach (self::schemaCalls($code) as $call) {
            if ($call['literal'] === null) {
                $violations[] = ['rule' => 'plugin-table-literal', 'found' => trim($call['raw'])];

                continue;
            }

            if (! str_starts_with($call['literal'], $prefix)) {
                $violations[] = ['rule' => 'plugin-table-prefix', 'found' => $call['literal']];
            }
        }

        foreach (self::RAW_SQL_PATTERNS as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $violations[] = ['rule' => 'plugin-no-raw-sql', 'found' => $pattern];
            }
        }

        foreach (self::jsonValidityTargets($code) as $target) {
            if ($target === null || ! str_starts_with($target, $prefix)) {
                $violations[] = ['rule' => 'plugin-json-validity-prefix', 'found' => (string) $target];
            }
        }

        return $violations;
    }

    /**
     * @return list<array{literal: ?string, raw: string}>
     */
    private static function schemaCalls(string $code): array
    {
        preg_match_all(self::SCHEMA_PATTERN, $code, $matches, PREG_SET_ORDER);

        $calls = [];

        foreach ($matches as $match) {
            $calls[] = ['literal' => self::literalOf(trim($match[1])), 'raw' => $match[0]];
        }

        return $calls;
    }

    /**
     * @return list<?string>
     */
    private static function jsonValidityTargets(string $code): array
    {
        preg_match_all(self::JSON_VALIDITY_PATTERN, $code, $matches, PREG_SET_ORDER);

        $targets = [];

        foreach ($matches as $match) {
            $targets[] = self::literalOf(trim($match[1]));
        }

        return $targets;
    }

    private static function literalOf(string $argument): ?string
    {
        if (preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'$/', $argument, $match) === 1) {
            return stripcslashes($match[1]);
        }

        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $argument, $match) === 1) {
            return stripcslashes($match[1]);
        }

        return null;
    }
}
