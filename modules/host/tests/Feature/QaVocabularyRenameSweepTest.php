<?php

use Symfony\Component\Finder\Finder;

/*
 * T-005 AC-5 and AC-6: the standing proof that the pre-T-005 vocabulary is gone.
 *
 * The defining risk of a repository-wide rename is a stale reference that
 * nothing executes: the suite stays green while the tree carries two
 * vocabularies. No behavioural test can see that, so this file searches every
 * file in the repository - by relative path AND by contents - for a fixed list
 * of the old identifiers, and asserts the result is empty.
 *
 * An assertion that nothing was found is worthless without a control, so there
 * are three, each a separate case below:
 *
 *   1. MECHANISM. A synthetic subject built from the token list, and a synthetic
 *      path, are both reported. Case sensitivity is pinned in both directions.
 *   2. FRAGMENTS, against text nobody here wrote. Every token except one is
 *      demonstrated on a historical record the sweep excludes - prose written
 *      long before this token list existed. A mis-assembled fragment matches a
 *      synthetic subject built from the same fragment but cannot match an
 *      independent document, which is why control 1 alone would prove nothing.
 *      This is AC-6.
 *   3. CORPUS. The enumeration is non-empty, holds named files that must be in
 *      it, and holds nothing from a dependency directory. A Finder that silently
 *      scanned an empty tree fails here.
 *
 * Two structural points, both deliberate:
 *
 * - THE TOKENS ARE ASSEMBLED FROM FRAGMENTS and never written whole, so this
 *   file is swept like any other instead of being exempted from its own check -
 *   the same technique as toolchainSpawnTokens() in tests/Helpers.php. The
 *   comments obey the same rule, which is why they say "the old word" rather
 *   than spelling it.
 * - THE FRAMEWORK'S OWN WORD IS NOT ON THE LIST (decision D-44). Every
 *   *ServiceProvider class name, Illuminate\Support\ServiceProvider,
 *   config/services.php and src/Services/ inside a generated module are current,
 *   correct names. Measured while writing this: config/services.php produces 0
 *   hits under this list, so it is swept rather than exempted - the trap AC-4
 *   names is handled by the pattern, not by a hole in the corpus.
 *
 * This file spawns no child process, so it is deliberately absent from
 * toolchainGroupFiles(): the one cheap completeness check in the suite must also
 * run in `composer test:fast`. Cost, measured 2026-10-02: 234 files enumerated,
 * 201 swept after exemptions, 1.26 MB read, 0.41 s - under 0.5 % of a full run.
 */

/**
 * The fragments every token is built from.
 *
 * @return array{lower: non-empty-string, studly: non-empty-string, screaming: non-empty-string, short: non-empty-string}
 */
function qaVocabFragments(): array
{
    return [
        'lower' => 'ser'.'vice',
        'studly' => 'Ser'.'vice',
        'screaming' => 'SER'.'VICE',
        'short' => 'sv'.'c',
    ];
}

/**
 * The old identifiers that are matched literally and case-sensitively.
 *
 * Keys are descriptions rather than the identifiers themselves, so that a
 * failure message can name what was found without this file carrying the word.
 *
 * @return array<string, non-empty-string>
 */
function qaVocabLiteralTokens(): array
{
    ['lower' => $lower, 'studly' => $studly, 'screaming' => $screaming, 'short' => $short] = qaVocabFragments();

    return [
        'the former top-level directory' => $lower.'s/',
        'the former environment variable' => $screaming.'S',
        'the former generator signature' => $short.':make',
        'the former generator class prefix' => ucfirst($short).'Make',
        'the former support namespace segment' => $studly.'Host',
        'the former registry class' => $studly.'Registry',
        'the former configuration file stem' => $lower.'host',
        'the former unknown-name exception' => 'Unknown'.$studly.'Exception',
        'the former package name prefix' => 'flexipos/'.$lower.'-',
        'the former host package keyword' => $lower.'-host',
        'the former boundary layer prefix' => $studly.'_',
        'the former first boundary fixture' => $studly.'A',
        'the former second boundary fixture' => $studly.'B',
        'the former stub directory' => 'stubs/'.$lower,
        'the former path helper' => $lower.'sPath',
        'the former singular path helper' => $lower.'Path',
        'the former dotted manifest key' => 'flexipos.'.$lower,
        'the former phpunit suite label' => 'name="'.$studly.'s"',
    ];
}

