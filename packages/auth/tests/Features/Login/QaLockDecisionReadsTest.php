<?php

use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Features\Login\Login;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 W-1 re-test: no read of the users row after the counter is written
|--------------------------------------------------------------------------
|
| LoginRefusalTest pins that the failure path issues no `select` that
| mentions `failed_attempts`. That keys on a column name, so a read that
| names none - `select * from users`, i.e. `->first()` - and decides in PHP
| is invisible to it (measured: a mutant reading the row with `first()` and
| refusing on `$row->failed_attempts < $threshold` while keeping the
| predicate stays green there). The property worth pinning is wider: once
| the counter has been written, nothing reads the users table again before
| the request ends, because a later read is where a PHP-side decision would
| have to come from.
|
| The liveness control is in the same test: the SAME log must show a read of
| users BEFORE the first write (the lookup by address), so an empty
| after-write list cannot mean "the log captured nothing".
*/

require_once __DIR__.'/../../Support/auth-fixture.php';

uses(TestCase::class);

const QA_LOCK_PASSWORD = 'qa-lock-correct-horse-battery';

beforeEach(function () {
    authFixtureClockAt('2026-10-15 09:00:00.000');
    authFixtureMigrateMaster();
    authFixtureInstallKey('qa-lock-key');
});

function qaLockAttempt(): void
{
    try {
        authFixtureLoginHandler()->handle(new Login(
            email: 'owner@example.com',
            password: 'not-the-password',
            ip: '203.0.113.7',
            userAgent: 'FlexiPOS/1.0',
        ));
    } catch (CredentialRefused) {
        // expected
    }
}

/**
 * @param  list<string>  $statements
 * @return array{before: list<string>, after: list<string>} reads of users around the first write to it
 */
function qaLockUserReads(array $statements): array
{
    $before = [];
    $after = [];
    $written = false;

    foreach ($statements as $sql) {
        if (str_starts_with($sql, 'update "users"')) {
            $written = true;

            continue;
        }

        if (str_starts_with($sql, 'select') && str_contains($sql, 'from "users"')) {
            if ($written) {
                $after[] = $sql;
            } else {
                $before[] = $sql;
            }
        }
    }

    return ['before' => $before, 'after' => $after];
}

it('reads the users table before the counter is written and never after, on the sub-threshold and the crossing request', function () {
    authFixtureUser('owner@example.com', QA_LOCK_PASSWORD);

    foreach (range(1, 3) as $ignored) {
        qaLockAttempt();
    }

    [, $below] = authFixtureWithMasterQueryLog(fn () => qaLockAttempt());
    [, $crossing] = authFixtureWithMasterQueryLog(fn () => qaLockAttempt());

    foreach ([$below, $crossing] as $statements) {
        $reads = qaLockUserReads($statements);

        // Liveness: the lookup is in the log, so the log is a real one.
        expect($reads['before'])->not->toBe([])
            ->and($reads['after'])->toBe([]);
    }

    // And the crossing request really did cross: one lockout, so the
    // request exercised the branch the property is about.
    expect(array_column(authFixtureRows('security_events'), 'type'))->toHaveCount(1);
});
