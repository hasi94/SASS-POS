import { UnsupportedRuleError } from './refusal';
import { assertKnownRules, DISCOUNT_TYPES, LIST_TYPES, TAX_APPLIES, TAX_CONTEXTS } from './rules';

function unsupported(fn: () => unknown): { field: string; value: string } | null {
  try {
    fn();
  } catch (error) {
    if (error instanceof UnsupportedRuleError) {
      return { field: error.field, value: error.value };
    }

    throw error;
  }

  return null;
}

const member = (over: Record<string, unknown> = {}) => ({
  type: 'percentage',
  applies_to: 'both',
  is_deleted: false,
  ...over,
});

const list = (over: Record<string, unknown> = {}) => ({
  type: 'standard',
  assignments: [{ scope_type: 'customer' }],
  ...over,
});

const rule = (over: Record<string, unknown> = {}) => ({
  type: 'percentage',
  scope: 'item',
  ...over,
});

describe('assertKnownRules', () => {
  it('pins the vocabularies', () => {
    expect([...TAX_APPLIES]).toEqual(['sale', 'purchase', 'both']);
    expect([...TAX_CONTEXTS]).toEqual(['sale', 'purchase']);
    expect([...LIST_TYPES]).toEqual(['standard', 'customer_group', 'promotion', 'branch']);
    expect([...DISCOUNT_TYPES]).toEqual(['percentage', 'fixed', 'buy_x_get_y']);
  });

  it('names an unknown tax type', () => {
    expect(
      unsupported(() => assertKnownRules('tax', { members: [member({ type: 'surcharge' })] })),
    ).toEqual({ field: 'members[].type', value: 'surcharge' });
  });

  it('names an unknown applies_to', () => {
    expect(
      unsupported(() => assertKnownRules('tax', { members: [member({ applies_to: 'export' })] })),
    ).toEqual({ field: 'members[].applies_to', value: 'export' });
  });

  it('names an unknown tax context', () => {
    expect(
      unsupported(() => assertKnownRules('tax', { members: [], context: 'transfer' })),
    ).toEqual({
      field: 'context',
      value: 'transfer',
    });
  });

  it('still throws for an unknown value inside a deleted member', () => {
    expect(
      unsupported(() =>
        assertKnownRules('tax', {
          members: [member({ type: 'surcharge', is_deleted: true, is_active: false })],
        }),
      ),
    ).toEqual({ field: 'members[].type', value: 'surcharge' });
  });

  it('names an unknown list type and scope type', () => {
    expect(
      unsupported(() => assertKnownRules('price', { lists: [list({ type: 'vip' })] })),
    ).toEqual({
      field: 'lists[].type',
      value: 'vip',
    });
    expect(
      unsupported(() =>
        assertKnownRules('price', { lists: [list({ assignments: [{ scope_type: 'region' }] })] }),
      ),
    ).toEqual({ field: 'assignments[].scope_type', value: 'region' });
  });

  it('names an unknown discount type and scope, on discount, bill and line', () => {
    const loyalty = { discounts: [rule({ type: 'loyalty' })] };
    const region = { discounts: [rule({ scope: 'region' })] };

    for (const kind of ['discount', 'bill', 'line'] as const) {
      expect(unsupported(() => assertKnownRules(kind, loyalty))).toEqual({
        field: 'discounts[].type',
        value: 'loyalty',
      });
      expect(unsupported(() => assertKnownRules(kind, region))).toEqual({
        field: 'discounts[].scope',
        value: 'region',
      });
    }
  });

  it('walks the ladder lists and the tax group of a line', () => {
    expect(
      unsupported(() =>
        assertKnownRules('line', { ladder: { lists: [list({ type: 'vip' })] }, discounts: [] }),
      ),
    ).toEqual({ field: 'lists[].type', value: 'vip' });
    expect(
      unsupported(() =>
        assertKnownRules('line', { tax_group: { members: [member({ type: 'surcharge' })] } }),
      ),
    ).toEqual({ field: 'members[].type', value: 'surcharge' });
  });

  it('does not throw for buy_x_get_y, which is a known type', () => {
    expect(
      unsupported(() =>
        assertKnownRules('discount', { discounts: [rule({ type: 'buy_x_get_y' })] }),
      ),
    ).toBeNull();
  });

  it('does not throw for known values, and returns nothing', () => {
    expect(assertKnownRules('tax', { members: [member()], context: 'sale' })).toBeUndefined();
    expect(assertKnownRules('price', { lists: [list({ type: 'promotion' })] })).toBeUndefined();
  });

  it('leaves a non-string enum value to the build phase', () => {
    expect(
      unsupported(() => assertKnownRules('tax', { members: [member({ type: 5 })], context: null })),
    ).toBeNull();
    expect(
      unsupported(() => assertKnownRules('discount', { discounts: [rule({ type: null })] })),
    ).toBeNull();
  });

  it('ignores input that is not shaped as expected', () => {
    expect(unsupported(() => assertKnownRules('tax', null))).toBeNull();
    expect(unsupported(() => assertKnownRules('price', { lists: 'x' }))).toBeNull();
    expect(unsupported(() => assertKnownRules('line', { ladder: 3 }))).toBeNull();
  });
});
