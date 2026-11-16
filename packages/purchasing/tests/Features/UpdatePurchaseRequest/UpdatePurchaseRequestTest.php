<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\UpdatePurchaseRequest;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\UpdatePurchaseRequest\UpdatePurchaseRequest;
use Flexipos\Purchasing\Features\UpdatePurchaseRequest\UpdatePurchaseRequestHandler;
use Flexipos\Purchasing\Features\UpdatePurchaseRequest\UpdatePurchaseRequestResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 12 - UpdatePurchaseRequest (AC-20, AC-21, AC-25, AC-39)
|--------------------------------------------------------------------------
|
| Only a draft; only what differs is written; a call that changes nothing writes
| nothing; the branch never changes and the permission is asked at the stored one.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/purchasing-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    puEnterTenant();
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
function puUpdateRequest(array $fields, ?string $actorId): UpdatePurchaseRequestResult
{
    /** @var UpdatePurchaseRequestHandler $handler */
    $handler = app(UpdatePurchaseRequestHandler::class);

    return $handler->handle(new UpdatePurchaseRequest($fields, $actorId));
}

/**
 * @param  array<string, mixed>  $fields
 */
function puUpdateRequestRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puUpdateRequest($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-25: edits the location, the required date and the notes of a draft, moves updated_by and updated_at, and writes one audit row with both images', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['notes' => 'old']);
    $other = PurchasingSeed::location($desk['branch'], 'DOCK2');
    $editor = AccessSeed::actorHolding('editor', [PurchasingPermissions::REQUESTS_MANAGE], $desk['branch']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puUpdateRequest([
        'request_id' => strtolower($request),
        'location_id' => strtolower($other),
        'required_date' => '2026-12-01',
        'notes' => ' new ',
    ], $editor);

    expect($result->changedColumns)->toBe(['location_id', 'required_date', 'notes'])
        ->and(PurchasingSeed::row('purchase_requests', $request))->toMatchArray([
            'location_id' => $other,
            'required_date' => '2026-12-01',
            'notes' => 'new',
            'branch_id' => $desk['branch'],
            'requested_by' => $desk['buyer'],
            'updated_by' => $editor,
            'updated_at' => '2026-11-17 10:00:00.000',
            'status' => 'draft',
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $editor,
        'branch_id' => $desk['branch'],
        'action' => PurchasingAuditActions::REQUEST_UPDATED,
        'subject_type' => 'purchase_request',
        'subject_id' => $request,
    ])
        ->and($audit['before']['notes'])->toBe('old')
        ->and($audit['after']['notes'])->toBe('new')
        ->and($audit['changed_fields'])->toBe(['location_id', 'notes', 'required_date', 'updated_at', 'updated_by']);
});

it('AC-25: a value left out is kept, and null clears the required date and the notes', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['notes' => 'keep', 'required_date' => '2026-12-01']);

    $cleared = puUpdateRequest(['request_id' => $request, 'required_date' => null], $desk['buyer']);

    expect($cleared->changedColumns)->toBe(['required_date'])
        ->and($cleared->request['notes'])->toBe('keep')
        ->and($cleared->request['required_date'])->toBeNull();

    expect(puUpdateRequest(['request_id' => $request, 'notes' => null], $desk['buyer'])->request['notes'])->toBeNull();
});

it('AC-25: a call that changes nothing writes nothing at all, and an unchanged past date is not judged again', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['notes' => 'n', 'required_date' => '2026-01-05']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $before = PurchasingRefusals::state();

    $result = puUpdateRequest([
        'request_id' => $request,
        'location_id' => $desk['location'],
        'required_date' => '2026-01-05',
        'notes' => ' n ',
    ], $desk['buyer']);

    expect($result->changed())->toBeFalse()
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(puUpdateRequest(['request_id' => $request], $desk['buyer'])->changed())->toBeFalse();

    // A request whose date has passed can still have its notes edited.
    expect(puUpdateRequest(['request_id' => $request, 'notes' => 'later'], $desk['buyer'])->request['required_date'])->toBe('2026-01-05');
});

