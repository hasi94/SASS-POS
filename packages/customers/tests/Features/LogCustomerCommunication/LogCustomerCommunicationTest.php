<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\LogCustomerCommunication;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\LogCustomerCommunication\LogCustomerCommunication;
use Flexipos\Customers\Features\LogCustomerCommunication\LogCustomerCommunicationHandler;
use Flexipos\Customers\Features\LogCustomerCommunication\LogCustomerCommunicationResult;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 14 - logging a customer communication (AC-35)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 18:00:00.000'));
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
function logCommunicationInput(array $overrides = []): array
{
    return array_merge(['channel' => 'call', 'direction' => 'out'], $overrides);
}

/**
 * @param  array<string, mixed>  $fields
 */
function logCustomerCommunication(array $fields, ?string $actorId = null): LogCustomerCommunicationResult
{
    /** @var LogCustomerCommunicationHandler $handler */
    $handler = app(LogCustomerCommunicationHandler::class);

    return $handler->handle(new LogCustomerCommunication($fields, $actorId));
}

it('logs a communication, defaulting occurred_at to now, with one audit row', function () {
    $customerId = CustomersSeed::customer('Jane');

    $result = logCustomerCommunication(logCommunicationInput(['customer_id' => $customerId]));

    $row = (array) CustomersSeed::connection()->table('customer_communications')->where('id', $result->communicationId)->first();

    expect($row['channel'])->toBe('call')
        ->and($row['direction'])->toBe('out')
        ->and($row['occurred_at'])->toBe('2026-11-10 18:00:00.000');

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'module' => 'customers',
        'action' => 'customer_communication_logged',
        'subject_type' => 'customer_communication',
        'subject_id' => $result->communicationId,
        'before' => null,
    ]);
});

it('keeps a supplied occurred_at exactly', function () {
    $customerId = CustomersSeed::customer('Jane');

    $result = logCustomerCommunication(logCommunicationInput(['customer_id' => $customerId, 'occurred_at' => '2026-01-01 00:00:00.000']));

    $row = (array) CustomersSeed::connection()->table('customer_communications')->where('id', $result->communicationId)->first();
    expect($row['occurred_at'])->toBe('2026-01-01 00:00:00.000');
});

it('refuses each bad enum', function () {
    $customerId = CustomersSeed::customer('Jane');

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => logCustomerCommunication(logCommunicationInput(['customer_id' => $customerId, 'channel' => 'fax'])));
    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => logCustomerCommunication(logCommunicationInput(['customer_id' => $customerId, 'direction' => 'sideways'])));
});

it('refuses an unknown employee', function () {
    $customerId = CustomersSeed::customer('Jane');

    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => logCustomerCommunication(logCommunicationInput(['customer_id' => $customerId, 'employee_id' => (string) Str::ulid()])));
});
