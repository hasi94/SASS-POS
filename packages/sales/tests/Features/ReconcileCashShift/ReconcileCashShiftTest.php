<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\ReconcileCashShift;

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\ReconcileCashShift\ReconcileCashShift;
use Flexipos\Sales\Features\ReconcileCashShift\ReconcileCashShiftHandler;
use Flexipos\Sales\Features\ReconcileCashShift\ReconcileCashShiftResult;
use Flexipos\Sales\Registry\SalesPermissions;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 21 - ReconcileCashShift (AC-36, AC-37; D-21, D-30, D-69)
|--------------------------------------------------------------------------
|
| A status marker only: no comparison against anything external. The permission
| key is branch-scoped (D-69): held at the shift's branch it allows, held at
| another branch only it does not, held at the null scope it allows everywhere.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/sales-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    salesEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * A closed shift at one branch, its closer, and four supervisors: one holding the key at the branch, one at another
 * branch only, one company-wide, one not at all.
 *
 * @return array<string, mixed>
 */
function rcsWorld(string $status = 'closed'): array
{
    // The real registry sync is what makes the key known to the resolver.
    app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);

    $branch = SalesSeed::branch();
    $otherBranch = SalesSeed::branch();
    $terminal = SalesSeed::terminal($branch);
    $closer = AccessSeed::employee('closer');

    $atBranch = AccessSeed::employee('atbranch');
    AccessSeed::attach($atBranch, $branch);
    AccessSeed::assign($atBranch, AccessSeed::role('role_atbranch', [SalesPermissions::SHIFTS_RECONCILE]), $branch);

    $elsewhere = AccessSeed::employee('elsewhere');
    AccessSeed::attach($elsewhere, $otherBranch);
    AccessSeed::assign($elsewhere, AccessSeed::role('role_elsewhere', [SalesPermissions::SHIFTS_RECONCILE]), $otherBranch);

    $owner = AccessSeed::employee('owner');
    AccessSeed::assign($owner, AccessSeed::role('role_owner', [SalesPermissions::SHIFTS_RECONCILE]), null);

    $nobody = AccessSeed::employee('nobody');

    $shift = SalesSeed::shift($terminal['id'], $branch, $closer, 'SH9RCS', $status === 'open'
        ? []
        : ['status' => $status, 'closed_at' => SalesSeed::NOW, 'closed_by' => $closer, 'counted_cash' => '100.0000', 'expected_cash' => '100.0000', 'variance' => '0.0000']);

    return compact('branch', 'otherBranch', 'closer', 'atBranch', 'elsewhere', 'owner', 'nobody', 'shift');
}

function rcsReconcile(string $shift, ?string $actor): ReconcileCashShiftResult
{
    return app(ReconcileCashShiftHandler::class)->handle(new ReconcileCashShift(['shift_id' => $shift], $actor));
}

/**
 * @return array<string, mixed>
 */
function rcsState(): array
{
    return ['shifts' => SalesSeed::connection()->table('cash_shifts')->get()->map(fn ($r) => (array) $r)->all(), 'audit' => SalesSeed::audit()];
}

it('moves a closed shift to reconciled, recording who, with one audit row; a different supervisor than the closer is accepted', function () {
    $w = rcsWorld();

    $result = rcsReconcile(strtolower($w['shift']), $w['atBranch']);

    expect($result->status)->toBe('reconciled')
        ->and($result->replayed)->toBeFalse()
        ->and(SalesSeed::row('cash_shifts', $w['shift']))->toMatchArray(['status' => 'reconciled', 'reconciled_by' => $w['atBranch'], 'closed_by' => $w['closer']]);

    $audits = array_values(array_filter(SalesSeed::audit(), static fn (array $r): bool => $r['action'] === 'sales_cash_shift_reconciled'));

    expect($audits)->toHaveCount(1)
        ->and($audits[0])->toMatchArray(['module' => 'sales', 'subject_type' => 'cash_shift', 'subject_id' => $w['shift'], 'actor_id' => $w['atBranch']]);
});

it('allows the closer when they hold the key', function () {
    $w = rcsWorld();
    AccessSeed::attach($w['closer'], $w['branch']);
    AccessSeed::assign($w['closer'], AccessSeed::role('role_closer', [SalesPermissions::SHIFTS_RECONCILE]), $w['branch']);

    expect(rcsReconcile($w['shift'], $w['closer'])->status)->toBe('reconciled');
});

it('allows a company-wide grant at any branch (D-69)', function () {
    $w = rcsWorld();

    expect(rcsReconcile($w['shift'], $w['owner'])->status)->toBe('reconciled');
});

it('refuses an employee without the key, and one who holds it at another branch only, as not_authorised, writing nothing', function (string $who) {
    $w = rcsWorld();
    $before = rcsState();

    SalesRefusals::expect(SalesRefusal::NotAuthorised, fn () => rcsReconcile($w['shift'], $w[$who]));

    expect(rcsState())->toBe($before);
})->with(['nobody', 'elsewhere']);

it('is shift_not_open for an open shift, after the permission check', function () {
    $w = rcsWorld('open');
    $before = rcsState();

    SalesRefusals::expect(SalesRefusal::ShiftNotOpen, fn () => rcsReconcile($w['shift'], $w['atBranch']));
    SalesRefusals::expect(SalesRefusal::NotAuthorised, fn () => rcsReconcile($w['shift'], $w['nobody']));

    expect(rcsState())->toBe($before);
});

it('is a plain no-op when the shift is already reconciled, with no second audit row (AC-37)', function () {
    $w = rcsWorld();
    rcsReconcile($w['shift'], $w['atBranch']);
    $before = rcsState();

    $again = rcsReconcile($w['shift'], $w['owner']);

    expect($again->replayed)->toBeTrue()
        ->and($again->status)->toBe('reconciled')
        ->and(rcsState())->toBe($before)
        // Whoever reconciled it first is kept.
        ->and(SalesSeed::row('cash_shifts', $w['shift'])['reconciled_by'])->toBe($w['atBranch']);
});

it('does not tell an unauthorised caller whether the shift was reconciled', function () {
    $w = rcsWorld('reconciled');

    SalesRefusals::expect(SalesRefusal::NotAuthorised, fn () => rcsReconcile($w['shift'], $w['nobody']));
});

it('refuses a shift that is not there, a bad id, other fields and a missing or unknown actor', function () {
    $w = rcsWorld();
    $handler = app(ReconcileCashShiftHandler::class);

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rcsReconcile((string) Str::ulid(), $w['atBranch']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rcsReconcile('x', $w['atBranch']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => $handler->handle(new ReconcileCashShift(['shift_id' => $w['shift'], 'status' => 'reconciled'], $w['atBranch'])));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rcsReconcile($w['shift'], null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rcsReconcile($w['shift'], (string) Str::ulid()));

    expect(SalesSeed::row('cash_shifts', $w['shift'])['status'])->toBe('closed');
});
