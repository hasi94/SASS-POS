<?php

use Flexipos\Kernel\Schema\Support\SourceRules;

/*
|--------------------------------------------------------------------------
| QA, T-008, AC-36: the DATETIME(3) clause, read from source
|--------------------------------------------------------------------------
|
| AC-36 is an [E] criterion: InnoDB, utf8mb4 and the reported precision of a
| column are `information_schema`'s to answer, and while ENGINE_HOLD is set
| they are NOT VERIFIED (G-1). This is the one clause of it that source can
| answer on its own: every `dateTime` column the shipped migrations declare
| states precision 3, so the engine has nothing to default.
|
| Comments are stripped first (SourceRules::codeOnly), as every static rule in
| this task does, and the control feeds the same scan a column with no
| precision and one with the wrong precision.
*/

/**
 * Every dateTime-family declaration in the source whose precision is not 3.
 *
 * @return list<string>
 */
function qaDatetimesWithoutPrecisionThree(string $source): array
{
    $code = SourceRules::codeOnly($source);
    $offenders = [];

    preg_match_all('/->\s*(dateTime(?:Tz)?)\s*\(([^)]*)\)/', $code, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $arguments = array_map('trim', explode(',', $match[2]));

        if (($arguments[1] ?? '') !== '3') {
            $offenders[] = trim($match[0]);
        }
    }

    return $offenders;
}

it('reports a dateTime with no precision and one with the wrong precision', function () {
    expect(qaDatetimesWithoutPrecisionThree('<?php $table->dateTime(\'seen_at\');'))->toBe(['->dateTime(\'seen_at\')'])
        ->and(qaDatetimesWithoutPrecisionThree('<?php $table->dateTime(\'seen_at\', 6);'))->toBe(['->dateTime(\'seen_at\', 6)'])
        ->and(qaDatetimesWithoutPrecisionThree('<?php $table->dateTime(\'seen_at\', 3)->nullable();'))->toBe([]);
});

it('declares precision 3 on every dateTime column in both shipped trees', function () {
    $files = array_merge(
        glob(base_path('database/migrations').'/*.php') ?: [],
        glob(base_path((string) config('schema.tenant_migration_path')).'/*.php') ?: []
    );

    $offenders = [];
    $declared = 0;

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        $declared += preg_match_all('/->\s*dateTime(?:Tz)?\s*\(/', SourceRules::codeOnly($source));

        foreach (qaDatetimesWithoutPrecisionThree($source) as $offender) {
            $offenders[] = basename($file).': '.$offender;
        }
    }

    // The scan saw columns: the master alone declares dozens.
    expect($declared)->toBeGreaterThan(50)
        ->and($offenders)->toBe([]);
});
