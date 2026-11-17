/*
 * The failures of the pricing engine: three thrown types, plus a fourth kind that valid input
 * cannot reach. None of the three extends another, so a `catch` aimed at one class cannot catch
 * another. The fourth kind is the plain `Error` thrown by `dec()` and `divideHalfUp()` in
 * decimal.ts (an unparseable string, a zero divisor); it is not a PricingRefused and is not
 * meant to be caught.
 *
 * - PricingRefused: a named business refusal, the twin of PHP's PricingRefused. `.reason` is the
 *   machine value; tests and callers read `.reason`, never `.message`.
 * - PricingInputError: a programmer error (a malformed id, date, instant, currency, integer or
 *   field shape), the twin of PHP's InvalidArgumentException. It is never a refusal.
 * - UnsupportedRuleError: the input names a rule value this build does not know. The engine never
 *   guesses; the till decides what to do.
 */

/** The closed refusal vocabulary, in the order of PHP's `PricingRefusal` enum. */
export const PRICING_REFUSALS = /* @__PURE__ */ Object.freeze([
  'unknown_product',
  'unknown_tax_group',
  'no_effective_rate',
  'inclusive_amount_too_small',
  'no_price_resolved',
  'unknown_discount',
  'discount_scope_mismatch',
  'inactive',
  'not_yet_started',
  'expired',
  'usage_limit_reached',
  'discount_not_allowed',
  'not_applicable',
  'below_min_amount',
  'unsupported_discount_type',
  'invalid_amount',
  'out_of_range',
] as const);

export type PricingRefusal = (typeof PRICING_REFUSALS)[number];

export class PricingRefused extends Error {
  readonly reason: PricingRefusal;

  constructor(reason: PricingRefusal, message = '') {
    super(message === '' ? reason : message);
    this.name = 'PricingRefused';
    this.reason = reason;
  }
}

export class PricingInputError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'PricingInputError';
  }
}

export class UnsupportedRuleError extends Error {
  readonly field: string;
  readonly value: string;

  constructor(field: string, value: string) {
    super('Unsupported rule value [' + value + '] for [' + field + '].');
    this.name = 'UnsupportedRuleError';
    this.field = field;
    this.value = value;
  }
}
