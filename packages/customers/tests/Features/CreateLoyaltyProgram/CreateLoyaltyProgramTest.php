<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\CreateLoyaltyProgram;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\CreateLoyaltyProgram\CreateLoyaltyProgram;
use Flexipos\Customers\Features\CreateLoyaltyProgram\CreateLoyaltyProgramHandler;
use Flexipos\Customers\Features\CreateLoyaltyProgram\CreateLoyaltyProgramResult;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 12 - creating a loyalty program (AC-26, D-22, F-5)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 13:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function createProgramInput(array $overrides = []): array
{
    return array_merge(['name' => 'Base program'], $overrides);
}

/**
 * @param  array<string, mixed>  $fields
 */
function createLoyaltyProgram(array $fields, ?string $actorId = null): CreateLoyaltyProgramResult
{
    /** @var CreateLoyaltyProgramHandler $handler */
    $handler = app(CreateLoyaltyProgramHandler::class);

    return $handler->handle(new CreateLoyaltyProgram($fields, $actorId));
}

function expectProgramRefusal(CustomersRefusal $reason, callable $operation): void
{
    CustomersRefusals::expect($reason, $operation);
}

it('creates a program with is_active defaulting to true, and one audit row', function () {
    $result = createLoyaltyProgram(createProgramInput());

    $row = (array) CustomersSeed::connection()->table('loyalty_programs')->where('id', $result->programId)->first();

    expect((bool) $row['is_active'])->toBeTrue()
        ->and($row['tier_rules'])->toBeNull();

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'module' => 'customers',
        'action' => 'loyalty_program_created',
        'subject_type' => 'loyalty_program',
        'subject_id' => $result->programId,
        'before' => null,
    ]);
});

it('refuses a missing name', function () {
    expectProgramRefusal(CustomersRefusal::Validation, fn () => createLoyaltyProgram(createProgramInput(['name' => ''])));
});

it('round-trips earn_rule and redeem_rule as any JSON, with no schema validation', function () {
    $result = createLoyaltyProgram(createProgramInput([
        'earn_rule' => ['per_currency_unit' => 1.5],
        'redeem_rule' => ['min_redeem' => 100],
    ]));

    $row = (array) CustomersSeed::connection()->table('loyalty_programs')->where('id', $result->programId)->first();

    expect(json_decode((string) $row['earn_rule'], true))->toBe(['per_currency_unit' => 1.5])
        ->and(json_decode((string) $row['redeem_rule'], true))->toBe(['min_redeem' => 100]);
});

it('accepts a valid tier_rules shape and round-trips it', function () {
    $tierRules = [
        ['tier' => 'bronze', 'min_points' => '0'],
        ['tier' => 'silver', 'min_points' => '100'],
        ['tier' => 'gold', 'min_points' => '500'],
    ];

    $result = createLoyaltyProgram(createProgramInput(['tier_rules' => $tierRules]));

    $row = (array) CustomersSeed::connection()->table('loyalty_programs')->where('id', $result->programId)->first();

    expect(json_decode((string) $row['tier_rules'], true))->toBe($tierRules);
});

it('refuses an invalid tier_rules shape (F-5)', function () {
    expectProgramRefusal(CustomersRefusal::Validation, fn () => createLoyaltyProgram(createProgramInput(['tier_rules' => []])));
    expectProgramRefusal(CustomersRefusal::Validation, fn () => createLoyaltyProgram(createProgramInput(['tier_rules' => [
        ['tier' => 'bronze', 'min_points' => '0'],
        ['tier' => 'bronze', 'min_points' => '100'],
    ]])));
    expectProgramRefusal(CustomersRefusal::Validation, fn () => createLoyaltyProgram(createProgramInput(['tier_rules' => [
        ['tier' => 'bronze', 'min_points' => '0'],
        ['tier' => 'silver', 'min_points' => '0'],
    ]])));
    expectProgramRefusal(CustomersRefusal::Validation, fn () => createLoyaltyProgram(createProgramInput(['tier_rules' => [
        ['tier' => 'bronze', 'min_points' => '-1'],
    ]])));
    expectProgramRefusal(CustomersRefusal::Validation, fn () => createLoyaltyProgram(createProgramInput(['tier_rules' => [
        ['min_points' => '0'],
    ]])));
    expectProgramRefusal(CustomersRefusal::Validation, fn () => createLoyaltyProgram(createProgramInput(['tier_rules' => 'not-a-list'])));
});

it('refuses a field the operation does not take', function () {
    expectProgramRefusal(CustomersRefusal::Validation, fn () => createLoyaltyProgram(createProgramInput(['extra' => 1])));
});

it('writes nothing, including no audit row, on every refusal above', function () {
    $before = CustomersSeed::connection()->table('loyalty_programs')->count();

    foreach ([['name' => ''], ['extra' => 1], ['tier_rules' => []]] as $overrides) {
        try {
            createLoyaltyProgram(createProgramInput($overrides));
        } catch (\Throwable) {
            // expected
        }
    }

    expect(CustomersSeed::connection()->table('loyalty_programs')->count())->toBe($before)
        ->and(CustomersSeed::audit())->toBe([]);
});
