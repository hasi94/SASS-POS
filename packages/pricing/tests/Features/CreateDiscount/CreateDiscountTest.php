<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\CreateDiscount;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\CreateDiscount\CreateDiscount;
use Flexipos\Pricing\Features\CreateDiscount\CreateDiscountHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 15 - creating a discount (AC-19)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-08 12:00:00.000'));
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
function cdCreate(array $fields): string
{
    /** @var CreateDiscountHandler $handler */
    $handler = app(CreateDiscountHandler::class);

    return $handler->handle(new CreateDiscount($fields))->discountId;
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function cdFields(array $extra = []): array
{
    return array_merge(['code' => 'D'.substr(md5(serialize($extra)), 0, 8), 'name' => 'Ten off', 'type' => 'percentage', 'value' => '10', 'scope' => 'item'], $extra);
}

it('creates a discount with the documented defaults and writes one audit row', function () {
    $id = cdCreate(cdFields(['code' => 'TEN']));

    $row = PricingSeed::row('discounts', $id);

    expect($row)->toMatchArray([
        'code' => 'TEN',
        'type' => 'percentage',
        'scope' => 'item',
        'applies_to' => null,
        'min_amount' => null,
        'max_discount' => null,
        'approval_threshold' => null,
        'requires_approval' => 0,
        'workflow_definition_id' => null,
        'starts_at' => null,
        'ends_at' => null,
        'usage_limit' => null,
        'used_count' => 0,
        'is_active' => 1,
        'deleted_at' => null,
    ])->and((float) $row['value'])->toBe(10.0);

    expect(PricingSeed::onlyAuditRow())->toMatchArray(['action' => 'pricing_discount_created', 'subject_type' => 'discount', 'subject_id' => $id]);
});

it('stores every optional column as supplied', function () {
    $workflow = PricingSeed::workflowDefinition('discount-approval');

    $id = cdCreate(cdFields([
        'code' => 'FULL', 'type' => 'fixed', 'value' => '25.5', 'scope' => 'bill', 'min_amount' => '100', 'max_discount' => '50',
        'requires_approval' => true, 'approval_threshold' => '40', 'workflow_definition_id' => strtolower($workflow),
        'starts_at' => '2025-06-01T00:00:00.000Z', 'ends_at' => '2025-06-30T23:59:59.999Z', 'usage_limit' => 100, 'is_active' => false,
    ]));

    $row = PricingSeed::row('discounts', $id);

    expect($row)->toMatchArray([
        'type' => 'fixed', 'scope' => 'bill', 'requires_approval' => 1, 'workflow_definition_id' => $workflow,
        'starts_at' => '2025-06-01 00:00:00.000', 'ends_at' => '2025-06-30 23:59:59.999', 'usage_limit' => 100, 'is_active' => 0,
    ])->and((float) $row['min_amount'])->toBe(100.0)
        ->and((float) $row['max_discount'])->toBe(50.0)
        ->and((float) $row['approval_threshold'])->toBe(40.0);
});

it('refuses each missing required field, and a bad type or scope', function () {
    foreach (['code', 'name', 'type', 'value', 'scope'] as $required) {
        $fields = cdFields(['code' => 'X']);
        unset($fields[$required]);

        PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate($fields), "missing {$required}");
    }

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate(cdFields(['type' => 'bogo'])), 'bad type');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate(cdFields(['scope' => 'order'])), 'bad scope');

    expect(PricingSeed::connection()->table('discounts')->count())->toBe(0);
});

it('refuses a percentage above 100 and accepts exactly 100 (D-27, AC-30.2), while a fixed value may exceed 100', function () {
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate(cdFields(['value' => '100.0001'])), 'just above');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate(cdFields(['value' => '101'])), 'above');

    cdCreate(cdFields(['code' => 'ALL', 'value' => '100']));
    cdCreate(cdFields(['code' => 'ALL2', 'value' => '100.0000']));
    cdCreate(cdFields(['code' => 'FIXED', 'type' => 'fixed', 'value' => '500']));

    expect(PricingSeed::connection()->table('discounts')->count())->toBe(3);
});

it('refuses negative money and a float, wherever a money column is', function (string $field) {
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate(cdFields([$field => '-1'])), 'negative');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate(cdFields([$field => 1.5])), 'a float');
})->with(['value', 'min_amount', 'max_discount', 'approval_threshold']);

