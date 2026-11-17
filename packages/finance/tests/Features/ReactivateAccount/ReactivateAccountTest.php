<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\ReactivateAccount;

use ArrayObject;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Accounts\AccountRows;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\ReactivateAccount\ReactivateAccount;
use Flexipos\Finance\Features\ReactivateAccount\ReactivateAccountHandler;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 11 - reactivating an account (AC-24)
|--------------------------------------------------------------------------
|
| The full deactivate and reactivate cases are in
| `DeactivateAccount/DeactivateAccountTest.php`; this folder mirrors the
| slice (the vertical-slice layout rule).
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/finance-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    financeEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('reactivates an inactive account and refuses an active one', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('A1', ['is_active' => false]);

    $handler = app(ReactivateAccountHandler::class);
    $handler->handle(new ReactivateAccount($id, $actor));

    expect(FinanceSeed::row($id)['is_active'])->toBe(1);

    FinanceRefusals::expect(FinanceRefusal::AlreadyActive, fn () => $handler->handle(new ReactivateAccount($id, $actor)));
});

/**
 * An AccountRows that records the locking reads a reactivation makes, in order. `$stalePeek` makes the unlocked
 * parent peek answer with another id, as it would after a re-parent committed between the peek and the locks.
 *
 * @param  ArrayObject<int, string>  $calls
 */
function reactivationRowsRecording(ArrayObject $calls, ?string $stalePeek = null): void
{
    app()->instance(AccountRows::class, new class(app(TenantConnection::class), $calls, $stalePeek) extends AccountRows
    {
        /** @param ArrayObject<int, string> $calls */
        public function __construct(TenantConnection $tenant, private readonly ArrayObject $calls, private readonly ?string $stalePeek)
        {
            parent::__construct($tenant);
        }

        public function parentIdUnlocked(string $id): ?string
        {
            return $this->stalePeek ?? parent::parentIdUnlocked($id);
        }

        public function anySharedLock(string $id): ?object
        {
            $this->calls->append("shared {$id}");

            return parent::anySharedLock($id);
        }

        public function liveForUpdate(string $id): ?object
        {
            $this->calls->append("update {$id}");

            return parent::liveForUpdate($id);
        }
    });
}

it('locks the parent shared before the child exclusive, the order a parent deactivation takes (D-33)', function () {
    $actor = FinanceSeed::admin();
    $parent = FinanceSeed::header('P1');
    $child = FinanceSeed::account('C1', ['parent_id' => $parent, 'is_active' => false]);

    $calls = new ArrayObject;
    reactivationRowsRecording($calls);

    app(ReactivateAccountHandler::class)->handle(new ReactivateAccount($child, $actor));

    expect(array_slice($calls->getArrayCopy(), 0, 2))->toBe(["shared {$parent}", "update {$child}"])
        ->and(FinanceSeed::row($child)['is_active'])->toBe(1);
});

it('locks the actual parent late, and decides on it, when a re-parent moved the child after the peek (D-33)', function () {
    $actor = FinanceSeed::admin();
    $stale = FinanceSeed::header('P1');
    $actual = FinanceSeed::header('P2', ['is_active' => false]);
    $child = FinanceSeed::account('C1', ['parent_id' => $actual, 'is_active' => false]);

    $calls = new ArrayObject;
    reactivationRowsRecording($calls, $stale);

    FinanceRefusals::expect(FinanceRefusal::ParentInactive, fn () => app(ReactivateAccountHandler::class)->handle(new ReactivateAccount($child, $actor)));

    expect(array_slice($calls->getArrayCopy(), 0, 3))->toBe(["shared {$stale}", "update {$child}", "shared {$actual}"]);
});
