<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\CreatePurchaseRequest;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Contracts\Organization\DocumentNumbersRefusal;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\CreatePurchaseRequest\CreatePurchaseRequest;
use Flexipos\Purchasing\Features\CreatePurchaseRequest\CreatePurchaseRequestHandler;
use Flexipos\Purchasing\Features\CreatePurchaseRequest\CreatePurchaseRequestResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\DeadlockingNumbers;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 12 - CreatePurchaseRequest (AC-19 to AC-21, AC-36, AC-39, AC-90)
|--------------------------------------------------------------------------
|
| The requester is the actor, the number is the sequence's and is taken last, a
| refusal consumes no number (the whole of `number_sequences` is compared), the
| branch decides the permission, and a replay of the id is answered not written.
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
function puCreateRequest(array $fields, ?string $actorId): CreatePurchaseRequestResult
{
    /** @var CreatePurchaseRequestHandler $handler */
    $handler = app(CreatePurchaseRequestHandler::class);

    return $handler->handle(new CreatePurchaseRequest($fields, $actorId));
}

/**
 * @param  array{branch: string, location: string}  $desk
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function puRequestFields(array $desk, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(),
        'branch_id' => $desk['branch'],
        'location_id' => $desk['location'],
    ], $overrides);
}

/**
 * Refuse the create with this reason, and prove nothing changed anywhere, the
 * number sequences included.
 *
 * @param  array<string, mixed>  $fields
 */
function puCreateRequestRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puCreateRequest($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-19: creates a draft whose requester is the actor, with the sequence s first number and one audit row carrying the branch', function () {
    $desk = PurchasingWorld::desk();
    $fields = puRequestFields($desk, ['required_date' => '2026-11-20', 'notes' => ' Restock ']);

    $result = puCreateRequest(array_merge($fields, ['id' => strtolower($fields['id'])]), strtolower($desk['buyer']));

    expect($result->requestId)->toBe($fields['id'])
        ->and($result->number)->toBe('PR000001')
        ->and($result->replayed)->toBeFalse()
        ->and(PurchasingSeed::row('purchase_requests', $fields['id']))->toMatchArray([
            'number' => 'PR000001',
            'status' => 'draft',
            'branch_id' => $desk['branch'],
            'location_id' => $desk['location'],
            'requested_by' => $desk['buyer'],
            'created_by' => $desk['buyer'],
            'updated_by' => $desk['buyer'],
            'required_date' => '2026-11-20',
            'notes' => 'Restock',
            'workflow_instance_id' => null,
            'created_at' => PurchasingSeed::NOW,
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $desk['buyer'],
        'branch_id' => $desk['branch'],
        'module' => PurchasingAuditActions::MODULE,
        'action' => PurchasingAuditActions::REQUEST_CREATED,
        'subject_type' => 'purchase_request',
        'subject_id' => $fields['id'],
        'before' => null,
    ])
        ->and($audit['after']['number'])->toBe('PR000001')
        ->and($audit['after']['status'])->toBe('draft')
        ->and($audit['after']['requested_by'])->toBe($desk['buyer']);
});

it('AC-19: a request may carry no required date and no notes, and the next number is PR000002', function () {
    $desk = PurchasingWorld::desk();

    $first = puCreateRequest(puRequestFields($desk), $desk['buyer']);
    $second = puCreateRequest(puRequestFields($desk), $desk['buyer']);

    expect($first->request['required_date'])->toBeNull()
        ->and($first->request['notes'])->toBeNull()
        ->and([$first->number, $second->number])->toBe(['PR000001', 'PR000002']);
});

it('AC-19 and D-19: the requester, the number, the status and the date raised are the server s, so a caller cannot supply them', function (string $field, mixed $value) {
    $desk = PurchasingWorld::desk();

    puCreateRequestRefused(PurchasingRefusal::Validation, puRequestFields($desk, [$field => $value]), $desk['buyer'], $field);
})->with([
    'requested_by' => ['requested_by', '01JABCDEFGHJKMNPQRSTVWXYZ0'],
    'number' => ['number', 'PR9'],
    'status' => ['status', 'approved'],
    'created_at' => ['created_at', '2020-01-01 00:00:00.000'],
    'workflow_instance_id' => ['workflow_instance_id', '01JABCDEFGHJKMNPQRSTVWXYZ0'],
]);

