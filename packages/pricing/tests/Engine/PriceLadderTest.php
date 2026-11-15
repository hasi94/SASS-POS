<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Engine;

use Flexipos\Contracts\Pricing\PriceBasis;
use Flexipos\Pricing\Engine\LadderInput;
use Flexipos\Pricing\Engine\LadderItem;
use Flexipos\Pricing\Engine\LadderOutcome;
use Flexipos\Pricing\Engine\ListType;
use Flexipos\Pricing\Engine\PriceLadder;
use Flexipos\Pricing\Engine\ScopeType;
use Flexipos\Pricing\Tests\Support\Build;
use Flexipos\Pricing\Tests\Support\FixtureReplay;

/*
|--------------------------------------------------------------------------
| PriceLadder (T-029 step 8, AC-15 to AC-17, AC-16a; D-13, D-16, D-17)
|--------------------------------------------------------------------------
|
| A single costs 7.50 per stock unit and a case is 24 of them. The fixtures
| replay the named scenarios; these tests add the properties a fixture cannot
| state: input-order independence, "later steps are never consulted", and the
| shape refusals.
*/

function priceCase(): string
{
    return Build::id('UNITCASE');
}

function priceEach(): string
{
    return Build::id('UNITEACH');
}

/**
 * @return array{string, ?string, string, int, ?string, string}
 */
function priceSummary(LadderOutcome $outcome): array
{
    return [$outcome->listPrice, $outcome->discountPercent, $outcome->unitPrice, $outcome->source->step, $outcome->source->priceListId, $outcome->source->basis->value];
}

function priceCustomer(): string
{
    return Build::id('CUSTA');
}

it('uses a unit-matched row as typed and a unit-less row derived by the factor', function () {
    $assigned = [Build::assignment(ScopeType::Customer, priceCustomer())];

    $typed = PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', '24.0000', [
        Build::list('L', [Build::item(priceCase(), '1.0000', '179.0000')], assignments: $assigned),
    ], customer: priceCustomer()));

    $derived = PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', '24.0000', [
        Build::list('L', [Build::item(null, '1.0000', '7.5000')], assignments: $assigned),
    ], customer: priceCustomer()));

    expect($typed->unitPrice)->toBe('179.0000')
        ->and($typed->source->basis)->toBe(PriceBasis::UnitMatched)
        ->and($derived->unitPrice)->toBe('180.0000')
        ->and($derived->source->basis)->toBe(PriceBasis::Derived);
});

it('applies the unit test before the min_qty test: a unit row at min_qty 1 beats a unit-less row at min_qty 24', function () {
    $list = Build::list('L', [
        Build::item(null, '24.0000', '7.0000'),
        Build::item(priceCase(), '1.0000', '179.0000'),
    ], assignments: [Build::assignment(ScopeType::Customer, priceCustomer())]);

    $outcome = PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', '24.0000', [$list], customer: priceCustomer()));

    // A min_qty-outer ladder would pick the unit-less 24 row and derive 7 x 24 = 168.
    expect($outcome->unitPrice)->toBe('179.0000');
});

