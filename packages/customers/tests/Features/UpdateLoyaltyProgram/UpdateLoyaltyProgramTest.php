<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\UpdateLoyaltyProgram;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\UpdateLoyaltyProgram\UpdateLoyaltyProgram;
use Flexipos\Customers\Features\UpdateLoyaltyProgram\UpdateLoyaltyProgramHandler;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 12 - updating a loyalty program (AC-26, AC-28, D-23)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 13:30:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $fields
 */
function updateLoyaltyProgram(string $programId, array $fields, ?string $actorId = null): void
{
    /** @var UpdateLoyaltyProgramHandler $handler */
    $handler = app(UpdateLoyaltyProgramHandler::class);

    $handler->handle(new UpdateLoyaltyProgram($programId, $fields, $actorId));
}

it('retires a program via is_active = false, with one audit row', function () {
    $programId = CustomersSeed::program('Base', ['is_active' => true]);

    updateLoyaltyProgram($programId, ['is_active' => false]);

    $row = (array) CustomersSeed::connection()->table('loyalty_programs')->where('id', $programId)->first();

    expect((bool) $row['is_active'])->toBeFalse();

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'module' => 'customers',
        'action' => 'loyalty_program_updated',
        'subject_type' => 'loyalty_program',
        'subject_id' => $programId,
    ]);
});

it('refuses an unknown program', function () {
    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => updateLoyaltyProgram((string) Str::ulid(), ['is_active' => false]));
});

it('refuses an invalid tier_rules shape on update (F-5)', function () {
    $programId = CustomersSeed::program('Base');

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => updateLoyaltyProgram($programId, ['tier_rules' => []]));
});

it('round-trips a valid tier_rules shape on update', function () {
    $programId = CustomersSeed::program('Base');
    $tierRules = [['tier' => 'bronze', 'min_points' => '0']];

    updateLoyaltyProgram($programId, ['tier_rules' => $tierRules]);

    $row = (array) CustomersSeed::connection()->table('loyalty_programs')->where('id', $programId)->first();

    expect(json_decode((string) $row['tier_rules'], true))->toBe($tierRules);
});

it('refuses a field the operation does not take', function () {
    $programId = CustomersSeed::program('Base');

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => updateLoyaltyProgram($programId, ['extra' => 1]));
});
