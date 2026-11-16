/*
 * Test-only: small factories for engine inputs, so a spec states only what it varies. Every
 * default is a valid, ordinary input (a 15 % VAT group, one standard list, one 10 % discount).
 */

import { PricingInputError, PricingRefused, UnsupportedRuleError } from '../refusal';
import type {
  DiscountRuleInput,
  LineInput,
  PriceInput,
  PriceListAssignmentInput,
  PriceListInput,
  PriceListItemInput,
  ProductFactsInput,
  RateRowInput,
  TaxInput,
  TaxMemberInput,
} from '../types';

/** A 26-character id: the prefix padded with zeros. */
export function id(prefix: string): string {
  return prefix.padEnd(26, '0');
}

export const AT = '2025-06-15T09:30:00.000Z';

export function rateRow(over: Partial<RateRowInput> = {}): RateRowInput {
  return { rate: '15.0000', effective_from: '2020-01-01', effective_to: null, ...over };
}

export function taxMember(over: Partial<TaxMemberInput> = {}): TaxMemberInput {
  return {
    tax_id: id('01TAXVAT'),
    code: 'VAT',
    tax_code: null,
    type: 'percentage',
    is_compound: false,
    applies_to: 'both',
    is_active: true,
    is_deleted: false,
    sequence: '1',
    rates: [rateRow()],
    ...over,
  };
}

export function taxInput(over: Partial<TaxInput> = {}): TaxInput {
  return {
    members: [taxMember()],
    amount: '100.0000',
    base_quantity: '1.0000',
    date: '2025-06-01',
    context: 'sale',
    is_inclusive: false,
    ...over,
  };
}

export function assignment(over: Partial<PriceListAssignmentInput> = {}): PriceListAssignmentInput {
  return { scope_type: 'customer', scope_id: id('01CUSTA'), priority: '0', ...over };
}

export function listItem(over: Partial<PriceListItemInput> = {}): PriceListItemInput {
  return { unit_id: null, min_qty: '1.0000', price: '10.0000', discount_percent: null, ...over };
}

export function priceList(over: Partial<PriceListInput> = {}): PriceListInput {
  return {
    list_id: id('01LISTA'),
    code: 'LA',
    type: 'standard',
    currency: 'USD',
    priority: '0',
    starts_at: null,
    ends_at: null,
    is_active: true,
    is_deleted: false,
    assignments: [assignment()],
    items: [listItem()],
    ...over,
  };
}

export function priceInput(over: Partial<PriceInput> = {}): PriceInput {
  return {
    unit_id: id('01UNITEACH'),
    unit_factor: '1.000000',
    base_quantity: '1.0000',
    at: AT,
    currency: 'USD',
    customer_id: id('01CUSTA'),
    customer_group_id: null,
    branch_id: null,
    lists: [priceList()],
    branch_selling_price: null,
    selling_price: null,
    ...over,
  };
}

export function discountRule(over: Partial<DiscountRuleInput> = {}): DiscountRuleInput {
  return {
    id: id('01DISC10'),
    type: 'percentage',
    value: '10.0000',
    scope: 'item',
    is_active: true,
    starts_at: null,
    ends_at: null,
    usage_limit: null,
    used_count: '0',
    min_amount: null,
    max_discount: null,
    applies_to: { products: [], categories: [], brands: [] },
    ...over,
  };
}

export function product(over: Partial<ProductFactsInput> = {}): ProductFactsInput {
  return {
    id: id('01PRODA'),
    category_id: id('01CATA'),
    brand_id: id('01BRANDA'),
    allow_discount: true,
    ...over,
  };
}

export function lineInput(over: Partial<LineInput> = {}): LineInput {
  return {
    ladder: priceInput({ lists: [priceList({ items: [listItem({ price: '100.0000' })] })] }),
    quantity: '1.0000',
    product: product(),
    tax_group: { id: id('01TAXGROUPA'), is_inclusive: false, members: [taxMember()] },
    document_date: '2025-06-01',
    discount_id: null,
    discounts: [],
    ...over,
  };
}

/**
 * What a call threw, as a short machine description: `refused <reason>`, `input-error`,
 * `unsupported <field>=<value>`, `other ...`, or `no throw`. Reads `.reason` and never `.message`.
 */
export function failureOf(fn: () => unknown): string {
  try {
    fn();
  } catch (error) {
    if (error instanceof PricingRefused) {
      return 'refused ' + error.reason;
    }

    if (error instanceof PricingInputError) {
      return 'input-error';
    }

    if (error instanceof UnsupportedRuleError) {
      return 'unsupported ' + error.field + '=' + error.value;
    }

    return 'other ' + String(error);
  }

  return 'no throw';
}