/**
 * The one token matched case-insensitively: the old three-letter abbreviation.
 *
 * Case-insensitive because it was spelled three ways. It is the reason the
 * allowance list exists at all - see qaVocabAllowances().
 *
 * @return array<string, non-empty-string>
 */
function qaVocabCaselessTokens(): array
{
    return [
        'the former abbreviation' => qaVocabFragments()['short'],
    ];
}

/**
 * The two old identifiers that need a regular expression.
 *
 * The first catches the old namespace root in both its source and its escaped
 * form, which differ only in the number of backslashes. The second catches the
 * old configuration key and the old health-response field - the quotes are what
 * separate them from ordinary English prose, which no pattern could safely
 * claim. The third catches the singular spelling in KEY position only - a quote,
 * the word, a quote, then a colon, an arrow or a closing bracket - because the
 * bare quoted singular is NOT safe: MEASURED 2026-10-02, it reports a sentence of
 * ordinary prose in docs/backlog.md, and its trailing-quote half reports
 * composer.lock ("web service") and docs/database.md (enum(user, terminal,
 * service)). In key position the same search reports nothing. The fourth catches
 * the five test class names whose old word is followed by a word character, which
 * none of the literals above can reach.
 *
 * @return array<string, non-empty-string>
 */
function qaVocabPatternTokens(): array
{
    ['lower' => $lower, 'studly' => $studly] = qaVocabFragments();

    return [
        'the former namespace root' => '/Flexipos.{1,2}'.$studly.'/',
        'the former quoted key or JSON field' => '/[\'"]'.$lower.'s[\'"]/',
        'the former quoted singular key' => '/[\'"]'.$lower.'[\'"]\s*(?::|=>|\])/',
        'the former test class names' => '/(?:'.$studly.'sEnvParsing|'.$studly.'NameValidation|Unlisted'.$studly.'Returns404|'.
            'Generated'.$studly.'(?:TestDiscovery|Reachable))Test/',
    ];
}

/**
 * Every label the sweep can report.
 *
 * @return list<string>
 */
function qaVocabLabels(): array
{
    $labels = array_keys(array_merge(
        qaVocabLiteralTokens(),
        qaVocabCaselessTokens(),
        qaVocabPatternTokens(),
    ));

    sort($labels);

    return $labels;
}

/**
 * Justified survivals of the old vocabulary: the literal, where it is allowed,
 * and why.
 *
 * Each literal is redacted from the contents of the named files before matching,
 * and ONLY of those files. Every entry is asserted to be findable on disk in
 * every path it names (see the allowance case below), so an allowance that has
 * outlived its reason fails loudly instead of quietly widening the hole.
 *
 * MEASURED while writing this, correcting the plan's allowance table: the first
 * row covers 5 occurrences in 3 files, not 1 occurrence in 1 file. Only one of
 * them is the dead replacement key the plan names. Three are live assertions
 * that the old per-module connection prefix is ABSENT from a generated module -
 * renaming those would delete the assertion rather than update it - and one is a
 * specimen of a bad assertion shape held as a fixture. So the allowance covers
 * the prefix, not the one full name.
 *
 * @return list<array{literal: non-empty-string, why: string, paths: non-empty-list<string>}>
 */
