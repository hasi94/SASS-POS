<?php

namespace Flexipos\Finance\Tests\Contracts;

use Flexipos\Contracts\Finance\AccountRefusal;
use Flexipos\Contracts\Finance\AccountRefused;
use Flexipos\Contracts\Finance\JournalDimension;
use Flexipos\Contracts\Finance\JournalEntryStatus;
use Flexipos\Contracts\Finance\JournalSourceType;
use Flexipos\Contracts\Finance\PeriodGateRefused;
use Flexipos\Contracts\Finance\PeriodRefusal;
use Flexipos\Contracts\Finance\PostedEntry;
use Flexipos\Contracts\Finance\PostingLine;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\PostingRequest;
use Flexipos\Contracts\Finance\PostingService;
use Flexipos\Contracts\Finance\ReversalRequest;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| The published journal contract (T-033 plan step B1; AC-1, AC-4, AC-16, AC-16a)
|--------------------------------------------------------------------------
|
| Shape and vocabulary only. Nothing here reads a table: the checks a request
| goes through are PostingInputTest's, the service's are chunk C's.
*/

/**
 * @param  callable(): mixed  $run
 */
function jcRefusal(callable $run): PostingRefused
{
    try {
        $run();
    } catch (PostingRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The request was accepted but should have been refused.');
}

/**
 * @param  class-string  $class
 * @return list<string>
 */
function jcPublicProperties(string $class): array
{
    return array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC),
    );
}

it('AC-1: publishes the service with exactly post and reverse', function () {
    $methods = array_map(
        fn (\ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(PostingService::class))->getMethods(),
    );

    expect(interface_exists(PostingService::class))->toBeTrue()
        ->and($methods)->toBe(['post', 'reverse']);
});

it('D-7: lists the 26 source types in the document order', function () {
    expect(array_map(fn (JournalSourceType $case): string => $case->value, JournalSourceType::cases()))->toBe([
        'manual', 'opening', 'sale', 'sale_refund', 'sale_void', 'grn', 'purchase_return',
        'supplier_invoice', 'supplier_payment', 'customer_payment', 'expense', 'expense_payment',
        'payroll_run', 'payroll_payment', 'commission_payout', 'stock_adjustment', 'stock_transfer',
        'stock_count', 'stock_production', 'cash_movement', 'bank_transaction', 'revaluation',
        'period_close', 'year_close', 'reversal', 'plugin',
    ]);
});

it('lists the three statuses and the seven dimensions in their fixed order', function () {
    expect(array_map(fn (JournalEntryStatus $case): string => $case->value, JournalEntryStatus::cases()))
        ->toBe(['draft', 'posted', 'reversed'])
        ->and(array_map(fn (JournalDimension $case): string => $case->value, JournalDimension::cases()))
        ->toBe(['branch_id', 'location_id', 'department_id', 'employee_id', 'customer_id', 'supplier_id', 'product_id']);
});

it('keeps every source type and status within the 30-character enum column', function () {
    $values = array_merge(
        array_map(fn (JournalSourceType $case): string => $case->value, JournalSourceType::cases()),
        array_map(fn (JournalEntryStatus $case): string => $case->value, JournalEntryStatus::cases()),
    );

    foreach ($values as $value) {
        expect(strlen($value))->toBeLessThanOrEqual(30);
    }
});

it('AC-16a: lists the 27 posting refusal codes', function () {
    $codes = array_map(fn (PostingRefusal $case): string => $case->value, PostingRefusal::cases());

    expect($codes)->toBe([
        'unknown_field', 'invalid_source_type', 'invalid_source_id', 'invalid_source_purpose', 'invalid_date',
        'invalid_description', 'invalid_branch', 'invalid_lines', 'too_few_lines', 'too_many_lines',
        'invalid_account_id', 'invalid_memo', 'invalid_dimension', 'invalid_amount', 'line_both_sides',
        'line_zero', 'entry_unbalanced', 'total_out_of_range', 'invalid_actor', 'idempotency_conflict',
        'unknown_reference', 'entry_not_found', 'not_reversible', 'already_reversed',
        'reversal_before_original', 'reason_required', 'invalid_reason',
    ])->and($codes)->toHaveCount(27)
        ->and(array_unique($codes))->toHaveCount(27);
});