it('takes the greatest qualifying min_qty, never the least', function (string $base, string $expected) {
    $list = Build::list('L', [
        Build::item(priceCase(), '1.0000', '179.0000'),
        Build::item(priceCase(), '48.0000', '170.0000'),
        Build::item(priceCase(), '96.0000', '160.0000'),
    ], assignments: [Build::assignment(ScopeType::Customer, priceCustomer())]);

    expect(PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', $base, [$list], customer: priceCustomer()))->unitPrice)->toBe($expected);
})->with([
    'base 24: only the first qualifies' => ['24.0000', '179.0000'],
    'base 47.9999: just under the second' => ['47.9999', '179.0000'],
    'base 48: the second' => ['48.0000', '170.0000'],
    'base 95: still the second' => ['95.0000', '170.0000'],
    'base 96: the third' => ['96.0000', '160.0000'],
    'base 1000: still the third' => ['1000.0000', '160.0000'],
]);

it('compares min_qty with the base quantity, not the line-unit quantity', function () {
    // One case is base 24: a min_qty 24 row qualifies although the line quantity is 1.
    $list = Build::list('L', [Build::item(priceCase(), '24.0000', '170.0000')], assignments: [Build::assignment(ScopeType::Customer, priceCustomer())]);

    expect(PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', '24.0000', [$list], customer: priceCustomer()))->unitPrice)->toBe('170.0000')
        ->and(Build::reasonOf(fn () => PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', '23.9999', [$list], customer: priceCustomer()))))->toBe('no_price_resolved');
});

it('tries the six steps in order and stops at the first that prices the line', function () {
    $customer = priceCustomer();
    $group = Build::id('GROUPA');
    $branch = Build::id('BRANCHA');

    $lists = [
        Build::list('LD', [Build::item(null, '1.0000', '7.0000')], ListType::Branch, assignments: [Build::assignment(ScopeType::Branch, $branch)]),
        Build::list('LP', [Build::item(null, '1.0000', '8.0000')], ListType::Promotion),
        Build::list('LB', [Build::item(null, '1.0000', '9.0000')], ListType::CustomerGroup, assignments: [Build::assignment(ScopeType::CustomerGroup, $group)]),
        Build::list('LA', [Build::item(null, '1.0000', '10.0000')], assignments: [Build::assignment(ScopeType::Customer, $customer)]),
    ];

    $at = static fn (array $lists, ?string $c, ?string $g, ?string $b, ?string $bp, ?string $p): array => priceSummary(PriceLadder::resolve(
        Build::ladder(priceEach(), '1.000000', '1.0000', $lists, $c, $g, $b, $bp, $p),
    ));

    expect($at($lists, $customer, $group, $branch, '6.0000', '5.0000')[3])->toBe(1)
        ->and($at($lists, null, $group, $branch, '6.0000', '5.0000')[3])->toBe(2)
        ->and($at($lists, null, null, $branch, '6.0000', '5.0000')[3])->toBe(3)
        ->and($at([$lists[0], $lists[2], $lists[3]], null, null, $branch, '6.0000', '5.0000')[3])->toBe(4)
        ->and($at([], null, null, $branch, '6.0000', '5.0000')[3])->toBe(5)
        ->and($at([], null, null, $branch, null, '5.0000')[3])->toBe(6);
});

it('never consults a later step once one has priced the line', function () {
    // A step-6 price that would overflow if it were ever derived. If the
    // ladder looked past step 1 this would refuse out_of_range.
    $list = Build::list('L', [Build::item(null, '1.0000', '10.0000')], assignments: [Build::assignment(ScopeType::Customer, priceCustomer())]);

    $outcome = PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', '24.0000', [$list], customer: priceCustomer(), branchPrice: '99999999999999.9999', price: '99999999999999.9999'));

    expect($outcome->unitPrice)->toBe('240.0000')
        ->and($outcome->source->step)->toBe(1);
});

it('derives steps 5 and 6 by the unit factor, so a case is not sold for the price of a single', function () {
    $five = PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', '24.0000', branchPrice: '7.5000', price: '7.0000'));
    $six = PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', '24.0000', price: '7.5000'));
    $single = PriceLadder::resolve(Build::ladder(priceEach(), '1.000000', '1.0000', price: '7.5000'));

    expect(priceSummary($five))->toBe(['180.0000', null, '180.0000', 5, null, 'product'])
        ->and(priceSummary($six))->toBe(['180.0000', null, '180.0000', 6, null, 'product'])
        ->and($single->unitPrice)->toBe('7.5000');
});

it('gives the same answer whatever order the lists and items arrive in', function () {
    $customer = priceCustomer();
    $assigned = [Build::assignment(ScopeType::Customer, $customer, 1)];

    $lists = [
        Build::list('A', [Build::item(null, '1.0000', '3.0000')], priority: 5, assignments: $assigned),
        Build::list('B', [Build::item(null, '1.0000', '4.0000')], priority: 9, assignments: $assigned),
        Build::list('C', [Build::item(null, '1.0000', '5.0000')], priority: 9, assignments: $assigned),
        Build::list('P1', [Build::item(null, '1.0000', '6.0000')], ListType::Promotion, priority: 3),
        Build::list('P2', [Build::item(null, '1.0000', '7.0000')], ListType::Promotion, priority: 3),
    ];

    $expected = priceSummary(PriceLadder::resolve(Build::ladder(priceEach(), '1.000000', '1.0000', $lists, customer: $customer)));

    // B and C tie on priority 9 and assignment priority 1: the code decides, so B.
    expect($expected[2])->toBe('4.0000');

    mt_srand(2911);

    for ($i = 0; $i < 25; $i++) {
        $shuffled = $lists;
        shuffle($shuffled);

        expect(priceSummary(PriceLadder::resolve(Build::ladder(priceEach(), '1.000000', '1.0000', $shuffled, customer: $customer))))->toBe($expected);
    }
});

it('compares codes bytewise, so numeric-looking codes order as text', function () {
    $assigned = [Build::assignment(ScopeType::Customer, priceCustomer())];

    $outcome = PriceLadder::resolve(Build::ladder(priceEach(), '1.000000', '1.0000', [
        Build::list('9', [Build::item(null, '1.0000', '9.0000')], assignments: $assigned),
        Build::list('10', [Build::item(null, '1.0000', '10.0000')], assignments: $assigned),
    ], customer: priceCustomer()));

    // As text "10" sorts before "9"; as numbers 9 would.
    expect($outcome->unitPrice)->toBe('10.0000');
});

it('ignores an assignment when the line has no such scope id', function () {
    $list = Build::list('L', [Build::item(null, '1.0000', '9.0000')], assignments: [Build::assignment(ScopeType::Customer, priceCustomer())]);

    expect(Build::reasonOf(fn () => PriceLadder::resolve(Build::ladder(priceEach(), '1.000000', '1.0000', [$list]))))->toBe('no_price_resolved');
});

it('does not treat a standard list with no assignment as a candidate at any step', function () {
    $list = Build::list('L', [Build::item(null, '1.0000', '9.0000')]);

    expect(Build::reasonOf(fn () => PriceLadder::resolve(Build::ladder(priceEach(), '1.000000', '1.0000', [$list], customer: priceCustomer()))))->toBe('no_price_resolved');
});

it('filters each list on active, deleted, window and currency', function (array $override) {
    $list = Build::list('BAD', [Build::item(null, '1.0000', '1.0000')], ...[...$override, 'priority' => 10, 'assignments' => [Build::assignment(ScopeType::Customer, priceCustomer())]]);

    expect(Build::reasonOf(fn () => PriceLadder::resolve(Build::ladder(priceEach(), '1.000000', '1.0000', [$list], customer: priceCustomer()))))->toBe('no_price_resolved');
})->with([
    'inactive' => [['active' => false]],
    'deleted' => [['deleted' => true]],
    'not yet started' => [['startsAt' => '2025-06-15T09:30:00.001Z']],
    'ended a millisecond ago' => [['endsAt' => '2025-06-15T09:29:59.999Z']],
    'another currency' => [['currency' => 'EUR']],
]);

it('accepts a window that opens or closes at exactly the document instant', function (array $override) {
    $list = Build::list('OK', [Build::item(null, '1.0000', '1.0000')], ...[...$override, 'assignments' => [Build::assignment(ScopeType::Customer, priceCustomer())]]);

    expect(PriceLadder::resolve(Build::ladder(priceEach(), '1.000000', '1.0000', [$list], customer: priceCustomer()))->unitPrice)->toBe('1.0000');
})->with([
    'starts at the instant' => [['startsAt' => Build::AT]],
    'ends at the instant' => [['endsAt' => Build::AT]],
    'open on both sides' => [[]],
]);

it('applies a winning item discount_percent exactly and rounds once', function () {
    $assigned = [Build::assignment(ScopeType::Customer, priceCustomer())];

    $a = PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', '24.0000', [
        Build::list('L', [Build::item(priceCase(), '1.0000', '179.0000', '10.0000')], assignments: $assigned),
    ], customer: priceCustomer()));

    $b = PriceLadder::resolve(Build::ladder(priceEach(), '1.000000', '1.0000', [
        Build::list('L', [Build::item(null, '1.0000', '1.2345', '10.0000')], assignments: $assigned),
    ], customer: priceCustomer()));

    expect(priceSummary($a))->toBe(['179.0000', '10.0000', '161.1000', 1, Build::id('LISTL'), 'unit_matched'])
        // 1.2345 x 90 / 100 = 1.11105 -> 1.1111; subtracting a rounded 0.1235 would give 1.1110.
        ->and(priceSummary($b)[2])->toBe('1.1111')
        ->and($b->listPrice)->toBe('1.2345');
});

it('returns the list price unchanged when there is no item discount', function () {
    $outcome = PriceLadder::resolve(Build::ladder(priceEach(), '1.000000', '1.0000', price: '7.5000'));

    expect($outcome->listPrice)->toBe('7.5000')
        ->and($outcome->discountPercent)->toBeNull()
        ->and($outcome->unitPrice)->toBe('7.5000');
});

it('refuses nothing resolving, two rows at one min_qty, and an out-of-range derived price, each by name', function () {
    $assigned = [Build::assignment(ScopeType::Customer, priceCustomer())];

    $duplicate = Build::list('L', [Build::item(priceCase(), '1.0000', '1.0000'), Build::item(priceCase(), '1.0000', '2.0000')], assignments: $assigned);

    expect(Build::reasonOf(fn () => PriceLadder::resolve(Build::ladder(priceEach(), '1.000000', '1.0000'))))->toBe('no_price_resolved')
        ->and(Build::reasonOf(fn () => PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', '24.0000', [$duplicate], customer: priceCustomer()))))->toBe('invalid_amount')
        ->and(Build::reasonOf(fn () => PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', '24.0000', price: '99999999999999.9999'))))->toBe('out_of_range');
});

it('gives every price fixture the same outcome with its lists, items and assignments reversed (D-29, AC-30.4)', function () {
    $paths = array_values(array_filter(FixtureReplay::paths(), static fn (string $path): bool => basename(dirname($path)) === 'price'));

    expect(count($paths))->toBeGreaterThan(30);

    foreach ($paths as $path) {
        /** @var array<string, mixed> $case */
        $case = json_decode((string) file_get_contents($path), true);
        $reversed = $case;
        $lists = array_reverse($case['input']['lists']);

        foreach ($lists as $i => $list) {
            $lists[$i]['items'] = array_reverse($list['items']);
            $lists[$i]['assignments'] = array_reverse($list['assignments']);
        }

        $reversed['input']['lists'] = $lists;

        expect(FixtureReplay::replay($reversed))->toBe(FixtureReplay::replay($case), basename($path));
    }
});

it('refuses a duplicate min_qty in any order, qualifying or not, and compares 1 with 1.0000 numerically', function (array $mins, string $base) {
    $list = Build::list('L', array_map(static fn (string $min): LadderItem => Build::item(priceCase(), $min, '1.0000'), $mins), assignments: [Build::assignment(ScopeType::Customer, priceCustomer())]);

    expect(Build::reasonOf(fn () => PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', $base, [$list], customer: priceCustomer()))))->toBe('invalid_amount');
})->with([
    '1, 1, 5' => [['1.0000', '1.0000', '5.0000'], '10.0000'],
    '5, 1, 1' => [['5.0000', '1.0000', '1.0000'], '10.0000'],
    '1, 5, 1' => [['1.0000', '5.0000', '1.0000'], '10.0000'],
    '1, 48, 48 above the base' => [['1.0000', '48.0000', '48.0000'], '24.0000'],
    '1 and 1.0000 are one value' => [['5.0000', '1', '1.0000'], '10.0000'],
]);

it('does not inspect the items of another unit, so one bad row cannot poison an unrelated line', function () {
    $list = Build::list('L', [
        Build::item(priceEach(), '1.0000', '1.0000'),
        Build::item(priceEach(), '1.0000', '2.0000'),
        Build::item(priceCase(), '1.0000', '179.0000'),
    ], assignments: [Build::assignment(ScopeType::Customer, priceCustomer())]);

    expect(PriceLadder::resolve(Build::ladder(priceCase(), '24.000000', '24.0000', [$list], customer: priceCustomer()))->unitPrice)->toBe('179.0000');
});

it('refuses a malformed ladder input', function () {
    expect(Build::reasonOf(fn () => Build::ladder(priceCase(), '0.500000', '1.0000')))->toBe('invalid_amount')
        ->and(Build::reasonOf(fn () => Build::ladder(priceCase(), '24.000000', '1.00005')))->toBe('invalid_amount')
        ->and(Build::reasonOf(fn () => Build::ladder(priceCase(), '24.000000', '1.0000', price: '-1.0000')))->toBe('invalid_amount')
        ->and(fn () => Build::ladder(priceCase(), '24.000000', '1.0000', at: '2025-06-15'))->toThrow(\InvalidArgumentException::class, '[at]')
        ->and(fn () => Build::ladder(priceCase(), '24.000000', '1.0000', currency: 'us'))->toThrow(\InvalidArgumentException::class, '[currency]')
        ->and(fn () => Build::item(null, '1.0000', '1.0000', '100.0001'))->toThrow(\InvalidArgumentException::class, '[discountPercent]');
});

it('is a LadderInput with exactly the facts it names', function () {
    $input = Build::ladder(priceCase(), '24', '24', price: '7.5');

    expect($input)->toBeInstanceOf(LadderInput::class)
        ->and($input->unitFactor)->toBe('24.000000')
        ->and($input->baseQuantity)->toBe('24.0000')
        ->and($input->sellingPrice)->toBe('7.5000')
        ->and($input->branchSellingPrice)->toBeNull();
});

it('accepts an item discount of exactly 100 and refuses one a hair above (the numeric cap)', function () {
    expect(Build::item(null, '1.0000', '1.0000', '100.0000')->discountPercent)->toBe('100.0000')
        ->and(fn () => Build::item(null, '1.0000', '1.0000', '100.0001'))->toThrow(\InvalidArgumentException::class, '[discountPercent]');
});
