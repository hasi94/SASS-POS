<?php

use Symfony\Component\Finder\Finder;

/*
 * A lint for the one assertion shape that silently stops testing anything.
 *
 * Pest's toContain() takes VARIADIC NEEDLES, not a message; toHaveKey() takes
 * ($key, $value); toHaveProperty() takes ($name, $value, $message) - so even its
 * *second* argument is a value; toThrow() takes ($class, $message). Negate any
 * of them and the assertion passes as soon as *one* argument does not match - so
 *
 *     expect($output)->not->toContain($secret, 'CliDumper');
 *
 * reads like "the secret is absent, and label this check CliDumper" and
 * actually means "at least one of these two is absent", which the label always
 * satisfies. It went green in T-003 QA while the credential really was in the
 * output, in two leak tests at once. Nothing in PHPStan, Pint or Pest reports
 * it, and the failure mode is a test that passes forever.
 *
 * This is a lint over test sources, so it is cheap, and it is written with its
 * own negative control below: a detector for an assertion that never fires
 * would be the same class of mistake.
 */

/**
 * Every test file in the repository.
 *
 * @return array<string, string>
 */
function qaShapeTestFiles(): array
{
    $repository = dirname(base_path());
    $roots = [];

    foreach ([$repository, dirname($repository).DIRECTORY_SEPARATOR.'packages'] as $group) {
        foreach (glob($group.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'tests', GLOB_ONLYDIR) ?: [] as $directory) {
            $roots[] = $directory;
        }
    }

    $files = [];

    foreach ($roots as $root) {
        foreach (Finder::create()->in($root)->files()->name('*.php') as $file) {
            // This file is the specimen jar: it holds the bad shapes on purpose,
            // in the docblock and in the negative control below. Excluded by
            // exact basename rather than by a substring, so a real test file
            // cannot slip out of the sweep by being named something similar.
            if ($file->getFilename() === 'QaAssertionShapeGuardTest.php') {
                continue;
            }

            $files[str_replace('\\', '/', $file->getPathname())] = (string) file_get_contents($file->getPathname());
        }
    }

    return $files;
}

/**
 * Negated multi-argument calls to a matcher whose extra argument is a value,
 * not a message.
 *
 * String literals and nested parentheses are removed from the argument text
 * before commas are counted, so `not->toContain(implode(', ', $x))` and
 * `not->toContain('a, b')` are correctly seen as single-argument calls.
 *
 * @return list<string>
 */
function qaShapeAmbiguousAssertions(string $source): array
{
    $found = [];
    $pattern = '/->not->(toContain|toHaveKey|toHaveProperty|toThrow)\(/';

    if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
        return $found;
    }

    foreach ($matches[0] as $index => $match) {
        $matcher = (string) $matches[1][$index][0];
        $start = (int) $match[1] + strlen((string) $match[0]);
        $depth = 1;
        $arguments = '';
        $quote = null;
        $length = strlen($source);

        for ($position = $start; $position < $length && $depth > 0; $position++) {
            $character = $source[$position];

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

            if ($character === "'" || $character === '"') {
                $quote = $character;

                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            }

            if ($depth === 1 && $character !== ')') {
                $arguments .= $character;
            }
        }

        if (str_contains($arguments, ',')) {
            $line = substr_count(substr($source, 0, $start), "\n") + 1;
            $found[] = 'line '.$line.': ->not->'.$matcher.'(...,...)';
        }
    }

    return $found;
}

it('detects an ambiguous negated assertion, and only an ambiguous one', function () {
    // The negative control, first: a detector that never fires would pass the
    // real check below for the wrong reason.
    $ambiguous = <<<'PHP'
        expect($output)->not->toContain($secret, 'CliDumper');
        expect($manifest)->not->toHaveKey('connection', 'svc_demo');
        expect($record)->not->toHaveProperty('password', 'hunter2');
        expect($fn)->not->toThrow(RuntimeException::class, 'boom');
        PHP;

    expect(qaShapeAmbiguousAssertions($ambiguous))->toHaveCount(4);

    // And the shapes that are fine must not be reported: a single needle, a
    // matcher whose second parameter really is a message, a comma inside a
    // string literal, and a comma inside a nested call.
    $safe = <<<'PHP'
        expect($output)->not->toContain($secret);
        expect($written)->not->toBe('', $name);
        expect($output)->not->toContain('a, b');
        expect($output)->not->toContain(implode(', ', $parts));
        expect($record)->not->toHaveProperty('password');
        expect($e)->toThrow(RuntimeException::class, 'boom');
        PHP;

    expect(qaShapeAmbiguousAssertions($safe))->toBe([]);
});

it('has no ambiguous negated assertion anywhere in the test suite', function () {
    $offenders = [];

    foreach (qaShapeTestFiles() as $path => $source) {
        foreach (qaShapeAmbiguousAssertions($source) as $finding) {
            $offenders[] = basename($path).' '.$finding;
        }
    }

    expect($offenders)->toBe(
        [],
        'a negated toContain()/toHaveKey()/toHaveProperty()/toThrow() with two arguments passes when EITHER argument fails to '.
        'match, so it proves nothing. Use one needle per assertion, or collect offenders with str_contains() '.
        'and assert toBe([]). Found: '.implode('; ', $offenders)
    );

    // The sweep really looked at something.
    expect(count(qaShapeTestFiles()))->toBeGreaterThan(25);
});
