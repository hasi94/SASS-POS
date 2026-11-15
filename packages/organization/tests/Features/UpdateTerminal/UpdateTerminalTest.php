<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\UpdateTerminal;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\UpdateTerminal\UpdateTerminal;
use Flexipos\Organization\Features\UpdateTerminal\UpdateTerminalHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 10 - updating a terminal (AC-42, AC-20)
|--------------------------------------------------------------------------
|
| The new location obeys the rule creating a terminal follows, and each way it
| can break that rule is a case. A request naming a field the operation does
| not take is refused and its control is the same request without it.
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

/**
 * @param  array<string, mixed>  $fields
 */
function updateTerminal(string $terminalId, array $fields, ?string $actorId = null): void
{
    /** @var UpdateTerminalHandler $handler */
    $handler = app(UpdateTerminalHandler::class);

    $handler->handle(new UpdateTerminal($terminalId, $fields, $actorId));
}

/**
 * @return array{terminal: string, branch: string, store: string, second: string, actor: string}
 */
function updateTerminalWorld(string $status = 'active'): array
{
    $branch = OrganizationSeed::branch();

    return [
        'branch' => $branch,
        'store' => OrganizationSeed::location($branch, 'ST1', 'branch_store', ['is_sellable_from' => true]),
        'second' => OrganizationSeed::location($branch, 'ST2', 'warehouse', ['is_sellable_from' => true]),
        'terminal' => OrganizationSeed::terminal($branch, OrganizationSeed::location($branch, 'ST0', 'warehouse', ['is_sellable_from' => true]), 'T1', $status),
        'actor' => AccessSeed::employee('ut_actor', ['display_name' => 'Terminal Editor']),
    ];
}

it('changes the name and the selling location, writing one audit row that names what moved (AC-42, AC-20)', function () {
    $ids = updateTerminalWorld();

    updateTerminal(strtolower($ids['terminal']), ['name' => 'Renamed till', 'location_id' => strtolower($ids['second'])], $ids['actor']);

    $row = (array) OrganizationSeed::connection()->table('terminals')->where('id', $ids['terminal'])->first();

    expect($row['name'])->toBe('Renamed till')
        ->and($row['location_id'])->toBe($ids['second'])
        ->and($row['status'])->toBe('active')
        ->and($row['code'])->toBe('T1');

    $audit = OrganizationSeed::onlyAuditRow();

    expect($audit['action'])->toBe('terminal_updated')
        ->and($audit['branch_id'])->toBe($ids['branch'])
        ->and($audit['changed_fields'])->toBe(['location_id', 'name'])
        ->and($audit['actor_name'])->toBe('Terminal Editor');
});

it('refuses the fields the operation does not take, and accepts the same request without them (AC-42)', function (string $field, mixed $value) {
    $ids = updateTerminalWorld();
    $before = (array) OrganizationSeed::connection()->table('terminals')->where('id', $ids['terminal'])->first();

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => updateTerminal($ids['terminal'], ['name' => 'Sneaky', $field => $value], $ids['actor']));

    expect((array) OrganizationSeed::connection()->table('terminals')->where('id', $ids['terminal'])->first())->toBe($before)
        ->and(OrganizationSeed::audit())->toBe([]);

    updateTerminal($ids['terminal'], ['name' => 'Sneaky'], $ids['actor']);

    expect(OrganizationSeed::connection()->table('terminals')->where('id', $ids['terminal'])->value('name'))->toBe('Sneaky');
})->with([
    'code' => ['code', 'T9'],
    'branch_id' => ['branch_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    'status' => ['status', 'revoked'],
    'id' => ['id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    'activation_code_hash' => ['activation_code_hash', 'x'],
]);

it('refuses a location that breaks the selling rule, and an empty update (AC-42)', function () {
    $ids = updateTerminalWorld();
    $other = OrganizationSeed::branch();
    $otherStore = OrganizationSeed::location($other, 'OS1', 'branch_store', ['is_sellable_from' => true]);
    $central = OrganizationSeed::location(null, 'CW1', 'warehouse', ['is_sellable_from' => true]);
    $notSellable = OrganizationSeed::location($ids['branch'], 'NS1', 'warehouse');
    $inactive = OrganizationSeed::location($ids['branch'], 'IA1', 'warehouse', ['is_sellable_from' => true, 'is_active' => false]);

    foreach ([$otherStore, $central, $notSellable, $inactive, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'nope'] as $location) {
        OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => updateTerminal($ids['terminal'], ['location_id' => $location], $ids['actor']));
    }

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => updateTerminal($ids['terminal'], [], $ids['actor']), 'empty');

    expect(OrganizationSeed::audit())->toBe([]);
});

it('refuses to update a revoked terminal, and answers not_found for an unknown one (AC-42)', function () {
    $ids = updateTerminalWorld('revoked');

    OrganizationSeed::refusal(OrganizationRefusal::TerminalRevoked, fn () => updateTerminal($ids['terminal'], ['name' => 'x'], $ids['actor']));
    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => updateTerminal('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['name' => 'x'], $ids['actor']));

    expect(OrganizationSeed::audit())->toBe([]);
});
