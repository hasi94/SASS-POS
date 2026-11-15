<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Support;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\TaxContext;
use Flexipos\Pricing\Engine\DiscountCalculator;
use Flexipos\Pricing\Engine\DiscountRule;
use Flexipos\Pricing\Engine\DiscountScope;
use Flexipos\Pricing\Engine\DiscountType;
use Flexipos\Pricing\Engine\LadderAssignment;
use Flexipos\Pricing\Engine\LadderInput;
use Flexipos\Pricing\Engine\LadderItem;
use Flexipos\Pricing\Engine\LadderList;
use Flexipos\Pricing\Engine\LineCalculator;
use Flexipos\Pricing\Engine\LineInput;
use Flexipos\Pricing\Engine\ListType;
use Flexipos\Pricing\Engine\PriceLadder;
use Flexipos\Pricing\Engine\ProductFacts;
use Flexipos\Pricing\Engine\RateRow;
use Flexipos\Pricing\Engine\ScopeType;
use Flexipos\Pricing\Engine\TaxApplies;
use Flexipos\Pricing\Engine\TaxCalculator;
use Flexipos\Pricing\Engine\TaxMember;
use Flexipos\Pricing\Engine\TaxType;
use RuntimeException;

/**
 * Loads, lints and replays the checked-in JSON fixtures (T-029 step 10, D-9,
 * D-21). The one place the fixture format meets the PHP engine.
 *
 * **The format**, which FP-432's TypeScript twin reads byte for byte:
 *
 * ```
 * { "name": "...", "kind": "tax|price|discount|line|bill", "discriminates": "...",
 *   "input": { ... }, "expect": { ... } | "refusal": "<PricingRefusal value>" }
 * ```
 *
 * Every decimal and every integer is a JSON **string** (a JavaScript number
 * loses precision at the fourth decimal), so the lint refuses any JSON number
 * anywhere. Dates are `YYYY-MM-DD`, instants `YYYY-MM-DDTHH:MM:SS.mmmZ`. There
 * is no database, no container and no product lookup in a fixture.
 */
final class FixtureReplay
{
    public const KINDS = ['tax', 'price', 'discount', 'line', 'bill'];

    /** @var list<string> */
    private const REQUIRED_KEYS = ['name', 'kind', 'discriminates', 'input'];

    public static function directory(): string
    {
        return dirname(__DIR__, 2).'/fixtures';
    }

    /**
     * Every fixture file under the directory, found by glob so that a file
     * dropped in is exercised without anyone listing it.
     *
     * @return list<string>
     */
    public static function paths(?string $directory = null): array
    {
        $paths = glob(($directory ?? self::directory()).'/*/*.json') ?: [];

        sort($paths);

        return $paths;
    }

    /**
     * The lint: every reason this file is not a well-formed fixture.
     *
     * @return list<string>
     */
    public static function lint(string $path): array
    {
        $problems = [];
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return ['does not parse as a JSON object'];
        }

