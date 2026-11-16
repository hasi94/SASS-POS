<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Kernel\Schema\Support\SourceRules;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-029 step 26 - the lock-order scan (review W-1 and W-2, D-39)
|--------------------------------------------------------------------------
|
| SQLite compiles lockForUpdate() and sharedLock() to nothing and has no row
| locks or snapshots, so a lock-order fix cannot be seen to work on the stand-in.
| This file holds the TEXT of the fix: the statement order and the lock
| clauses. It proves nothing about the run-time lock. That proof is the
| engine-gated cases in PricingSchemaEngineTest (E-1 to E-5), which are skipped
| while ENGINE_HOLD is set: until the hold lifts, W-1 and W-2 are written and
| scanned but NOT VERIFIED at run time.
|
| WHAT EACH RULE COVERS (named for what it scans)
|
|  L1  In each rate and tax-group writer and in UpdateProduct, the first
|      statement inside the single `->transaction(function` that touches the
|      database is a locking read (`lock: true`, `->lockForUpdate()` or
|      `->sharedLock()`). Under InnoDB REPEATABLE READ the first plain read
|      fixes the transaction's snapshot, so a lock taken after it does not
|      refresh what the check reads. CreateProductHandler is deliberately not
|      listed: its first statement is a plain duplicate-sku read, which is safe
|      because its tax-group read is itself locking (L4).
|  L2  RateRanges::assertNoOverlap reads its sibling rates with
|      `->lockForUpdate()`, before its `->get(`.
|  L3  DatabaseTaxGroupDirectory::state() and ::defaultGroupId() both read with
|      `->sharedLock()`.
|  L4  Catalog's TaxGroups asks the directory for the lock on every `->state(`
|      and `->defaultGroupId(` call (`lock: true`).
|
| Code only (SourceRules::codeOnly): a docblock that says "lock: true" does not
| satisfy a rule. Every rule is a function of one path, so every control runs
| the same function over a planted file in a fresh temporary directory, and the
| scope proof checks that each named file holds what its rule looks for, so a
| rename fails loudly instead of emptying a rule. Every helper is prefixed
| `pricingLock`, so no other scan file's helper can be called by accident.
|
| Honest limit: the text order is not the run-time lock. A new rate writer must
| be added to L1's list; the scan names its files.
*/

function pricingLockRoot(): string
{
    return dirname(__DIR__, 4);
}

/**
 * @param  string  $relative  repo-relative
 */
function pricingLockPath(string $relative): string
{
    $real = realpath(pricingLockRoot().'/'.$relative);

    if ($real === false) {
        throw new \RuntimeException("Expected [{$relative}] to exist; a rename must fail this scan loudly, not empty it.");
    }

    return $real;
}

function pricingLockCode(string $path): string
{
    return SourceRules::codeOnly((string) file_get_contents($path));
}

function pricingLockTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/pricing_lock_scan_'.bin2hex(random_bytes(6));

    mkdir($directory, 0777, true);

    return $directory;
}

function pricingLockDeleteDirectory(string $directory): void
{
    foreach ((new Finder)->files()->in($directory) as $file) {
        unlink($file->getRealPath());
    }

    rmdir($directory);
}

/**
 * Plant one file in a fresh directory, run a rule over it, and clean up.
 *
 * @param  callable(string): list<string>  $rule
 * @return list<string>
 */
function pricingLockPlanted(callable $rule, string $source): array
{
    $directory = pricingLockTempDirectory();

    try {
        file_put_contents($directory.'/Planted.php', $source);

        return $rule($directory.'/Planted.php');
    } finally {
        pricingLockDeleteDirectory($directory);
    }
}

/**
 * The text between a method's braces, or null when the method is not there.
 */
