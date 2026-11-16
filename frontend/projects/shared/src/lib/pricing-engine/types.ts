/*
 * The wire shapes of the pricing engine. Each operation takes exactly the fixture `input` object of
 * its kind (snake_case; every decimal and integer is a string; `null` for absent) and returns
 * exactly the PHP `toArray()` shape. The ladder `step` is the only number: a literal 1 to 6, as PHP
 * emits it.
 *
 * Enum-valued fields are typed as their literal unions, but at run time every field is checked as
 * `unknown`: a value cast past the compiler is refused, never coerced.
 */

export type TaxType = 'percentage' | 'fixed';
export type TaxApplies = 'sale' | 'purchase' | 'both';
export type TaxContext = 'sale' | 'purchase';
export type ListType = 'standard' | 'customer_group' | 'promotion' | 'branch';
export type ScopeType = 'branch' | 'customer_group' | 'customer';
export type DiscountScope = 'item' | 'bill';
export type DiscountType = 'percentage' | 'fixed' | 'buy_x_get_y';
export type PriceBasis = 'unit_matched' | 'derived' | 'product';

// ------------------------------------------------------------ inputs

export interface RateRowInput {
  rate: string;
  effective_from: string;
  effective_to: string | null;
}

export interface TaxMemberInput {
  tax_id: string;
  code: string;
  tax_code: string | null;
  type: TaxType;
  is_compound: boolean;
  applies_to: TaxApplies;
  is_active: boolean;
  is_deleted: boolean;
  /** An integer, 0 to 255, as a string. */
  sequence: string;
  rates: RateRowInput[];
}

export interface TaxInput {
  members: TaxMemberInput[];
  amount: string;
  base_quantity: string;
  date: string;
  context: TaxContext;
  is_inclusive: boolean;
}

export interface PriceListAssignmentInput {
  scope_type: ScopeType;
  scope_id: string;
  /** An integer as a string. */
  priority: string;
}

export interface PriceListItemInput {
  unit_id: string | null;
  min_qty: string;
  price: string;
  discount_percent: string | null;
}

export interface PriceListInput {
  list_id: string;
  code: string;
  type: ListType;
  currency: string;
  /** An integer as a string. */
  priority: string;
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
  is_deleted: boolean;
  assignments: PriceListAssignmentInput[];
  items: PriceListItemInput[];
}

export interface PriceInput {
  unit_id: string;
  unit_factor: string;
  base_quantity: string;
  at: string;
  currency: string;
  customer_id: string | null;
  customer_group_id: string | null;
  branch_id: string | null;
  lists: PriceListInput[];
  branch_selling_price: string | null;
  selling_price: string | null;
}

export interface DiscountRuleInput {
  id: string;
  type: DiscountType;
  value: string;
  scope: DiscountScope;
  is_active: boolean;
  starts_at: string | null;
  ends_at: string | null;
  /** An integer as a string, or null for no limit. */
  usage_limit: string | null;
  /** An integer as a string. */
  used_count: string;
  min_amount: string | null;
  max_discount: string | null;
  applies_to: { products: string[]; categories: string[]; brands: string[] };
}

export interface ProductFactsInput {
  id: string;
  category_id: string | null;
  brand_id: string | null;
  allow_discount: boolean;
}

export interface ItemDiscountInput {
  discount_id: string;
  discounts: DiscountRuleInput[];
  at: string;
  product: ProductFactsInput;
  subtotal: string;
}

export interface BillDiscountInput {
  discount_id: string;
  discounts: DiscountRuleInput[];
  at: string;
  discounted_subtotals: string[];
}

export interface LineInput {
  ladder: PriceInput;
  /** The line-unit quantity, never the base quantity. */
  quantity: string;
  product: ProductFactsInput;
  tax_group: { id: string; is_inclusive: boolean; members: TaxMemberInput[] };
  document_date: string;
  discount_id: string | null;
  discounts: DiscountRuleInput[];
}

// ------------------------------------------------------------ outputs

export interface TaxLine {
  tax_id: string;
  code: string;
  tax_code: string | null;
  type: TaxType;
  is_compound: boolean;
  rate: string;
  base: string;
  amount: string;
}

export interface TaxBreakdown {
  is_inclusive: boolean;
  net: string;
  lines: TaxLine[];
  total: string;
}

export interface PriceSource {
  step: 1 | 2 | 3 | 4 | 5 | 6;
  price_list_id: string | null;
  basis: PriceBasis;
}

/** The `price` kind names its percentage `discount_percent`; only the line says `list_discount_percent`. */
export interface PriceOutcome {
  list_price: string;
  discount_percent: string | null;
  unit_price: string;
  source: PriceSource;
}

export interface ItemDiscountResult {
  discount_amount: string;
}

export interface BillDiscountResult {
  discount_id: string;
  base: string;
  amount: string;
}

export interface PricedLine {
  list_price: string;
  list_discount_percent: string | null;
  unit_price: string;
  source: PriceSource;
  subtotal: string;
  discount_amount: string;
  discount_id: string | null;
  discounted_subtotal: string;
  tax_group_id: string;
  tax: TaxBreakdown;
  line_total: string;
}