it('requires a buy_x_get_y discount to name at least one product, as missing_target', function () {
    PricingAdminRefusals::expect(PricingAdminRefusal::MissingTarget, fn () => cdCreate(cdFields(['type' => 'buy_x_get_y'])), 'no applies_to');
    PricingAdminRefusals::expect(PricingAdminRefusal::MissingTarget, fn () => cdCreate(cdFields(['type' => 'buy_x_get_y', 'applies_to' => ['categories' => ['C1']]])), 'categories only');
    PricingAdminRefusals::expect(PricingAdminRefusal::MissingTarget, fn () => cdCreate(cdFields(['type' => 'buy_x_get_y', 'applies_to' => ['products' => []]])), 'empty products');

    cdCreate(cdFields(['code' => 'BXGY', 'type' => 'buy_x_get_y', 'applies_to' => ['products' => ['P1']]]));

    expect(PricingSeed::connection()->table('discounts')->count())->toBe(1);
});

it('stores applies_to as supplied, without checking that any listed id exists', function () {
    $given = ['products' => ['NOT-A-REAL-ID', 'P2'], 'categories' => ['C1'], 'brands' => []];

    $id = cdCreate(cdFields(['code' => 'A', 'applies_to' => $given]));
    $asJson = cdCreate(cdFields(['code' => 'B', 'applies_to' => json_encode($given)]));

    expect(json_decode((string) PricingSeed::row('discounts', $id)['applies_to'], true))->toBe($given)
        ->and(json_decode((string) PricingSeed::row('discounts', $asJson)['applies_to'], true))->toBe($given);
});

it('accepts a null and an empty applies_to, which both mean everything', function () {
    cdCreate(cdFields(['code' => 'A', 'applies_to' => null]));
    $empty = cdCreate(cdFields(['code' => 'B', 'applies_to' => []]));

    expect(json_decode((string) PricingSeed::row('discounts', $empty)['applies_to'], true))->toBe([]);
});

it('refuses an applies_to of the wrong shape, naming the field (K-11, D-32)', function (mixed $appliesTo) {
    $refused = PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate(cdFields(['applies_to' => $appliesTo])));

    expect($refused->getMessage())->toContain('applies_to')
        ->and(PricingSeed::connection()->table('discounts')->count())->toBe(0);
})->with([
    'a foreign key' => [['customers' => ['C1']]],
    'a scalar list value' => [['products' => 'X']],
    'an object list value' => [['products' => ['a' => 'X']]],
    'a non-string entry' => [['products' => [1]]],
    'a nested list' => [['products' => [['X']]]],
    'a bare list at the top' => [['X', 'Y']],
    'a number' => [5],
    'a boolean' => [true],
    'text that is not JSON' => ['not json'],
    'JSON that is not an object' => ['"products"'],
]);

it('refuses used_count whole, writing nothing at all', function () {
    PricingAdminRefusals::expect(PricingAdminRefusal::Maintained, fn () => cdCreate(cdFields(['used_count' => 0])));
    PricingAdminRefusals::expect(PricingAdminRefusal::Maintained, fn () => cdCreate(cdFields(['used_count' => 7, 'name' => 'x'])));

    expect(PricingSeed::connection()->table('discounts')->count())->toBe(0)
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});

it('refuses the sales discount account column as unknown, because it is not built (F-17)', function () {
    $refused = PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate(cdFields(['sales_discount_account_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])));

    expect($refused->getMessage())->toContain('sales_discount_account_id');
});

it('refuses an unknown workflow definition and accepts an existing one', function () {
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => cdCreate(cdFields(['workflow_definition_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])));
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate(cdFields(['workflow_definition_id' => 'nope'])));

    cdCreate(cdFields(['code' => 'OK', 'workflow_definition_id' => PricingSeed::workflowDefinition('w')]));

    expect(PricingSeed::connection()->table('discounts')->count())->toBe(1);
});

it('refuses an ends_at before the starts_at, a bad usage_limit and a duplicate code also by case', function () {
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate(cdFields(['starts_at' => '2025-06-15T09:30:00.001Z', 'ends_at' => '2025-06-15T09:30:00.000Z'])), 'window');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate(cdFields(['usage_limit' => -1])), 'negative limit');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cdCreate(cdFields(['usage_limit' => 1.5])), 'float limit');

    cdCreate(cdFields(['code' => 'TEN', 'usage_limit' => 0]));

    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => cdCreate(cdFields(['code' => 'ten'])), 'code by case');

    expect(PricingSeed::connection()->table('discounts')->count())->toBe(1);
});
