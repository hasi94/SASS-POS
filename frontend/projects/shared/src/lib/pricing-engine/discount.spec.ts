import { calculateBillDiscount, calculateItemDiscount } from './discount';
import { AT, discountRule, failureOf, id, product } from './testing/builders';
import type {
  BillDiscountInput,
  DiscountRuleInput,
  ItemDiscountInput,
  ProductFactsInput,
} from './types';

const RULE_ID = id('01DISC10');

function item(
  rule: Partial<DiscountRuleInput> = {},
  over: Partial<ItemDiscountInput> = {},
): ItemDiscountInput {
  return {
    discount_id: RULE_ID,
    discounts: [discountRule(rule)],
    at: AT,
    product: product(),
    subtotal: '100.0000',
    ...over,
  };
}

function bill(
  rule: Partial<DiscountRuleInput> = {},
  over: Partial<BillDiscountInput> = {},
): BillDiscountInput {
  return {
    discount_id: RULE_ID,
    discounts: [discountRule({ scope: 'bill', ...rule })],
    at: AT,
    discounted_subtotals: ['100.0000'],
    ...over,
  };
}

describe('calculateItemDiscount: amounts', () => {
  it('takes a percentage of the subtotal', () => {
    expect(calculateItemDiscount(item())).toEqual({ discount_amount: '10.0000' });
  });

  it('rounds a percentage half-up, once', () => {
    expect(calculateItemDiscount(item({ value: '50.0000' }, { subtotal: '0.0001' }))).toEqual({
      discount_amount: '0.0001',
    });
  });

  it('caps a percentage at max_discount', () => {
    expect(calculateItemDiscount(item({ max_discount: '4.0000' }))).toEqual({
      discount_amount: '4.0000',
    });
  });

  it('gives a fixed discount of min(value, subtotal)', () => {
    expect(calculateItemDiscount(item({ type: 'fixed', value: '30.0000' }))).toEqual({
      discount_amount: '30.0000',
    });
    expect(
      calculateItemDiscount(item({ type: 'fixed', value: '300.0000' }, { subtotal: '12.0000' })),
    ).toEqual({ discount_amount: '12.0000' });
  });

  it('accepts a subtotal exactly at min_amount and refuses one below it', () => {
    expect(calculateItemDiscount(item({ min_amount: '100.0000' })).discount_amount).toBe('10.0000');
    expect(failureOf(() => calculateItemDiscount(item({ min_amount: '100.0001' })))).toBe(
      'refused below_min_amount',
    );
  });

  it('applies to a named product, category or brand and to everything when all are empty', () => {
    const named = (applies_to: DiscountRuleInput['applies_to']) =>
      calculateItemDiscount(item({ applies_to })).discount_amount;

    expect(named({ products: [id('01PRODA')], categories: [], brands: [] })).toBe('10.0000');
    expect(named({ products: [], categories: [id('01CATA')], brands: [] })).toBe('10.0000');
    expect(named({ products: [], categories: [], brands: [id('01BRANDA')] })).toBe('10.0000');
    expect(
      failureOf(() =>
        named({
          products: [id('01OTHER')],
          categories: [id('01OTHERC')],
          brands: [id('01OTHERB')],
        }),
      ),
    ).toBe('refused not_applicable');
  });
});

