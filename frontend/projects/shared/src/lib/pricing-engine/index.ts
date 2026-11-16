/*
 * The TypeScript twin of the PHP pricing engine (packages/pricing): five pure, synchronous
 * operations that take the fixture `input` shape of their kind and return the PHP `toArray()`
 * shape. Everything else in this directory is internal.
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
export type * from './types';
