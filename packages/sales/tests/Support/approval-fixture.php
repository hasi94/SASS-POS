<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Illuminate\Contracts\Hashing\Hasher;

/*
|--------------------------------------------------------------------------
| The approval world (T-030 step 10), shared by four test files
|--------------------------------------------------------------------------
|
| A branch with a cashier who requests, a manager who may decide at it, a
| manager who may decide only at another branch, an owner who holds the key at
| the null scope, and a sale the cashier rang up. Every deciding employee has the
| same known PIN, set the way the access tests set one: the hash is written
| directly, because the subject here is what a decision does, not how a PIN is
| stored.
*/

const APV_PIN = '48275916';

const APV_KEY = 'sales.approvals.decide';

function apvEmployeeWithPin(string $code, ?string $pin = APV_PIN): string
{
    $id = AccessSeed::employee($code);

    /** @var Hasher $hasher */
    $hasher = app('hash');

    AccessSeed::connection()->table('employees')->where('id', $id)->update([
        'pin_hash' => $pin === null ? null : $hasher->make($pin),
        'pin_set_at' => SalesSeed::NOW,
    ]);

    return $id;
}

/**
 * @return array{branch: string, otherBranch: string, cashier: string, manager: string, elsewhere: string, owner: string, location: string, sale: string, terminal: string}
 */
function apvWorld(): array
{
    AccessSeed::permission(APV_KEY, true);

    $branch = SalesSeed::branch();
    $otherBranch = SalesSeed::branch();
    $terminal = SalesSeed::terminal($branch);

    $cashier = AccessSeed::employee('cashier');

    $manager = apvEmployeeWithPin('manager');
    $elsewhere = apvEmployeeWithPin('elsewhere');
    $owner = apvEmployeeWithPin('owner');

    AccessSeed::attach($manager, $branch);
    AccessSeed::assign($manager, AccessSeed::role('role_manager', [APV_KEY]), $branch);

    AccessSeed::attach($elsewhere, $otherBranch);
    AccessSeed::assign($elsewhere, AccessSeed::role('role_elsewhere', [APV_KEY]), $otherBranch);

    AccessSeed::assign($owner, AccessSeed::role('role_owner', [APV_KEY]), null);

    return [
        'branch' => $branch,
        'otherBranch' => $otherBranch,
        'cashier' => $cashier,
        'manager' => $manager,
        'elsewhere' => $elsewhere,
        'owner' => $owner,
        'location' => $terminal['location'],
        'terminal' => $terminal['id'],
        'sale' => SalesSeed::sale($branch, $terminal['location'], $cashier),
    ];
}