describe('calculateItemDiscount: the refusal order (AC-22)', () => {
  // A rule that fails every check at once. Each step removes the first failing condition.
  const broken: Partial<DiscountRuleInput> = {
    scope: 'bill',
    is_active: false,
    starts_at: '2025-07-01T00:00:00.000Z',
    ends_at: '2025-05-01T00:00:00.000Z',
    usage_limit: '1',
    used_count: '1',
    min_amount: '1000.0000',
    type: 'buy_x_get_y',
    applies_to: { products: [id('01OTHER')], categories: [], brands: [] },
  };

  const walk: [string, Partial<DiscountRuleInput>, Partial<ProductFactsInput>][] = [
    ['discount_scope_mismatch', {}, { allow_discount: false }],
    ['inactive', { scope: 'item' }, { allow_discount: false }],
    ['not_yet_started', { is_active: true }, { allow_discount: false }],
    ['expired', { starts_at: null }, { allow_discount: false }],
    ['usage_limit_reached', { ends_at: null }, { allow_discount: false }],
    ['discount_not_allowed', { used_count: '0' }, { allow_discount: false }],
    ['not_applicable', {}, { allow_discount: true }],
    ['below_min_amount', { applies_to: { products: [], categories: [], brands: [] } }, {}],
    ['unsupported_discount_type', { min_amount: null }, {}],
  ];

  it('walks down the whole order, one condition at a time', () => {
    expect(
      failureOf(() => calculateItemDiscount(item(broken, { discount_id: id('01NOSUCH') }))),
    ).toBe('refused unknown_discount');

    let rule = { ...broken };
    let facts: Partial<ProductFactsInput> = { allow_discount: false };

    for (const [reason, change, factChange] of walk) {
      rule = { ...rule, ...change };
      facts = { ...facts, ...factChange };

      expect(failureOf(() => calculateItemDiscount(item(rule, { product: product(facts) })))).toBe(
        'refused ' + reason,
      );
    }

    expect(
      calculateItemDiscount(item({ ...rule, type: 'percentage' }, { product: product(facts) })),
    ).toEqual({
      discount_amount: '10.0000',
    });
  });

  it('accepts an instant exactly at ends_at and refuses one millisecond later', () => {
    expect(calculateItemDiscount(item({ ends_at: AT })).discount_amount).toBe('10.0000');
    expect(
      failureOf(() => calculateItemDiscount(item({ ends_at: '2025-06-15T09:29:59.999Z' }))),
    ).toBe('refused expired');
  });

  it('accepts an instant exactly at starts_at', () => {
    expect(calculateItemDiscount(item({ starts_at: AT })).discount_amount).toBe('10.0000');
  });

  it('refuses when used_count equals usage_limit, and accepts one use left', () => {
    expect(
      failureOf(() => calculateItemDiscount(item({ usage_limit: '5', used_count: '5' }))),
    ).toBe('refused usage_limit_reached');
    expect(calculateItemDiscount(item({ usage_limit: '5', used_count: '4' })).discount_amount).toBe(
      '10.0000',
    );
  });

  it('compares usage as integers, so 9 of 10 is not reached', () => {
    expect(
      calculateItemDiscount(item({ usage_limit: '10', used_count: '9' })).discount_amount,
    ).toBe('10.0000');
  });
});

describe('calculateItemDiscount: known and unknown discount types (AC-30)', () => {
  it('refuses the known buy_x_get_y as unsupported_discount_type', () => {
    expect(failureOf(() => calculateItemDiscount(item({ type: 'buy_x_get_y' })))).toBe(
      'refused unsupported_discount_type',
    );
  });

  it('throws UnsupportedRuleError, not a refusal, for an unrecognised type', () => {
    const odd = item({ type: 'loyalty' as unknown as DiscountRuleInput['type'] });

    expect(failureOf(() => calculateItemDiscount(odd))).toBe(
      'unsupported discounts[].type=loyalty',
    );
  });

  it('throws UnsupportedRuleError for an unrecognised scope', () => {
    const odd = item({ scope: 'region' as unknown as DiscountRuleInput['scope'] });

    expect(failureOf(() => calculateItemDiscount(odd))).toBe(
      'unsupported discounts[].scope=region',
    );
  });
});

