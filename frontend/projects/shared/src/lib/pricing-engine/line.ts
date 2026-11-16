/*
 * One document line, priced: a line-for-line twin of PHP's `LineCalculator`, plus the `LineInput`
 * constructor (the build phase). The fixed order, each named step rounded half-up to four decimals
 * before the next consumes it:
 *
 * 1. the unit price from the ladder;
 * 2. subtotal = round(unit price x quantity), where quantity is the line-unit quantity, never the
 *    base quantity;
 * 3. the discount, then discounted subtotal = subtotal - discount (discount before tax);
 * 4. tax on the discounted subtotal, sale context, the base quantity feeding any fixed tax;
 * 5. line total = discounted subtotal + tax total (exclusive) or the discounted subtotal (inclusive).
 */

import { ZERO, add, mul, roundStep, sub, text } from './decimal';
import { PricingInputError } from './refusal';
import { buildProduct, buildRules, discountForLine, findRule } from './discount';
import { buildLadder, resolveLadder } from './price-ladder';
import { assertKnownRules } from './rules';
import {
  bool,
  date as checkDate,
  list,
  money,
  moneyDec,
  obj,
  raw,
  str,
  strOrNull,
  ulid,
} from './shape';
import { buildMembers, computeTax } from './tax';
import type { LineInput, PricedLine } from './types';

/** The unit price, discount and tax of one line, and its total. */
export function priceLine(input: LineInput): PricedLine {
  assertKnownRules('line', input);

  const root = obj(input, 'input');
  const group = obj(root['tax_group'], 'tax_group');

  // The build phase, in the order PHP's replay builds the objects.
  const ladder = buildLadder(obj(root['ladder'], 'ladder'));
  const quantity = raw(root, 'quantity');
  const product = buildProduct(root['product']);
  const taxGroupId = str(group, 'id');
  const inclusive = bool(group, 'is_inclusive');
  const members = buildMembers(list(group, 'members'));
  const documentDate = str(root, 'document_date');
  const discountId = strOrNull(root, 'discount_id');
  const rules = buildRules(list(root, 'discounts'));

  // The LineInput constructor body.
  if (taxGroupId === '') {
    throw new PricingInputError('[taxGroupId] must not be blank.');
  }

  const lineQuantity = money(quantity, 'quantity');
  const taxDate = checkDate(documentDate, 'documentDate');

  // The compute phase.
  const price = resolveLadder(ladder);

  const subtotal = roundStep(
    mul(moneyDec(price.unit_price, 'unitPrice'), moneyDec(lineQuantity, 'quantity')),
  );

  const discountAmount =
    discountId === null
      ? ZERO
      : discountForLine(findRule(rules, discountId), ladder.at, product, subtotal);

  const discounted = sub(subtotal, discountAmount);

  const tax = computeTax(
    members,
    text(discounted),
    ladder.baseQuantity,
    taxDate,
    'sale',
    inclusive,
  );

  const lineTotal = inclusive ? discounted : add(discounted, moneyDec(tax.total, 'tax'));

  // PricedLine: every text() runs before the id checks of its constructor.
  const subtotalText = text(subtotal);
  const discountText = text(discountAmount);
  const discountedText = text(discounted);
  const totalText = text(lineTotal);

  return {
    list_price: price.list_price,
    list_discount_percent: price.discount_percent,
    unit_price: price.unit_price,
    source: price.source,
    subtotal: subtotalText,
    discount_amount: discountText,
    discount_id: discountId === null ? null : ulid(discountId, 'discountId'),
    discounted_subtotal: discountedText,
    tax_group_id: ulid(taxGroupId, 'taxGroupId'),
    tax,
    line_total: totalText,
  };
}