function qaVocabAllowances(): array
{
    $lower = qaVocabFragments()['lower'];
    $short = qaVocabFragments()['short'];
    $studly = qaVocabFragments()['studly'];

    // The developer's rename recipe quotes the five old test class names because
    // quoting them is its whole point: it records what a word-boundary rule cannot
    // reach. One row per name, so each is asserted live on its own.
    $recipe = [];

    foreach ([
        $studly.'sEnvParsingTest',
        'Qa'.$studly.'NameValidationTest',
        'Unlisted'.$studly.'Returns404Test',
        'QaGenerated'.$studly.'TestDiscoveryTest',
        'Generated'.$studly.'ReachableTest',
    ] as $name) {
        $recipe[] = [
            'literal' => $name,
            'why' => 'the rename recipe quotes the old test class names, which is its point (review W-1)',
            'paths' => ['.claude/agent-memory/developer/project_repo_wide_rename_recipe.md'],
        ];
    }

    return array_merge($recipe, [
        [
            'literal' => $short.'_',
            'why' => 'the removed per-module connection prefix (T-003): one dead replacement key the '.
                'T-005 spec puts out of scope, one bad-shape specimen held as a fixture, and three live '.
                'assertions that the prefix is absent from a generated module',
            'paths' => [
                'modules/host/tests/Feature/QaAssertionShapeGuardTest.php',
                'modules/host/tests/Feature/QaModuleConnectionRemovalTest.php',
                'modules/host/tests/Feature/QaTestCodeAnalysisCoverageTest.php',
            ],
        ],
        [
            'literal' => 'ai/T-001-monorepo-'.$lower.'-host',
            'why' => 'a branch name is a fact about the repository and cannot be rewritten',
            'paths' => ['docs/backlog.md'],
        ],
        [
            'literal' => 'ai/T-002-'.$lower.'-host-hardening',
            'why' => 'a branch name is a fact about the repository and cannot be rewritten',
            'paths' => ['docs/backlog.md'],
        ],
        [
            'literal' => 'T-001-monorepo-'.$lower.'-host.md',
            'why' => 'the file name of a historical record, immutable for the same reason as the record itself (D-52)',
            'paths' => [
                '.claude/agent-memory/qa-engineer/project_module-host-fragile-areas.md',
                'modules/host/tests/Feature/QaGeneratedModuleTestDiscoveryTest.php',
                'modules/host/tests/Feature/QaTestSuiteIsolationTest.php',
            ],
        ],
        [
            'literal' => 'T-002-'.$lower.'-host-hardening.md',
            'why' => 'the file name of a historical record, immutable for the same reason as the record itself (D-52)',
            'paths' => ['.claude/agent-memory/qa-engineer/project_module-host-fragile-areas.md'],
        ],
    ]);
}

/**
 * Paths the sweep does not look at, and why, each asserted to exist on disk.
 *
 * AC-5 allows exactly three kinds: the product owner's document set, which this
 * task must not touch; the T-001 to T-004 records, whose substance is the
 * reasoning of the time and would be falsified by a rewrite (D-52), together
 * with T-005's own records, which necessarily quote what is being renamed; and
 * CLAUDE.md, the lead's file, which AC-25 requires to stay byte-identical.
 *
 * "Records" means ALL SIX hand-off folders, not the five that already held a T-005
 * file. docs/releases is the sixth: a release note or PR description for this very
 * task has to name the old directory, the old environment variable and the old
 * namespace to say what changed, so leaving it swept would turn the gate red at
 * the release step through no fault of its author (review R-3). The case that
 * keeps the exemptions live pins the six that exist today, so none of them can be
 * dropped; a seventh hand-off folder still has to be added here by hand.
 *
 * Dependency and build directories are NOT in this list: they are pruned by name
 * in qaVocabCorpus() and their absence cannot hide a tracked file, so an
 * existence assertion on them would only couple this test to whether anyone has
 * run `npm ci` yet.
 *
 * @return list<string>
 */
function qaVocabExemptPaths(): array
{
    return [
        'CLAUDE.md',
        'Refference',
        'docs/decisions',
        'docs/plans',
        'docs/qa',
        'docs/releases',
        'docs/reviews',
        'docs/specs',
    ];
}

/**
 * Whether a repository-relative path is exempt.
 */
function qaVocabIsExempt(string $path): bool
{
    foreach (qaVocabExemptPaths() as $exempt) {
        if ($path === $exempt || str_starts_with($path, $exempt.'/')) {
            return true;
        }
    }

    return false;
}

