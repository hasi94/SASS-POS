/*
 * When a discount applies and how much it takes off: a line-for-line twin of PHP's
 * `DiscountCalculator`, plus the constructors of `DiscountRule` and `ProductFacts` (the build
 * phase).
 *
 * The refusal order is fixed: unknown_discount, discount_scope_mismatch, inactive, not_yet_started,
 * expired, usage_limit_reached, discount_not_allowed (item only), not_applicable (item only),
 * below_min_amount, unsupported_discount_type. Every time check runs against the document instant
 * passed in, as fixed-width strings, never the clock.
 */

import { Dec, HUNDRED, ZERO, add, cmp, percentOf, roundStep, text } from './decimal';
import { PricingInputError, PricingRefused } from './refusal';
import { DISCOUNT_SCOPES, DISCOUNT_TYPES, assertKnownRules } from './rules';
import {
  bool,
  enumOf,
  instant as checkInstant,
  intOrNull,
  int,
  list,
  money,
  moneyDec,
  obj,
  raw,
  rawOrNull,
  str,
  strOrNull,
  strings,
  ulid,
} from './shape';
import type { Obj } from './shape';
import type {
  BillDiscountInput,
  BillDiscountResult,
  DiscountScope,
  DiscountType,
  ItemDiscountInput,
  ItemDiscountResult,
} from './types';

export interface DiscountRule {
  readonly id: string;
  readonly type: DiscountType;
  readonly value: string;
  readonly scope: DiscountScope;
  readonly isActive: boolean;
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  readonly usageLimit: bigint | null;
  readonly usedCount: bigint;
  readonly minAmount: string | null;
  readonly maxDiscount: string | null;
  readonly products: readonly string[];
  readonly categories: readonly string[];
  readonly brands: readonly string[];
}

export interface ProductFacts {
  readonly productId: string;
  readonly categoryId: string | null;
  readonly brandId: string | null;
  readonly allowDiscount: boolean;
}

// ------------------------------------------------------------ build phase

function buildRule(item: unknown): DiscountRule {
  const node = obj(item, 'discount');
  const appliesTo = obj(node['applies_to'], 'applies_to');

  const id = str(node, 'id');
  const type = enumOf(node, 'type', DISCOUNT_TYPES);
  const value = raw(node, 'value');
  const scope = enumOf(node, 'scope', DISCOUNT_SCOPES);
  const isActive = bool(node, 'is_active');
  const startsAt = strOrNull(node, 'starts_at');
  const endsAt = strOrNull(node, 'ends_at');
  const usageLimit = intOrNull(node, 'usage_limit');
  const usedCount = int(node, 'used_count');
  const minAmount = rawOrNull(node, 'min_amount');
  const maxDiscount = rawOrNull(node, 'max_discount');
  const products = strings(appliesTo, 'products');
  const categories = strings(appliesTo, 'categories');
  const brands = strings(appliesTo, 'brands');

  if (id === '') {
    throw new PricingInputError('[id] must not be blank.');
  }

  if (usedCount < 0n || (usageLimit !== null && usageLimit < 0n)) {
    throw new PricingInputError('[usedCount] and [usageLimit] must not be negative.');
  }

  const checkedValue = money(value, 'value');
  const checkedMin = minAmount === null ? null : money(minAmount, 'minAmount');
  const checkedMax = maxDiscount === null ? null : money(maxDiscount, 'maxDiscount');
  const checkedStart = startsAt === null ? null : checkInstant(startsAt, 'startsAt');
  const checkedEnd = endsAt === null ? null : checkInstant(endsAt, 'endsAt');

  if (type === 'percentage' && cmp(moneyDec(checkedValue, 'value'), HUNDRED) > 0) {
    throw new PricingRefused(
      'invalid_amount',
      '[value] a percentage discount must not exceed 100.',
    );
  }

  return {
    id,
    type,
    value: checkedValue,
    scope,
    isActive,
    startsAt: checkedStart,
    endsAt: checkedEnd,
    usageLimit,
    usedCount,
    minAmount: checkedMin,
    maxDiscount: checkedMax,
    products,
    categories,
    brands,
  };
}

/** Every rule is built, and so validated, not only the one selected. */
export function buildRules(nodes: unknown[]): DiscountRule[] {
  return nodes.map(buildRule);
}

export function buildProduct(item: unknown): ProductFacts {
  const node = obj(item, 'product');

  const productId = str(node, 'id');
  const categoryId = strOrNull(node, 'category_id');
  const brandId = strOrNull(node, 'brand_id');
  const allowDiscount = bool(node, 'allow_discount');

  if (productId === '') {
    throw new PricingInputError('[productId] must not be blank.');
  }

  return { productId, categoryId, brandId, allowDiscount };
}

/** The first rule whose id equals the requested one, compared with `===`. */
export function findRule(rules: readonly DiscountRule[], id: string): DiscountRule | null {
  for (const rule of rules) {
    if (rule.id === id) {
      return rule;
    }
  }

  return null;
}

