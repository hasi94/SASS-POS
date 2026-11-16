/**
 * Typed catalog read models derived from docs/specs/T-027-catalog.md (T-036 D-8, AC-22).
 *
 * PROVISIONAL pending B-1: the real read surface does not exist yet; reconciliation is F-4.
 * Field names are snake_case as on the wire. Identifiers are strings. Decimals are strings at
 * full scale (docs/database.md), never JS numbers. The `*_name` and `*_code` fields are
 * read-only display fields proposed for B-1's read models.
 *
 * Omitted on purpose (AC-23, D-9): tax_group_id, purchase_tax_group_id, income_account_id,
 * cogs_account_id, inventory_account_id, kitchen_station_id and custom_fields.
 */

export type CatalogId = string;
/** A decimal as a string at full scale, for example `"24.0000"`. */
export type CatalogDecimal = string;

export const UNIT_DIMENSIONS = ['count', 'mass', 'volume', 'length', 'area'] as const;
export type UnitDimension = (typeof UNIT_DIMENSIONS)[number];

export const PRODUCT_TYPES = [
  'simple',
  'variant_parent',
  'variant',
  'service',
  'bundle',
  'composite',
] as const;
export type ProductType = (typeof PRODUCT_TYPES)[number];

export const PRICE_MODES = ['per_unit', 'per_weight', 'open_price'] as const;
export type PriceMode = (typeof PRICE_MODES)[number];

export const TRACKING_MODES = ['none', 'quantity', 'batch', 'serial'] as const;
export type TrackingMode = (typeof TRACKING_MODES)[number];

export interface Category {
  id: CatalogId;
  code: string;
  name: string;
  parent_id: CatalogId | null;
  parent_name: string | null;
  path: string;
  depth: number;
  is_active: boolean;
  deleted_at: string | null;
}

export interface Brand {
  id: CatalogId;
  code: string;
  name: string;
  is_active: boolean;
  deleted_at: string | null;
}

/** There is no `deleted_at`: the units table has none. */
export interface Unit {
  id: CatalogId;
  code: string;
  name: string;
  dimension: UnitDimension;
  precision: number;
  base_unit_id: CatalogId | null;
  base_unit_code: string | null;
  factor: CatalogDecimal | null;
  is_active: boolean;
}

export interface UnitConversion {
  id: CatalogId;
  product_id: CatalogId | null;
  product_name: string | null;
  from_unit_id: CatalogId;
  from_unit_code: string;
  to_unit_id: CatalogId;
  to_unit_code: string;
  factor: CatalogDecimal;
}

export interface Product {
  id: CatalogId;
  type: ProductType;
  parent_id: CatalogId | null;
  sku: string;
  name: string;
  category_id: CatalogId;
  category_name: string;
  brand_id: CatalogId | null;
  brand_name: string | null;
  stock_unit_id: CatalogId;
  stock_unit_code: string;
  purchase_unit_id: CatalogId | null;
  sales_unit_id: CatalogId | null;
  is_weighable: boolean;
  price_mode: PriceMode;
  plu_code: string | null;
  track_stock: boolean;
  tracking_mode: TrackingMode;
  is_active: boolean;
  variant_options: unknown;
  variant_values: unknown;
  version: number;
  deleted_at: string | null;
}

export interface ProductBarcode {
  id: CatalogId;
  product_id: CatalogId;
  barcode: string;
  unit_id: CatalogId | null;
  unit_code: string | null;
  quantity: CatalogDecimal;
  is_primary: boolean;
}

export interface ModifierGroup {
  id: CatalogId;
  name: string;
  min_select: number;
  max_select: number | null;
  deleted_at: string | null;
}

export interface Modifier {
  id: CatalogId;
  group_id: CatalogId;
  name: string;
  price_delta: CatalogDecimal;
  cost_delta: CatalogDecimal;
  linked_product_id: CatalogId | null;
  linked_product_name: string | null;
  linked_quantity: CatalogDecimal | null;
  linked_unit_id: CatalogId | null;
  linked_unit_code: string | null;
}
