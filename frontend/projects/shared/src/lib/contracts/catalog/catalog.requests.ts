import {
  CatalogDecimal,
  CatalogId,
  PriceMode,
  ProductType,
  TrackingMode,
  UnitDimension,
} from './catalog.models';

/**
 * Create and update request shapes (T-036 plan, "Requests"). PROVISIONAL pending B-1.
 * Updates use PUT with the edited field set; `ApiClient` has no PATCH.
 */

/** `path` and `depth` never appear: the server computes them (T-027 AC-2). */
export interface CategoryWrite {
  code: string;
  name: string;
  parent_id: CatalogId | null;
}

export interface BrandWrite {
  code: string;
  name: string;
}

export interface UnitCreate {
  code: string;
  name: string;
  dimension: UnitDimension;
  precision: number;
  base_unit_id: CatalogId | null;
  factor: CatalogDecimal | null;
}

/** `dimension` cannot change, so it is not part of an update. */
export type UnitUpdate = Omit<UnitCreate, 'dimension'>;

export interface UnitConversionCreate {
  from_unit_id: CatalogId;
  to_unit_id: CatalogId;
  factor: CatalogDecimal;
  product_id: CatalogId | null;
}

/** `parent_id` only for `variant`; `plu_code` omitted when blank (AC-40, AC-44). */
export interface ProductCreate {
  type: ProductType;
  parent_id?: CatalogId;
  sku: string;
  name: string;
  category_id: CatalogId;
  brand_id: CatalogId | null;
  stock_unit_id: CatalogId;
  purchase_unit_id: CatalogId | null;
  sales_unit_id: CatalogId | null;
  is_weighable: boolean;
  price_mode: PriceMode;
  plu_code?: string;
  track_stock: boolean;
  tracking_mode: TrackingMode;
  variant_options?: unknown;
  variant_values?: unknown;
}

/** `stock_unit_id` is excluded by the type itself (AC-42); `plu_code: null` removes a PLU. */
export type ProductUpdate = Omit<ProductCreate, 'stock_unit_id' | 'plu_code'> & {
  plu_code?: string | null;
  version: number;
};

export interface BarcodeCreate {
  barcode: string;
  unit_id: CatalogId | null;
  quantity: CatalogDecimal;
  is_primary: boolean;
}

/** The barcode value cannot change on update. */
export type BarcodeUpdate = Omit<BarcodeCreate, 'barcode'>;

export interface ModifierGroupWrite {
  name: string;
  min_select: number;
  /** `null` means unlimited (T-027 AC-36). */
  max_select: number | null;
}

/**
 * The three linked fields are all set or all empty (T-027 AC-37). On an update an absent key
 * means "unchanged"; an explicit `null` clears the link, so unlinking sends all three as
 * `null` (T-036 review W-2).
 */
export interface ModifierWrite {
  name: string;
  price_delta: CatalogDecimal;
  cost_delta: CatalogDecimal;
  linked_product_id?: CatalogId | null;
  linked_quantity?: CatalogDecimal | null;
  linked_unit_id?: CatalogId | null;
}
