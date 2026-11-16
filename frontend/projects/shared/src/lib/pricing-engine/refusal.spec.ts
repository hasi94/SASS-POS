import {
  PRICING_REFUSALS,
  PricingInputError,
  PricingRefused,
  UnsupportedRuleError,
} from './refusal';

describe('refusal vocabulary and error classes', () => {
  it('lists exactly the seventeen PricingRefusal values, in order', () => {
    expect([...PRICING_REFUSALS]).toEqual([
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
    ]);
    expect(PRICING_REFUSALS.length).toBe(17);
    expect(new Set(PRICING_REFUSALS).size).toBe(17);
  });

  it('carries the machine reason on a refusal', () => {
    const refusal = new PricingRefused('out_of_range', 'x');

    expect(refusal).toBeInstanceOf(PricingRefused);
    expect(refusal).toBeInstanceOf(Error);
    expect(refusal.reason).toBe('out_of_range');
    expect(refusal.name).toBe('PricingRefused');
  });

  it('keeps the three failure types distinct', () => {
    const refusal = new PricingRefused('expired');
    const input = new PricingInputError('bad');
    const rule = new UnsupportedRuleError('discounts[].type', 'loyalty');

    expect(input).not.toBeInstanceOf(PricingRefused);
    expect(rule).not.toBeInstanceOf(PricingRefused);
    expect(refusal).not.toBeInstanceOf(PricingInputError);
    expect(rule).not.toBeInstanceOf(PricingInputError);
    expect(refusal).not.toBeInstanceOf(UnsupportedRuleError);
    expect(input).not.toBeInstanceOf(UnsupportedRuleError);
  });

  it('sets a name on each error and the field and value on an unsupported rule', () => {
    expect(new PricingInputError('bad').name).toBe('PricingInputError');

    const rule = new UnsupportedRuleError('members[].type', 'surcharge');

    expect(rule.name).toBe('UnsupportedRuleError');
    expect(rule.field).toBe('members[].type');
    expect(rule.value).toBe('surcharge');
  });
});
