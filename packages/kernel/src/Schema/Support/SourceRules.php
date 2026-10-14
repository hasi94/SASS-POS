<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Support;

/**
 * The static rules of the migration trees, as one readable pass over a file's
 * text.
 *
 * T-008 step 2, and it ships **before the first real migration on the branch**
 * on purpose (AC-34, plan section 6): run the other way round, every rule here
 * passes against a tree of zero tenant migrations and proves nothing. The
 * fixtures under `modules/host/tests/Fixtures/MigrationSourceRules/` are the
 * mutation that proves each rule can fail; nothing in this class is ever
 * weakened to make a test red.
 *
 * Five rules, each from a criterion:
 *
 * - **AC-33** an `expand` step may not drop, rename or `change`. Those are the
 *   statements that can lose data or break a running deploy, and a contract step
 *   is where they belong.
 * - **AC-34** no migration alters an **existing** table to add a foreign key
 *   unless its phase is `contract`. Creating a new table with its keys in an
 *   expand step is fine - the table has no rows yet. This is the rule that
 *   forces D-5's four unguarded columns (`number_sequences.branch_id`,
 *   `audit_logs.branch_id`, `outbox_events.branch_id`, `settings.updated_by`)
 *   into a contract migration whenever `branches` and `employees` arrive.
 * - **AC-35** (a) a `decimal` column states its precision - a bare `DECIMAL` is
 *   `DECIMAL(10,0)` on both engines, so 0.5 kg truncates to zero; (b) no native
 *   `enum` type, because adding a value is a table rebuild (enumerations are
 *   `VARCHAR(30)` with application enums); (c) no `timestamp` column outside the
 *   eight Laravel system tables, because the design's business datetimes are
 *   `DATETIME(3)` and a `TIMESTAMP` is both second-precision and
 *   timezone-converting; (d) no migration names a charset, a collation or a
 *   storage engine - those come from the connection configuration - and no
 *   first-party file anywhere names a collation MariaDB does not have.
 * - **AC-37** a tenant migration may not name the `master` connection, a master
 *   migration may not name the `tenant` one, and neither may name a
 *   schema-qualified table: the database *is* the tenant boundary, so a
 *   `other_db.table` name in a migration is that boundary being crossed.
 * - **AC-24** no tenant migration declares a `tenant_id` column. A tenant
 *   database that needs to know which tenant it is holds that as a `schema_meta`
 *   **row**.
 *
 * **Everything here reads code with comments and docblocks removed** (the one
 * exception is the phase tag, which `MigrationPhase` reads out of a comment by
 * definition). Every migration below discusses these rules in its own prose -
 * the baseline's docblock has to name the four unguarded foreign keys, and
 * `settings`'s has to explain the sentinel - so a scan over raw text would
 * report the documentation of the rule as a violation of it. On this project a
 * literal search has already been defeated twice, once by a line wrap and once
 * by a bold marker, and this is the other half of that lesson: strip what is
 * prose, assert over what is code.
 *
 * **There is no exemption list here.** AC-35(d) has exactly one exemption and it
 * is a single symbol, not a list: `IdentifierColumn`, the one site allowed to
 * name a charset, which may name `ascii` and `ascii_bin` and nothing else -
 * `inspectIdentifierHelper()` reports any other charset, collation or engine
 * literal in that very file. A flagged string is reworded, never exempted.
 */
final class SourceRules
{
    /**
     * The one file AC-35(d) exempts, as a repository-relative path.
     *
     * It is one path and not a list, and the file is not unchecked: it is
     * checked by a *different* and narrower rule
     * (`inspectIdentifierHelper()`), which permits `ascii` and `ascii_bin` and
     * reports everything else.
     */
    public const IDENTIFIER_HELPER_PATH = 'packages/kernel/src/Schema/Support/IdentifierColumn.php';

    /**
     * The two names that one file may write, and nothing may write anywhere
     * else. Held here rather than read from `IdentifierColumn` on purpose: this
     * is the rule's own statement of what it permits, and a rule that asked the
     * file it is checking what it is allowed to say would permit whatever that
     * file said.
     */
    private const CHARSET_ALLOWED_IN_HELPER = 'ascii';

