<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\DecideApproval;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\PinVerifier;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\DecideApproval\DecideApproval;
use Flexipos\Sales\Features\DecideApproval\DecideApprovalHandler;
use Flexipos\Sales\Features\DecideApproval\DecideApprovalResult;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 10 - DecideApproval (AC-23, AC-24; D-12 to D-15, D-30; Ruling 5)
|--------------------------------------------------------------------------
|
| The fixed order: the PIN is verified first and outside any transaction, so a
| wrong PIN costs the decider's counter and writes nothing in the sales tables;
| then, in one transaction, the row is locked and must be pending, the decider
| must not be the requester or the sale's cashier, and must hold
| `sales.approvals.decide` at the request's branch.
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
function decideApproval(array $fields): DecideApprovalResult
{
    /** @var DecideApprovalHandler $handler */
    $handler = app(DecideApprovalHandler::class);

    return $handler->handle(new DecideApproval($fields));
}

/**
 * A pending void approval, requested by the cashier, for the world's sale.
 *
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $overrides
 */
function pendingVoid(array $w, array $overrides = []): string
{
    return SalesSeed::approval($w['branch'], $w['cashier'], array_merge(['reference_id' => $w['sale']], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function decision(string $approvalId, string $code, array $overrides = []): array
{
    return array_merge(['approval_id' => $approvalId, 'employee_code' => $code, 'pin' => APV_PIN, 'decision' => 'approved'], $overrides);
}

function pinAttempts(string $employeeId): int
{
    return (int) AccessSeed::connection()->table('employees')->where('id', $employeeId)->value('pin_failed_attempts');
}

it('approves a pending request: status, decider, instant, method and reason, plus one audit row', function () {
    $w = apvWorld();
    $id = pendingVoid($w);

    $result = decideApproval(decision($id, 'manager', ['reason' => 'Customer was overcharged']));

    $row = SalesSeed::row('approval_requests', $id);

    expect($result->status)->toBe('approved')
        ->and($result->deciderId)->toBe($w['manager'])
        ->and($row)->toMatchArray([
            'status' => 'approved',
            'decided_by' => $w['manager'],
            'decided_at' => '2026-11-12 10:00:00.000',
            'decision_method' => 'pin_local',
            'reason' => 'Customer was overcharged',
            'consumed_at' => null,
        ]);

    $audit = SalesSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['module' => 'sales', 'action' => 'sales_approval_decided', 'subject_type' => 'approval_request', 'subject_id' => $id, 'actor_id' => $w['manager']])
        ->and($audit['before']['status'])->toBe('pending')
        ->and($audit['after']['status'])->toBe('approved');
});

it('rejects a pending request', function () {
    $w = apvWorld();
    $id = pendingVoid($w);

    $result = decideApproval(decision($id, 'manager', ['decision' => 'rejected']));

    expect($result->status)->toBe('rejected')
        ->and(SalesSeed::row('approval_requests', $id)['status'])->toBe('rejected');
});

it('refuses a decision that is neither approved nor rejected', function (mixed $value) {
    $w = apvWorld();
    $id = pendingVoid($w);

    SalesRefusals::expect(SalesRefusal::Validation, fn () => decideApproval(decision($id, 'manager', ['decision' => $value])));
})->with(['pending' => ['pending'], 'expired' => ['expired'], 'upper case' => ['APPROVED'], 'a bool' => [true]]);

it('refuses a wrong PIN as pin_invalid, writes nothing in the sales tables, and still counts the attempt against the decider (F-5b)', function () {
    $w = apvWorld();
    $id = pendingVoid($w);

    SalesRefusals::expect(SalesRefusal::PinInvalid, fn () => decideApproval(decision($id, 'manager', ['pin' => '11223344'])));

    expect(SalesSeed::row('approval_requests', $id)['status'])->toBe('pending')
        ->and(SalesSeed::row('approval_requests', $id)['decided_by'])->toBeNull()
        ->and(SalesSeed::audit())->toBe([])
        // Access's own counter ran, outside the transaction that this refusal never opened.
        ->and(pinAttempts($w['manager']))->toBe(1);
});

it('refuses an unknown employee code the same way', function () {
    $w = apvWorld();
    $id = pendingVoid($w);

    $refused = SalesRefusals::expect(SalesRefusal::PinInvalid, fn () => decideApproval(decision($id, 'nobody')));

    expect($refused->getMessage())->not->toContain('nobody');
});

it('verifies the PIN before it touches the approval, and outside any transaction (mutation M3)', function () {
    $w = apvWorld();
    $id = pendingVoid($w);

    $spy = new class implements PinVerifier
    {
        /** @var list<int> */
        public array $levels = [];

        public function verify(string $employeeCode, string $pin, ?string $ip = null): ?string
        {
            $this->levels[] = SalesSeed::connection()->transactionLevel();

            return null;
        }
    };

    app()->instance(PinVerifier::class, $spy);

    SalesRefusals::expect(SalesRefusal::PinInvalid, fn () => decideApproval(decision($id, 'manager')));

    expect($spy->levels)->toBe([0]);
});

it('decides nothing for a row that is no longer pending, whichever way it was decided', function (string $status) {
    $w = apvWorld();
    $id = pendingVoid($w, ['status' => $status]);

    SalesRefusals::expect(SalesRefusal::ApprovalNotPending, fn () => decideApproval(decision($id, 'manager')));

    expect(SalesSeed::row('approval_requests', $id)['status'])->toBe($status);
})->with(['approved' => ['approved'], 'rejected' => ['rejected'], 'expired' => ['expired']]);

it('refuses an unknown approval after the PIN, and a malformed id before it', function () {
    $w = apvWorld();

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => decideApproval(decision('01HZZZZZZZZZZZZZZZZZZZZZZZ', 'manager')));
    // A wrong PIN with a malformed id: the id is refused first, so the verifier never ran and the attempt was never counted.
    SalesRefusals::expect(SalesRefusal::Validation, fn () => decideApproval(decision('nope', 'manager', ['pin' => '11223344'])));

    expect(pinAttempts($w['manager']))->toBe(0);
});

it('refuses a decider who holds the permission nowhere, or only at another branch, and allows the null scope (R-25)', function () {
    $w = apvWorld();
    $nobody = apvEmployeeWithPin('nobody');
    $id = pendingVoid($w);

    SalesRefusals::expect(SalesRefusal::NotAuthorised, fn () => decideApproval(decision($id, 'nobody')));
    SalesRefusals::expect(SalesRefusal::NotAuthorised, fn () => decideApproval(decision($id, 'elsewhere')));

    expect(SalesSeed::row('approval_requests', $id)['status'])->toBe('pending');

    // The owner holds it at the null scope, which covers every branch.
    decideApproval(decision($id, 'owner'));

    expect(SalesSeed::row('approval_requests', $id)['decided_by'])->toBe($w['owner'])
        ->and($nobody)->not->toBe($w['owner']);
});

it('allows a branch-scoped grant at the request branch, and refuses it for a request at another branch', function () {
    $w = apvWorld();

    $atOther = SalesSeed::approval($w['otherBranch'], $w['cashier'], ['reference_type' => 'goodwill_refund', 'type' => 'refund']);

    SalesRefusals::expect(SalesRefusal::NotAuthorised, fn () => decideApproval(decision($atOther, 'manager')));

    $atBranch = SalesSeed::approval($w['branch'], $w['cashier'], ['reference_type' => 'goodwill_refund', 'type' => 'refund']);

    expect(decideApproval(decision($atBranch, 'manager'))->status)->toBe('approved');
});

it('refuses the requester deciding their own request, in any spelling of the id (D-15, R-38)', function () {
    $w = apvWorld();
    $manager = $w['manager'];

    // The manager asked and the manager decides.
    $id = SalesSeed::approval($w['branch'], $manager, ['reference_id' => $w['sale']]);

    SalesRefusals::expect(SalesRefusal::SelfApprovalNotAllowed, fn () => decideApproval(decision($id, 'manager')));

    // The same row, with the requester stored in lower case, is still the same person.
    // (The stand-in's foreign keys are case-sensitive, so the lower-case spelling is written with them off.)
    SalesSeed::connection()->statement('PRAGMA foreign_keys = OFF');
    SalesSeed::connection()->table('approval_requests')->where('id', $id)->update(['requested_by' => strtolower($manager)]);
    SalesSeed::connection()->statement('PRAGMA foreign_keys = ON');

    SalesRefusals::expect(SalesRefusal::SelfApprovalNotAllowed, fn () => decideApproval(decision($id, 'manager')));

    expect(SalesSeed::row('approval_requests', $id)['status'])->toBe('pending');
});

it('refuses the decider being the cashier who rang up the referenced sale (D-15)', function () {
    $w = apvWorld();
    $managersSale = SalesSeed::sale($w['branch'], $w['location'], $w['manager']);

    $id = SalesSeed::approval($w['branch'], $w['cashier'], ['reference_id' => $managersSale]);

    SalesRefusals::expect(SalesRefusal::SelfApprovalNotAllowed, fn () => decideApproval(decision($id, 'manager')));

    // The control: a different decider, same request.
    expect(decideApproval(decision($id, 'owner'))->status)->toBe('approved');
});

it('checks only the requester for a goodwill refund, which has no sale to compare', function () {
    $w = apvWorld();

    $id = SalesSeed::approval($w['branch'], $w['cashier'], ['type' => 'refund', 'reference_type' => 'goodwill_refund', 'reference_id' => null]);

    expect(decideApproval(decision($id, 'manager'))->status)->toBe('approved');
});

it('writes nothing when the permission is refused after a correct PIN', function () {
    $w = apvWorld();
    apvEmployeeWithPin('nobody');
    $id = pendingVoid($w);

    SalesRefusals::expect(SalesRefusal::NotAuthorised, fn () => decideApproval(decision($id, 'nobody')));

    expect(SalesSeed::audit())->toBe([])
        ->and(SalesSeed::row('approval_requests', $id)['decided_at'])->toBeNull();
});

it('lets a PIN reach no table, no audit row, no log line, no query binding and no result (R-24)', function () {
    $w = apvWorld();
    $id = pendingVoid($w);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql.json_encode($query->bindings);
    });

    $result = decideApproval(decision($id, 'manager'));

    // A refused attempt too: a wrong PIN, and the sentinel as a (wrong) attempt on another employee.
    SalesRefusals::capture(fn () => decideApproval(decision($id, 'owner', ['pin' => APV_PIN.'0'])));

    $tables = AccessSeed::connection()->select("select name from sqlite_master where type = 'table'");
    $dump = [];

    foreach ($tables as $table) {
        $dump[] = json_encode(AccessSeed::connection()->table($table->name)->get()->all());
    }

    $haystacks = [
        'tables' => implode("\n", $dump),
        'logs' => implode("\n", $logged),
        'queries' => implode("\n", $queries),
        'result' => json_encode($result).var_export($result, true),
    ];

    foreach ($haystacks as $where => $text) {
        expect(str_contains($text, APV_PIN))->toBeFalse("The PIN reached {$where}.");
    }

    // Liveness of the probe: it does see the approval it was looking after.
    expect($haystacks['tables'])->toContain($id);
});