        $hasExpect = array_key_exists('expect', $decoded);
        $hasRefusal = array_key_exists('refusal', $decoded);

        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $decoded)) {
                $problems[] = "missing key [{$key}]";
            }
        }

        if ($hasExpect === $hasRefusal) {
            $problems[] = 'must hold exactly one of [expect] and [refusal]';
        }

        foreach (array_keys($decoded) as $key) {
            if (! in_array($key, [...self::REQUIRED_KEYS, 'expect', 'refusal'], true)) {
                $problems[] = "unexpected top-level key [{$key}]";
            }
        }

        $kind = $decoded['kind'] ?? null;

        if (! in_array($kind, self::KINDS, true)) {
            $problems[] = 'kind is not one of '.implode(', ', self::KINDS);
        } elseif (basename(dirname($path)) !== $kind) {
            $problems[] = "kind [{$kind}] does not match its directory";
        }

        if (($decoded['name'] ?? null) !== basename($path, '.json')) {
            $problems[] = 'name does not match the file name';
        }

        if ($hasRefusal && (! is_string($decoded['refusal']) || preg_match('/^[a-z_]+$/', $decoded['refusal']) !== 1)) {
            $problems[] = 'refusal is not a refusal value string';
        }

        self::walk($decoded, '$', $problems);

        return $problems;
    }

    /**
     * @param  list<string>  $problems
     */
    private static function walk(mixed $node, string $at, array &$problems): void
    {
        if (is_int($node) || is_float($node)) {
            $problems[] = "{$at} is a JSON number; decimals and integers must be strings";

            return;
        }

        if (is_string($node)) {
            // A decimal-looking string must carry its full stored scale.
            if (preg_match('/^\d+\.\d+$/', $node) === 1 && preg_match('/^\d+\.(\d{4}|\d{6})$/', $node) !== 1) {
                $problems[] = "{$at} is a decimal [{$node}] without its full scale (4, or 6 for a factor)";
            }

            return;
        }

        if (is_array($node)) {
            foreach ($node as $key => $child) {
                self::walk($child, $at.'.'.$key, $problems);
            }
        }
    }

    /**
     * Run one fixture through the pure class its kind names.
     *
     * @param  array<string, mixed>  $case
     * @return array{expect: mixed}|array{refusal: string}
     */
    public static function replay(array $case): array
    {
        $input = self::map($case['input'] ?? null);

        try {
            $result = match ($case['kind'] ?? null) {
                'tax' => self::tax($input),
                'price' => PriceLadder::resolve(self::ladder(self::map($input))),
                'discount' => self::discount($input),
                'line' => LineCalculator::compute(self::line($input))->toArray(),
                'bill' => self::bill($input),
                default => throw new RuntimeException('Unknown fixture kind.'),
            };
        } catch (PricingRefused $refused) {
            return ['refusal' => $refused->reason->value];
        }

        return ['expect' => self::stringify($result)];
    }

    /**
     * What a fixture says the answer is, in the shape {@see replay()} returns.
     *
     * @param  array<string, mixed>  $case
     * @return array{expect: mixed}|array{refusal: string}
     */
    public static function expected(array $case): array
    {
        return array_key_exists('refusal', $case) ? ['refusal' => (string) $case['refusal']] : ['expect' => $case['expect']];
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Expected an object in the fixture.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Engine outputs hold ints (a ladder step) where the format holds strings.
     */
    private static function stringify(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return array_map(self::stringify(...), $value);
        }

        return $value;
    }

    // ------------------------------------------------------------ field access

    /**
     * @param  array<string, mixed>  $node
     */
    private static function str(array $node, string $key): string
    {
        $value = $node[$key] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("Fixture key [{$key}] must be a string.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function strOrNull(array $node, string $key): ?string
    {
        if (! array_key_exists($key, $node)) {
            throw new RuntimeException("Fixture key [{$key}] is missing (use null).");
        }

        return $node[$key] === null ? null : self::str($node, $key);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function bool(array $node, string $key): bool
    {
        $value = $node[$key] ?? null;

        if (! is_bool($value)) {
            throw new RuntimeException("Fixture key [{$key}] must be a boolean.");
        }

        return $value;
    }

    /**
     * Integers are strings in the format.
     *
     * @param  array<string, mixed>  $node
     */
    private static function int(array $node, string $key): int
    {
        $value = self::str($node, $key);

        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new RuntimeException("Fixture key [{$key}] must be an integer string.");
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function intOrNull(array $node, string $key): ?int
    {
        return self::strOrNull($node, $key) === null ? null : self::int($node, $key);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function objects(array $node, string $key): array
    {
        $value = $node[$key] ?? null;

        if (! is_array($value)) {
            throw new RuntimeException("Fixture key [{$key}] must be a list.");
        }

        return array_values(array_map(self::map(...), $value));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function strings(array $node, string $key): array
    {
        $value = $node[$key] ?? null;

        if (! is_array($value)) {
            throw new RuntimeException("Fixture key [{$key}] must be a list.");
        }

        return array_values(array_map(static fn (mixed $item): string => is_string($item) ? $item : throw new RuntimeException("Fixture list [{$key}] holds a non-string."), $value));
    }

    // ------------------------------------------------------------ mappers

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function tax(array $input): array
    {
        return TaxCalculator::compute(
            self::members(self::objects($input, 'members')),
            self::str($input, 'amount'),
            self::str($input, 'base_quantity'),
            self::str($input, 'date'),
            TaxContext::from(self::str($input, 'context')),
            self::bool($input, 'is_inclusive'),
        )->toArray();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<TaxMember>
     */
    private static function members(array $nodes): array
    {
        return array_map(static fn (array $node): TaxMember => new TaxMember(
            self::str($node, 'tax_id'),
            self::str($node, 'code'),
            self::strOrNull($node, 'tax_code'),
            TaxType::from(self::str($node, 'type')),
            self::bool($node, 'is_compound'),
            TaxApplies::from(self::str($node, 'applies_to')),
            self::bool($node, 'is_active'),
            self::bool($node, 'is_deleted'),
            self::int($node, 'sequence'),
            array_map(static fn (array $rate): RateRow => new RateRow(
                self::str($rate, 'rate'),
                self::str($rate, 'effective_from'),
                self::strOrNull($rate, 'effective_to'),
            ), self::objects($node, 'rates')),
        ), $nodes);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function ladder(array $node): LadderInput
    {
        return new LadderInput(
            self::str($node, 'unit_id'),
            self::str($node, 'unit_factor'),
            self::str($node, 'base_quantity'),
            self::str($node, 'at'),
            self::str($node, 'currency'),
            self::strOrNull($node, 'customer_id'),
            self::strOrNull($node, 'customer_group_id'),
            self::strOrNull($node, 'branch_id'),
            array_map(static fn (array $list): LadderList => new LadderList(
                self::str($list, 'list_id'),
                self::str($list, 'code'),
                ListType::from(self::str($list, 'type')),
                self::str($list, 'currency'),
                self::int($list, 'priority'),
                self::strOrNull($list, 'starts_at'),
                self::strOrNull($list, 'ends_at'),
                self::bool($list, 'is_active'),
                self::bool($list, 'is_deleted'),
                array_map(static fn (array $assignment): LadderAssignment => new LadderAssignment(
                    ScopeType::from(self::str($assignment, 'scope_type')),
                    self::str($assignment, 'scope_id'),
                    self::int($assignment, 'priority'),
                ), self::objects($list, 'assignments')),
                array_map(static fn (array $item): LadderItem => new LadderItem(
                    self::strOrNull($item, 'unit_id'),
                    self::str($item, 'min_qty'),
                    self::str($item, 'price'),
                    self::strOrNull($item, 'discount_percent'),
                ), self::objects($list, 'items')),
            ), self::objects($node, 'lists')),
            self::strOrNull($node, 'branch_selling_price'),
            self::strOrNull($node, 'selling_price'),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<DiscountRule>
     */
    private static function rules(array $input): array
    {
        return array_map(static function (array $rule): DiscountRule {
            $appliesTo = self::map($rule['applies_to'] ?? null);

            return new DiscountRule(
                self::str($rule, 'id'),
                DiscountType::from(self::str($rule, 'type')),
                self::str($rule, 'value'),
                DiscountScope::from(self::str($rule, 'scope')),
                self::bool($rule, 'is_active'),
                self::strOrNull($rule, 'starts_at'),
                self::strOrNull($rule, 'ends_at'),
                self::intOrNull($rule, 'usage_limit'),
                self::int($rule, 'used_count'),
                self::strOrNull($rule, 'min_amount'),
                self::strOrNull($rule, 'max_discount'),
                self::strings($appliesTo, 'products'),
                self::strings($appliesTo, 'categories'),
                self::strings($appliesTo, 'brands'),
            );
        }, self::objects($input, 'discounts'));
    }

    /**
     * @param  list<DiscountRule>  $rules
     */
    private static function findRule(array $rules, string $id): ?DiscountRule
    {
        foreach ($rules as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function product(array $node): ProductFacts
    {
        return new ProductFacts(
            self::str($node, 'id'),
            self::strOrNull($node, 'category_id'),
            self::strOrNull($node, 'brand_id'),
            self::bool($node, 'allow_discount'),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private static function discount(array $input): array
    {
        $amount = DiscountCalculator::forLine(
            self::findRule(self::rules($input), self::str($input, 'discount_id')),
            self::str($input, 'at'),
            self::product(self::map($input['product'] ?? null)),
            BigDecimal::of(self::str($input, 'subtotal')),
        );

        return ['discount_amount' => (string) $amount];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private static function bill(array $input): array
    {
        return DiscountCalculator::forBill(
            self::findRule(self::rules($input), self::str($input, 'discount_id')),
            self::str($input, 'at'),
            self::strings($input, 'discounted_subtotals'),
        )->toArray();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function line(array $input): LineInput
    {
        $group = self::map($input['tax_group'] ?? null);

        return new LineInput(
            self::ladder(self::map($input['ladder'] ?? null)),
            self::str($input, 'quantity'),
            self::product(self::map($input['product'] ?? null)),
            self::str($group, 'id'),
            self::bool($group, 'is_inclusive'),
            self::members(self::objects($group, 'members')),
            self::str($input, 'document_date'),
            self::strOrNull($input, 'discount_id'),
            self::rules($input),
        );
    }
}
