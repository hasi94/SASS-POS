<?php

/*
 * T-004 FP-155 (AC-3).
 *
 * The unit half of the `toolchain` group's decay guard: the detector's own
 * behaviour, over synthetic strings and synthetic lists, with no disk access and
 * no child process. ToolchainGroupCoverageTest applies the same functions to the
 * real repository.
 *
 * Every source string below is assembled at runtime from the keys of
 * toolchainSpawnTokens(), never written out as a literal. A test file that
 * contained a real spawn token would be reported as a spawner by the sweep in
 * ToolchainGroupCoverageTest and would then have to be exempted - which is
 * exactly the kind of exemption that hides a genuine gap. Building the tokens
 * also means this test covers every token the detector knows, including any
 * added later, instead of the two somebody thought to write down.
 */

it('reports a source that starts a child process, one token at a time', function (string $token) {
    $source = '<?php $result = '.$token.'anything);';

    expect(toolchainSourceSpawns($source))->toBeTrue(
        'the detector missed the token '.$token.', so a new child-process test using it would silently rejoin the fast loop'
    );
})->with(array_keys(toolchainSpawnTokens()));

it('reports a source that starts no child process', function () {
    // The negative control: without it, "no spawner found" would be
    // indistinguishable from a detector that reports nothing at all.
    $source = <<<'PHP'
        <?php

        it('does arithmetic', function () {
            expect(1 + 1)->toBe(2);
        });
        PHP;

    expect(toolchainSourceSpawns($source))->toBeFalse();
});

it('does not mistake a method or static call for a bare process function', function () {
    // The measured trap: /\bexec\s*\(/ also matches $pdo->exec('create table
    // ...'), which would sweep the whole tenancy fixture into the toolchain
    // group. Every function-shaped token is checked, so a token added later
    // without the lookbehind is caught here.
    $functionTokens = array_values(array_filter(
        array_keys(toolchainSpawnTokens()),
        static fn (string $token): bool => ! str_contains($token, ':')
    ));

    expect($functionTokens)->not->toBe([]);

    foreach ($functionTokens as $token) {
        foreach (['$pdo->', 'SomeClass::', '$this->'] as $receiver) {
            expect(toolchainSourceSpawns('<?php '.$receiver.$token.'"select 1");'))->toBeFalse(
                'the detector treated '.$receiver.$token.' as a child process'
            );
        }
    }
});

it('subtracts the exemption list from the spawners and nothing else', function () {
    $exempt = toolchainGroupExemptions();

    expect($exempt)->not->toBe([]);

    $spawners = array_merge(['a/tests/OneTest.php', 'b/tests/TwoTest.php'], $exempt);

    sort($spawners);

    expect(toolchainExpectedGroupFiles($spawners))
        ->toBe(['a/tests/OneTest.php', 'b/tests/TwoTest.php']);

    // Nothing is subtracted that was not exempt.
    expect(toolchainExpectedGroupFiles(['a/tests/OneTest.php']))->toBe(['a/tests/OneTest.php']);
});

it('reports a registry or exemption entry that names no file on disk', function () {
    $missing = 'services/host/tests/Feature/NoSuchFileWasEverCommittedTest.php';

    expect(toolchainMissingPaths([$missing]))->toBe([$missing]);

    // And says nothing about a path that does exist, so the check is not simply
    // always positive.
    expect(toolchainMissingPaths(['services/host/tests/Helpers.php']))->toBe([]);
});

it('computes the repository root from its own location, not from the container', function () {
    // Pest.php calls toolchainGroupFiles() at bootstrap time, before the
    // application exists, so this must never start depending on base_path().
    expect(is_file(toolchainRepositoryRoot().DIRECTORY_SEPARATOR.'.gitattributes'))->toBeTrue()
        ->and(is_dir(toolchainRepositoryRoot().DIRECTORY_SEPARATOR.'services'.DIRECTORY_SEPARATOR.'host'))->toBeTrue();
});
