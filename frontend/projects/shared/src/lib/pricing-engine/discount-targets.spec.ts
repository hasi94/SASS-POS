/*
 * QA: `not_applicable` (AC-22). A discount that names only one kind of target must refuse a
 * product outside it; "all three lists empty" is the only case that means everything, and the
 * three lists are OR-ed. The shipped suite only refuses a rule that names all three kinds.
 */

import { calculateBillDiscount, calculateItemDiscount } from './discount';
import { AT, discountRule, failureOf, id, product } from './testing/builders';
import type { DiscountRuleInput } from './types';

type Targets = DiscountRuleInput['applies_to'];

function run(applies_to: Targets, facts = product()): string {
  return failureOf(() =>
    calculateItemDiscount({
      discount_id: discountRule().id,
      discounts: [discountRule({ applies_to })],
      at: AT,
      product: facts,
      subtotal: '100.0000',
    }),
  );
}

const NONE: Targets = { products: [], categories: [], brands: [] };

describe('item discount targets: one kind named, product outside it', () => {
  it('refuses when only products are named and this product is not one', () => {
    expect(run({ ...NONE, products: [id('01OTHER')] })).toBe('refused not_applicable');
  });

  it('refuses when only categories are named and the category differs', () => {
    expect(run({ ...NONE, categories: [id('01OTHERC')] })).toBe('refused not_applicable');
  });

  it('refuses when only brands are named and the brand differs', () => {
    expect(run({ ...NONE, brands: [id('01OTHERB')] })).toBe('refused not_applicable');
  });

  it('refuses a product with no category when only categories are named', () => {
    expect(run({ ...NONE, categories: [id('01CATA')] }, product({ category_id: null }))).toBe(
      'refused not_applicable',
    );
  });

  it('refuses a product with no brand when only brands are named', () => {
    expect(run({ ...NONE, brands: [id('01BRANDA')] }, product({ brand_id: null }))).toBe(
      'refused not_applicable',
    );
  });

  it('is case sensitive: a lower-case copy of the product id is not the product', () => {
    expect(run({ ...NONE, products: [id('01proda')] })).toBe('refused not_applicable');
  });
});

describe('item discount targets: the three lists are OR-ed', () => {
  it('applies through the brand alone although products and categories name others', () => {
    expect(
      run({
        products: [id('01OTHER')],
        categories: [id('01OTHERC')],
        brands: [id('01BRANDA')],
      }),
    ).toBe('no throw');
  });

  it('applies through the category alone although products and brands name others', () => {
    expect(
      run({
        products: [id('01OTHER')],
        categories: [id('01CATA')],
        brands: [id('01OTHERB')],
      }),
    ).toBe('no throw');
  });

  it('does not apply to a bill through the targeting lists: a bill discount has no product', () => {
    const got = calculateBillDiscount({
      discount_id: discountRule().id,
      discounts: [
        discountRule({ scope: 'bill', applies_to: { ...NONE, products: [id('01OTHER')] } }),
      ],
      at: AT,
      discounted_subtotals: ['100.0000'],
    });

    expect(got.amount).toBe('10.0000');
  });
});
