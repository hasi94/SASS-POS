<?php

namespace Flexipos\Hr\Tests\Support;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Support\Fields;

/*
|--------------------------------------------------------------------------
| Which keys a request may carry (T-038 plan step 5; AC-16, AC-22, AC-42, D-21)
|--------------------------------------------------------------------------
*/

it('accepts a body inside the allowed set and an empty body', function () {
    Fields::only(['name' => 'x', 'branch_id' => 'y'], ['name', 'branch_id', 'phone']);
    Fields::only([], ['name']);

    expect(true)->toBeTrue();
});

it('names every unknown key in one validation refusal', function () {
    $refused = HrRefusals::expect(
        HrRefusal::Validation,
        fn () => Fields::only(['name' => 'x', 'salary' => 1, 'bank' => 2, 'is_active' => true], ['name'])
    );

    expect($refused->context['fields'])->toBe(['salary', 'bank', 'is_active'])
        ->and($refused->getMessage())->toContain('salary')->toContain('bank')->toContain('is_active');
});

it('refuses an identity field as not_updatable_here and still names the others', function () {
    $refused = HrRefusals::expect(
        HrRefusal::NotUpdatableHere,
        fn () => Fields::only(
            ['employment_type' => 'permanent', 'first_name' => 'A', 'salary' => 9, 'pin_hash' => 'h'],
            ['employment_type'],
            Fields::IDENTITY,
        )
    );

    expect($refused->context['fields'])->toBe(['first_name', 'salary', 'pin_hash']);
});

it('refuses every identity key the spec names as not_updatable_here', function () {
    foreach (['code', 'first_name', 'last_name', 'display_name', 'status', 'user_id', 'pin_hash', 'perm_version'] as $key) {
        HrRefusals::expect(HrRefusal::NotUpdatableHere, fn () => Fields::only([$key => 'v'], ['branch_id'], Fields::IDENTITY), $key);
    }

    expect(Fields::IDENTITY)->toHaveCount(8);
});

it('treats a key that has its own operation as not_updatable_here on an update', function () {
    foreach (['code', 'parent_id', 'is_active', 'id', 'deleted_at'] as $key) {
        HrRefusals::expect(
            HrRefusal::NotUpdatableHere,
            fn () => Fields::only([$key => 'v'], ['name'], ['code', 'parent_id', 'is_active', 'id', 'deleted_at']),
            $key
        );
    }
});

it('names fields only: a hostile key is not echoed, a sensitive value never was', function () {
    $sentinel = 'SENTINEL-8812-QQ';

    $refused = HrRefusals::capture(fn () => Fields::only(['nic:'.$sentinel => $sentinel, 'nic' => $sentinel], ['name']));

    expect($refused->getMessage())->not->toContain($sentinel)
        ->and($refused->getMessage())->toContain('nic')
        ->and($refused->getMessage())->toContain('(invalid field name)');
});

it('refuses a numeric key rather than treating it as allowed', function () {
    HrRefusals::expect(HrRefusal::Validation, fn () => Fields::only([0 => 'x'], ['name']));
});
