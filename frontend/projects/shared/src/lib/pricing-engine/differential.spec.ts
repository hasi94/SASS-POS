/*
 * QA differential spec (T-035). The expected values come from an independent exact-fraction
 * re-implementation of the PHP engine (see testing/differential-vectors.ts), not from this engine
 * and not from the shared fixtures. They are TS-only parity evidence, not a PHP fixture (D-9): a
 * mismatch is reported to the lead, never "fixed" by editing the vectors to match.
 */

import { calculateItemDiscount } from './discount';
import { priceLine } from './line';
import { resolvePrice } from './price-ladder';
import { calculateTax } from './tax';
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
  taxInput,
} from './testing/builders';
import { DIFF_DISCOUNT, DIFF_LINE, DIFF_TAX } from './testing/differential-vectors';
import type { DiscountRuleInput, TaxMemberInput } from './types';

const TAX_IDS = [
  '01TAXAAAAAAAAAAAAAAAAAAAAA',
  '01TAXBBBBBBBBBBBBBBBBBBBBB',
  '01TAXCCCCCCCCCCCCCCCCCCCCC',
  '01TAXDDDDDDDDDDDDDDDDDDDDD',
];

/** `seq:p|f:compound:rate` to a tax member input. */
function member(spec: string, index: number): TaxMemberInput {
  const [seq = '', kind = '', compound = '', rate = ''] = spec.split(':');

  return {
    tax_id: TAX_IDS[index] ?? '',
    code: 'T' + String(index),
    tax_code: null,
    type: kind === 'p' ? 'percentage' : 'fixed',
    is_compound: compound === '1',
    applies_to: 'both',
    is_active: true,
    is_deleted: false,
    sequence: seq,
    rates: [{ rate, effective_from: '2020-01-01', effective_to: null }],
  };
}

/** `p|f:value:min:max` (a dash is null) to a discount rule input. */
function rule(spec: string): DiscountRuleInput {
  const [kind = '', value = '', min = '-', max = '-'] = spec.split(':');

  return discountRule({
    type: kind === 'p' ? 'percentage' : 'fixed',
    value,
    min_amount: min === '-' ? null : min,
    max_discount: max === '-' ? null : max,
  });
}

function outcome<T>(run: () => T): { value: T } | { refusal: string } {
  try {
    return { value: run() };
  } catch (error) {
    const reason = (error as { reason?: unknown }).reason;

    if (typeof reason === 'string') {
      return { refusal: reason };
    }

    throw error;
  }
}

describe('differential: tax against an independent exact-fraction oracle', () => {
  it('holds a representative share of results and of each refusal the oracle produced', () => {
    const refusals = DIFF_TAX.filter((row) => typeof row[4] === 'string').length;

    expect(DIFF_TAX.length).toBeGreaterThan(150);
    expect(refusals).toBeGreaterThan(5);
    expect(DIFF_TAX.filter((row) => row[3] === 1).length).toBeGreaterThan(50);
    expect(DIFF_TAX.filter((row) => row[3] === 0).length).toBeGreaterThan(50);
  });

  it.each(DIFF_TAX.map((row, index) => [index, row] as const))('vector %i', (_index, row) => {
    const [members, amount, quantity, inclusive, wanted] = row;

    const got = outcome(() =>
      calculateTax(
        taxInput({
          members: members.map(member),
          amount,
          base_quantity: quantity,
          is_inclusive: inclusive === 1,
        }),
      ),
    );

    if (typeof wanted === 'string') {
      expect(got).toEqual({ refusal: wanted });

      return;
    }

    expect(got).toHaveProperty('value');

    const result = (got as { value: ReturnType<typeof calculateTax> }).value;

    expect(result.net).toBe(wanted[0]);
    expect(result.total).toBe(wanted[1]);
    expect(result.lines.map((line) => [line.base, line.amount])).toEqual(wanted[2]);
  });
});

describe('differential: item discount against the oracle', () => {
  it.each(DIFF_DISCOUNT.map((row, index) => [index, row] as const))('vector %i', (_index, row) => {
    const [spec, subtotal, wanted] = row;
    const built = rule(spec ?? '');

    const got = outcome(() =>
      calculateItemDiscount({
        discounts: [built],
        discount_id: built.id,
        at: AT,
        product: product(),
        subtotal,
      }),
    );

    if (/^[a-z_]+$/.test(wanted)) {
      expect(got).toEqual({ refusal: wanted });
    } else {
      expect(got).toEqual({ value: { discount_amount: wanted } });
    }
  });
});

describe('differential: one priced line against the oracle', () => {
  it.each(DIFF_LINE.map((row, index) => [index, row] as const))('vector %i', (_index, row) => {
    const [sellingPrice, factor, quantity, baseQuantity, spec, members, inclusive, wanted] = row;
    const built = spec === null ? null : rule(spec);

    const got = outcome(() =>
      priceLine(
        lineInput({
          ladder: priceInput({
            lists: [],
            customer_id: null,
            unit_factor: factor,
            base_quantity: baseQuantity,
            selling_price: sellingPrice,
          }),
          quantity,
          tax_group: {
            id: id('01TAXGROUPA'),
            is_inclusive: inclusive === 1,
            members: members.map(member),
          },
          discount_id: built === null ? null : built.id,
          discounts: built === null ? [] : [built],
        }),
      ),
    );

    if (typeof wanted === 'string') {
      expect(got).toEqual({ refusal: wanted });

      return;
    }

    const result = (got as { value: ReturnType<typeof priceLine> }).value;

    expect([
      result.unit_price,
      result.subtotal,
      result.discount_amount,
      result.discounted_subtotal,
      result.tax.net,
      result.tax.total,
      result.line_total,
    ]).toEqual(wanted);
  });
});

describe('ordering by UTF-8 bytes reaches the engine (AC-40)', () => {
  // U+FF5E is EF BD 9E in UTF-8; U+1F600 is F0 9F 98 80, so the first sorts first by byte order
  // even though JavaScript's default string order (UTF-16 units, 0xFF5E vs 0xD83D) says the second.
  const high = '～';
  const astral = '\u{1F600}';

  function promotion(code: string, listId: string, price: string) {
    return priceList({
      list_id: id(listId),
      code,
      type: 'promotion',
      priority: '5',
      assignments: [],
      items: [listItem({ price })],
    });
  }

  it('breaks an equal promotion priority by byte order of the code, whatever the row order', () => {
    const a = promotion(high, '01LISTHIGH', '1.0000');
    const b = promotion(astral, '01LISTASTRAL', '2.0000');

    for (const lists of [
      [a, b],
      [b, a],
    ]) {
      const got = resolvePrice(priceInput({ lists, customer_id: null }));

      expect(got.unit_price).toBe('1.0000');
      expect(got.source.step).toBe(3);
    }
  });

  it('breaks an equal assignment and list priority the same way at step 1', () => {
    const a = priceList({
      list_id: id('01LISTHIGH'),
      code: high,
      items: [listItem({ price: '1.0000' })],
    });
    const b = priceList({
      list_id: id('01LISTASTRAL'),
      code: astral,
      items: [listItem({ price: '2.0000' })],
    });

    for (const lists of [
      [a, b],
      [b, a],
    ]) {
      expect(resolvePrice(priceInput({ lists })).unit_price).toBe('1.0000');
    }
  });

  it('control: failureOf reports a clean call as no throw', () => {
    expect(failureOf(() => resolvePrice(priceInput()))).toBe('no throw');
  });
});
