/*
 * QA: AC-5 on every amount field of every operation. A value that is not a string is refused
 * `invalid_amount` wherever an amount is read, never coerced and never a different failure.
 * Each case replaces exactly one field of an otherwise valid input and asserts the machine value.
 */

import { calculateBillDiscount, calculateItemDiscount } from './discount';
import { priceLine } from './line';
import { resolvePrice } from './price-ladder';
import { calculateTax } from './tax';
import {
  AT,
  discountRule,
  failureOf,
  lineInput,
  listItem,
  priceInput,
  priceList,
  product,
  taxInput,
  taxMember,
} from './testing/builders';

const NOT_STRINGS: readonly (readonly [string, unknown])[] = [
  ['a JS number', 1],
  ['a fractional JS number', 1.5],
  ['a bigint', 1n],
  ['NaN', NaN],
  ['a boolean', true],
  ['an object', {}],
  ['an array', ['1.0000']],
];

/** Where the value may legitimately be null (absent). */
const NOT_STRINGS_OR_NULL: readonly (readonly [string, unknown])[] = [...NOT_STRINGS];

const REQUIRED: readonly (readonly [string, unknown])[] = [...NOT_STRINGS, ['null', null]];

type Case = readonly [string, (value: unknown) => unknown, readonly (readonly [string, unknown])[]];

const CASES: readonly Case[] = [
  ['tax.amount', (v) => calculateTax(taxInput({ amount: v as string })), REQUIRED],
  ['tax.base_quantity', (v) => calculateTax(taxInput({ base_quantity: v as string })), REQUIRED],
  [
    'tax member rate',
    (v) =>
      calculateTax(
        taxInput({
          members: [
            taxMember({
              rates: [{ rate: v as string, effective_from: '2020-01-01', effective_to: null }],
            }),
          ],
        }),
      ),
    REQUIRED,
  ],
  ['price.unit_factor', (v) => resolvePrice(priceInput({ unit_factor: v as string })), REQUIRED],
  [
    'price.base_quantity',
    (v) => resolvePrice(priceInput({ base_quantity: v as string })),
    REQUIRED,
  ],
  [
    'price item min_qty',
    (v) =>
      resolvePrice(
        priceInput({ lists: [priceList({ items: [listItem({ min_qty: v as string })] })] }),
      ),
    REQUIRED,
  ],
  [
    'price item price',
    (v) =>
      resolvePrice(
        priceInput({ lists: [priceList({ items: [listItem({ price: v as string })] })] }),
      ),
    REQUIRED,
  ],
  [
    'price item discount_percent',
    (v) =>
      resolvePrice(
        priceInput({
          lists: [priceList({ items: [listItem({ discount_percent: v as string })] })],
        }),
      ),
    NOT_STRINGS_OR_NULL,
  ],
  [
    'price.branch_selling_price',
    (v) =>
      resolvePrice(priceInput({ lists: [], customer_id: null, branch_selling_price: v as string })),
    NOT_STRINGS_OR_NULL,
  ],
  [
    'price.selling_price',
    (v) => resolvePrice(priceInput({ lists: [], customer_id: null, selling_price: v as string })),
    NOT_STRINGS_OR_NULL,
  ],
  [
    'discount.value',
    (v) =>
      calculateItemDiscount({
        discounts: [discountRule({ value: v as string })],
        discount_id: discountRule().id,
        at: AT,
        product: product(),
        subtotal: '100.0000',
      }),
    REQUIRED,
  ],
  [
    'discount.min_amount',
    (v) =>
      calculateItemDiscount({
        discounts: [discountRule({ min_amount: v as string })],
        discount_id: discountRule().id,
        at: AT,
        product: product(),
        subtotal: '100.0000',
      }),
    NOT_STRINGS_OR_NULL,
  ],
  [
    'discount.max_discount',
    (v) =>
      calculateItemDiscount({
        discounts: [discountRule({ max_discount: v as string })],
        discount_id: discountRule().id,
        at: AT,
        product: product(),
        subtotal: '100.0000',
      }),
    NOT_STRINGS_OR_NULL,
  ],
  [
    'discount.subtotal',
    (v) =>
      calculateItemDiscount({
        discounts: [discountRule()],
        discount_id: discountRule().id,
        at: AT,
        product: product(),
        subtotal: v as string,
      }),
    REQUIRED,
  ],
  [
    'bill.discounted_subtotals[0]',
    (v) =>
      calculateBillDiscount({
        discounts: [discountRule({ scope: 'bill' })],
        discount_id: discountRule().id,
        at: AT,
        discounted_subtotals: [v as string],
      }),
    REQUIRED,
  ],
  [
    'bill.discount value',
    (v) =>
      calculateBillDiscount({
        discounts: [discountRule({ scope: 'bill', value: v as string })],
        discount_id: discountRule().id,
        at: AT,
        discounted_subtotals: ['10.0000'],
      }),
    REQUIRED,
  ],
  ['line.quantity', (v) => priceLine(lineInput({ quantity: v as string })), REQUIRED],
];

describe('every amount field refuses a non-string as invalid_amount (AC-5)', () => {
  it('control: every case is valid with a proper string', () => {
    expect(failureOf(() => calculateTax(taxInput()))).toBe('no throw');
    expect(failureOf(() => priceLine(lineInput()))).toBe('no throw');
    expect(
      failureOf(() =>
        calculateBillDiscount({
          discounts: [discountRule({ scope: 'bill' })],
          discount_id: discountRule().id,
          at: AT,
          discounted_subtotals: ['10.0000'],
        }),
      ),
    ).toBe('no throw');
  });

  for (const [field, run, values] of CASES) {
    it.each(values.map(([label, value]) => [label, value] as const))(
      field + ' given %s',
      (_label, value) => {
        expect(failureOf(() => run(value))).toBe('refused invalid_amount');
      },
    );
  }
});
