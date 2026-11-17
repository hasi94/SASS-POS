import * as barrel from './index';
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

const INLINE_DECLARATION =
  /export\s+(?:declare\s+)?(?:abstract\s+)?(?:(?:interface|const|let|var|function|class|enum)\b|type\s+\w+\s*(?:<[^>]*>)?\s*=)/;

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

  it('keeps the allow-list itself Pricing-prefixed (a check of the list, not of index.ts)', () => {
    for (const name of TYPES) {
      expect(name.startsWith('Pricing')).toBe(true);
    }
  });

  it('declares nothing inline: every export is a re-export from another module', () => {
    const index = readEngineSources().find((source) => source.name === 'index.ts');
    const code = (index?.text ?? '').replace(/\/\*[\s\S]*?\*\//g, '');

    expect(code).not.toMatch(INLINE_DECLARATION);
  });

  it('INLINE_DECLARATION can fail: it matches each inline declaration form', () => {
    for (const sample of [
      'export interface X {}',
      'export type X = string;',
      'export const X = 1;',
      'export function x() {}',
      'export class X {}',
      'export enum X {}',
    ]) {
      expect(sample).toMatch(INLINE_DECLARATION);
    }

    expect("export { priceLine } from './line';").not.toMatch(INLINE_DECLARATION);
  });

  it('freezes the exported refusal vocabulary', () => {
    expect(Object.isFrozen(barrel.PRICING_REFUSALS)).toBe(true);
  });
});
