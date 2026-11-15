<?php

/*
|--------------------------------------------------------------------------
| QA, T-008, AC-63(b): the hold lives in the suite's configuration, so a
| shell variable must not be able to clear it
|--------------------------------------------------------------------------
|
| MEASURED 2026-10-15 with a probe test that only reads
| EngineConnection::onHold(), run against the real phpunit.xml:
|
|   ambient ENGINE_HOLD unset -> onHold() true   (the <env> value, 1)
|   ENGINE_HOLD=0 in the shell -> onHold() false  (the shell won)
|   ENGINE_HOLD=off            -> onHold() false
|
| PHPUnit's <env> never overwrites a variable that is already set unless it
| carries force="true". The same measurement against a copy of the file with
| force on <env> alone STILL gave onHold() false, because EngineConnection
| reads $_SERVER before $_ENV and getenv(), and <env> does not touch $_SERVER.
| So the hold needs force on both the <env> and the <server> entry, or the
| reader must stop preferring the ambient value.
|
| The case reads the file rather than spawning a child, which keeps it in the
| fast loop and out of the toolchain registry. Its control feeds the same
| reader a fixture with and without the attribute, so a pass is a fact about
| the attribute and not about the parser.
*/

/**
 * Whether the named variable is forced on every PHPUnit element that sets it.
 *
 * @return array<string, bool> element name => forced
 */
function qaForcedElements(string $xml, string $variable): array
{
    $document = simplexml_load_string($xml);

    expect($document)->not->toBeFalse();

    $found = [];

    foreach (['env', 'server'] as $element) {
        foreach ($document->php->{$element} ?? [] as $entry) {
            if ((string) $entry['name'] === $variable) {
                $found[$element] = (string) $entry['force'] === 'true';
            }
        }
    }

    return $found;
}

it('can tell a forced variable from an unforced one', function () {
    $unforced = '<phpunit><php><env name="ENGINE_HOLD" value="1"/></php></phpunit>';
    $forced = '<phpunit><php><env name="ENGINE_HOLD" value="1" force="true"/>'
        .'<server name="ENGINE_HOLD" value="1" force="true"/></php></phpunit>';
    $half = '<phpunit><php><env name="ENGINE_HOLD" value="1" force="true"/>'
        .'<server name="ENGINE_HOLD" value="1"/></php></phpunit>';

    expect(qaForcedElements($unforced, 'ENGINE_HOLD'))->toBe(['env' => false])
        ->and(qaForcedElements($forced, 'ENGINE_HOLD'))->toBe(['env' => true, 'server' => true])
        ->and(qaForcedElements($half, 'ENGINE_HOLD'))->toBe(['env' => true, 'server' => false])
        ->and(qaForcedElements($forced, 'ENGINE_PORT'))->toBe([]);
});

it('forces ENGINE_HOLD on both the environment and the server entry', function () {
    $xml = (string) file_get_contents(base_path('phpunit.xml'));

    // Both must exist and both must be forced: EngineConnection reads
    // $_SERVER first, so an unforced or absent <server> entry leaves the
    // shell in charge even when <env> is forced.
    expect(qaForcedElements($xml, 'ENGINE_HOLD'))->toBe(['env' => true, 'server' => true]);
});
