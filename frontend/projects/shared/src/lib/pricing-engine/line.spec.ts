// Imported through the public API, not a relative path, to prove the engine is reachable from
// another project the way the till will import it (AC-3, AC-4).
import {
  PRICING_REFUSALS,
  PricingInputError,
  PricingRefused,
  UnsupportedRuleError,
  calculateBillDiscount,
  calculateItemDiscount,
  calculateTax,
  priceLine,
  resolvePrice,
} from '@flexipos/shared';
import type { LineInput, PricedLine } from '@flexipos/shared';
import {
  AT,
  discountRule,
  failureOf,
  id,
  lineInput,
  listItem,
  priceInput,
  priceList,
  product,
  rateRow,
  taxInput,
  taxMember,
} from './testing/builders';

describe('the public API (AC-3, AC-4)', () => {
  it('exposes the five operations and the error types through @flexipos/shared', () => {
    for (const operation of [
      calculateTax,
      resolvePrice,
      calculateItemDiscount,
      calculateBillDiscount,
      priceLine,
    ]) {
      expect(typeof operation).toBe('function');
    }

    expect(new PricingRefused('expired')).toBeInstanceOf(PricingRefused);
    expect(new PricingInputError('x')).toBeInstanceOf(PricingInputError);
    expect(new UnsupportedRuleError('f', 'v')).toBeInstanceOf(UnsupportedRuleError);
    expect(PRICING_REFUSALS.length).toBe(17);
  });

  it('runs each operation from the public import', () => {
    expect(calculateTax(taxInput()).total).toBe('15.0000');
    expect(resolvePrice(priceInput()).unit_price).toBe('10.0000');
    expect(
      calculateItemDiscount({
        discount_id: id('01DISC10'),
        discounts: [discountRule()],
        at: AT,
        product: product(),
        subtotal: '100.0000',
      }).discount_amount,
    ).toBe('10.0000');
    expect(
      calculateBillDiscount({
        discount_id: id('01DISC10'),
        discounts: [discountRule({ scope: 'bill' })],
        at: AT,
        discounted_subtotals: ['100.0000'],
      }).amount,
    ).toBe('10.0000');
    expect(priceLine(lineInput()).line_total).toBe('115.0000');
  });
});