it('AC-91: refuses a missing or malformed id, branch, location, date and notes as validation', function (array $overrides) {
    $desk = PurchasingWorld::desk();
    $fields = array_merge(puRequestFields($desk), $overrides);

    foreach ($overrides as $key => $value) {
        if ($value === '__unset__') {
            unset($fields[$key]);
        }
    }

    puCreateRequestRefused(PurchasingRefusal::Validation, $fields, $desk['buyer']);
})->with([
    'no id' => [['id' => '__unset__']],
    'a bad id' => [['id' => 'nope']],
    'no branch' => [['branch_id' => '__unset__']],
    'a bad branch' => [['branch_id' => 'nope']],
    'no location' => [['location_id' => '__unset__']],
    'a bad location' => [['location_id' => 'nope']],
    'a date with a time' => [['required_date' => '2026-11-20 10:00:00']],
    'a date written d/m/Y' => [['required_date' => '20/11/2026']],
    'an impossible date' => [['required_date' => '2026-02-30']],
    'a date that is an int' => [['required_date' => 20261120]],
    'notes that are an array' => [['notes' => ['x']]],
]);

it('AC-20: refuses an unknown or inactive branch as unknown_branch, and consumes no number', function () {
    $desk = PurchasingWorld::desk();
    $inactive = PurchasingSeed::branch(false);
    $inactiveLocation = PurchasingSeed::location($inactive);

    puCreateRequestRefused(PurchasingRefusal::UnknownBranch, puRequestFields($desk, ['branch_id' => (string) Str::ulid()]), $desk['buyer'], 'unknown');
    puCreateRequestRefused(PurchasingRefusal::UnknownBranch, puRequestFields($desk, ['branch_id' => $inactive, 'location_id' => $inactiveLocation]), $desk['buyer'], 'inactive');
});

it('AC-20 and D-40: a location that is not an eligible delivery location of the branch is unknown_location, and consumes no number', function (string $why) {
    $desk = PurchasingWorld::desk();
    $otherBranch = PurchasingSeed::branch();

    $location = match ($why) {
        'unknown' => (string) Str::ulid(),
        'another branch' => PurchasingSeed::location($otherBranch),
        'a central warehouse' => PurchasingSeed::location(null, 'CENTRAL'),
        'inactive' => PurchasingSeed::location($desk['branch'], 'OFF', ['is_active' => false]),
        'not purchasable to' => PurchasingSeed::location($desk['branch'], 'NOBUY', ['is_purchasable_to' => false]),
        default => PurchasingSeed::location($desk['branch'], 'DEL', ['deleted_at' => PurchasingSeed::NOW]),
    };

    puCreateRequestRefused(PurchasingRefusal::UnknownLocation, puRequestFields($desk, ['location_id' => $location]), $desk['buyer'], $why);
})->with([
    'unknown' => ['unknown'],
    'another branch' => ['another branch'],
    'a central warehouse' => ['a central warehouse'],
    'inactive' => ['inactive'],
    'not purchasable to' => ['not purchasable to'],
    'soft-deleted' => ['soft-deleted'],
]);

it('AC-20: a required date earlier than the branch-local today is refused, today and later are accepted, and no number is consumed', function () {
    $desk = PurchasingWorld::desk();

    puCreateRequestRefused(PurchasingRefusal::Validation, puRequestFields($desk, ['required_date' => '2026-11-15']), $desk['buyer'], 'yesterday');

    $today = puCreateRequest(puRequestFields($desk, ['required_date' => '2026-11-16']), $desk['buyer']);
    $later = puCreateRequest(puRequestFields($desk, ['required_date' => '2030-01-01']), $desk['buyer']);

    expect($today->request['required_date'])->toBe('2026-11-16')
        ->and($later->number)->toBe('PR000002');
});

it('AC-20: today is the branch s own date, which at 20:00 UTC is already tomorrow in Colombo', function () {
    $desk = PurchasingWorld::desk();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-16 20:00:00.000'));

    puCreateRequestRefused(PurchasingRefusal::Validation, puRequestFields($desk, ['required_date' => '2026-11-16']), $desk['buyer'], 'the UTC date');

    expect(puCreateRequest(puRequestFields($desk, ['required_date' => '2026-11-17']), $desk['buyer'])->number)->toBe('PR000001');
});

