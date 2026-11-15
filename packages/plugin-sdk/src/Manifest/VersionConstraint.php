<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Manifest;

/**
 * A narrow version-constraint grammar, written for this task with no new
 * dependency (T-019 AC-9, AC-10, plan Ruling 3).
 *
 * - a `||` between alternatives means OR;
 * - a space between terms means AND;
 * - each term is `*`, or an optional operator from
 *   `>=, <=, >, <, =, ^, ~` followed by `MAJOR.MINOR.PATCH`, where `MINOR`
 *   and `PATCH` may be omitted and count as `0`;
 * - `^1.2.3` means at least `1.2.3` and below `2.0.0`. `^0.2.3` means at
 *   least `0.2.3` and below `0.3.0`;
 * - `~1.2` means at least `1.2.0` and below `1.3.0`.
 *
 * Anything else is refused with {@see InvalidVersionConstraint}. The
 * comparison uses `version_compare()`.
 */
final class VersionConstraint
{
    private const TERM_PATTERN = '/^(>=|<=|>|<|=|\^|~)?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/';

    /**
     * @param  list<list<array{0: string, 1: string}>>  $alternatives  each
     *                                                                 inner list is one OR-alternative, ANDed comparisons of [operator, version]
     */
    private function __construct(
        private readonly array $alternatives,
    ) {}

    public static function parse(string $expression): self
    {
        $expression = trim($expression);

        if ($expression === '') {
            throw InvalidVersionConstraint::malformed($expression);
        }

        $alternatives = [];

        foreach (explode('||', $expression) as $alternative) {
            $terms = preg_split('/\s+/', trim($alternative));

            if ($terms === false || $terms === [''] || $terms === []) {
                throw InvalidVersionConstraint::malformed($expression);
            }

            $comparisons = [];

            foreach ($terms as $term) {
                $comparisons = array_merge($comparisons, self::parseTerm($term, $expression));
            }

            $alternatives[] = $comparisons;
        }

        return new self($alternatives);
    }

    public function satisfiedBy(string $version): bool
    {
        $version = self::normalize($version);

        foreach ($this->alternatives as $comparisons) {
            $allPass = true;

            foreach ($comparisons as [$operator, $bound]) {
                if (! version_compare($version, $bound, $operator)) {
                    $allPass = false;

                    break;
                }
            }

            if ($allPass) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function parseTerm(string $term, string $wholeExpression): array
    {
        if ($term === '*') {
            return [];
        }

        if (preg_match(self::TERM_PATTERN, $term, $matches) !== 1) {
            throw InvalidVersionConstraint::malformed($wholeExpression);
        }

        $operator = $matches[1] !== '' ? $matches[1] : '=';
        $major = (int) $matches[2];
        $hasMinor = isset($matches[3]);
        $minor = $hasMinor ? (int) $matches[3] : 0;
        $patch = isset($matches[4]) ? (int) $matches[4] : 0;
        $version = "{$major}.{$minor}.{$patch}";

        if ($operator === '^') {
            $upper = match (true) {
                $major > 0 => ($major + 1).'.0.0',
                $minor > 0 => '0.'.($minor + 1).'.0',
                default => '0.0.'.($patch + 1),
            };

            return [['>=', $version], ['<', $upper]];
        }

        if ($operator === '~') {
            $upper = $hasMinor ? "{$major}.".($minor + 1).'.0' : ($major + 1).'.0.0';

            return [['>=', $version], ['<', $upper]];
        }

        return [[$operator, $version]];
    }

    private static function normalize(string $version): string
    {
        $parts = explode('.', $version);

        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', $parts);
    }
}
