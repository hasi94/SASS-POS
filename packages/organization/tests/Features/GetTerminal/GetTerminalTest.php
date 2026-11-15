<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\GetTerminal;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\GetTerminal\GetTerminal;
use Flexipos\Organization\Features\GetTerminal\GetTerminalHandler;
use Flexipos\Organization\Support\ActivationCode;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 10 - reading one terminal (AC-42)
|--------------------------------------------------------------------------
|
| **The hash's absence is asserted over the key list**, not by looking for one
| value: the view's keys are enumerated and `activation_code_hash` is in none
| of them, and the stored hash string is in no value either. `has_pending_code`
| is the boolean that says a code exists.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('shows the fields AC-42 lists, has_pending_code as a boolean, and never the hash (AC-42)', function () {
    $branch = OrganizationSeed::branch();
    $store = OrganizationSeed::location($branch, 'ST1', 'branch_store', ['is_sellable_from' => true]);
    $hash = ActivationCode::hashOf('ABCD-EFGH');
    $pending = OrganizationSeed::terminal($branch, $store, 'T1', 'pending', ['activation_code_hash' => $hash, 'activation_expires_at' => '2026-10-23 10:00:00.000']);
    $active = OrganizationSeed::terminal($branch, $store, 'T2', 'active', ['last_seen_at' => '2026-10-22 09:30:00.000']);

    /** @var GetTerminalHandler $handler */
    $handler = app(GetTerminalHandler::class);

    $view = $handler->handle(new GetTerminal(strtolower($pending)));
    $other = $handler->handle(new GetTerminal($active));

    expect(array_keys($view))->toBe([
        'id', 'branch_id', 'location_id', 'code', 'name', 'status', 'last_seen_at', 'current_device_id',
        'has_pending_code', 'activation_expires_at',
    ])
        ->and($view['id'])->toBe($pending)
        ->and($view['has_pending_code'])->toBeTrue()
        ->and($view['activation_expires_at'])->toBe('2026-10-23 10:00:00.000')
        ->and($other['has_pending_code'])->toBeFalse()
        ->and($other['last_seen_at'])->toBe('2026-10-22 09:30:00.000')
        ->and(array_key_exists('activation_code_hash', $view))->toBeFalse()
        ->and((string) json_encode($view))->not->toContain($hash);
});

it('answers not_found for an unknown, soft-deleted or another tenants id, and validation for a malformed one (AC-42)', function () {
    $branch = OrganizationSeed::branch();
    $store = OrganizationSeed::location($branch, 'ST1', 'branch_store', ['is_sellable_from' => true]);
    $gone = OrganizationSeed::terminal($branch, $store, 'T9', 'pending', ['deleted_at' => '2026-10-22 00:00:00.000']);

    /** @var GetTerminalHandler $handler */
    $handler = app(GetTerminalHandler::class);

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => $handler->handle(new GetTerminal('01ARZ3NDEKTSV4RRFFQ69G5FAV')), 'unknown');
    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => $handler->handle(new GetTerminal($gone)), 'soft-deleted');
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new GetTerminal('nope')), 'malformed');

    AccessTenantFixture::switchTo('tenant-b');
    $otherBranch = OrganizationSeed::branch();
    $otherTerminal = OrganizationSeed::terminal($otherBranch, OrganizationSeed::location($otherBranch, 'OT1', 'branch_store', ['is_sellable_from' => true]), 'T1');
    AccessTenantFixture::switchTo('tenant-a');

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => $handler->handle(new GetTerminal($otherTerminal)), 'another tenant');
});
