<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Approvals;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Approvals\ApprovalGate;
use Flexipos\Sales\Approvals\ApprovalType;
use Flexipos\Sales\Approvals\ConsumedApproval;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 10 - ApprovalGate::consume() (AC-24; D-47; Ruling 5; R-4, R-8)
|--------------------------------------------------------------------------
|
| Called inside the consuming write's transaction. Four refusals by name, in
| order, then the binding table; and the first consume sets `consumed_at`.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/sales-fixture.php';
require_once __DIR__.'/../Support/approval-fixture.php';

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
 * @param  array<string, mixed>|null  $payload
 */
function consumeApproval(ApprovalType $type, string $branch, string $referenceType, ?string $referenceId, ?array $payload, ?string $approvalId): ConsumedApproval
{
    /** @var ApprovalGate $gate */
    $gate = app(ApprovalGate::class);

    return SalesSeed::connection()->transaction(
        fn (): ConsumedApproval => $gate->consume($type, $branch, $referenceType, $referenceId, $payload, $approvalId)
    );
}

/**
 * An approved void approval for the world's sale, decided by the manager.
 *
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $overrides
 */
function approvedVoid(array $w, array $overrides = []): string
{
    return SalesSeed::approval($w['branch'], $w['cashier'], array_merge([
        'type' => 'void',
        'reference_id' => $w['sale'],
        'status' => 'approved',
        'decided_by' => $w['manager'],
        'decided_at' => SalesSeed::NOW,
        'decision_method' => 'pin_local',
    ], $overrides));
}

it('consumes an approved request once: it returns who approved it and stamps consumed_at', function () {
    $w = apvWorld();
    $id = approvedVoid($w);

    $consumed = consumeApproval(ApprovalType::Void, $w['branch'], 'sale', $w['sale'], null, $id);

    expect($consumed->approvalId)->toBe($id)
        ->and($consumed->decidedBy)->toBe($w['manager'])
        ->and($consumed->requestedBy)->toBe($w['cashier'])
        ->and(SalesRefusals::capture(fn () => consumeApproval(ApprovalType::Void, $w['branch'], 'sale', $w['sale'], null, $id))->reason)
        ->toBe(SalesRefusal::ApprovalAlreadyUsed);

    expect(SalesSeed::row('approval_requests', $id)['consumed_at'])->toBe('2026-11-12 10:00:00.000');
});

it('refuses with approval_required when none was given or none exists', function () {
    $w = apvWorld();

    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => consumeApproval(ApprovalType::Void, $w['branch'], 'sale', $w['sale'], null, null));
    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => consumeApproval(ApprovalType::Void, $w['branch'], 'sale', $w['sale'], null, '01HZZZZZZZZZZZZZZZZZZZZZZZ'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => consumeApproval(ApprovalType::Void, $w['branch'], 'sale', $w['sale'], null, 'not-an-id'));
});

it('refuses a pending or rejected request as approval_not_approved, and nothing is consumed', function (string $status) {
    $w = apvWorld();
    $id = approvedVoid($w, ['status' => $status]);

    SalesRefusals::expect(SalesRefusal::ApprovalNotApproved, fn () => consumeApproval(ApprovalType::Void, $w['branch'], 'sale', $w['sale'], null, $id));

    expect(SalesSeed::row('approval_requests', $id)['consumed_at'])->toBeNull();
})->with(['pending' => ['pending'], 'rejected' => ['rejected'], 'expired' => ['expired']]);

it('refuses an already consumed request as approval_already_used', function () {
    $w = apvWorld();
    $id = approvedVoid($w, ['consumed_at' => SalesSeed::NOW]);

    SalesRefusals::expect(SalesRefusal::ApprovalAlreadyUsed, fn () => consumeApproval(ApprovalType::Void, $w['branch'], 'sale', $w['sale'], null, $id));
});

it('refuses a type, branch or reference that is not the one approved, as approval_mismatch, and consumes nothing', function (string $field) {
    $w = apvWorld();
    $id = approvedVoid($w);

    $arguments = [
        'type' => [ApprovalType::Refund, $w['branch'], 'sale', $w['sale']],
        'branch' => [ApprovalType::Void, $w['otherBranch'], 'sale', $w['sale']],
        'reference type' => [ApprovalType::Void, $w['branch'], 'goodwill_refund', null],
        'reference id' => [ApprovalType::Void, $w['branch'], 'sale', '01HZZZZZZZZZZZZZZZZZZZZZZZ'],
    ][$field];

    SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => consumeApproval(...[...$arguments, null, $id]));

    expect(SalesSeed::row('approval_requests', $id)['consumed_at'])->toBeNull();
})->with(['type', 'branch', 'reference type', 'reference id']);