function pricingLockMethodBody(string $code, string $method): ?string
{
    if (preg_match('/\bfunction\s+'.preg_quote($method, '/').'\s*\(/', $code, $found, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    $open = strpos($code, '{', $found[0][1]);

    if ($open === false) {
        return null;
    }

    $depth = 0;

    for ($index = $open, $length = strlen($code); $index < $length; $index++) {
        if ($code[$index] === '{') {
            $depth++;
        } elseif ($code[$index] === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($code, $open + 1, $index - $open - 1);
            }
        }
    }

    return null;
}

/**
 * The text of one call, from its opening parenthesis to the matching close.
 *
 * @return list<string>
 */
function pricingLockCalls(string $code, string $call): array
{
    $calls = [];
    $offset = 0;

    while (preg_match('/'.preg_quote($call, '/').'\s*\(/', $code, $found, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $found[0][1] + strlen($found[0][0]);
        $depth = 1;

        for ($index = $start, $length = strlen($code); $index < $length && $depth > 0; $index++) {
            if ($code[$index] === '(') {
                $depth++;
            } elseif ($code[$index] === ')') {
                $depth--;
            }
        }

        $calls[] = substr($code, $start, $index - $start - 1);
        $offset = $start;
    }

    return $calls;
}

const PRICING_LOCK_CLAUSE = '/\block:\s*true\b|->\s*lockForUpdate\s*\(\s*\)|->\s*sharedLock\s*\(\s*\)/';

/**
 * L1: the first database statement inside the one transaction closure is a
 * locking read.
 *
 * @return list<string>
 */
function pricingLockRuleFirstStatement(string $path): array
{
    $name = basename($path);
    $code = pricingLockCode($path);
    $count = preg_match_all('/->transaction\(\s*function\b/', $code, $found, PREG_OFFSET_CAPTURE);

    if ($count !== 1) {
        return ["{$name}: expected exactly one ->transaction(function, found {$count}"];
    }

    $open = strpos($code, '{', $found[0][0][1]);

    if ($open === false) {
        return ["{$name}: the transaction closure has no body"];
    }

    $body = substr($code, $open + 1);

    if (preg_match('/\$connection->|\bLookup::|\bRateRanges::|\bUniqueness::|\bDefaultTaxGroup::|->table\(/', $body, $first, PREG_OFFSET_CAPTURE) !== 1) {
        return ["{$name}: no database statement inside the transaction"];
    }

    $end = strpos($body, ';', $first[0][1]);
    $statement = $end === false ? substr($body, $first[0][1]) : substr($body, $first[0][1], $end - $first[0][1]);

    if (preg_match(PRICING_LOCK_CLAUSE, $statement) !== 1) {
        return ["{$name}: the first statement in the transaction is a plain read: ".trim((string) preg_replace('/\s+/', ' ', $statement))];
    }

    return [];
}

/**
 * L2: assertNoOverlap's sibling read locks, and does so before it reads.
 *
 * @return list<string>
 */
function pricingLockRuleOverlapRead(string $path): array
{
    $name = basename($path);
    $body = pricingLockMethodBody(pricingLockCode($path), 'assertNoOverlap');

    if ($body === null) {
        return ["{$name}: no assertNoOverlap method"];
    }

    $lock = strpos($body, '->lockForUpdate()');
    $read = strpos($body, '->get(');

    if ($lock === false) {
        return ["{$name}: assertNoOverlap reads its sibling rates without ->lockForUpdate()"];
    }

    if ($read !== false && $read < $lock) {
        return ["{$name}: assertNoOverlap reads with ->get( before it takes ->lockForUpdate()"];
    }

    return [];
}

/**
 * L3: both directory reads take the shared lock.
 *
 * @return list<string>
 */
function pricingLockRuleDirectoryReads(string $path): array
{
    $name = basename($path);
    $code = pricingLockCode($path);
    $problems = [];

    foreach (['state', 'defaultGroupId'] as $method) {
        $body = pricingLockMethodBody($code, $method);

        if ($body === null) {
            $problems[] = "{$name}: no {$method} method";
        } elseif (! str_contains($body, '->sharedLock()')) {
            $problems[] = "{$name}: {$method} reads without ->sharedLock()";
        }
    }

    return $problems;
}

/**
 * L4: every directory call catalog makes asks for the lock.
 *
 * @return list<string>
 */
function pricingLockRuleCatalogCalls(string $path): array
{
    $name = basename($path);
    $code = pricingLockCode($path);
    $problems = [];

    foreach (['->state', '->defaultGroupId'] as $call) {
        foreach (pricingLockCalls($code, $call) as $arguments) {
            if (preg_match('/\block:\s*true\b/', $arguments) !== 1) {
                $problems[] = "{$name}: a {$call}( call does not pass lock: true";
            }
        }
    }

    return $problems;
}

/**
 * @return array<string, array{string}>
 */
function pricingLockWriters(): array
{
    return [
        'CloseTaxRateHandler' => ['packages/pricing/src/Features/CloseTaxRate/CloseTaxRateHandler.php'],
        'CreateTaxRateHandler' => ['packages/pricing/src/Features/CreateTaxRate/CreateTaxRateHandler.php'],
        'UpdateTaxHandler' => ['packages/pricing/src/Features/UpdateTax/UpdateTaxHandler.php'],
        'UpdateTaxGroupHandler' => ['packages/pricing/src/Features/UpdateTaxGroup/UpdateTaxGroupHandler.php'],
        'UpdateProductHandler' => ['packages/catalog/src/Features/UpdateProduct/UpdateProductHandler.php'],
    ];
}

// ------------------------------------------------------------------ the rules

it('L1: the first statement inside the transaction is a locking read', function (string $relative) {
    expect(pricingLockRuleFirstStatement(pricingLockPath($relative)))->toBe([]);
})->with(pricingLockWriters());

it('L2: the overlap check reads its sibling rates with a lock, before it reads', function () {
    expect(pricingLockRuleOverlapRead(pricingLockPath('packages/pricing/src/Support/RateRanges.php')))->toBe([]);
});

it('L3: the tax group directory reads both answers with a shared lock', function () {
    expect(pricingLockRuleDirectoryReads(pricingLockPath('packages/pricing/src/Taxes/DatabaseTaxGroupDirectory.php')))->toBe([]);
});

it('L4: catalog asks the directory for the lock on every call', function () {
    expect(pricingLockRuleCatalogCalls(pricingLockPath('packages/catalog/src/Support/TaxGroups.php')))->toBe([]);
});

it('scope proof: every named file exists and holds what its rule looks for', function () {
    foreach (pricingLockWriters() as [$relative]) {
        $code = pricingLockCode(pricingLockPath($relative));

        expect(preg_match_all('/->transaction\(\s*function\b/', $code))->toBe(1, "{$relative} must hold exactly one ->transaction(function.");
    }

    $ranges = pricingLockCode(pricingLockPath('packages/pricing/src/Support/RateRanges.php'));
    $directory = pricingLockCode(pricingLockPath('packages/pricing/src/Taxes/DatabaseTaxGroupDirectory.php'));
    $groups = pricingLockCode(pricingLockPath('packages/catalog/src/Support/TaxGroups.php'));

    expect(pricingLockMethodBody($ranges, 'assertNoOverlap'))->not->toBeNull()
        ->and(pricingLockMethodBody($directory, 'state'))->not->toBeNull()
        ->and(pricingLockMethodBody($directory, 'defaultGroupId'))->not->toBeNull()
        ->and(count(pricingLockCalls($groups, '->state')))->toBeGreaterThanOrEqual(1)
        ->and(count(pricingLockCalls($groups, '->defaultGroupId')))->toBeGreaterThanOrEqual(1);
});

// ------------------------------------------------------ planted-file controls

const PRICING_LOCK_HANDLER_OPEN = "<?php\nfinal class H\n{\n    public function handle(): void\n    {\n        \$connection = \$this->tenant->get();\n\n        \$connection->transaction(function () use (\$connection): void {\n";

const PRICING_LOCK_HANDLER_CLOSE = "        });\n    }\n}\n";

it('L1 control: a handler whose first statement is a plain Lookup::any is reported', function () {
    $planted = PRICING_LOCK_HANDLER_OPEN."            \$rate = Lookup::any(\$connection, 'tax_rates', \$id, 'tax rate');\n".PRICING_LOCK_HANDLER_CLOSE;

    expect(pricingLockPlanted(pricingLockRuleFirstStatement(...), $planted))->toHaveCount(1);
});

it('L1 control: the same handler with lock: true passes', function () {
    $planted = PRICING_LOCK_HANDLER_OPEN."            \$rate = Lookup::any(\$connection, 'tax_rates', \$id, 'tax rate', lock: true);\n".PRICING_LOCK_HANDLER_CLOSE;

    expect(pricingLockPlanted(pricingLockRuleFirstStatement(...), $planted))->toBe([]);
});

it('L1 control: a locking query-builder read passes, and a plain one is reported', function () {
    $locked = PRICING_LOCK_HANDLER_OPEN."            \$row = \$connection->table('products')->where('id', \$id)->lockForUpdate()->first();\n".PRICING_LOCK_HANDLER_CLOSE;
    $plain = PRICING_LOCK_HANDLER_OPEN."            \$row = \$connection->table('products')->where('id', \$id)->first();\n".PRICING_LOCK_HANDLER_CLOSE;

    expect(pricingLockPlanted(pricingLockRuleFirstStatement(...), $locked))->toBe([])
        ->and(pricingLockPlanted(pricingLockRuleFirstStatement(...), $plain))->toHaveCount(1);
});

it('L1 control: a file with no ->transaction(function is reported, never a vacuous pass', function () {
    expect(pricingLockPlanted(pricingLockRuleFirstStatement(...), "<?php\nfinal class H\n{\n    public function handle(): void {}\n}\n"))->toHaveCount(1);
});

it('L1 control: a plain Uniqueness:: first, with a later locking statement, is reported', function () {
    $planted = PRICING_LOCK_HANDLER_OPEN
        ."            Uniqueness::assertCodeFree(\$connection, 'taxes', \$code);\n"
        ."            \$row = Lookup::any(\$connection, 'taxes', \$id, 'tax', lock: true);\n"
        .PRICING_LOCK_HANDLER_CLOSE;

    expect(pricingLockPlanted(pricingLockRuleFirstStatement(...), $planted))->toHaveCount(1);
});

it('L1 control: a docblock that mentions lock: true does not satisfy the rule', function () {
    $planted = PRICING_LOCK_HANDLER_OPEN
        ."            /** The read below uses lock: true and ->lockForUpdate(). */\n"
        ."            // lock: true\n"
        ."            \$rate = Lookup::any(\$connection, 'tax_rates', \$id, 'tax rate');\n"
        .PRICING_LOCK_HANDLER_CLOSE;

    expect(pricingLockPlanted(pricingLockRuleFirstStatement(...), $planted))->toHaveCount(1);
});

it('L2 control: a RateRanges copy with a plain get is reported, a locking one passes, a late lock is reported', function () {
    $plain = "<?php\nfinal class R\n{\n    public static function assertNoOverlap(\$connection): void\n    {\n        \$query = \$connection->table('tax_rates')->where('tax_id', 1);\n\n        foreach (\$query->get(['effective_from']) as \$other) {\n        }\n    }\n}\n";
    $locked = "<?php\nfinal class R\n{\n    public static function assertNoOverlap(\$connection): void\n    {\n        \$query = \$connection->table('tax_rates')->where('tax_id', 1)->lockForUpdate();\n\n        foreach (\$query->get(['effective_from']) as \$other) {\n        }\n    }\n}\n";
    $late = "<?php\nfinal class R\n{\n    public static function assertNoOverlap(\$connection): void\n    {\n        \$rows = \$connection->table('tax_rates')->get();\n        \$connection->table('tax_rates')->lockForUpdate();\n    }\n}\n";

    expect(pricingLockPlanted(pricingLockRuleOverlapRead(...), $plain))->toHaveCount(1)
        ->and(pricingLockPlanted(pricingLockRuleOverlapRead(...), $locked))->toBe([])
        ->and(pricingLockPlanted(pricingLockRuleOverlapRead(...), $late))->toHaveCount(1)
        ->and(pricingLockPlanted(pricingLockRuleOverlapRead(...), "<?php\nfinal class R {}\n"))->toHaveCount(1);
});

it('L3 control: a directory copy with sharedLock in one method only is reported', function () {
    $both = "<?php\nfinal class D\n{\n    public function state(string \$id): int\n    {\n        return \$this->t->table('tax_groups')->where('id', \$id)->sharedLock()->value('is_active');\n    }\n\n    public function defaultGroupId(): ?string\n    {\n        return \$this->t->table('tax_groups')->where('default_marker', 1)->sharedLock()->value('id');\n    }\n}\n";
    $oneOnly = str_replace('->where(\'default_marker\', 1)->sharedLock()', '->where(\'default_marker\', 1)', $both);

    expect(pricingLockPlanted(pricingLockRuleDirectoryReads(...), $both))->toBe([])
        ->and(pricingLockPlanted(pricingLockRuleDirectoryReads(...), $oneOnly))->toHaveCount(1)
        ->and(pricingLockPlanted(pricingLockRuleDirectoryReads(...), str_replace('state', 'status', $both)))->not->toBe([]);
});

it('L4 control: a TaxGroups copy with one unlocked call is reported', function () {
    $locked = "<?php\nfinal class T\n{\n    public static function a(\$d, \$id): void\n    {\n        \$d->state(\$id, lock: true);\n        \$d->defaultGroupId(lock: true);\n    }\n}\n";
    $unlocked = str_replace('$d->defaultGroupId(lock: true)', '$d->defaultGroupId()', $locked);
    $prose = str_replace('$d->state($id, lock: true)', "\$d->state(\$id); // lock: true\n", $locked);

    expect(pricingLockPlanted(pricingLockRuleCatalogCalls(...), $locked))->toBe([])
        ->and(pricingLockPlanted(pricingLockRuleCatalogCalls(...), $unlocked))->toHaveCount(1)
        ->and(pricingLockPlanted(pricingLockRuleCatalogCalls(...), $prose))->toHaveCount(1);
});
