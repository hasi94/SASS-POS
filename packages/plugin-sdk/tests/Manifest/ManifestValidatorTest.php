<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Manifest;

use Flexipos\PluginSdk\Exceptions\ManifestRefused;
use Flexipos\PluginSdk\Manifest\Manifest;
use Flexipos\PluginSdk\Manifest\ManifestValidator;
use Flexipos\PluginSdk\Tests\Fixtures\FixtureEcho\EchoBefore;
use Flexipos\PluginSdk\Tests\Fixtures\FixtureEcho\EchoOverride;
use Flexipos\PluginSdk\Tests\Fixtures\FixtureEcho\EchoSuffixFilter;
use Flexipos\PluginSdk\Tests\Fixtures\FixtureEcho\EchoUpperFilter;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\NotAHook;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 5 - the manifest validator (plan Ruling 2). Every case asserts
| the named constructor, never just "it throws".
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    app()->register(new FixtureHookPointProvider(app()), true);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validManifestArray(array $overrides = []): array
{
    return array_merge([
        'name' => 'fixture-echo',
        'version' => '1.0.0',
        'core_constraint' => '^0.1.0',
        'hooks' => [
            ['point' => 'fixture.echo', 'mode' => 'before', 'handler' => EchoBefore::class],
        ],
        'permissions' => ['fixture.echo.read'],
        'requires_licence' => false,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $manifest
 */
function validate(array $manifest): Manifest
{
    return app(ManifestValidator::class)->validate(json_encode($manifest, JSON_THROW_ON_ERROR));
}

function refusalReason(callable $call): string
{
    try {
        $call();
    } catch (ManifestRefused $e) {
        return $e->reason;
    }

    throw new \RuntimeException('Expected a ManifestRefused exception.');
}

it('validates the real FixtureEcho plugin.json', function () {
    $json = file_get_contents(__DIR__.'/../Fixtures/FixtureEcho/plugin.json');

    $manifest = app(ManifestValidator::class)->validate($json);

    expect($manifest->name)->toBe('fixture-echo')
        ->and($manifest->hooks)->toHaveCount(6)
        ->and($manifest->visibility)->toBe('official')
        ->and($manifest->requiresLicence)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| AC-1: missing required fields
|--------------------------------------------------------------------------
*/

it('refuses a missing required field', function (string $field) {
    $manifest = validManifestArray();
    unset($manifest[$field]);

    expect(refusalReason(fn () => validate($manifest)))->toBe('missingField');
})->with(['name', 'version', 'core_constraint', 'hooks', 'permissions', 'requires_licence']);

it('refuses a hook entry missing its handler, naming hooks[0].handler', function () {
    $manifest = validManifestArray(['hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'before'],
    ]]);

    try {
        validate($manifest);
        $this->fail('Expected ManifestRefused.');
    } catch (ManifestRefused $e) {
        expect($e->reason)->toBe('missingField')
            ->and($e->getMessage())->toContain('hooks[0].handler');
    }
});

/*
|--------------------------------------------------------------------------
| AC-2: unknown mode, never coerced
|--------------------------------------------------------------------------
*/

it('refuses every unknown mode spelling', function (string $mode) {
    $manifest = validManifestArray(['hooks' => [
        ['point' => 'fixture.echo', 'mode' => $mode, 'handler' => EchoBefore::class],
    ]]);

    expect(refusalReason(fn () => validate($manifest)))->toBe('unknownMode');
})->with(['Before', 'BEFORE', 'replace', '']);

it('accepts the real mode "before" as a control', function () {
    $manifest = validManifestArray();

    expect(fn () => validate($manifest))->not->toThrow(ManifestRefused::class);
});

/*
|--------------------------------------------------------------------------
| AC-3: override only on an overridable point
|--------------------------------------------------------------------------
*/

it('refuses override on a point not marked overridable', function () {
    $manifest = validManifestArray(['hooks' => [
        ['point' => 'fixture.plain', 'mode' => 'override', 'handler' => EchoOverride::class],
    ]]);

    expect(refusalReason(fn () => validate($manifest)))->toBe('overrideNotPermitted');
});

it('accepts override on fixture.echo, which is marked overridable', function () {
    $manifest = validManifestArray(['hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'override', 'handler' => EchoOverride::class],
    ]]);

    expect(fn () => validate($manifest))->not->toThrow(ManifestRefused::class);
});

/*
|--------------------------------------------------------------------------
| AC-4: handler resolution
|--------------------------------------------------------------------------
*/

it('refuses a missing handler class', function () {
    $manifest = validManifestArray(['hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'before', 'handler' => 'Flexipos\\PluginSdk\\Tests\\Support\\DoesNotExist'],
    ]]);

    expect(refusalReason(fn () => validate($manifest)))->toBe('handlerNotFound');
});

it('refuses a concrete class implementing no hook contract', function () {
    $manifest = validManifestArray(['hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'before', 'handler' => NotAHook::class],
    ]]);

    expect(refusalReason(fn () => validate($manifest)))->toBe('handlerWrongContract');
});

it('refuses a FilterHook class declared for mode before', function () {
    $manifest = validManifestArray(['hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'before', 'handler' => EchoUpperFilter::class],
    ]]);

    expect(refusalReason(fn () => validate($manifest)))->toBe('handlerWrongContract');
});