it('AC-28: carries a gate refusal unchanged', function () {
    $cases = [
        [PeriodGateRefused::invalidDate('d'), PeriodRefusal::InvalidDate],
        [PeriodGateRefused::noFiscalPeriod('n'), PeriodRefusal::NoFiscalPeriod],
        [PeriodGateRefused::periodClosed('c'), PeriodRefusal::PeriodClosed],
        [PeriodGateRefused::periodSoftClosed('s'), PeriodRefusal::PeriodSoftClosed],
    ];

    foreach ($cases as [$gate, $code]) {
        $refused = PostingRefused::fromPeriodGate($gate, 'entry_date');

        expect($refused->reason)->toBe($code)
            ->and($refused->field)->toBe('entry_date')
            ->and($refused->getPrevious())->toBe($gate)
            ->and($refused->getMessage())->toBe($gate->getMessage());
    }
});

it('AC-23: carries an account refusal unchanged, with its line', function () {
    $cases = [
        [AccountRefused::notFound('n'), AccountRefusal::AccountNotFound],
        [AccountRefused::notLive('l'), AccountRefusal::AccountNotLive],
        [AccountRefused::inactive('i'), AccountRefusal::AccountInactive],
        [AccountRefused::notPostable('p'), AccountRefusal::AccountNotPostable],
    ];

    foreach ($cases as [$account, $code]) {
        $refused = PostingRefused::fromAccount($account, 'lines.2.account_id', ['line' => 2]);

        expect($refused->reason)->toBe($code)
            ->and($refused->field)->toBe('lines.2.account_id')
            ->and($refused->context)->toBe(['line' => 2])
            ->and($refused->getPrevious())->toBe($account);
    }
});

it('refuses with a code, a field and a context, and is not constructible any other way', function () {
    $refused = PostingRefused::because(PostingRefusal::EntryUnbalanced, 'm', 'lines', ['total_debit' => '1.0000']);
    $unknown = PostingRefused::unknownFields(['id', 'lines.0.tax_id'], 'm');

    expect($refused->reason)->toBe(PostingRefusal::EntryUnbalanced)
        ->and($refused->field)->toBe('lines')
        ->and($refused->context)->toBe(['total_debit' => '1.0000'])
        ->and($refused->fields)->toBe([])
        ->and($unknown->reason)->toBe(PostingRefusal::UnknownField)
        ->and($unknown->fields)->toBe(['id', 'lines.0.tax_id'])
        ->and((new ReflectionClass(PostingRefused::class))->getConstructor()?->isPrivate())->toBeTrue();
});

it('AC-4: a posted entry carries exactly the documented properties', function () {
    expect(jcPublicProperties(PostedEntry::class))
        ->toBe(['id', 'number', 'periodId', 'entryDate', 'totalDebit', 'totalCredit', 'status', 'replayed']);

    $entry = new PostedEntry('ID', 'JE-000001', 'PERIOD', '2026-01-15', '1.0000', '1.0000', JournalEntryStatus::Posted, false);

    expect($entry->status)->toBe(JournalEntryStatus::Posted)
        ->and($entry->replayed)->toBeFalse();
});

it('keeps the request value objects immutable and unvalidated', function () {
    $line = new PostingLine('anything');
    $request = new PostingRequest(5, 6, 7, 8, 9, 10, 11, null);

    expect($line->debit)->toBe('0')
        ->and($line->credit)->toBe('0')
        ->and($line->memo)->toBeNull()
        ->and($line->dimensions)->toBe([])
        ->and((new ReflectionClass(PostingLine::class))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass(PostingRequest::class))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass(ReversalRequest::class))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass(PostedEntry::class))->isReadOnly())->toBeTrue()
        ->and($request->sourceType)->toBe(5)
        ->and($request->lines)->toBe(11);
});

it('AC-16: fromArray names every unknown key at both levels in one refusal', function () {
    $refused = jcRefusal(fn () => PostingRequest::fromArray([
        'source_type' => 'sale',
        'id' => 'x',
        'number' => 'x',
        'status' => 'posted',
        'period_id' => 'x',
        'posted_at' => 'x',
        'posted_by' => 'x',
        'reversal_of_id' => 'x',
        'total_debit' => '1',
        'is_auto' => true,
        'lines' => [
            ['account_id' => 'a', 'debit' => '1', 'project_ref' => 'x', 'tax_id' => 'x', 'tax_base' => '1'],
            ['account_id' => 'b', 'credit' => '1', 'colour' => 'red', 'branch_id' => 'ok'],
        ],
    ], null));

    expect($refused->reason)->toBe(PostingRefusal::UnknownField)
        ->and($refused->field)->toBeNull()
        ->and($refused->fields)->toBe([
            'id', 'number', 'status', 'period_id', 'posted_at', 'posted_by', 'reversal_of_id',
            'total_debit', 'is_auto',
            'lines.0.project_ref', 'lines.0.tax_id', 'lines.0.tax_base', 'lines.1.colour',
        ]);
});