// ------------------------------------------------------------ compute phase

/** Checks 1 to 3 (and the document instant's shape). */
function available(rule: DiscountRule | null, scope: DiscountScope, at: unknown): DiscountRule {
  const instant = checkInstant(at, 'at');

  if (rule === null) {
    throw new PricingRefused('unknown_discount', 'No discount resolves to the requested id.');
  }

  if (rule.scope !== scope) {
    throw new PricingRefused(
      'discount_scope_mismatch',
      'Discount [' +
        rule.id +
        '] has scope [' +
        rule.scope +
        '] and cannot be applied to a ' +
        scope +
        '.',
    );
  }

  if (!rule.isActive) {
    throw new PricingRefused('inactive', 'Discount [' + rule.id + '] is not active.');
  }

  // Fixed-width UTC instants: string order is time order.
  if (rule.startsAt !== null && instant < rule.startsAt) {
    throw new PricingRefused('not_yet_started', 'Discount [' + rule.id + '] has not started.');
  }

  if (rule.endsAt !== null && instant > rule.endsAt) {
    throw new PricingRefused('expired', 'Discount [' + rule.id + '] has ended.');
  }

  if (rule.usageLimit !== null && rule.usedCount >= rule.usageLimit) {
    throw new PricingRefused(
      'usage_limit_reached',
      'Discount [' + rule.id + '] has reached its usage limit.',
    );
  }

  return rule;
}

function targets(rule: DiscountRule, product: ProductFacts): boolean {
  if (rule.products.length === 0 && rule.categories.length === 0 && rule.brands.length === 0) {
    return true;
  }

  return (
    rule.products.includes(product.productId) ||
    (product.categoryId !== null && rule.categories.includes(product.categoryId)) ||
    (product.brandId !== null && rule.brands.includes(product.brandId))
  );
}

function capped(amount: Dec, rule: DiscountRule): Dec {
  if (rule.maxDiscount === null) {
    return amount;
  }

  const cap = moneyDec(rule.maxDiscount, 'maxDiscount');

  return cmp(amount, cap) > 0 ? cap : amount;
}

/** Checks 6 and 7, then the amount. */
function amount(rule: DiscountRule, base: Dec): Dec {
  if (rule.minAmount !== null && cmp(base, moneyDec(rule.minAmount, 'minAmount')) < 0) {
    throw new PricingRefused(
      'below_min_amount',
      'The base is below the minimum of discount [' + rule.id + '].',
    );
  }

  const value = moneyDec(rule.value, 'value');
  let result: Dec;

  switch (rule.type) {
    case 'percentage':
      result = capped(percentOf(base, value), rule);
      break;
    case 'fixed':
      result = cmp(value, base) < 0 ? value : base;
      break;
    case 'buy_x_get_y':
      throw new PricingRefused(
        'unsupported_discount_type',
        'Discount [' + rule.id + '] is buy_x_get_y, which has no arithmetic yet.',
      );
  }

  return roundStep(result);
}

/** Twin of `DiscountCalculator::forLine`: the discount amount for one line. */
export function discountForLine(
  found: DiscountRule | null,
  at: unknown,
  product: ProductFacts,
  subtotal: Dec,
): Dec {
  const rule = available(found, 'item', at);

  if (!product.allowDiscount) {
    throw new PricingRefused('discount_not_allowed', 'The product does not allow discounts.');
  }

  if (!targets(rule, product)) {
    throw new PricingRefused('not_applicable', 'Discount [' + rule.id + '] does not apply.');
  }

  return amount(rule, subtotal);
}

/** The discount of one line against its subtotal. */
export function calculateItemDiscount(input: ItemDiscountInput): ItemDiscountResult {
  assertKnownRules('discount', input);

  const root = obj(input, 'input');
  const rules = buildRules(list(root, 'discounts'));
  const discountId = str(root, 'discount_id');
  const rule = findRule(rules, discountId);
  const at = str(root, 'at');
  const product = buildProduct(root['product']);
  const subtotal = moneyDec(raw(root, 'subtotal'), 'subtotal');

  return { discount_amount: text(discountForLine(rule, at, product, subtotal)) };
}

/** A bill discount against the summed discounted (pre-tax) subtotals. */
export function calculateBillDiscount(input: BillDiscountInput): BillDiscountResult {
  assertKnownRules('bill', input);

  const root = obj(input, 'input');
  const rules = buildRules(list(root, 'discounts'));
  const discountId = str(root, 'discount_id');
  const rule = findRule(rules, discountId);
  const at = str(root, 'at');
  const subtotals = list(root, 'discounted_subtotals');

  const found = available(rule, 'bill', at);

  let base: Dec = ZERO;

  for (const subtotal of subtotals) {
    base = add(base, moneyDec(subtotal, 'discountedSubtotal'));
  }

  const discount = amount(found, roundStep(base));
  const baseText = text(base);
  const amountText = text(discount);

  return { discount_id: ulid(found.id, 'discountId'), base: baseText, amount: amountText };
}
