import { Money, Quantity } from '../decimal-string';
import {
  CategoryId,
  Currency,
  CustomerId,
  DiscountId,
  PaymentMethodId,
  ProductId,
  UnitId,
} from './common';

/** The till's product categories (the grid tabs). */
export interface TillCategory {
  id: CategoryId;
  name: string;
}

/** One unit a product can be sold in. */
export interface TillProductUnit {
  unit_id: UnitId;
  unit_code: string;
}

/**
 * A sellable product as the till's read API lists it (D-29, AC-85): only server-marked sellable
 * products, with a variant parent already expanded into its variants. `list_price` is the list
 * price in the product's default unit; the price in the cart may differ.
 */
export interface TillProduct {
  id: ProductId;
  name: string;
  sku: string;
  plu: string | null;
  category_id: CategoryId;
  list_price: Money;
  currency: Currency;
  is_weighable: boolean;
  unit_id: UnitId;
  unit_code: string;
  weigh_unit_id: UnitId | null;
  weigh_unit_code: string | null;
  units: TillProductUnit[];
  allow_price_override: boolean;
}

/** `GET till/products`. `limit` is always sent (50): the whole catalogue is never requested. */
export interface ProductQuery {
  category_id?: CategoryId;
  q?: string;
  cursor?: string;
  limit: number;
}

/** `POST till/scan`. A body, not a query, so a Code 128 `+` reaches the server unchanged (P-6). */
export interface ScanRequest {
  code: string;
}

export type ScanKind = 'product' | 'embedded_weight' | 'embedded_price';
export type ScanSource = 'barcode' | 'plu' | 'rule';

/**
 * What the server made of a scanned code (D-5, AC-7). `quantity` is null when the code carries
 * none; the till then uses 1 (or the multiplier), or asks for a weight when the product is
 * weighable. A pack barcode and a scale label carry their own quantity and unit.
 */
export interface ScanResolution {
  kind: ScanKind;
  product: TillProduct;
  unit_id: UnitId;
  unit_code: string;
  quantity: Quantity | null;
  source: ScanSource;
}

export type PaymentMethodType =
  'cash' | 'card' | 'digital' | 'credit' | 'voucher' | 'bank' | 'loyalty' | 'other';

/** The T-030 `payment_methods` columns the till needs (T-030 AC-16). */
export interface PaymentMethod {
  id: PaymentMethodId;
  code: string;
  name: string;
  type: PaymentMethodType;
  opens_drawer: boolean;
  requires_reference: boolean;
  allows_change: boolean;
  allows_refund: boolean;
  allows_partial: boolean;
}

/** One discount the server will accept on a given line (`GET sales/{id}/lines/{lineId}/discounts`). */
export interface ApplicableDiscount {
  id: DiscountId;
  code: string;
  name: string;
  requires_approval: boolean;
  description: string;
}

/** A customer found by `GET customers/lookup`. */
export interface CustomerSummary {
  id: CustomerId;
  code: string;
  name: string;
  phone: string | null;
  credit_balance: Money;
}
