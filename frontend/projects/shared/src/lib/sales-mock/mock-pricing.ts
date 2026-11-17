import { TillProduct } from '../sales/contract/catalog';
import { Sale, SaleLine } from '../sales/contract/sale';
import { Money, Quantity } from '../sales/decimal-string';
import { add, cmp, format4, mul, sub, sum, ZERO } from './mock-decimal';
import { MockSeed, SeedDiscount, SeedProduct, SeedProductUnit } from './mock-seed';

/**
 * Line pricing for the mock. A per-unit price table stands in for the real engine's ladder, so
 * there is never a division: the price of a case is a number in the table, not the price of an
 * item times the factor. Every figure is exact BigInt arithmetic from `mock-decimal.ts`.
 */

export function findProduct(seed: MockSeed, id: string): SeedProduct | undefined {
  return seed.products.find((p) => p.id === id);
}

export function findDiscount(seed: MockSeed, id: string): SeedDiscount | undefined {
  return seed.discounts.find((d) => d.id === id);
}

export function findUnit(product: SeedProduct, unitId: string): SeedProductUnit | undefined {
  return product.units.find((u) => u.unit_id === unitId);
}

/** The stock unit's code of a product (the code of its first unit with factor one). */
export function baseUnitCode(product: SeedProduct): string {
  const base = product.units.find((u) => cmp(u.factor, '1') === 0) ?? product.units[0];
  return base?.unit_code ?? '';
}

/** A product as the till's read API lists it. */
export function tillProductOf(seed: MockSeed, product: SeedProduct): TillProduct {
  const first = product.units[0];
  return {
    id: product.id,
    name: product.name,
    sku: product.sku,
    plu: product.plu,
    category_id: product.category_id,
    list_price: (first?.price ?? ZERO) as Money,
    currency: seed.currency,
    is_weighable: product.is_weighable,
    unit_id: first?.unit_id ?? '',
    unit_code: first?.unit_code ?? '',
    weigh_unit_id: product.is_weighable ? (first?.unit_id ?? null) : null,
    weigh_unit_code: product.is_weighable ? (first?.unit_code ?? null) : null,
    units: product.units.map((u) => ({ unit_id: u.unit_id, unit_code: u.unit_code })),
    allow_price_override: product.allow_price_override,
  };
}

/** Is the product one the till may sell? Names the first fact that fails, or null. */
export function unsellableFact(product: SeedProduct): string | null {
  if (!product.is_active) {
    return 'is_active';
  }
  if (!product.is_sellable) {
    return 'is_sellable';
  }
  if (product.type === 'variant_parent') {
    return 'variant_parent';
  }
  return null;
}

export interface PriceInput {
  product: SeedProduct;
  unit: SeedProductUnit;
  quantity: string;
  discount: SeedDiscount | null;
  /** A typed unit price (an approved override), or null for the list price. */
  overridePrice: string | null;
}

export interface Priced {
  list_price: string;
  unit_price: string;
  subtotal: string;
  discount_amount: string;
  tax_amount: string;
  line_total: string;
  base_quantity: string;
}

/** Prices one line; null when the unit has no price (`unresolvable_price`). */
export function priceLine(input: PriceInput): Priced | null {
  const { product, unit, quantity, discount, overridePrice } = input;
  if (unit.price === null) {
    return null;
  }
  const unitPrice = overridePrice ?? unit.price;
  const subtotal = mul(quantity, unitPrice);
  const discountAmount = discount ? mul(subtotal, discount.rate) : ZERO;
  const net = sub(subtotal, discountAmount);
  const tax = mul(net, product.tax_rate);
  return {
    list_price: unit.price,
    unit_price: unitPrice,
    subtotal,
    discount_amount: discountAmount,
    tax_amount: tax,
    line_total: add(net, tax),
    base_quantity: mul(quantity, unit.factor),
  };
}

export interface LineParts {
  id: string;
  lineNo: number;
  product: SeedProduct;
  unit: SeedProductUnit;
  quantity: string;
  priced: Priced;
  discount: SeedDiscount | null;
  overridden: boolean;
}

/** One sale line, with the keys in contract order (fixtures depend on it). */
export function buildLine(parts: LineParts): SaleLine {
  const { product, unit, priced } = parts;
  return {
    id: parts.id,
    line_no: parts.lineNo,
    product_id: product.id,
    product_name: product.name,
    sku: product.sku,
    unit_id: unit.unit_id,
    unit_code: unit.unit_code,
    quantity: format4(parts.quantity) as Quantity,
    unit_factor: unit.factor,
    base_quantity: priced.base_quantity as Quantity,
    base_unit_code: baseUnitCode(product),
    list_price: priced.list_price as Money,
    unit_price: priced.unit_price as Money,
    subtotal: priced.subtotal as Money,
    discount_id: parts.discount?.id ?? null,
    discount_amount: priced.discount_amount as Money,
    tax_amount: priced.tax_amount as Money,
    line_total: priced.line_total as Money,
    price_overridden: parts.overridden,
    refund_of_item_id: null,
    refunded_quantity: ZERO as Quantity,
    refundable_quantity: format4(parts.quantity) as Quantity,
  };
}

/** Rolls the header up from the lines and the tenders, as T-030 AC-8 and AC-13A describe. */
export function recalc(sale: Sale): void {
  sale.subtotal = sum(sale.lines.map((l) => l.subtotal)) as Money;
  sale.discount_total = sum(sale.lines.map((l) => l.discount_amount)) as Money;
  sale.tax_total = sum(sale.lines.map((l) => l.tax_amount)) as Money;
  sale.total = sum(sale.lines.map((l) => l.line_total)) as Money;
  sale.paid_total = sum(sale.tenders.map((t) => t.amount)) as Money;
  sale.balance_due = sub(sale.total, sale.paid_total) as Money;
}