/**
 * Every file the sweep reads, as repository-relative path with forward slashes
 * => contents.
 *
 * Enumerated with Finder rather than by asking git, because a child process
 * would put this file in the `toolchain` group (T-004 D-37) and take the one
 * cheap completeness check out of `composer test:fast`.
 *
 * ignoreVCSIgnored() makes the corpus the tracked set plus anything untracked
 * and not ignored. MEASURED: that is exactly `git ls-files` plus T-005's own
 * three records, which the exemptions remove anyway. It matters in both
 * directions - without it the machine-local, git-ignored .claude/settings
 * file is swept, which would make this test red on one developer's machine and
 * green in CI; with it, a stray directory of the old name left in a working tree
 * is still caught, because an untracked file nothing ignores is in the corpus.
 *
 * `vendor` and `node_modules` are pruned by name as well: ignoreVCSIgnored()
 * alone leaves the path-repository symlinks under vendor/ behind and makes the
 * enumeration ten times slower by walking the whole dependency tree first.
 *
 * @return array<string, string>
 */
function qaVocabCorpus(): array
{
    /** @var array<string, string>|null $corpus */
    static $corpus = null;

    if ($corpus !== null) {
        return $corpus;
    }

    $root = toolchainRepositoryRoot();
    $files = Finder::create()
        ->in($root)
        ->files()
        ->exclude(['vendor', 'node_modules'])
        ->ignoreDotFiles(false)
        ->ignoreVCS(true)
        ->ignoreVCSIgnored(true);

    // Symlinks are deliberately not followed - Finder's default. The host's
    // vendor/flexipos/* entries are symlinks into this repository, so following
    // them would read half the tree twice under a path that is pruned anyway.
    $corpus = [];

    foreach ($files as $file) {
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if (qaVocabIsExempt($path)) {
            continue;
        }

        $corpus[$path] = (string) file_get_contents($file->getPathname());
    }

    ksort($corpus);

    return $corpus;
}

/**
 * Every old identifier in a piece of text, as label => 1-based line number of
 * its first occurrence.
 *
 * @return array<string, int>
 */
function qaVocabFound(string $subject): array
{
    $found = [];

    foreach (qaVocabLiteralTokens() as $label => $token) {
        $offset = strpos($subject, $token);

        if ($offset !== false) {
            $found[$label] = substr_count(substr($subject, 0, $offset), "\n") + 1;
        }
    }

    foreach (qaVocabCaselessTokens() as $label => $token) {
        $offset = stripos($subject, $token);

        if ($offset !== false) {
            $found[$label] = substr_count(substr($subject, 0, $offset), "\n") + 1;
        }
    }

    foreach (qaVocabPatternTokens() as $label => $pattern) {
        if (preg_match($pattern, $subject, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int) $matches[0][1];
            $found[$label] = substr_count(substr($subject, 0, $offset), "\n") + 1;
        }
    }

    ksort($found);

    return $found;
}

/**
 * The contents of one file with its justified survivals blanked out.
 *
 * Replaced with same-length filler rather than removed, so the line numbers in a
 * failure message still point at the real line.
 */
function qaVocabRedact(string $path, string $contents): string
{
    foreach (qaVocabAllowances() as $allowance) {
        if (! in_array($path, $allowance['paths'], true)) {
            continue;
        }

        $contents = str_replace(
            $allowance['literal'],
            str_repeat('#', strlen($allowance['literal'])),
            $contents
        );
    }

    return $contents;
}

/**
 * The sweep itself: every surviving old identifier in the repository, as a list
 * of human-readable findings.
 *
 * @return list<string>
 */
function qaVocabSweep(): array
{
    $findings = [];

    foreach (qaVocabCorpus() as $path => $contents) {
        foreach (array_keys(qaVocabFound($path)) as $label) {
            $findings[] = $path.' (in its path): '.$label;
        }

        foreach (qaVocabFound(qaVocabRedact($path, $contents)) as $label => $line) {
            $findings[] = $path.':'.$line.': '.$label;
        }
    }

    sort($findings);

    return $findings;
}

/**
 * The historical records used as independent witnesses, and the labels each one
 * must still demonstrate.
 *
 * Both are excluded from the sweep and tracked in git. Untracked files are
 * deliberately not used here: a control that depends on a file nobody has
 * committed yet would fail in a fresh clone of this branch.
 *
 * @return array<string, list<string>>
 */