/*
|--------------------------------------------------------------------------
| AC-5: malformed JSON, distinct from missingField, and wins ordering
|--------------------------------------------------------------------------
*/

it('refuses malformed JSON: a truncated object', function () {
    expect(refusalReason(fn () => app(ManifestValidator::class)->validate('{"name":')))->toBe('malformedJson');
});

it('refuses malformed JSON: a bare list, which is not an object', function () {
    expect(refusalReason(fn () => app(ManifestValidator::class)->validate('[]')))->toBe('malformedJson');
});

it('names malformedJson even when fields are also missing (ordering)', function () {
    expect(refusalReason(fn () => app(ManifestValidator::class)->validate('{"name":')))->toBe('malformedJson');
});

/*
|--------------------------------------------------------------------------
| AC-6: an empty hooks list is legal
|--------------------------------------------------------------------------
*/

it('accepts an empty hooks list with zero registrations', function () {
    $manifest = validate(validManifestArray(['hooks' => []]));

    expect($manifest->hooks)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| AC-10: core_constraint vs the running core version
|--------------------------------------------------------------------------
*/

it('refuses a core_constraint that does not match the running core version', function () {
    $manifest = validManifestArray(['core_constraint' => '>=9.0']);

    expect(refusalReason(fn () => validate($manifest)))->toBe('coreVersionMismatch');
});

it('accepts once plugins.core_version moves to satisfy the constraint', function () {
    config(['plugins.core_version' => '9.1.0']);

    $manifest = validManifestArray(['core_constraint' => '>=9.0']);

    expect(fn () => validate($manifest))->not->toThrow(ManifestRefused::class);
});

/*
|--------------------------------------------------------------------------
| Other rulings
|--------------------------------------------------------------------------
*/

it('refuses requires_licence given as the string "true", never coerced', function () {
    $manifest = validManifestArray(['requires_licence' => 'true']);

    expect(refusalReason(fn () => validate($manifest)))->toBe('invalidField');
});

it('refuses an unknown top-level key', function () {
    $manifest = validManifestArray(['unexpected_key' => 'x']);

    expect(refusalReason(fn () => validate($manifest)))->toBe('unknownField');
});

it('refuses a second override registration at one point, within one manifest', function () {
    $manifest = validManifestArray(['hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'override', 'handler' => EchoOverride::class],
        ['point' => 'fixture.echo', 'mode' => 'override', 'handler' => EchoOverride::class],
    ]]);

    expect(refusalReason(fn () => validate($manifest)))->toBe('duplicateRegistration');
});

it('allows two filter registrations at one point, which is how filters chain', function () {
    $manifest = validManifestArray(['hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'filter', 'handler' => EchoUpperFilter::class],
        ['point' => 'fixture.echo', 'mode' => 'filter', 'handler' => EchoSuffixFilter::class],
    ]]);

    $result = validate($manifest);

    expect($result->hooks)->toHaveCount(2);
});

it('defaults visibility to private when the manifest does not declare it', function () {
    $manifest = validate(validManifestArray());

    expect($manifest->visibility)->toBe('private');
});

it('refuses a point not in the catalogue', function () {
    $manifest = validManifestArray(['hooks' => [
        ['point' => 'no.such.point', 'mode' => 'before', 'handler' => EchoBefore::class],
    ]]);

    expect(refusalReason(fn () => validate($manifest)))->toBe('unknownPoint');
});

it('refuses override on a non-overridable point through overrideNotPermitted, not modeNotAccepted', function () {
    $manifest = validManifestArray(['hooks' => [
        ['point' => 'fixture.plain', 'mode' => 'override', 'handler' => EchoOverride::class],
    ]]);

    expect(refusalReason(fn () => validate($manifest)))->toBe('overrideNotPermitted');
});

it('refuses a non-override mode the point does not declare at all', function () {
    $manifest = validManifestArray(['hooks' => [
        ['point' => 'fixture.narrow', 'mode' => 'filter', 'handler' => EchoUpperFilter::class],
    ]]);

    expect(refusalReason(fn () => validate($manifest)))->toBe('modeNotAccepted');
});
