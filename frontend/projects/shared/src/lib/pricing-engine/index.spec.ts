import * as barrel from './index';
import { MAX_AMOUNT, cmp, dec } from './decimal';
import { readEngineSources } from './testing/fixture-files';

// The barrel is re-exported by `export *` from @flexipos/shared, so every name it adds is a
// name that T-036 and T-037 can collide with (TS2308). The list is explicit; growing it is a
// decision, not a side effect.
const VALUES = [
  'PRICING_REFUSALS',
  'PricingInputError',
  'PricingRefused',
  'UnsupportedRuleError',
  'calculateBillDiscount',
  'calculateItemDiscount',
  'calculateTax',
  'priceLine',
  'resolvePrice',
];

const TYPES = [
  'PricingBillDiscountInput',
  'PricingBillDiscountResult',
  'PricingDiscountRuleInput',
  'PricingItemDiscountInput',
  'PricingItemDiscountResult',
  'PricingLine',
  'PricingLineInput',
  'PricingPriceInput',
  'PricingPriceListAssignmentInput',
  'PricingPriceListInput',
  'PricingPriceListItemInput',
  'PricingPriceOutcome',
  'PricingPriceSource',
  'PricingProductFactsInput',
  'PricingRateRowInput',
  'PricingRefusal',
  'PricingTaxBreakdown',
  'PricingTaxInput',
  'PricingTaxLine',
  'PricingTaxMemberInput',
];

function declaredNames(): string[] {
  const index = readEngineSources().find((source) => source.name === 'index.ts');
  const text = (index?.text ?? '').replace(/\/\*[\s\S]*?\*\//g, '');
  const names: string[] = [];

  for (const match of text.matchAll(/export\s+(?:type\s+)?\{([^}]*)\}/g)) {
    for (const part of (match[1] ?? '').split(',')) {
      const name =
        part
          .trim()
          .split(/\s+as\s+/)
          .pop()
          ?.trim() ?? '';

      if (name !== '') {
        names.push(name);
      }
    }
  }

  return names.sort();
}

describe('the public barrel', () => {
  it('exports at run time exactly the allow-listed values', () => {
    expect(Object.keys(barrel).sort()).toEqual([...VALUES].sort());
  });

  it('declares exactly the allow-listed names, values and types', () => {
    expect(declaredNames()).toEqual([...VALUES, ...TYPES].sort());
  });

  it('has no `export *`, so no name can slip in unlisted', () => {
    const index = readEngineSources().find((source) => source.name === 'index.ts');

    const code = (index?.text ?? '').replace(/\/\*[\s\S]*?\*\//g, '');

    expect(code).not.toMatch(/export\s+(type\s+)?\*/);
  });

  it('prefixes every exported type, except the ones that already named the engine', () => {
    for (const name of TYPES) {
      expect(name.startsWith('Pricing')).toBe(true);
    }
  });

  it('freezes the exported refusal vocabulary', () => {
    expect(Object.isFrozen(barrel.PRICING_REFUSALS)).toBe(true);
  });
});

describe('decimal.ts module-load constants', () => {
  it('keeps the literal MAX equal to MAX_AMOUNT', () => {
    expect(cmp(dec(MAX_AMOUNT), dec('99999999999999.9999'))).toBe(0);
  });
});