function qaVocabWitnesses(): array
{
    $lower = qaVocabFragments()['lower'];

    return [
        'docs/plans/T-001-monorepo-'.$lower.'-host.md' => [
            'the former abbreviation',
            'the former boundary layer prefix',
            'the former configuration file stem',
            'the former environment variable',
            'the former first boundary fixture',
            'the former generator class prefix',
            'the former generator signature',
            'the former host package keyword',
            'the former namespace root',
            'the former package name prefix',
            'the former quoted key or JSON field',
            'the former registry class',
            'the former second boundary fixture',
            'the former stub directory',
            'the former support namespace segment',
            'the former top-level directory',
            'the former unknown-name exception',
        ],
        'docs/qa/T-001-monorepo-'.$lower.'-host.md' => [
            'the former quoted singular key',
            'the former test class names',
        ],
        'docs/qa/T-002-'.$lower.'-host-hardening.md' => [
            'the former path helper',
            'the former singular path helper',
        ],
    ];
}

it('reports a synthetic old identifier, in a path and in contents, case-sensitively', function () {
    // Control 1, the mechanism. Every token must fire on a subject that holds
    // it, the two regular expressions must compile, and the case rules must hold
    // in BOTH directions - a sweep that had quietly become case-insensitive
    // everywhere would flag the current vocabulary, and one that had become
    // case-sensitive everywhere would stop catching the old abbreviation.
    $all = implode("\n", array_merge(
        array_values(qaVocabLiteralTokens()),
        array_values(qaVocabCaselessTokens()),
        [
            'Flexipos\\'.qaVocabFragments()['studly'].'\\Demo',
            "'".qaVocabFragments()['lower']."s'",
            '"'.qaVocabFragments()['lower'].'": "demo"',
            qaVocabFragments()['studly'].'sEnvParsingTest',
        ],
    ));

    expect(array_keys(qaVocabFound($all)))->toBe(qaVocabLabels());

    // The two W-1 patterns, one example at a time. The class-name pattern is an
    // alternation, so one example per branch: a branch with a typo in it would
    // otherwise hide behind the branches that work.
    $studly = qaVocabFragments()['studly'];
    $lower = qaVocabFragments()['lower'];

    foreach ([
        $studly.'sEnvParsingTest',
        'Qa'.$studly.'NameValidationTest',
        'Unlisted'.$studly.'Returns404Test',
        'QaGenerated'.$studly.'TestDiscoveryTest',
        'Generated'.$studly.'ReachableTest',
    ] as $oldClass) {
        expect(array_keys(qaVocabFound('class '.$oldClass.' extends TestCase')))->toBe(['the former test class names']);
    }

    // Key position is caught in all three spellings; the same word as plain
    // English, quoted or at the end of a quoted phrase, is not.
    foreach (['"'.$lower.'": 1', "'".$lower."' => 1", '$m[\''.$lower.'\']'] as $oldKey) {
        expect(array_keys(qaVocabFound($oldKey)))->toBe(['the former quoted singular key']);
    }

    foreach (['the vocabulary says "'.$lower.'" where', '"web '.$lower.'"', 'enum(user, terminal, '.$lower.')'] as $prose) {
        expect(qaVocabFound($prose))->toBe([]);
    }

    // A path, not contents: the old directory name has to be caught even in a
    // file whose text is clean.
    $path = qaVocabFragments()['lower'].'s/host/app/Support/'
        .qaVocabFragments()['studly'].'Host/'
        .qaVocabFragments()['studly'].'Registry.php';

    expect(array_keys(qaVocabFound($path)))->toBe([
        'the former registry class',
        'the former support namespace segment',
        'the former top-level directory',
    ]);

    // Upper-cased, so only the screaming form of the old word is left: the two
    // studly tokens and the lower-case directory token must all stop matching.
    expect(array_keys(qaVocabFound(strtoupper($path))))->toBe([
        'the former environment variable',
    ]);

    // Lower-cased, so the studly tokens disappear and the lower-case ones appear.
    expect(array_keys(qaVocabFound(strtolower($path))))->toBe([
        'the former configuration file stem',
        'the former top-level directory',
    ]);

    // The other direction of the case rule: the old abbreviation is the one
    // token that must match whichever of its three historical spellings it is
    // written in, and must bring no other label with it.
    $short = qaVocabFragments()['short'];
    $spellings = [];

    foreach ([strtolower($short), strtoupper($short), ucfirst($short)] as $spelling) {
        $spellings[$spelling] = array_keys(qaVocabFound($spelling.'_demo'));
    }

    expect($spellings)->toBe([
        strtolower($short) => ['the former abbreviation'],
        strtoupper($short) => ['the former abbreviation'],
        ucfirst($short) => ['the former abbreviation'],
    ]);

    // And a subject in the CURRENT vocabulary must be clean - a token list that
    // flagged the new names would make the sweep unusable.
    expect(qaVocabFound(
        "modules/host/app/Support/ModuleHost/ModuleRegistry.php\n"
        ."Flexipos\\Module\\Demo\\DemoServiceProvider\n"
        ."MODULES=demo module:make Module_Demo flexipos/module-demo stubs/module\n"
        ."Illuminate\\Support\\ServiceProvider config/services.php src/Services/\n"
        .'extra.flexipos.module $this->modulePath() ModulesEnvParsingTest {"module": "demo"}'."\n"
    ))->toBe([]);
});

