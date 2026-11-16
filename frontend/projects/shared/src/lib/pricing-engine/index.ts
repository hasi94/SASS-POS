/*
 * The TypeScript twin of the PHP pricing engine (packages/pricing): five pure, synchronous
 * operations that take the fixture `input` shape of their kind and return the PHP `toArray()`
 * shape. Everything else in this directory is internal. Every type exported here carries a
 * `Pricing` prefix (or already did), because this barrel is re-exported by `export *` from
 * `@flexipos/shared`; the enum-like unions stay internal. `index.spec.ts` pins the list.
 */

export { calculateTax } from './tax';
export { resolvePrice } from './price-ladder';
export { calculateItemDiscount, calculateBillDiscount } from './discount';
export { priceLine } from './line';
export {
  PricingRefused,
  PricingInputError,
  UnsupportedRuleError,
  PRICING_REFUSALS,
} from './refusal';
export type { PricingRefusal } from './refusal';
export type {
  BillDiscountInput as PricingBillDiscountInput,
  BillDiscountResult as PricingBillDiscountResult,
  DiscountRuleInput as PricingDiscountRuleInput,
  ItemDiscountInput as PricingItemDiscountInput,
  ItemDiscountResult as PricingItemDiscountResult,
  LineInput as PricingLineInput,
  PricedLine as PricingLine,
  PriceInput as PricingPriceInput,
  PriceListAssignmentInput as PricingPriceListAssignmentInput,
  PriceListInput as PricingPriceListInput,
  PriceListItemInput as PricingPriceListItemInput,
  PriceOutcome as PricingPriceOutcome,
  PriceSource as PricingPriceSource,
  ProductFactsInput as PricingProductFactsInput,
  RateRowInput as PricingRateRowInput,
  TaxBreakdown as PricingTaxBreakdown,
  TaxInput as PricingTaxInput,
  TaxLine as PricingTaxLine,
  TaxMemberInput as PricingTaxMemberInput,
} from './types';