describe('priceLine', () => {
  const withDiscount = (over: Partial<LineInput> = {}) =>
    lineInput({ discount_id: id('01DISC10'), discounts: [discountRule()], ...over });

  it('prices an exclusive line: ladder, subtotal, discount, tax, total', () => {
    const line: PricedLine = priceLine(withDiscount());

    expect(line).toMatchObject({
      list_price: '100.0000',
      list_discount_percent: null,
      unit_price: '100.0000',
      subtotal: '100.0000',
      discount_amount: '10.0000',
      discount_id: id('01DISC10'),
      discounted_subtotal: '90.0000',
      tax_group_id: id('01TAXGROUPA'),
      line_total: '103.5000',
    });
    expect(line.source).toEqual({ step: 1, price_list_id: id('01LISTA'), basis: 'derived' });
    expect(line.tax.total).toBe('13.5000');
    expect(line.tax.lines[0]?.base).toBe('90.0000');
  });

  it('names the ladder percentage list_discount_percent on the line kind', () => {
    const items = [listItem({ price: '100.0000', discount_percent: '10.0000' })];
    const line = priceLine(lineInput({ ladder: priceInput({ lists: [priceList({ items })] }) }));

    expect(line.list_discount_percent).toBe('10.0000');
    expect(line.unit_price).toBe('90.0000');
    expect(Object.keys(line)).not.toContain('discount_percent');
  });

  it('multiplies the unit price by quantity, never by base_quantity', () => {
    const ladder = priceInput({
      unit_factor: '24.000000',
      base_quantity: '24.0000',
      lists: [priceList({ items: [listItem({ unit_id: id('01UNITEACH'), price: '179.0000' })] })],
    });
    const line = priceLine(lineInput({ ladder, quantity: '1.0000' }));

    expect(line.subtotal).toBe('179.0000');
  });

  it('feeds the base quantity to a fixed tax', () => {
    const fixed = taxMember({ type: 'fixed', rates: [rateRow({ rate: '0.5000' })] });
    const ladder = priceInput({
      unit_factor: '24.000000',
      base_quantity: '24.0000',
      lists: [priceList({ items: [listItem({ unit_id: id('01UNITEACH'), price: '179.0000' })] })],
    });
    const line = priceLine(
      lineInput({
        ladder,
        quantity: '1.0000',
        tax_group: { id: id('01TAXGROUPA'), is_inclusive: false, members: [fixed] },
      }),
    );

    expect(line.tax.total).toBe('12.0000');
    expect(line.line_total).toBe('191.0000');
  });

  it('refuses a negative quantity as invalid_amount', () => {
    expect(failureOf(() => priceLine(lineInput({ quantity: '-1.0000' })))).toBe(
      'refused invalid_amount',
    );
  });

  it('makes an inclusive line total equal the discounted subtotal', () => {
    const line = priceLine(
      withDiscount({
        tax_group: { id: id('01TAXGROUPA'), is_inclusive: true, members: [taxMember()] },
      }),
    );

    expect(line.line_total).toBe(line.discounted_subtotal);
    expect(line.tax.is_inclusive).toBe(true);
    expect(line.tax.net).toBe('78.2609');
  });

  it('gives a line with no discount a discount amount of 0.0000 and a null id', () => {
    const line = priceLine(lineInput());

    expect(line.discount_amount).toBe('0.0000');
    expect(line.discount_id).toBeNull();
    expect(line.discounted_subtotal).toBe(line.subtotal);
  });

  it('refuses a requested discount that no rule matches', () => {
    expect(
      failureOf(() => priceLine(lineInput({ discount_id: id('01NOSUCH'), discounts: [] }))),
    ).toBe('refused unknown_discount');
  });

  it('judges min_amount on the subtotal', () => {
    const rule = discountRule({ min_amount: '100.0001' });

    expect(failureOf(() => priceLine(lineInput({ discount_id: rule.id, discounts: [rule] })))).toBe(
      'refused below_min_amount',
    );
  });

  it('upper-cases the discount and tax group ids', () => {
    const lower = 'abcdefghijklmnopqrstuvwxyz';
    const rule = discountRule({ id: lower });
    const line = priceLine(
      lineInput({
        discount_id: lower,
        discounts: [rule],
        tax_group: { id: lower, is_inclusive: false, members: [taxMember()] },
      }),
    );

    expect(line.discount_id).toBe(lower.toUpperCase());
    expect(line.tax_group_id).toBe(lower.toUpperCase());
  });

  it('always prices tax in the sale context', () => {
    const purchaseOnly = taxMember({ applies_to: 'purchase' });
    const line = priceLine(
      lineInput({
        tax_group: { id: id('01TAXGROUPA'), is_inclusive: false, members: [purchaseOnly] },
      }),
    );

    expect(line.tax.lines).toEqual([]);
  });

  it('throws UnsupportedRuleError for an unknown rule value anywhere in the input', () => {
    const odd = lineInput({
      tax_group: {
        id: id('01TAXGROUPA'),
        is_inclusive: false,
        members: [{ ...taxMember(), type: 'surcharge' } as unknown as ReturnType<typeof taxMember>],
      },
    });

    expect(failureOf(() => priceLine(odd))).toBe('unsupported members[].type=surcharge');
  });

  it('throws a programmer error for a blank tax group id or a bad document date', () => {
    expect(
      failureOf(() =>
        priceLine(
          lineInput({ tax_group: { id: '', is_inclusive: false, members: [taxMember()] } }),
        ),
      ),
    ).toBe('input-error');
    expect(failureOf(() => priceLine(lineInput({ document_date: '2025-02-30' })))).toBe(
      'input-error',
    );
  });
});