it('finds the old identifiers in the historical records it excludes', function () {
    // Control 2, AC-6, and the only control that can catch a mis-assembled
    // fragment: these documents were written before this token list existed, so a
    // typo in a fragment cannot match them. Together they must demonstrate every
    // label but one.
    $demonstrated = [];

    foreach (qaVocabWitnesses() as $path => $expected) {
        $file = toolchainRepositoryRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);

        expect(is_file($file))->toBeTrue('the witness record '.$path.' is gone, so control 2 proves nothing');

        $found = array_keys(qaVocabFound((string) file_get_contents($file)));

        expect(array_values(array_intersect($expected, $found)))->toBe(
            $expected,
            'the historical record '.$path.' no longer demonstrates every old identifier it is cited for, '.
            'so a fragment of the token list may be mis-assembled. Missing: '
            .implode(', ', array_diff($expected, $found))
        );

        $demonstrated = array_merge($demonstrated, $found);
    }

    $demonstrated = array_values(array_unique($demonstrated));
    sort($demonstrated);

    // Stated rather than discovered later: the phpunit suite label and the dotted
    // manifest key are the two tokens with no independent witness in a tracked
    // record - nothing outside T-005's own documents ever quoted them. Their
    // fragments are covered
    // compositionally: the studly fragment is witnessed by eight other tokens
    // above, and the surrounding literal is written out in full in this file
    // because it carries no old identifier of its own.
    expect(array_values(array_diff(qaVocabLabels(), $demonstrated)))->toBe([
        'the former dotted manifest key',
        'the former phpunit suite label',
    ]);
});