    private const COLLATION_ALLOWED_IN_HELPER = 'ascii_bin';

    /**
     * The eight Laravel system tables of AC-1, the only tables whose migrations
     * may declare a `timestamp` column.
     *
     * The carve-out is by table name rather than by file, so it cannot grow into
     * a licence for one of ours: a first-party table declared inside an
     * otherwise stock migration file is still reported.
     *
     * @var list<string>
     */
    private const SYSTEM_TABLES = [
        'migrations',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
        'password_reset_tokens',
        'sessions',
    ];

    /**
     * Every rule violation in one migration file.
     *
     * @return list<array{rule: string, found: string}>
     */
    public static function inspect(string $source, MigrationTree $tree): array
    {
        $code = self::codeOnly($source);
        $phase = MigrationPhase::fromSource($source);

        return array_merge(
            self::expandOnlyAdds($code, $phase),
            self::foreignKeysOnlyInContract($code, $phase),
            self::decimalStatesPrecision($code),
            self::noNativeEnum($code),
            self::noTimestampColumn($code),
            self::noCharsetCollationOrEngine($code),
            self::noCrossConnectionOrQualifiedName($code, $tree),
            self::noTenantIdColumn($code, $tree),
        );
    }

    /**
     * The identifier-column helper's own, narrower rule: `ascii` and `ascii_bin`
     * are what AC-2 and AC-36 require of a ULID column and cannot be had without
     * naming them; any other charset, collation or engine literal in this file is
     * a violation like anywhere else.
     *
     * @return list<array{rule: string, found: string}>
     */
    public static function inspectIdentifierHelper(string $source): array
    {
        $allowed = [self::CHARSET_ALLOWED_IN_HELPER, self::COLLATION_ALLOWED_IN_HELPER];

        $found = [];

        // Names only, not the setters: calling `->charset()` and `->collation()`
        // is this file's entire job, so a rule that reported the call would make
        // the exemption meaningless. What the narrow rule polices is *which*
        // names appear.
        foreach (self::charsetCollationAndEngineNames(self::codeOnly($source)) as $name) {
            if (in_array(strtolower($name), $allowed, true)) {
                continue;
            }

            $found[] = ['rule' => 'AC-35(d)', 'found' => $name];
        }

        return $found;
    }

    /**
     * Collation names MariaDB 10.4 does not have, anywhere in first-party code.
     *
     * `utf8mb4_0900_*` is MySQL 8's and `utf8mb4_general_ci` is the old default
     * that sorts differently from the configured `utf8mb4_unicode_ci`; a file
     * naming either produces a statement this project's engine refuses or sorts
     * wrongly, so the rule is repository-wide rather than migration-only
     * (AC-35(d), second clause).
     *
     * The two patterns are assembled from fragments so that this file does not
     * carry the literals its own repository-wide check looks for - the same
     * technique the vocabulary sweep and the containment check use, and the
     * reason neither needs to exempt itself.
     *
     * @return list<string>
     */
    public static function bannedCollations(string $source): array
    {
        $patterns = [
            '/utf8mb4'.'_0900_\w+/i',
            '/utf8mb4'.'_general'.'_ci/i',
        ];

        $found = [];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, self::codeOnly($source), $matches);

