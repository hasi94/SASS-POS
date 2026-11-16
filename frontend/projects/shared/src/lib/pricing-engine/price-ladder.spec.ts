import { resolvePrice } from './price-ladder';
import { AT, assignment, failureOf, id, listItem, priceInput, priceList } from './testing/builders';
import type { PriceInput, PriceListInput } from './types';

const UNIT = id('01UNITEACH');
const CASE = id('01UNITCASE');

function winner(input: PriceInput): string | null {
  return resolvePrice(input).source.price_list_id;
}

describe('resolvePrice: the ladder', () => {
  it('prices from the customer list at step 1, unit matched, as typed', () => {
    const result = resolvePrice(
      priceInput({ lists: [priceList({ items: [listItem({ unit_id: UNIT, price: '9.5000' })] })] }),
    );

    expect(result).toEqual({
      list_price: '9.5000',
      discount_percent: null,
      unit_price: '9.5000',
      source: { step: 1, price_list_id: id('01LISTA'), basis: 'unit_matched' },
    });
  });

  it('names the percentage discount_percent on the price kind, as PHP does (Q-1)', () => {
    const result = resolvePrice(priceInput());

    expect(Object.keys(result)).toEqual(['list_price', 'discount_percent', 'unit_price', 'source']);
  });

  it('derives a no-unit item by the unit factor', () => {
    const result = resolvePrice(
      priceInput({ unit_id: CASE, unit_factor: '24.000000', base_quantity: '24.0000' }),
    );

    expect(result.list_price).toBe('240.0000');
    expect(result.source.basis).toBe('derived');
  });

  it('applies an item discount_percent once to the resolved price', () => {
    const result = resolvePrice(
      priceInput({ lists: [priceList({ items: [listItem({ discount_percent: '12.5000' })] })] }),
    );

    expect(result.list_price).toBe('10.0000');
    expect(result.discount_percent).toBe('12.5000');
    expect(result.unit_price).toBe('8.7500');
  });

  it('falls to the branch selling price at step 5 and the product price at step 6, both derived', () => {
    const base = priceInput({ lists: [], unit_factor: '24.000000', base_quantity: '24.0000' });
    const five = resolvePrice({ ...base, branch_selling_price: '2.0000', selling_price: '1.0000' });
    const six = resolvePrice({ ...base, selling_price: '1.5000' });

    expect(five.list_price).toBe('48.0000');
    expect(five.source).toEqual({ step: 5, price_list_id: null, basis: 'product' });
    expect(six.list_price).toBe('36.0000');
    expect(six.source).toEqual({ step: 6, price_list_id: null, basis: 'product' });
  });

  it('refuses no_price_resolved when no step prices the line', () => {
    expect(failureOf(() => resolvePrice(priceInput({ lists: [] })))).toBe(
      'refused no_price_resolved',
    );
  });

  it('skips a list that prices nothing in favour of the next one', () => {
    const empty = priceList({ list_id: id('01LISTEMPTY'), code: 'AA', items: [] });
    const full = priceList({ list_id: id('01LISTFULL'), code: 'BB' });

    expect(winner(priceInput({ lists: [empty, full] }))).toBe(id('01LISTFULL'));
  });
});

describe('resolvePrice: the list window', () => {
  const windowed = (over: Partial<PriceListInput>) => priceInput({ lists: [priceList(over)] });

  it('accepts an instant exactly at starts_at and exactly at ends_at', () => {
    expect(winner(windowed({ starts_at: AT }))).toBe(id('01LISTA'));
    expect(winner(windowed({ ends_at: AT }))).toBe(id('01LISTA'));
  });

  it('skips a list one millisecond outside the window', () => {
    expect(failureOf(() => resolvePrice(windowed({ starts_at: '2025-06-15T09:30:00.001Z' })))).toBe(
      'refused no_price_resolved',
    );
    expect(failureOf(() => resolvePrice(windowed({ ends_at: '2025-06-15T09:29:59.999Z' })))).toBe(
      'refused no_price_resolved',
    );
  });

  it('skips an inactive list, a deleted list and a list in another currency', () => {
    for (const over of [{ is_active: false }, { is_deleted: true }, { currency: 'EUR' }]) {
      expect(failureOf(() => resolvePrice(windowed(over)))).toBe('refused no_price_resolved');
    }
  });
});