it('sweeps a corpus that really holds the repository', function () {
    // Control 3. "No matches" from an empty enumeration is the same output as
    // "no matches" from a clean repository.
    $corpus = qaVocabCorpus();

    expect(count($corpus))->toBeGreaterThan(150);

    // Named files that must be in it: the two manifests, the lock file, the
    // generated-module stubs, both READMEs, the forward-looking documents, agent
    // memory (D-53), the dotfiles, the kernel package, and the stock Laravel
    // third-party credential file - which is swept, not exempted, because the
    // token list is precise enough not to need an exemption for it.
    $required = [
        '.claude/agent-memory/developer/MEMORY.md',
        '.claude/agent-memory/qa-engineer/MEMORY.md',
        '.editorconfig',
        '.gitattributes',
        '.gitignore',
        'README.md',
        'docs/architecture.md',
        'docs/backlog.md',
        'docs/database.md',
        'docs/deployment-vps.md',
        'modules/demo/composer.json',
        'modules/host/.env.example',
        'modules/host/README.md',
        'modules/host/composer.json',
        'modules/host/composer.lock',
        'modules/host/config/services.php',
        'modules/host/deptrac.yaml',
        'modules/host/phpstan.neon.dist',
        'modules/host/phpunit.xml',
        'modules/host/stubs/module/composer.json.stub',
        'modules/host/tests/Helpers.php',
        'packages/kernel/src/Tenancy/Exceptions/TenancyException.php',
    ];

    expect(array_values(array_diff($required, array_keys($corpus))))->toBe(
        [],
        'the sweep is not reading files it is supposed to read'
    );

    // This file is in its own corpus: it is swept like any other, which is what
    // the fragment assembly above buys.
    expect($corpus)->toHaveKey('modules/host/tests/Feature/QaVocabularyRenameSweepTest.php');

    // Nothing from a dependency tree, and nothing from the exemptions.
    $strays = [];

    foreach (array_keys($corpus) as $path) {
        if (str_contains($path, 'vendor/') || str_contains($path, 'node_modules/') || qaVocabIsExempt($path)) {
            $strays[] = $path;
        }
    }

    expect($strays)->toBe([], 'the corpus holds files it should have skipped: '.implode(', ', $strays));

    // The corpus is not empty on the inside either - a Finder returning stubs
    // with unreadable contents would pass everything above.
    expect(array_sum(array_map('strlen', $corpus)))->toBeGreaterThan(500_000);
});

it('keeps every allowance and every exemption live', function () {
    // An allowance for a literal that no longer exists, or an exemption for a
    // path that no longer exists, is a hole nobody can see. Both are asserted
    // against the disk, the qaFixturePackageNames() discipline.
    $stale = [];

    foreach (qaVocabAllowances() as $allowance) {
        foreach ($allowance['paths'] as $path) {
            $file = toolchainRepositoryRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);

            if (! is_file($file)) {
                $stale[] = $path.' does not exist';

                continue;
            }

            if (! str_contains((string) file_get_contents($file), $allowance['literal'])) {
                $stale[] = $path.' no longer contains the literal allowed for it ('.$allowance['why'].')';
            }
        }
    }

    expect($stale)->toBe(
        [],
        'an allowance has outlived its reason; remove the row instead of leaving it to widen the sweep. '
        .implode('; ', $stale)
    );

    $missing = [];

    foreach (qaVocabExemptPaths() as $path) {
        $full = toolchainRepositoryRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);

        if (! is_file($full) && ! is_dir($full)) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([], 'an exemption names a path that no longer exists: '.implode(', ', $missing));

    // The hand-off folders are exempt as a SET (R-3): the release folder was the
    // one missing, and it only mattered at the next role's step.
    $handOff = ['decisions', 'plans', 'qa', 'releases', 'reviews', 'specs'];

    expect(array_values(array_filter(
        $handOff,
        static fn (string $folder): bool => ! qaVocabIsExempt('docs/'.$folder.'/T-005-any-record.md')
    )))->toBe([], 'a hand-off folder is swept, so the role that writes there turns the gate red');

    // The match is a path-segment match, not a prefix match: a sibling that merely
    // starts with an exempt name must still be swept.
    expect(qaVocabIsExempt('docs/releases-draft/note.md'))->toBeFalse();

    // Every allowance must be needed: a row that redacts nothing the sweep would
    // otherwise report is dead weight pretending to be a justification.
    $unnecessary = [];

    foreach (qaVocabAllowances() as $allowance) {
        foreach ($allowance['paths'] as $path) {
            $contents = qaVocabCorpus()[$path] ?? '';

            if (qaVocabFound($contents) === []) {
                $unnecessary[] = $path.' needs no allowance any more ('.$allowance['why'].')';
            }
        }
    }

    expect($unnecessary)->toBe([], 'a redundant allowance: '.implode('; ', $unnecessary));
});

it('has no file whose name or contents carries the old vocabulary', function () {
    // AC-5. The whole point of the three controls above is that this empty
    // result is evidence.
    $findings = qaVocabSweep();

    expect($findings)->toBe(
        [],
        'the pre-T-005 vocabulary survives in '.count($findings).' place(s). Each is either a rename that '.
        'was missed - fix it - or a justified survival, which belongs in qaVocabAllowances() with a reason. '
        .implode(' | ', $findings)
    );
});
