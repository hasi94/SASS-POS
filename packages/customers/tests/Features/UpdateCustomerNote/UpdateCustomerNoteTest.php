<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\UpdateCustomerNote;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\UpdateCustomerNote\UpdateCustomerNote;
use Flexipos\Customers\Features\UpdateCustomerNote\UpdateCustomerNoteHandler;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 14 - updating a customer note (AC-34)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 17:30:00.000'));
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
function updateCustomerNote(string $noteId, array $fields, ?string $actorId = null): void
{
    /** @var UpdateCustomerNoteHandler $handler */
    $handler = app(UpdateCustomerNoteHandler::class);

    $handler->handle(new UpdateCustomerNote($noteId, $fields, $actorId));
}

it('updates pinned and note, with one audit row', function () {
    $customerId = CustomersSeed::customer('Jane');
    $noteId = CustomersSeed::note($customerId, ['pinned' => false]);

    updateCustomerNote($noteId, ['pinned' => true, 'note' => 'Updated']);

    $row = (array) CustomersSeed::connection()->table('customer_notes')->where('id', $noteId)->first();
    expect((bool) $row['pinned'])->toBeTrue()
        ->and($row['note'])->toBe('Updated');

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'module' => 'customers',
        'action' => 'customer_note_updated',
        'subject_type' => 'customer_note',
        'subject_id' => $noteId,
    ]);
});

it('refuses an unknown id', function () {
    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => updateCustomerNote((string) Str::ulid(), ['pinned' => true]));
});

it('refuses a bad follow_up_date', function () {
    $customerId = CustomersSeed::customer('Jane');
    $noteId = CustomersSeed::note($customerId);

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => updateCustomerNote($noteId, ['follow_up_date' => 'bad']));
});
