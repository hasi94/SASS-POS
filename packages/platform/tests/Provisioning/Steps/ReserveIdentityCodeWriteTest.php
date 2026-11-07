<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning\Steps;

/*
|--------------------------------------------------------------------------
| T-026 AC-37: the only value ReserveIdentity ever writes to `code` is
| TenantCode::normalise()'s return.
|--------------------------------------------------------------------------
|
| A static, source-level assertion rather than a runtime one alone (the
| T-008/T-014 idiom `Flexipos\Kernel\Schema\Support\SourceRules` already
| uses): tokenised with comments and docblocks stripped, so this file's own
| prose about `code` never counts as a violation of the rule it is checking.
*/

it('writes `code` only from TenantCode::normalise(), in ReserveIdentity.php', function () {
    $path = dirname(__DIR__, 3).'/src/Provisioning/Steps/ReserveIdentity.php';
    $source = (string) file_get_contents($path);

    $code = reserveIdentityCodeOnly($source);

    preg_match_all(
        "/(['\"])code\\1\\s*=>\\s*([^,\\]\\)]+)/",
        $code,
        $matches
    );

    $assignments = $matches[2];

    expect($assignments)->not->toBe([]);

    foreach ($assignments as $assignment) {
        expect(trim($assignment))->toStartWith('TenantCode::normalise(');
    }
});

/**
 * The given PHP source with every comment and docblock removed - the same
 * tokenised approach `SourceRules::codeOnly()` uses, so a comment discussing
 * `code` can never be mistaken for a write to it.
 */
function reserveIdentityCodeOnly(string $source): string
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