it('AC-21: a grant scoped to branch A is refused at branch B with the resolver s reason, a company-wide grant is accepted at both, and nothing is consumed', function () {
    $a = PurchasingWorld::desk('a');
    $b = PurchasingWorld::desk('b');
    $scopedToA = AccessSeed::actorHolding('scoped', [PurchasingPermissions::REQUESTS_MANAGE], $a['branch']);
    $companyWide = AccessSeed::actorHolding('director', [PurchasingPermissions::REQUESTS_MANAGE]);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCreateRequest(puRequestFields($b), $scopedToA));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(puCreateRequest(puRequestFields($a), $scopedToA)->request['requested_by'])->toBe($scopedToA)
        ->and(puCreateRequest(puRequestFields($a), $companyWide)->request['branch_id'])->toBe($a['branch'])
        ->and(puCreateRequest(puRequestFields($b), $companyWide)->request['branch_id'])->toBe($b['branch']);
});

it('AC-38 and AC-84: the view key alone, an order key alone and no key are refused, and a missing actor is validation', function (array $keys) {
    $desk = PurchasingWorld::desk();
    $actor = AccessSeed::actorHolding('limited', $keys, $desk['branch']);

    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCreateRequest(puRequestFields($desk), $actor));

    expect(PurchasingRefusals::state())->toBe($before);

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puCreateRequest(puRequestFields($desk), null));
})->with([
    'view only' => [[PurchasingPermissions::REQUESTS_VIEW]],
    'an order key' => [[PurchasingPermissions::ORDERS_MANAGE]],
    'no key' => [[]],
]);