describe('calculateItemDiscount: every rule is built, not only the selected one', () => {
  it('refuses a percentage value above 100 even when another rule is selected', () => {
    const other = discountRule({ id: id('01OTHERDISC'), value: '100.0001' });
    const input = { ...item(), discounts: [discountRule(), other] };

    expect(failureOf(() => calculateItemDiscount(input))).toBe('refused invalid_amount');
  });

  it('accepts a fixed value above 100', () => {
    expect(calculateItemDiscount(item({ type: 'fixed', value: '150.0000' })).discount_amount).toBe(
      '100.0000',
    );
  });

  it('throws a programmer error for a blank id, a negative count, or a malformed instant', () => {
    expect(failureOf(() => calculateItemDiscount(item({ id: '' }, { discount_id: '' })))).toBe(
      'input-error',
    );
    expect(failureOf(() => calculateItemDiscount(item({ used_count: '-1' })))).toBe('input-error');
    expect(failureOf(() => calculateItemDiscount(item({ usage_limit: '-1' })))).toBe('input-error');
    expect(failureOf(() => calculateItemDiscount(item({ ends_at: '2025-06-15' })))).toBe(
      'input-error',
    );
    expect(failureOf(() => calculateItemDiscount(item({}, { at: 'now' })))).toBe('input-error');
  });

  it('uses the first rule whose id matches', () => {
    const first = discountRule({ value: '10.0000' });
    const second = discountRule({ value: '20.0000' });

    expect(calculateItemDiscount({ ...item(), discounts: [first, second] }).discount_amount).toBe(
      '10.0000',
    );
  });

  it('refuses a subtotal that is not a plain decimal string as invalid_amount (R-7)', () => {
    const numeric = { ...item(), subtotal: 100 } as unknown as ItemDiscountInput;

    expect(failureOf(() => calculateItemDiscount(numeric))).toBe('refused invalid_amount');
    expect(failureOf(() => calculateItemDiscount(item({}, { subtotal: '1.00001' })))).toBe(
      'refused invalid_amount',
    );
  });
});

describe('calculateBillDiscount', () => {
  it('discounts the sum of the discounted subtotals', () => {
    expect(
      calculateBillDiscount(bill({}, { discounted_subtotals: ['100.0000', '50.0000', '25.0000'] })),
    ).toEqual({ discount_id: RULE_ID, base: '175.0000', amount: '17.5000' });
  });

  it('gives an empty bill a base of 0.0000 and an amount of 0.0000', () => {
    expect(calculateBillDiscount(bill({}, { discounted_subtotals: [] }))).toEqual({
      discount_id: RULE_ID,
      base: '0.0000',
      amount: '0.0000',
    });
  });

  it('never lets a fixed discount exceed the base', () => {
    expect(calculateBillDiscount(bill({ type: 'fixed', value: '500.0000' })).amount).toBe(
      '100.0000',
    );
  });

  it('refuses an item-scope discount given to a bill', () => {
    const input = bill({ scope: 'item' });

    expect(failureOf(() => calculateBillDiscount(input))).toBe('refused discount_scope_mismatch');
  });

  it('refuses below_min_amount against the summed base', () => {
    expect(failureOf(() => calculateBillDiscount(bill({ min_amount: '100.0001' })))).toBe(
      'refused below_min_amount',
    );
  });

  it('refuses an unknown discount, and an expired one against the document instant', () => {
    expect(failureOf(() => calculateBillDiscount(bill({}, { discount_id: id('01NOSUCH') })))).toBe(
      'refused unknown_discount',
    );
    expect(
      failureOf(() => calculateBillDiscount(bill({ ends_at: '2025-06-15T09:29:59.999Z' }))),
    ).toBe('refused expired');
  });

  it('upper-cases the discount id of the result', () => {
    const lower = 'abcdefghijklmnopqrstuvwxyz';
    const input = bill({ id: lower }, { discount_id: lower });

    expect(calculateBillDiscount(input).discount_id).toBe(lower.toUpperCase());
  });

  it('refuses a subtotal that is not a plain decimal string as invalid_amount', () => {
    const input = { ...bill(), discounted_subtotals: [100] } as unknown as BillDiscountInput;

    expect(failureOf(() => calculateBillDiscount(input))).toBe('refused invalid_amount');
  });

  it('refuses a base above the range, and checks the instant first', () => {
    const big = ['99999999999999.9999', '0.0001'];

    expect(failureOf(() => calculateBillDiscount(bill({}, { discounted_subtotals: big })))).toBe(
      'refused out_of_range',
    );
    expect(failureOf(() => calculateBillDiscount(bill({}, { at: 'now' })))).toBe('input-error');
  });
});