it('AC-16: an unknown key is named even when the lines are not a list', function () {
    $refused = jcRefusal(fn () => PostingRequest::fromArray(['id' => 'x', 'lines' => 'nope'], null));

    expect($refused->fields)->toBe(['id']);
});

it('AC-71: an excluded key becomes an unknown field', function () {
    $request = PostingRequest::fromArray(['entry_date' => '2026-01-15', 'lines' => []], null, ['source_type']);
    $refused = jcRefusal(fn () => PostingRequest::fromArray(['source_type' => 'manual', 'lines' => []], null, ['source_type']));

    expect($request->sourceType)->toBeNull()
        ->and($refused->reason)->toBe(PostingRefusal::UnknownField)
        ->and($refused->fields)->toBe(['source_type'])
        ->and(PostingRequest::fromArray(['source_type' => 'manual', 'lines' => []], null)->sourceType)->toBe('manual');
});

it('AC-19: an omitted side becomes zero and an explicit null is kept for the amount check', function () {
    $request = PostingRequest::fromArray(['lines' => [
        ['account_id' => 'a', 'debit' => '5'],
        ['account_id' => 'b', 'credit' => null],
        ['account_id' => 'c'],
    ]], null);

    expect($request->lines)->toHaveCount(3)
        ->and($request->lines[0]->debit)->toBe('5')
        ->and($request->lines[0]->credit)->toBe('0')
        ->and($request->lines[1]->debit)->toBe('0')
        ->and($request->lines[1]->credit)->toBeNull()
        ->and($request->lines[2]->debit)->toBe('0');
});

it('gathers a line\'s flat dimension keys into its dimensions', function () {
    $request = PostingRequest::fromArray(['lines' => [
        ['account_id' => 'a', 'debit' => '1', 'product_id' => 'p', 'branch_id' => null],
    ]], 'actor');

    expect($request->lines[0]->dimensions)->toBe(['branch_id' => null, 'product_id' => 'p'])
        ->and($request->actorId)->toBe('actor');
});

it('withSourceType replaces the type and nothing else', function () {
    $request = new PostingRequest('x', 'sid', 'purpose', '2026-01-15', 'd', 'b', ['l'], 'actor');
    $typed = $request->withSourceType('manual');

    expect($typed->sourceType)->toBe('manual')
        ->and($typed->sourceId)->toBe('sid')
        ->and($typed->sourcePurpose)->toBe('purpose')
        ->and($typed->entryDate)->toBe('2026-01-15')
        ->and($typed->description)->toBe('d')
        ->and($typed->branchId)->toBe('b')
        ->and($typed->lines)->toBe(['l'])
        ->and($typed->actorId)->toBe('actor')
        ->and($request->sourceType)->toBe('x');
});

it('builds a reversal request from a body and refuses unknown keys', function () {
    $request = ReversalRequest::fromArray(['entry_id' => 'e', 'entry_date' => '2026-02-01', 'reason' => 'r'], 'actor');
    $partial = ReversalRequest::fromArray([], null);
    $refused = jcRefusal(fn () => ReversalRequest::fromArray(['entry_id' => 'e', 'status' => 'x', 'force' => true], null));

    expect($request->entryId)->toBe('e')
        ->and($request->entryDate)->toBe('2026-02-01')
        ->and($request->reason)->toBe('r')
        ->and($request->actorId)->toBe('actor')
        ->and($partial->entryId)->toBeNull()
        ->and($refused->reason)->toBe(PostingRefusal::UnknownField)
        ->and($refused->fields)->toBe(['status', 'force']);
});

it('imports nothing outside contracts and PHP core', function () {
    $directory = dirname(__DIR__, 3).'/contracts/src/Finance';
    $names = ['JournalDimension', 'JournalEntryStatus', 'JournalSourceType', 'PostedEntry', 'PostingLine', 'PostingRefusal',
        'PostingRefused', 'PostingRequest', 'PostingService', 'ReversalRequest'];
    $offenders = [];

    foreach ($names as $name) {
        $source = (string) file_get_contents($directory.'/'.$name.'.php');
        preg_match_all('/^use\s+([^;]+);/m', $source, $uses);

        foreach ($uses[1] as $import) {
            if (str_contains($import, '\\') && ! str_starts_with($import, 'Flexipos\\Contracts\\')) {
                $offenders[] = $name.' imports '.$import;
            }
        }
    }

    expect($offenders)->toBe([]);
});
