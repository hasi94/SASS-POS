/*
 * The closed rule vocabularies this build knows, and the pre-pass that refuses an unknown value
 * before any arithmetic (D-6, R-3). The engine never treats an unknown value as zero, as exclusive,
 * as a percentage or as "no discount": it throws UnsupportedRuleError and the till decides.
 *
 * `buy_x_get_y` is known, so it passes here and is refused `unsupported_discount_type` at its place
 * in the discount refusal order. A non-string in an enum field is skipped here and becomes a
 * PricingInputError in the build phase.
 */

import { UnsupportedRuleError } from './refusal';

export const TAX_TYPES = /* @__PURE__ */ Object.freeze(['percentage', 'fixed'] as const);
export const TAX_APPLIES = /* @__PURE__ */ Object.freeze(['sale', 'purchase', 'both'] as const);
export const TAX_CONTEXTS = /* @__PURE__ */ Object.freeze(['sale', 'purchase'] as const);
export const LIST_TYPES = /* @__PURE__ */ Object.freeze([
  'standard',
  'customer_group',
  'promotion',
  'branch',
] as const);
export const SCOPE_TYPES = /* @__PURE__ */ Object.freeze([
  'branch',
  'customer_group',
  'customer',
] as const);
export const DISCOUNT_SCOPES = /* @__PURE__ */ Object.freeze(['item', 'bill'] as const);
export const DISCOUNT_TYPES = /* @__PURE__ */ Object.freeze([
  'percentage',
  'fixed',
  'buy_x_get_y',
] as const);
export const PRICE_BASES = /* @__PURE__ */ Object.freeze([
  'unit_matched',
  'derived',
  'product',
] as const);

export type RuleKind = 'tax' | 'price' | 'discount' | 'bill' | 'line';

type Loose = Record<string, unknown>;

function asObject(value: unknown): Loose | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Loose)
    : null;
}

function rows(value: unknown): Loose[] {
  if (!Array.isArray(value)) {
    return [];
  }

  const found: Loose[] = [];

  for (const item of value as unknown[]) {
    const row = asObject(item);

    if (row !== null) {
      found.push(row);
    }
  }

  return found;
}

function known(field: string, value: unknown, vocabulary: readonly string[]): void {
  if (typeof value === 'string' && !vocabulary.includes(value)) {
    throw new UnsupportedRuleError(field, value);
  }
}

function checkMembers(members: unknown): void {
  for (const member of rows(members)) {
    known('members[].type', member['type'], TAX_TYPES);
    known('members[].applies_to', member['applies_to'], TAX_APPLIES);
  }
}

function checkLists(lists: unknown): void {
  for (const list of rows(lists)) {
    known('lists[].type', list['type'], LIST_TYPES);

    for (const assignment of rows(list['assignments'])) {
      known('assignments[].scope_type', assignment['scope_type'], SCOPE_TYPES);
    }
  }
}

function checkDiscounts(discounts: unknown): void {
  for (const rule of rows(discounts)) {
    known('discounts[].type', rule['type'], DISCOUNT_TYPES);
    known('discounts[].scope', rule['scope'], DISCOUNT_SCOPES);
  }
}

/**
 * Walks the whole input, deleted and inactive rows included, and throws UnsupportedRuleError for the
 * first string enum value outside its set. Input that is not an object is left to the build phase.
 */
export function assertKnownRules(kind: RuleKind, input: unknown): void {
  const root = asObject(input);

  if (root === null) {
    return;
  }

  switch (kind) {
    case 'tax':
      checkMembers(root['members']);
      known('context', root['context'], TAX_CONTEXTS);
      break;
    case 'price':
      checkLists(root['lists']);
      break;
    case 'discount':
    case 'bill':
      checkDiscounts(root['discounts']);
      break;
    case 'line': {
      checkLists(asObject(root['ladder'])?.['lists']);
      checkMembers(asObject(root['tax_group'])?.['members']);
      checkDiscounts(root['discounts']);
      break;
    }
  }
}
