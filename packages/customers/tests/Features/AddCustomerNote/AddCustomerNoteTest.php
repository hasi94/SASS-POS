<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\AddCustomerNote;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\AddCustomerNote\AddCustomerNote;
use Flexipos\Customers\Features\AddCustomerNote\AddCustomerNoteHandler;
use Flexipos\Customers\Features\AddCustomerNote\AddCustomerNoteResult;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 14 - adding a customer note (AC-34)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 17:00:00.000'));
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
function addNoteInput(array $overrides = []): array
{
    return array_merge(['note' => 'Called about delivery.'], $overrides);
}

/**
 * @param  array<string, mixed>  $fields
 */
function addCustomerNote(array $fields, ?string $actorId = null): AddCustomerNoteResult
{
    /** @var AddCustomerNoteHandler $handler */
    $handler = app(AddCustomerNoteHandler::class);

    return $handler->handle(new AddCustomerNote($fields, $actorId));
}

it('adds a note with pinned defaulting to false, and one audit row', function () {
    $customerId = CustomersSeed::customer('Jane');

    $result = addCustomerNote(addNoteInput(['customer_id' => $customerId]));

    $row = (array) CustomersSeed::connection()->table('customer_notes')->where('id', $result->noteId)->first();

    expect($row['note'])->toBe('Called about delivery.')
        ->and((bool) $row['pinned'])->toBeFalse();

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'module' => 'customers',
        'action' => 'customer_note_added',
        'subject_type' => 'customer_note',
        'subject_id' => $result->noteId,
        'before' => null,
    ]);
});

it('refuses a blank note', function () {
    $customerId = CustomersSeed::customer('Jane');

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => addCustomerNote(addNoteInput(['customer_id' => $customerId, 'note' => ''])));
});

it('refuses a bad follow_up_date', function () {
    $customerId = CustomersSeed::customer('Jane');

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => addCustomerNote(addNoteInput(['customer_id' => $customerId, 'follow_up_date' => 'not-a-date'])));
});

it('refuses an unknown customer', function () {
    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => addCustomerNote(addNoteInput(['customer_id' => (string) Str::ulid()])));
});