it('AC-25: refuses every edit of a request that is not a draft as locked, and writes nothing', function (string $status) {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => $status]);

    puUpdateRequestRefused(PurchasingRefusal::Locked, ['request_id' => $request, 'notes' => 'x'], $desk['buyer'], $status);
    // A call that would change nothing is locked too: the status is decided first.
    puUpdateRequestRefused(PurchasingRefusal::Locked, ['request_id' => $request], $desk['buyer'], $status.' (no change)');
})->with(array_combine(
    array_map(static fn (RequestStatus $status): string => $status->value, array_values(array_filter(RequestStatus::cases(), static fn (RequestStatus $status): bool => ! $status->isEditable()))),
    array_map(static fn (RequestStatus $status): array => [$status->value], array_values(array_filter(RequestStatus::cases(), static fn (RequestStatus $status): bool => ! $status->isEditable()))),
));

it('AC-20: refuses a location that is not an eligible delivery location of the request s branch, and a required date before today', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $otherBranch = PurchasingSeed::branch();

    puUpdateRequestRefused(PurchasingRefusal::UnknownLocation, ['request_id' => $request, 'location_id' => PurchasingSeed::location($otherBranch)], $desk['buyer'], 'another branch');
    puUpdateRequestRefused(PurchasingRefusal::UnknownLocation, ['request_id' => $request, 'location_id' => PurchasingSeed::location(null, 'CENTRAL')], $desk['buyer'], 'central');
    puUpdateRequestRefused(PurchasingRefusal::UnknownLocation, ['request_id' => $request, 'location_id' => (string) Str::ulid()], $desk['buyer'], 'unknown');
    puUpdateRequestRefused(PurchasingRefusal::Validation, ['request_id' => $request, 'required_date' => '2026-11-15'], $desk['buyer'], 'yesterday');
    puUpdateRequestRefused(PurchasingRefusal::Validation, ['request_id' => $request, 'required_date' => '2026-02-30'], $desk['buyer'], 'impossible');
    puUpdateRequestRefused(PurchasingRefusal::Validation, ['request_id' => $request, 'location_id' => null], $desk['buyer'], 'null location');
});

it('AC-25: the branch never changes and a caller-supplied status, number or requester is an unknown field', function (string $field, mixed $value) {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);

    puUpdateRequestRefused(PurchasingRefusal::Validation, ['request_id' => $request, $field => $value], $desk['buyer'], $field);
})->with([
    'branch_id' => ['branch_id', '01JABCDEFGHJKMNPQRSTVWXYZ0'],
    'status' => ['status', 'approved'],
    'number' => ['number', 'PR9'],
    'requested_by' => ['requested_by', '01JABCDEFGHJKMNPQRSTVWXYZ0'],
]);

it('AC-25: an unknown request is not_found and a missing or malformed id is validation', function () {
    $desk = PurchasingWorld::desk();

    puUpdateRequestRefused(PurchasingRefusal::NotFound, ['request_id' => (string) Str::ulid(), 'notes' => 'x'], $desk['buyer']);
    puUpdateRequestRefused(PurchasingRefusal::Validation, ['notes' => 'x'], $desk['buyer']);
    puUpdateRequestRefused(PurchasingRefusal::Validation, ['request_id' => 'nope', 'notes' => 'x'], $desk['buyer']);
});

it('AC-21 and AC-85 d: the permission is asked at the request s stored branch', function () {
    $desk = PurchasingWorld::desk();
    $elsewhere = PurchasingSeed::branch();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);

    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::REQUESTS_MANAGE], $elsewhere);
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::REQUESTS_VIEW], $desk['branch']);
    $companyWide = AccessSeed::actorHolding('director', [PurchasingPermissions::REQUESTS_MANAGE]);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateRequest(['request_id' => $request, 'notes' => 'x'], $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateRequest(['request_id' => $request, 'notes' => 'x'], $viewer));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puUpdateRequest(['request_id' => $request, 'notes' => 'x'], null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(puUpdateRequest(['request_id' => $request, 'notes' => 'x'], $companyWide)->changedColumns)->toBe(['notes']);
});
