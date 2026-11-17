<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\RequestApproval;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\RequestApproval\RequestApproval;
use Flexipos\Sales\Features\RequestApproval\RequestApprovalHandler;
use Flexipos\Sales\Features\RequestApproval\RequestApprovalResult;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 10 - RequestApproval (AC-23; D-13, D-47)
|--------------------------------------------------------------------------
|
| Four types only, each bound as the D-47 table says, the requester is the
| actor, and the write is one row plus one audit row.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/sales-fixture.php';
require_once __DIR__.'/../../Support/approval-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    salesEnterTenant();
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
function requestApproval(array $fields, ?string $actorId): RequestApprovalResult
{
    /** @var RequestApprovalHandler $handler */
    $handler = app(RequestApprovalHandler::class);

    return $handler->handle(new RequestApproval($fields, $actorId));
}

/**
 * @param  array<string, mixed>  $w  the approval world
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function voidRequest(array $w, array $overrides = []): array
{
    return array_merge(['type' => 'void', 'branch_id' => $w['branch'], 'reference_type' => 'sale', 'reference_id' => $w['sale']], $overrides);
}

it('writes one pending approval request bound to the sale, and one audit row', function () {
    $w = apvWorld();

    $result = requestApproval(voidRequest($w, ['reference_id' => strtolower($w['sale'])]), $w['cashier']);

    $row = SalesSeed::row('approval_requests', $result->approvalId);

    expect($row)->toMatchArray([
        'type' => 'void',
        'status' => 'pending',
        'branch_id' => $w['branch'],
        'reference_type' => 'sale',
        // The caller's lower-case spelling is folded before it is stored.
        'reference_id' => $w['sale'],
        'requested_by' => $w['cashier'],
        'payload' => null,
        'decided_by' => null,
        'decided_at' => null,
        'decision_method' => null,
        'consumed_at' => null,
    ]);

    expect(SalesSeed::onlyAuditRow())->toMatchArray([
        'module' => 'sales',
        'action' => 'sales_approval_requested',
        'subject_type' => 'approval_request',
        'subject_id' => $result->approvalId,
        'before' => null,
    ]);
});

it('accepts each of the four types with its own binding', function (array $fields, ?array $payload) {
    $w = apvWorld();

    // '@sale' stands for the world's sale: a dataset cannot hold it, it is built per test.
    if (($fields['reference_id'] ?? null) === '@sale') {
        $fields['reference_id'] = $w['sale'];
    }

    $result = requestApproval(array_merge(['branch_id' => $w['branch']], $fields), $w['cashier']);

    $stored = SalesSeed::row('approval_requests', $result->approvalId);

    expect($stored['payload'] === null ? null : json_decode((string) $stored['payload'], true))->toBe($payload);
})->with([
    'void' => [
        ['type' => 'void', 'reference_type' => 'sale', 'reference_id' => '@sale'],
        null,
    ],
    'a refund against a sale' => [
        ['type' => 'refund', 'reference_type' => 'sale', 'reference_id' => '@sale'],
        null,
    ],
    'a goodwill refund' => [
        ['type' => 'refund', 'reference_type' => 'goodwill_refund', 'reference_id' => null],
        null,
    ],
    'a discount, its id folded' => [
        ['type' => 'discount', 'reference_type' => 'sale', 'reference_id' => '@sale', 'payload' => ['discount_id' => '01hzzzzzzzzzzzzzzzzzzzzzzz']],
        ['discount_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
    ],
    'a price override, its price canonicalised' => [
        ['type' => 'price_override', 'reference_type' => 'sale', 'reference_id' => '@sale', 'payload' => ['product_id' => '01HZZZZZZZZZZZZZZZZZZZZZZA', 'unit_id' => '01HZZZZZZZZZZZZZZZZZZZZZZB', 'unit_price' => '7.5']],
        ['product_id' => '01HZZZZZZZZZZZZZZZZZZZZZZA', 'unit_id' => '01HZZZZZZZZZZZZZZZZZZZZZZB', 'unit_price' => '7.5000'],
    ],
]);

it('refuses credit_limit, negative_stock, custom and anything else as validation (D-13)', function (string $type) {
    $w = apvWorld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => requestApproval(voidRequest($w, ['type' => $type]), $w['cashier']));
})->with(['credit_limit' => ['credit_limit'], 'negative_stock' => ['negative_stock'], 'custom' => ['custom'], 'unknown' => ['bogus'], 'upper case' => ['VOID']]);

it('refuses a binding that is not the D-47 shape', function (array $overrides) {
    $w = apvWorld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => requestApproval(voidRequest($w, $overrides), $w['cashier']));
})->with([
    'a void with a payload' => [['payload' => ['discount_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']]],
    'a void against a goodwill reference' => [['reference_type' => 'goodwill_refund', 'reference_id' => null]],
    'a sale reference with no id' => [['reference_id' => null]],
    'a goodwill refund naming a sale' => [['type' => 'refund', 'reference_type' => 'goodwill_refund']],
    'a discount with no payload' => [['type' => 'discount']],
    'a discount payload with an extra key' => [['type' => 'discount', 'payload' => ['discount_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'x' => 1]]],
    'an override missing its price' => [['type' => 'price_override', 'payload' => ['product_id' => '01HZZZZZZZZZZZZZZZZZZZZZZA', 'unit_id' => '01HZZZZZZZZZZZZZZZZZZZZZZB']]],
    'an override price with a fifth decimal' => [['type' => 'price_override', 'payload' => ['product_id' => '01HZZZZZZZZZZZZZZZZZZZZZZA', 'unit_id' => '01HZZZZZZZZZZZZZZZZZZZZZZB', 'unit_price' => '1.00005']]],
    'a malformed sale id' => [['reference_id' => 'not-a-ulid']],
]);

it('refuses fields it does not take', function (string $field) {
    $w = apvWorld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => requestApproval(voidRequest($w, [$field => 'x']), $w['cashier']));
})->with(['plugin_name' => ['plugin_name'], 'status' => ['status'], 'decided_by' => ['decided_by'], 'consumed_at' => ['consumed_at'], 'requested_by' => ['requested_by'], 'decision_method' => ['decision_method'], 'id' => ['id']]);

it('refuses an unknown sale, branch or terminal, and a sale at another branch', function () {
    $w = apvWorld();
    $unknown = '01HZZZZZZZZZZZZZZZZZZZZZZZ';

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => requestApproval(voidRequest($w, ['reference_id' => $unknown]), $w['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => requestApproval(voidRequest($w, ['branch_id' => $unknown]), $w['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => requestApproval(voidRequest($w, ['terminal_id' => $unknown]), $w['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => requestApproval(voidRequest($w, ['branch_id' => $w['otherBranch']]), $w['cashier']));

    // The control: the same request, with a real terminal, succeeds and records it.
    $ok = requestApproval(voidRequest($w, ['terminal_id' => strtolower($w['terminal'])]), $w['cashier']);

    expect(SalesSeed::row('approval_requests', $ok->approvalId)['terminal_id'])->toBe($w['terminal']);
});

it('needs a live requesting employee, and writes nothing without one', function () {
    $w = apvWorld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => requestApproval(voidRequest($w), null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => requestApproval(voidRequest($w), '01HZZZZZZZZZZZZZZZZZZZZZZZ'));

    expect(SalesSeed::connection()->table('approval_requests')->count())->toBe(0)
        ->and(SalesSeed::audit())->toBe([]);
});