it('compares a reference id in any spelling', function () {
    $w = apvWorld();
    $id = approvedVoid($w);

    expect(consumeApproval(ApprovalType::Void, strtolower($w['branch']), 'sale', strtolower($w['sale']), null, strtolower($id))->approvalId)->toBe($id);
});

it('binds a goodwill refund to a null reference', function () {
    $w = apvWorld();
    $id = approvedVoid($w, ['type' => 'refund', 'reference_type' => 'goodwill_refund', 'reference_id' => null]);

    SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => consumeApproval(ApprovalType::Refund, $w['branch'], 'goodwill_refund', $w['sale'], null, $id));

    expect(consumeApproval(ApprovalType::Refund, $w['branch'], 'goodwill_refund', null, null, $id)->decidedBy)->toBe($w['manager']);
});

it('matches an override payload by value: 7.5 is 7.5000, and a different price is a mismatch (R-8)', function () {
    $w = apvWorld();
    $payload = ['product_id' => '01HZZZZZZZZZZZZZZZZZZZZZZA', 'unit_id' => '01HZZZZZZZZZZZZZZZZZZZZZZB', 'unit_price' => '7.5000'];
    $id = approvedVoid($w, ['type' => 'price_override', 'payload' => json_encode($payload)]);

    SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => consumeApproval(ApprovalType::PriceOverride, $w['branch'], 'sale', $w['sale'], array_merge($payload, ['unit_price' => '7.5001']), $id));
    SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => consumeApproval(ApprovalType::PriceOverride, $w['branch'], 'sale', $w['sale'], array_merge($payload, ['unit_id' => '01HZZZZZZZZZZZZZZZZZZZZZZC']), $id));
    SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => consumeApproval(ApprovalType::PriceOverride, $w['branch'], 'sale', $w['sale'], ['unit_price' => '7.5'], $id));
    SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => consumeApproval(ApprovalType::PriceOverride, $w['branch'], 'sale', $w['sale'], null, $id));

    expect(SalesSeed::row('approval_requests', $id)['consumed_at'])->toBeNull();

    $sameNumber = ['product_id' => strtolower('01HZZZZZZZZZZZZZZZZZZZZZZA'), 'unit_id' => '01HZZZZZZZZZZZZZZZZZZZZZZB', 'unit_price' => '7.5'];

    expect(consumeApproval(ApprovalType::PriceOverride, $w['branch'], 'sale', $w['sale'], $sameNumber, $id)->approvalId)->toBe($id);
});

it('refuses a payload the approval does not carry', function () {
    $w = apvWorld();
    $id = approvedVoid($w);

    SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => consumeApproval(ApprovalType::Void, $w['branch'], 'sale', $w['sale'], ['discount_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'], $id));
});

it('binds a discount approval to the discount it names', function () {
    $w = apvWorld();
    $id = approvedVoid($w, ['type' => 'discount', 'payload' => json_encode(['discount_id' => '01HZZZZZZZZZZZZZZZZZZZZZZD'])]);

    SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => consumeApproval(ApprovalType::Discount, $w['branch'], 'sale', $w['sale'], ['discount_id' => '01HZZZZZZZZZZZZZZZZZZZZZZE'], $id));

    expect(consumeApproval(ApprovalType::Discount, $w['branch'], 'sale', $w['sale'], ['discount_id' => '01hzzzzzzzzzzzzzzzzzzzzzzd'], $id)->approvalId)->toBe($id);
});

it('names only ids it was given in a refusal, never anything else the approval carries', function () {
    $w = apvWorld();
    $id = approvedVoid($w, ['reason' => 'a private reason']);

    $refused = SalesRefusals::capture(fn () => consumeApproval(ApprovalType::Refund, $w['branch'], 'sale', $w['sale'], null, $id));

    expect($refused->getMessage())->not->toContain('a private reason');
});