describe('resolvePrice: ordering', () => {
  it('breaks an equal promotion priority by byte order of the code, so "10" beats "9"', () => {
    const nine = priceList({
      list_id: id('01LISTNINE'),
      code: '9',
      type: 'promotion',
      assignments: [],
    });
    const ten = priceList({
      list_id: id('01LISTTEN'),
      code: '10',
      type: 'promotion',
      assignments: [],
    });

    expect(winner(priceInput({ lists: [nine, ten] }))).toBe(id('01LISTTEN'));
    expect(winner(priceInput({ lists: [ten, nine] }))).toBe(id('01LISTTEN'));
  });

  it('orders priorities as integers, so a priority of 10 beats 9', () => {
    const nine = priceList({
      list_id: id('01LISTNINE'),
      code: 'A',
      type: 'promotion',
      priority: '9',
      assignments: [],
    });
    const ten = priceList({
      list_id: id('01LISTTEN'),
      code: 'B',
      type: 'promotion',
      priority: '10',
      assignments: [],
    });

    expect(winner(priceInput({ lists: [nine, ten] }))).toBe(id('01LISTTEN'));
  });

  it('orders assignment priority first, then list priority, descending', () => {
    const lowAssignment = priceList({
      list_id: id('01LISTLOW'),
      code: 'A',
      priority: '99',
      assignments: [assignment({ priority: '1' })],
    });
    const highAssignment = priceList({
      list_id: id('01LISTHIGH'),
      code: 'B',
      priority: '0',
      assignments: [assignment({ priority: '10' })],
    });

    expect(winner(priceInput({ lists: [lowAssignment, highAssignment] }))).toBe(id('01LISTHIGH'));
  });

  it('gives a customer list step 1 even when a promotion is also present', () => {
    const promotion = priceList({
      list_id: id('01LISTPROMO'),
      code: 'P',
      type: 'promotion',
      assignments: [],
      items: [listItem({ price: '1.0000' })],
    });

    const result = resolvePrice(priceInput({ lists: [promotion, priceList()] }));

    expect(result.source.step).toBe(1);
  });

  it('does not let an unmatched promotion assignment apply', () => {
    const promotion = priceList({
      list_id: id('01LISTPROMO'),
      code: 'P',
      type: 'promotion',
      assignments: [assignment({ scope_type: 'branch', scope_id: id('01BRANCHX') })],
    });

    expect(
      failureOf(() => resolvePrice(priceInput({ customer_id: null, lists: [promotion] }))),
    ).toBe('refused no_price_resolved');
  });
});

describe('resolvePrice: min_qty items', () => {
  const at = (minQty: string, price: string, unit: string | null = UNIT) =>
    listItem({ unit_id: unit, min_qty: minQty, price });

  it('takes the greatest min_qty at or below the base quantity', () => {
    const items = [at('1.0000', '10.0000'), at('12.0000', '9.0000'), at('24.0000', '8.0000')];

    expect(
      resolvePrice(priceInput({ base_quantity: '13.0000', lists: [priceList({ items })] }))
        .list_price,
    ).toBe('9.0000');
  });

  it('refuses a duplicate min_qty for one unit whatever the row order, even below the break', () => {
    const first = at('1.0000', '10.0000');
    const second = at('1.0000', '9.0000');
    const farAway = at('50.0000', '1.0000');

    for (const items of [
      [first, second, farAway],
      [farAway, second, first],
    ]) {
      expect(failureOf(() => resolvePrice(priceInput({ lists: [priceList({ items })] })))).toBe(
        'refused invalid_amount',
      );
    }
  });

  it('ignores a duplicate in another unit', () => {
    const items = [
      at('1.0000', '10.0000'),
      at('1.0000', '1.0000', CASE),
      at('1.0000', '2.0000', CASE),
    ];

    expect(resolvePrice(priceInput({ lists: [priceList({ items })] })).list_price).toBe('10.0000');
  });

  it('prefers a unit-matched row over a no-unit row with a lower min_qty', () => {
    const items = [at('1.0000', '3.0000', null), at('2.0000', '10.0000')];
    const result = resolvePrice(
      priceInput({ base_quantity: '2.0000', lists: [priceList({ items })] }),
    );

    expect(result.source.basis).toBe('unit_matched');
    expect(result.list_price).toBe('10.0000');
  });
});

describe('resolvePrice: unknown values and bad input', () => {
  it('throws a programmer error, not a refusal, for a discount_percent above 100', () => {
    const items = [listItem({ discount_percent: '100.0001' })];

    expect(failureOf(() => resolvePrice(priceInput({ lists: [priceList({ items })] })))).toBe(
      'input-error',
    );
  });

  it('refuses a price that is not a string as invalid_amount', () => {
    const items = [{ ...listItem(), price: 10 } as unknown as ReturnType<typeof listItem>];

    expect(failureOf(() => resolvePrice(priceInput({ lists: [priceList({ items })] })))).toBe(
      'refused invalid_amount',
    );
  });

  it('refuses a unit factor below one as invalid_amount', () => {
    expect(failureOf(() => resolvePrice(priceInput({ unit_factor: '0.999999' })))).toBe(
      'refused invalid_amount',
    );
  });

  it('throws UnsupportedRuleError for an unknown list type, even on a deleted list', () => {
    const odd = { ...priceList({ is_deleted: true }), type: 'vip' } as unknown as PriceListInput;

    expect(failureOf(() => resolvePrice(priceInput({ lists: [odd] })))).toBe(
      'unsupported lists[].type=vip',
    );
  });

  it('throws a programmer error for a malformed instant, currency or priority', () => {
    expect(failureOf(() => resolvePrice(priceInput({ at: '2025-06-15' })))).toBe('input-error');
    expect(failureOf(() => resolvePrice(priceInput({ currency: 'usd' })))).toBe('input-error');
    expect(
      failureOf(() => resolvePrice(priceInput({ lists: [priceList({ priority: '1.5' })] }))),
    ).toBe('input-error');
  });

  it('refuses a winning list whose id is not a ULID, as a programmer error', () => {
    expect(
      failureOf(() => resolvePrice(priceInput({ lists: [priceList({ list_id: 'short' })] }))),
    ).toBe('input-error');
  });

  it('upper-cases the winning list id', () => {
    const lower = 'abcdefghijklmnopqrstuvwxyz';

    expect(winner(priceInput({ lists: [priceList({ list_id: lower })] }))).toBe(
      lower.toUpperCase(),
    );
  });
});
