<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Support;

use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Uniqueness;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use PDO;

/*
|--------------------------------------------------------------------------
| T-034 step 6 - Uniqueness (AC-3, AC-9)
|--------------------------------------------------------------------------
|
| The case-insensitive code pre-check: it names the holder's id, it sees a
| soft-deleted holder, and it lets a row keep its own code on update.
*/

function puUniquenessConnection(): Connection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

    $connection->getSchemaBuilder()->create('suppliers', function (Blueprint $table): void {
        $table->string('id', 26)->primary();
        $table->string('code', 50);
        $table->dateTime('deleted_at')->nullable();
    });

    $connection->table('suppliers')->insert([
        ['id' => '01JACTIVE000000000000000A', 'code' => 'ACME-1', 'deleted_at' => null],
        ['id' => '01JDELETED00000000000000B', 'code' => 'GONE-1', 'deleted_at' => '2026-11-01 00:00:00'],
    ]);

    return $connection;
}

it('refuses a code case-insensitively and names the existing row s id (AC-3)', function (string $submitted, string $holder) {
    try {
        Uniqueness::assertCodeFree(puUniquenessConnection(), 'suppliers', $submitted);
        $refused = null;
    } catch (PurchasingRefused $refused) {
    }

    expect($refused)->toBeInstanceOf(PurchasingRefused::class)
        ->and($refused->code())->toBe('duplicate_code')
        ->and($refused->getMessage())->toContain($holder);
})->with([
    'the same case' => ['ACME-1', '01JACTIVE000000000000000A'],
    'lower case' => ['acme-1', '01JACTIVE000000000000000A'],
    'a soft-deleted holder keeps its code' => ['gone-1', '01JDELETED00000000000000B'],
]);

it('accepts a code nobody holds', function () {
    Uniqueness::assertCodeFree(puUniquenessConnection(), 'suppliers', 'NEW-1');

    expect(true)->toBeTrue();
});

it('lets a row keep its own code on update but not take another row s', function () {
    Uniqueness::assertCodeFree(puUniquenessConnection(), 'suppliers', 'acme-1', '01JACTIVE000000000000000A');

    try {
        Uniqueness::assertCodeFree(puUniquenessConnection(), 'suppliers', 'ACME-1', '01JDELETED00000000000000B');
        $refused = null;
    } catch (PurchasingRefused $refused) {
    }

    expect($refused)->toBeInstanceOf(PurchasingRefused::class)->and($refused->code())->toBe('duplicate_code');
});