it('AC-86: a suspended employee is refused with the resolver s reason', function () {
    $desk = PurchasingWorld::desk();
    AccessSeed::connection()->table('employees')->where('id', $desk['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCreateRequest(puRequestFields($desk), $desk['buyer']));

    expect($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});

it('AC-36: an identical replay returns the original with no second number, no second audit row and nothing else written', function () {
    $desk = PurchasingWorld::desk();
    $fields = puRequestFields($desk, ['required_date' => '2026-11-20', 'notes' => 'n']);

    $first = puCreateRequest($fields, $desk['buyer']);
    $before = PurchasingRefusals::state();

    $again = puCreateRequest(array_merge($fields, ['id' => strtolower($fields['id'])]), $desk['buyer']);

    expect($again->replayed)->toBeTrue()
        ->and($again->number)->toBe($first->number)
        ->and($again->request)->toBe($first->request)
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::audit())->toHaveCount(1);
});

it('AC-36: the same id with a different payload or another requester is replay_conflict naming the id, and writes nothing', function (array $change) {
    $desk = PurchasingWorld::desk();
    $fields = puRequestFields($desk, ['required_date' => '2026-11-20', 'notes' => 'n']);

    puCreateRequest($fields, $desk['buyer']);

    $otherBranch = PurchasingSeed::branch();
    $actor = $desk['buyer'];

    if (isset($change['other_actor'])) {
        $actor = AccessSeed::actorHolding('second', [PurchasingPermissions::REQUESTS_MANAGE], $desk['branch']);
        unset($change['other_actor']);
    }

    if (isset($change['other_location'])) {
        $change['location_id'] = PurchasingSeed::location($desk['branch'], 'SECOND');
        unset($change['other_location']);
    }

    if (isset($change['other_branch'])) {
        $actor = AccessSeed::actorHolding('elsewhere', [PurchasingPermissions::REQUESTS_MANAGE]);
        $change['branch_id'] = $otherBranch;
        $change['location_id'] = PurchasingSeed::location($otherBranch, 'ELSE');
        unset($change['other_branch']);
    }

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::ReplayConflict, fn () => puCreateRequest(array_merge($fields, $change), $actor));

    expect($refused->getMessage())->toContain($fields['id'])
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'other notes' => [['notes' => 'different']],
    'no notes' => [['notes' => null]],
    'another date' => [['required_date' => '2026-11-21']],
    'no date' => [['required_date' => null]],
    'another location' => [['other_location' => true]],
    'another requester' => [['other_actor' => true]],
    'another branch' => [['other_branch' => true]],
]);

it('AC-36: a replay is answered even after the required date has passed', function () {
    $desk = PurchasingWorld::desk();
    $fields = puRequestFields($desk, ['required_date' => '2026-11-16']);

    puCreateRequest($fields, $desk['buyer']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-30 09:00:00.000'));

    expect(puCreateRequest($fields, $desk['buyer'])->replayed)->toBeTrue();
});

it('AC-52 and AC-90: a numbering refusal is numbering_refused, or number_too_long for that cause, with the engine s reason as cause, and writes nothing', function (DocumentNumbersRefusal $cause, PurchasingRefusal $expected) {
    $desk = PurchasingWorld::desk();

    app()->instance(DocumentNumbers::class, new class($cause) implements DocumentNumbers
    {
        public function __construct(private readonly DocumentNumbersRefusal $cause) {}

        public function next(string $documentType, ?string $branchId): string
        {
            throw match ($this->cause) {
                DocumentNumbersRefusal::NumberTooLong => DocumentNumbersRefused::numberTooLong('too long'),
                default => DocumentNumbersRefused::unsupportedResetPeriod('no such period'),
            };
        }
    });

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect($expected, fn () => puCreateRequest(puRequestFields($desk), $desk['buyer']));

    expect($refused->cause)->toBe($cause)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'too long' => [DocumentNumbersRefusal::NumberTooLong, PurchasingRefusal::NumberTooLong],
    'any other' => [DocumentNumbersRefusal::UnsupportedResetPeriod, PurchasingRefusal::NumberingRefused],
]);

it('AC-52: a unique violation on the number is numbering_refused, never an unhandled error, and writes nothing', function () {
    $desk = PurchasingWorld::desk();
    $taken = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $takenNumber = (string) PurchasingSeed::row('purchase_requests', $taken)['number'];

    app()->instance(DocumentNumbers::class, new class($takenNumber) implements DocumentNumbers
    {
        public function __construct(private readonly string $number) {}

        public function next(string $documentType, ?string $branchId): string
        {
            return $this->number;
        }
    });

    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::NumberingRefused, fn () => puCreateRequest(puRequestFields($desk), $desk['buyer']));

    expect(PurchasingRefusals::state())->toBe($before);
});

it('AC-90: a failure after the number is taken rolls the number back with everything else', function () {
    $desk = PurchasingWorld::desk();

    app()->instance(AuditTrail::class, new class implements AuditTrail
    {
        public function record(string $module, ?string $actorId, string $action, string $subjectType, string $subjectId, ?array $before, ?array $after, ?string $branchId = null): void
        {
            throw new RuntimeException('the audit trail is down');
        }
    });

    $before = PurchasingRefusals::state();

    expect(fn () => puCreateRequest(puRequestFields($desk), $desk['buyer']))->toThrow(RuntimeException::class, 'the audit trail is down')
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('Ruling 3: one deadlock on the number is retried from the top and the request is created once, with the first number', function () {
    $desk = PurchasingWorld::desk();

    $numbers = DeadlockingNumbers::wrap(function (int $call): void {
        if ($call === 1) {
            throw DeadlockingNumbers::deadlock();
        }
    });

    $result = puCreateRequest(puRequestFields($desk), $desk['buyer']);

    expect($numbers->calls)->toBe(2)
        ->and($result->number)->toBe('PR000001')
        ->and(PurchasingSeed::connection()->table('purchase_requests')->count())->toBe(1)
        ->and(PurchasingSeed::audit())->toHaveCount(1);
});

it('Ruling 3: a second deadlock surfaces, and nothing is written', function () {
    $desk = PurchasingWorld::desk();

    $numbers = DeadlockingNumbers::wrap(function (): void {
        throw DeadlockingNumbers::deadlock();
    });

    $before = PurchasingRefusals::state();

    expect(fn () => puCreateRequest(puRequestFields($desk), $desk['buyer']))->toThrow(QueryException::class)
        ->and($numbers->calls)->toBe(2)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('Ruling 3: an error that is not a deadlock is not retried', function () {
    $desk = PurchasingWorld::desk();

    $numbers = DeadlockingNumbers::wrap(function (): void {
        throw new RuntimeException('the sequence table is gone');
    });

    expect(fn () => puCreateRequest(puRequestFields($desk), $desk['buyer']))->toThrow(RuntimeException::class, 'the sequence table is gone')
        ->and($numbers->calls)->toBe(1);
});
