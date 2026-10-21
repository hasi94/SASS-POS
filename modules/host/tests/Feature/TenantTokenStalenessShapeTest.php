<?php

use App\Http\Middleware\RequireTenantToken;
use Flexipos\Contracts\Access\EmployeeStalenessCheck;

/*
|--------------------------------------------------------------------------
| T-010 review S-4 - a malformed employee claim fails closed
|--------------------------------------------------------------------------
|
| RequireTenantToken's staleness question has three answers for three
| shapes: no `employee` claim, no check (AC-66); a well-formed pair, the
| check decides; and anything else - an employee id beside a `perm_version`
| that is not an integer, or an empty employee id - is stale. The verifier
| refuses that third shape before the middleware sees it, so it cannot be
| reached over HTTP with a signed token, and the method is asked directly.
| What is pinned is that the third shape is refused WITHOUT consulting the
| check: the check takes an int and would otherwise be asked a question
| it cannot be asked.
|
*/

final class StalenessShapeSpy implements EmployeeStalenessCheck
{
    /** @var list<array{string, int}> */
    public array $asked = [];

    public function isStale(string $employeeId, int $claimedPermVersion): bool
    {
        $this->asked[] = [$employeeId, $claimedPermVersion];

        return false;
    }
}

/**
 * @param  array<string, mixed>  $claims
 */
function stalenessShapeVerdict(StalenessShapeSpy $spy, array $claims): bool
{
    app()->instance(EmployeeStalenessCheck::class, $spy);

    /** @var RequireTenantToken $middleware */
    $middleware = app()->make(RequireTenantToken::class);

    $method = new ReflectionMethod($middleware, 'isStale');

    return (bool) $method->invoke($middleware, $claims);
}

it('refuses an employee claim beside a malformed one, and consults the check only for the legal pair (review S-4, AC-66)', function () {
    $employee = '01JBEMPLOYEE00000000000001';

    $cases = [
        'no employee claim' => [['sub' => 'u'], false, 0],
        'a well-formed pair' => [['employee' => $employee, 'perm_version' => 3], false, 1],
        'perm_version as a string' => [['employee' => $employee, 'perm_version' => '3'], true, 0],
        'perm_version missing' => [['employee' => $employee], true, 0],
        'perm_version as a float' => [['employee' => $employee, 'perm_version' => 3.0], true, 0],
        'an empty employee id' => [['employee' => '', 'perm_version' => 3], true, 0],
        'an employee id that is not a string' => [['employee' => 7, 'perm_version' => 3], true, 0],
    ];

    foreach ($cases as $why => [$claims, $stale, $asked]) {
        $spy = new StalenessShapeSpy;

        expect(stalenessShapeVerdict($spy, $claims))->toBe($stale, $why)
            ->and($spy->asked)->toHaveCount($asked, $why);
    }

    // The control: the legal pair is answered by the check, so a check that
    // says stale is what the middleware says - "false" above is the spy's
    // answer, not a constant.
    $stale = new class implements EmployeeStalenessCheck
    {
        public function isStale(string $employeeId, int $claimedPermVersion): bool
        {
            return true;
        }
    };

    app()->instance(EmployeeStalenessCheck::class, $stale);

    /** @var RequireTenantToken $middleware */
    $middleware = app()->make(RequireTenantToken::class);

    expect((new ReflectionMethod($middleware, 'isStale'))->invoke($middleware, ['employee' => $employee, 'perm_version' => 3]))
        ->toBeTrue();
});