            foreach ($matches[0] as $match) {
                $found[] = (string) $match;
            }
        }

        return $found;
    }

    /**
     * The given PHP source with every comment and docblock removed.
     *
     * Tokenised rather than regexed, because the rules are about code.
     */
    public static function codeOnly(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? (string) $token[1] : (string) $token;
        }

        return $code;
    }

    /**
     * AC-33: an expand step adds, and does nothing else to anything that
     * existed before it ran.
     *
     * Two deliberate narrowings, each with its reason, because this is the rule
     * a reader will suspect of having been narrowed until it was blind:
     *
     * - **Only `up()` is read.** A `down()` that drops what its own `up()`
     *   created is the framework's shape, not a schema change; the shipped tree
     *   has no `down()` at all (D-8), but the stock `users` file does.
     * - **A table the same migration created is not "existing".** `tenants`
     *   creates its `sequence` column and then issues one
     *   `ALTER TABLE tenants MODIFY sequence …` three statements later, because
     *   the grammar emits `auto_increment primary key` together and the table's
     *   key is a ULID. Nothing can be lost by altering a table that had no rows
     *   a moment ago. This is why the rule tracks the table name instead of
     *   banning the word: the alternative is a rule the shipped tree must dodge
     *   by choosing `MODIFY sequence` over `MODIFY COLUMN sequence`, which is
     *   enforcement by syntax accident.
     *
     * @return list<array{rule: string, found: string}>
     */
    private static function expandOnlyAdds(string $code, MigrationPhase $phase): array
    {
        if ($phase !== MigrationPhase::Expand) {
            return [];
        }

        $body = self::upBody($code);
        $created = self::createdTables($body);
        $found = [];

        $calls = [
            '/->\s*drop[A-Za-z]*\s*\(/',
            '/->\s*rename[A-Za-z]*\s*\(/',
            '/->\s*change\s*\(/',
        ];

        foreach (self::schemaBlocks($body) as $block) {
            if (in_array($block['table'], $created, true)) {
                continue;
            }

            foreach (self::matchesAgainst($block['body'], $calls, 'AC-33') as $violation) {
                $found[] = [
                    'rule' => $violation['rule'],
                    'found' => $block['table'].': '.$violation['found'],
                ];
            }
        }

        $statements = [
            '/Schema::\s*(?:drop|dropIfExists|rename)\s*\(\s*[\'"](\w+)[\'"]/',
            '/\bdrop\s+table\s+(?:if\s+exists\s+)?`?(\w+)`?/i',
            '/\brename\s+table\s+`?(\w+)`?/i',
        ];

        foreach ($statements as $pattern) {
            preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if (in_array($match[1], $created, true)) {
                    continue;
                }

                $found[] = ['rule' => 'AC-33', 'found' => trim($match[0])];
            }
        }

        preg_match_all('/\balter\s+table\s+`?(\w+)`?([^;\'"]*)/i', $body, $alters, PREG_SET_ORDER);

        foreach ($alters as $alter) {
            if (in_array($alter[1], $created, true)) {
                continue;
            }

            if (preg_match('/\b(?:drop|modify|change|rename)\b/i', $alter[2]) !== 1) {
                continue;
            }

            $found[] = ['rule' => 'AC-33', 'found' => trim($alter[0])];
        }

        return $found;
    }

    /**
     * The tables the given migration body creates itself.
     *
     * @return list<string>
     */
    private static function createdTables(string $body): array
    {
        $tables = [];

        foreach (self::schemaBlocks($body) as $block) {
            if ($block['kind'] === 'create') {
                $tables[] = $block['table'];
            }
        }

        preg_match_all('/\bcreate\s+table\s+(?:if\s+not\s+exists\s+)?`?(\w+)`?/i', $body, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $tables[] = (string) $match[1];
        }

        return array_values(array_unique($tables));
    }

    /**
     * AC-34: a foreign key onto an existing table is a contract step.
     *
     * Only `Schema::table()` blocks are read - they are what "alter an existing
     * table" is in this framework - and only the two calls that really create a
     * constraint (`->foreign()` and `->constrained()`) plus the raw statement
     * form. `foreignId()` on its own declares a column and no key, so it is not
     * reported: a rule that fired on it would be red on half the shipped tree
     * and would be narrowed until it was blind. A table the same migration
     * created is not an existing table, for the reason AC-33 gives above.
     *
     * @return list<array{rule: string, found: string}>
     */
    private static function foreignKeysOnlyInContract(string $code, MigrationPhase $phase): array
    {
        if ($phase === MigrationPhase::Contract) {
            return [];
        }

        $patterns = [
            '/->\s*foreign\s*\(/',
            '/->\s*constrained\s*\(/',
            '/\badd\s+(?:constraint\s+\S+\s+)?foreign\s+key\b/i',
        ];

        $found = [];
        $body = self::upBody($code);
        $created = self::createdTables($body);

        foreach (self::schemaBlocks($body) as $block) {
            if ($block['kind'] !== 'table' || in_array($block['table'], $created, true)) {
                continue;
            }

            foreach (self::matchesAgainst($block['body'], $patterns, 'AC-34') as $violation) {
                $found[] = [
                    'rule' => $violation['rule'],
                    'found' => $block['table'].': '.$violation['found'],
                ];
            }
        }

        return $found;
    }

    /**
     * AC-35(a): a decimal column states its precision.
     *
     * @return list<array{rule: string, found: string}>
     */
    private static function decimalStatesPrecision(string $code): array
    {
        $found = [];

        foreach (self::callsNamed($code, ['decimal', 'unsignedDecimal']) as $call) {
            if (count($call['arguments']) >= 2) {
                continue;
            }

            $found[] = ['rule' => 'AC-35(a)', 'found' => $call['snippet']];
        }

        return $found;
    }

    /**
     * AC-35(b): no native `enum` type.
     *
     * @return list<array{rule: string, found: string}>
     */
    private static function noNativeEnum(string $code): array
    {
        return self::matchesAgainst($code, ['/(?<![\w$])enum\s*\(/i'], 'AC-35(b)');
    }

    /**
     * AC-35(c): no `timestamp` column outside the eight Laravel system tables.
     *
     * `softDeletes()` is included: it declares `deleted_at` as a `TIMESTAMP`,
     * which is exactly the column type the rule exists to keep out of a business
     * table - the design's `[SOFT]` tag is `DATETIME(3)`.
     *
     * @return list<array{rule: string, found: string}>
     */
    private static function noTimestampColumn(string $code): array
    {
        $patterns = [
            '/->\s*timestamps?(?:Tz)?\s*\(/',
            '/->\s*nullableTimestamps\s*\(/',
            '/->\s*softDeletes(?:Tz)?\s*\(/',
        ];

        $found = [];
        $blocks = self::schemaBlocks($code);

        foreach ($blocks as $block) {
            if (in_array($block['table'], self::SYSTEM_TABLES, true)) {
                continue;
            }

            foreach (self::matchesAgainst($block['body'], $patterns, 'AC-35(c)') as $violation) {
                $found[] = [
                    'rule' => $violation['rule'],
                    'found' => $block['table'].': '.$violation['found'],
                ];
            }
        }

        // A declaration outside every block - in a raw statement, or in a helper
        // the migration defines itself - belongs to no table, so no table name
        // can exempt it.
        foreach (self::matchesAgainst(self::withoutBlocks($code), $patterns, 'AC-35(c)') as $violation) {
            $found[] = $violation;
        }

        return $found;
    }

    /**
     * AC-35(d), the migration half: nothing in a migration names a charset, a
     * collation or a storage engine.
     *
     * @return list<array{rule: string, found: string}>
     */
    private static function noCharsetCollationOrEngine(string $code): array
    {
        $found = self::matchesAgainst($code, self::CHARSET_SETTERS, 'AC-35(d)');

        foreach (self::charsetCollationAndEngineNames($code) as $name) {
            $found[] = ['rule' => 'AC-35(d)', 'found' => $name];
        }

        return $found;
    }

    /**
     * The calls and raw clauses that set a charset, a collation or a storage
     * engine.
     *
     * Needed beside the names below because a setter can be handed a name this
     * class has never heard of, or one held in a variable.
     *
     * @var list<string>
     */
    private const CHARSET_SETTERS = [
        // The framework's own setters, in their method and their property form.
        '/->\s*(?:charset|collation|collate)\s*\(/i',
        '/->\s*(?:charset|collation|engine)\s*=/i',
        '/->\s*engine\s*\(/i',
        // The raw table options the grammar would otherwise write itself.
        '/\bdefault\s+char(?:acter\s+set|set)\b/i',
        '/\bcollate\b/i',
        '/\bengine\s*=\s*\w+/i',
    ];

    /**
     * Every charset, collation or storage-engine *name* in code.
     *
     * Matched as a bare word rather than only as a quoted string, so a name
     * inside a raw SQL fragment is found too. A collation always begins with its
     * charset's name (`ascii_bin`, `utf8mb4_unicode_ci`), which is why one
     * pattern covers both.
     *
     * @return list<string>
     */
    private static function charsetCollationAndEngineNames(string $code): array
    {
        $patterns = [
            // `binary` is deliberately absent: it is also a column type, and
            // the master schema has four VARBINARY columns for IP addresses.
            '/\b(?:utf8|utf8mb3|utf8mb4|ascii|latin1|utf16|utf32)(?:_\w+)?\b/i',
            '/\b(?:innodb|myisam|aria|rocksdb|csv)\b/i',
        ];

        $found = [];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $code, $matches);

            foreach ($matches[0] as $match) {
                $found[] = trim((string) $match);
            }
        }

        return $found;
    }

    /**
     * AC-37: no cross-connection and no schema-qualified name.
     *
     * The schema-qualified half is applied to **both** trees. The criterion
     * words it for the tenant tree, where it is the tenant boundary being
     * crossed; a master migration naming `other_db.table` is the same statement
     * and there is no case for allowing it, so it is reported too.
     *
     * @return list<array{rule: string, found: string}>
     */
    private static function noCrossConnectionOrQualifiedName(string $code, MigrationTree $tree): array
    {
        $forbidden = $tree === MigrationTree::Tenant ? 'master' : 'tenant';

        $found = self::matchesAgainst(
            $code,
            ['/connection\s*\(\s*[\'"]'.$forbidden.'[\'"]\s*\)/i'],
            'AC-37'
        );

        foreach (self::schemaBlocks($code) as $block) {
            if (! str_contains($block['table'], '.')) {
                continue;
            }

            $found[] = ['rule' => 'AC-37', 'found' => 'schema-qualified table '.$block['table']];
        }

        return $found;
    }

    /**
     * AC-24, the source half: no tenant migration declares a `tenant_id`
     * column.
     *
     * Matched as a string literal in **code**, which is why the baseline can
     * explain in its own docblock why there is no such column: the reason for an
     * absent column belongs in the file that does not create it, and a naive
     * grep over raw text would report that sentence as a violation of the rule
     * it states. Any quoted `tenant_id` left in a tenant migration's code is a
     * violation whichever call it is passed to - a column, an index, a raw
     * statement or an inserted row - and the tenant tree has no legitimate use
     * for one.
     *
     * @return list<array{rule: string, found: string}>
     */
    private static function noTenantIdColumn(string $code, MigrationTree $tree): array
    {
        if ($tree !== MigrationTree::Tenant) {
            return [];
        }

        return self::matchesAgainst($code, ['/[\'"]tenant_id[\'"]/'], 'AC-24');
    }

    /**
     * The body of the migration's `up()` method, or the whole file when it
     * declares none.
     */
    private static function upBody(string $code): string
    {
        if (preg_match('/function\s+up\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{/', $code, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $code;
        }

        $open = (int) $matches[0][1] + strlen((string) $matches[0][0]) - 1;
        $close = self::matchingBracket($code, $open, '{', '}');

        return $close === null ? $code : substr($code, $open + 1, $close - $open - 1);
    }

    /**
     * Every `Schema::create()` / `Schema::table()` block in the given code.
     *
     * @return list<array{kind: string, table: string, body: string, from: int, to: int}>
     */
    private static function schemaBlocks(string $code): array
    {
        $pattern = '/Schema::\s*(create|table)\s*\(\s*([\'"])([^\'"]*)\2/';

        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
            return [];
        }

        $blocks = [];

        foreach ($matches as $match) {
            $from = (int) $match[0][1];
            $open = strpos($code, '(', $from);

            if ($open === false) {
                continue;
            }

            $close = self::matchingBracket($code, $open, '(', ')');

            if ($close === null) {
                continue;
            }

            $blocks[] = [
                'kind' => (string) $match[1][0],
                'table' => (string) $match[3][0],
                'body' => substr($code, $open + 1, $close - $open - 1),
                'from' => $from,
                'to' => $close,
            ];
        }

        return $blocks;
    }

    /**
     * The given code with every schema block blanked out, so that what is left
     * is every statement that belongs to no table.
     */
    private static function withoutBlocks(string $code): string
    {
        foreach (self::schemaBlocks($code) as $block) {
            $length = $block['to'] - $block['from'] + 1;
            $code = substr_replace($code, str_repeat(' ', $length), $block['from'], $length);
        }

        return $code;
    }

    /**
     * Every call to one of the named methods, with its top-level arguments.
     *
     * String literals and nested calls are skipped while the commas are
     * counted, so `decimal('a', $places)` is two arguments and
     * `decimal(implode(',', $x))` is one - the shape that defeated an earlier
     * assertion lint on this project.
     *
     * @param  list<string>  $names
     * @return list<array{name: string, arguments: list<string>, snippet: string}>
     */
    private static function callsNamed(string $code, array $names): array
    {
        $pattern = '/->\s*('.implode('|', array_map('preg_quote', $names)).')\s*\(/';

        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
            return [];
        }

        $calls = [];

        foreach ($matches as $match) {
            $open = (int) $match[0][1] + strlen((string) $match[0][0]) - 1;
            $close = self::matchingBracket($code, $open, '(', ')');

            if ($close === null) {
                continue;
            }

            $inner = substr($code, $open + 1, $close - $open - 1);

            $calls[] = [
                'name' => (string) $match[1][0],
                'arguments' => self::topLevelArguments($inner),
                'snippet' => '->'.$match[1][0].'('.$inner.')',
            ];
        }

        return $calls;
    }

    /**
     * The top-level arguments of an argument list.
     *
     * @return list<string>
     */
    private static function topLevelArguments(string $arguments): array
    {
        if (trim($arguments) === '') {
            return [];
        }

        $parts = [''];
        $depth = 0;
        $quote = null;
        $length = strlen($arguments);

        for ($position = 0; $position < $length; $position++) {
            $character = $arguments[$position];

            if ($quote !== null) {
                $parts[count($parts) - 1] .= $character;

                if ($character === '\\') {
                    $position++;

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '\'' || $character === '"') {
                $quote = $character;
                $parts[count($parts) - 1] .= $character;

                continue;
            }

            if (in_array($character, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($character, [')', ']', '}'], true)) {
                $depth--;
            }

            if ($character === ',' && $depth === 0) {
                $parts[] = '';

                continue;
            }

            $parts[count($parts) - 1] .= $character;
        }

        return array_map('trim', $parts);
    }

    /**
     * The position of the bracket that closes the one at `$open`, or null when
     * the code is unbalanced.
     *
     * String literals are skipped, so a bracket inside a quoted SQL fragment
     * does not move the depth.
     */
    private static function matchingBracket(string $code, int $open, string $opener, string $closer): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($code);

        for ($position = $open; $position < $length; $position++) {
            $character = $code[$position];

            if ($quote !== null) {
                if ($character === '\\') {
                    $position++;

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '\'' || $character === '"') {
                $quote = $character;

                continue;
            }

            if ($character === $opener) {
                $depth++;

                continue;
            }

            if ($character === $closer) {
                $depth--;

                if ($depth === 0) {
                    return $position;
                }
            }
        }

        return null;
    }

    /**
     * Every match of the given patterns, labelled with the rule they belong to.
     *
     * @param  list<string>  $patterns
     * @return list<array{rule: string, found: string}>
     */
    private static function matchesAgainst(string $code, array $patterns, string $rule): array
    {
        $found = [];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $code, $matches);

            foreach ($matches[0] as $match) {
                $found[] = ['rule' => $rule, 'found' => trim((string) $match)];
            }
        }

        return $found;
    }
}
