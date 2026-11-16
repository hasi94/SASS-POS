<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\RemovePurchaseRequestLine;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\RemovePurchaseRequestLine\RemovePurchaseRequestLine;
use Flexipos\Purchasing\Features\RemovePurchaseRequestLine\RemovePurchaseRequestLineHandler;
use Flexipos\Purchasing\Features\RemovePurchaseRequestLine\RemovePurchaseRequestLineResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 12 - RemovePurchaseRequestLine (AC-25, AC-39)
|--------------------------------------------------------------------------
|
| A draft line is deleted, its before image is in the audit row, and only a draft
| may lose a line.
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
function puRemoveRequestLine(array $fields, ?string $actorId): RemovePurchaseRequestLineResult
{
    /** @var RemovePurchaseRequestLineHandler $handler */
    $handler = app(RemovePurchaseRequestLineHandler::class);

    return $handler->handle(new RemovePurchaseRequestLine($fields, $actorId));
}

it('AC-25: deletes the line, keeps the others, and records the line as it was in the audit row s before image', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $first = PurchasingSeed::requestItem($request, $desk['product'], $desk['unit'], 1, ['quantity' => '5.0000', 'base_quantity' => '5.0000', 'estimated_cost' => '2.5000']);
    $second = PurchasingSeed::requestItem($request, $desk['product'], $desk['unit'], 2);

    $result = puRemoveRequestLine(['request_id' => strtolower($request), 'line_id' => strtolower($first)], $desk['buyer']);

    expect($result->removed['id'])->toBe($first)
        ->and(PurchasingSeed::connection()->table('purchase_request_items')->where('id', $first)->exists())->toBeFalse()
        ->and(PurchasingSeed::connection()->table('purchase_request_items')->where('id', $second)->exists())->toBeTrue();

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $desk['buyer'],
        'branch_id' => $desk['branch'],
        'action' => PurchasingAuditActions::REQUEST_LINE_REMOVED,
        'subject_type' => 'purchase_request',
        'subject_id' => $request,
        'after' => null,
    ])
        ->and($audit['before']['id'])->toBe($first)
        ->and($audit['before']['quantity'])->toBe('5.0000')
        ->and($audit['before']['estimated_cost'])->toBe('2.5000');
});

it('AC-25: removing the last line leaves a valid empty draft', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $line = PurchasingSeed::requestItem($request, $desk['product'], $desk['unit']);

    puRemoveRequestLine(['request_id' => $request, 'line_id' => $line], $desk['buyer']);

    expect(PurchasingSeed::connection()->table('purchase_request_items')->where('request_id', $request)->count())->toBe(0)
        ->and(PurchasingSeed::row('purchase_requests', $request)['status'])->toBe('draft');
});

it('AC-25: refuses a removal from a request that is not a draft as locked, and writes nothing', function (string $status) {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => $status]);
    $line = PurchasingSeed::requestItem($request, $desk['product'], $desk['unit']);
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::Locked, fn () => puRemoveRequestLine(['request_id' => $request, 'line_id' => $line], $desk['buyer']), $status);

    expect(PurchasingRefusals::state())->toBe($before);
})->with(array_combine(
    array_map(static fn (RequestStatus $status): string => $status->value, array_values(array_filter(RequestStatus::cases(), static fn (RequestStatus $status): bool => ! $status->isEditable()))),
    array_map(static fn (RequestStatus $status): array => [$status->value], array_values(array_filter(RequestStatus::cases(), static fn (RequestStatus $status): bool => ! $status->isEditable()))),
));

it('AC-25: a second removal of the same line, a line of another request and a malformed id are refused, and write nothing', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $other = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $line = PurchasingSeed::requestItem($request, $desk['product'], $desk['unit']);
    $foreign = PurchasingSeed::requestItem($other, $desk['product'], $desk['unit']);

    puRemoveRequestLine(['request_id' => $request, 'line_id' => $line], $desk['buyer']);

    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => puRemoveRequestLine(['request_id' => $request, 'line_id' => $line], $desk['buyer']), 'again');
    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => puRemoveRequestLine(['request_id' => $request, 'line_id' => $foreign], $desk['buyer']), 'another request');
    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => puRemoveRequestLine(['request_id' => (string) Str::ulid(), 'line_id' => $line], $desk['buyer']), 'unknown request');
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puRemoveRequestLine(['request_id' => $request, 'line_id' => 'nope'], $desk['buyer']), 'malformed');
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puRemoveRequestLine(['request_id' => $request], $desk['buyer']), 'missing');
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puRemoveRequestLine(['request_id' => $request, 'line_id' => $line, 'quantity' => '1'], $desk['buyer']), 'unknown field');

    expect(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::connection()->table('purchase_request_items')->where('id', $foreign)->exists())->toBeTrue();
});

it('AC-21 and AC-38: the permission is asked at the stored branch, and the view key alone is refused', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $line = PurchasingSeed::requestItem($request, $desk['product'], $desk['unit']);
    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::REQUESTS_MANAGE], PurchasingSeed::branch());
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::REQUESTS_VIEW], $desk['branch']);
    $fields = ['request_id' => $request, 'line_id' => $line];

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puRemoveRequestLine($fields, $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puRemoveRequestLine($fields, $viewer));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puRemoveRequestLine($fields, null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
});
