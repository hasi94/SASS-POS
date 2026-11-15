<?php

use Flexipos\Testing\Engine\EngineOnHold;

/*
|--------------------------------------------------------------------------
| T-008, BUG-9: AC-63 marker 2 says what the hold's class must carry
|--------------------------------------------------------------------------
|
| Every skip line in the suite reads "ENGINE_HOLD: see
| Flexipos\Testing\Engine\EngineOnHold", so that class is the only place a
| reader of a skipped run can find out what the hold is and when it lifts. qa
| found it carried the date and the sixteen databases but stated the wrong
| condition - "until the owner has decided whether file-level recovery will be
| attempted" - and named gate G-1 nowhere. A decay marker that states a
| different condition from the real one is worse than an absent marker: a
| reader acts on it.
|
| So the marker is asserted rather than read by hand once a round. The phrases
| are matched over whitespace-collapsed text, because a reflow of the docblock
| is not a change of meaning; the control is a fabricated docblock that is
| missing the condition, so a green case is a fact about the file and not
| about a reader that matches anything.
*/

/**
 * The file's text with every run of whitespace (and every leading `*`)
 * collapsed to one space.
 */
function engineHoldFlattened(string $text): string
{
    return (string) preg_replace('/\s+/', ' ', (string) preg_replace('/^\s*\*/m', ' ', $text));
}

/**
 * Which of the given phrases the flattened text is missing.
 *
 * @param  list<string>  $phrases
 * @return list<string>
 */
function engineHoldMissing(string $text, array $phrases): array
{
    $missing = [];

    foreach ($phrases as $phrase) {
        if (! str_contains(engineHoldFlattened($text), $phrase)) {
            $missing[] = $phrase;
        }
    }

    return $missing;
}

it('reports a phrase a text does not carry', function () {
    // The control: the reader can see an absence, and whitespace is not one.
    $fabricated = "/**\n * On hold since 2026-10-14.\n *\n * Sixteen\n * databases.\n */";

    expect(engineHoldMissing($fabricated, ['2026-10-14', 'Sixteen databases']))->toBe([])
        ->and(engineHoldMissing($fabricated, ['gate G-1']))->toBe(['gate G-1']);
});

it('names the incident, the sixteen databases and gate G-1 in the class a skip line points at', function () {
    $source = (string) file_get_contents(
        dirname(__DIR__, 4).'/packages/testing/src/Engine/EngineOnHold.php'
    );

    expect($source)->not->toBe('')
        ->and(engineHoldMissing($source, [
            '2026-10-14',
            'sixteen of the owner',
            'gate G-1',
            'verified non-empty',
        ]))->toBe([]);
});

it('names gate G-1 in the message the refusal carries', function () {
    $message = EngineOnHold::sinceTheIncident()->getMessage();

    // The message is what a developer sees first - in a failing engine test or
    // in the gate's first step - so the condition is in it, not only in the
    // docblock above it.
    expect(engineHoldMissing($message, ['2026-10-14', 'G-1', '43 surviving databases']))->toBe([]);
});
