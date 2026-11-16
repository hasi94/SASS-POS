import { CATALOG_ENDPOINTS } from './catalog.endpoints';
import {
  Brand,
  Category,
  Modifier,
  ModifierGroup,
  PRICE_MODES,
  PRODUCT_TYPES,
  Product,
  ProductBarcode,
  TRACKING_MODES,
  UNIT_DIMENSIONS,
  Unit,
  UnitConversion,
} from './catalog.models';
import { ProductUpdate, UnitUpdate } from './catalog.requests';

/** T-027's deferred columns (AC-23, D-9): plain names, not permission keys. */
const DEFERRED_COLUMNS = [
  'tax_group_id',
  'purchase_tax_group_id',
  'income_account_id',
  'cogs_account_id',
  'inventory_account_id',
  'kitchen_station_id',
  'custom_fields',
];

const category: Category = {
  id: 'C1',
  code: 'DRINKS',
  name: 'Drinks',
  parent_id: null,
  parent_name: null,
  path: 'C1',
  depth: 0,
  is_active: true,
  deleted_at: null,
};

const product: Product = {
  id: 'P1',
  type: 'simple',
  parent_id: null,
  sku: 'SKU-1',
  name: 'Water',
  category_id: 'C1',
  category_name: 'Drinks',
  brand_id: null,
  brand_name: null,
  stock_unit_id: 'U1',
  stock_unit_code: 'EA',
  purchase_unit_id: null,
  sales_unit_id: null,
  is_weighable: false,
  price_mode: 'per_unit',
  plu_code: null,
  track_stock: true,
  tracking_mode: 'quantity',
  is_active: true,
  variant_options: null,
  variant_values: null,
  version: 1,
  deleted_at: null,
};

describe('catalog contracts (AC-22, AC-23)', () => {
  it('constructs every read type', () => {
    const brand: Brand = {
      id: 'B1',
      code: 'ACME',
      name: 'Acme',
      is_active: true,
      deleted_at: null,
    };
    const unit: Unit = {
      id: 'U1',
      code: 'EA',
      name: 'Each',
      dimension: 'count',
      precision: 0,
      base_unit_id: null,
      base_unit_code: null,
      factor: null,
      is_active: true,
    };
    const conversion: UnitConversion = {
      id: 'X1',
      product_id: null,
      product_name: null,
      from_unit_id: 'U2',
      from_unit_code: 'CASE',
      to_unit_id: 'U1',
      to_unit_code: 'EA',
      factor: '24.0000',
    };
    const barcode: ProductBarcode = {
      id: 'BC1',
      product_id: 'P1',
      barcode: '5012345678900',
      unit_id: null,
      unit_code: null,
      quantity: '1.0000',
      is_primary: true,
    };
    const group: ModifierGroup = {
      id: 'G1',
      name: 'Extras',
      min_select: 0,
      max_select: null,
      deleted_at: null,
    };
    const modifier: Modifier = {
      id: 'M1',
      group_id: 'G1',
      name: 'Cheese',
      price_delta: '0.5000',
      cost_delta: '0.2000',
      linked_product_id: null,
      linked_product_name: null,
      linked_quantity: null,
      linked_unit_id: null,
      linked_unit_code: null,
    };
    for (const row of [category, brand, unit, conversion, product, barcode, group, modifier]) {
      expect(row.id).toBeTruthy();
    }
  });

  it('keeps the enumerations equal to T-027 lists', () => {
    expect([...UNIT_DIMENSIONS]).toEqual(['count', 'mass', 'volume', 'length', 'area']);
    expect([...PRODUCT_TYPES]).toEqual([
      'simple',
      'variant_parent',
      'variant',
      'service',
      'bundle',
      'composite',
    ]);
    expect([...PRICE_MODES]).toEqual(['per_unit', 'per_weight', 'open_price']);
    expect([...TRACKING_MODES]).toEqual(['none', 'quantity', 'batch', 'serial']);
  });

  it('holds none of the deferred columns on a fully populated Product or Category', () => {
    for (const row of [product, category]) {
      for (const column of DEFERRED_COLUMNS) {
        expect(Object.keys(row)).not.toContain(column);
      }
    }
  });

  it('control: the deferred-column check can fail', () => {
    const polluted = { ...product, tax_group_id: 'T1' };
    expect(Object.keys(polluted)).toContain(DEFERRED_COLUMNS[0]);
  });

  it('excludes stock_unit_id from a product update and dimension from a unit update', () => {
    // Each directive fails the type-check if its key ever becomes allowed.
    // @ts-expect-error stock_unit_id is not part of an update (AC-42)
    const badProduct: ProductUpdate = { ...product, stock_unit_id: 'U9', version: 1 };
    const badUnit: UnitUpdate = {
      code: 'X',
      name: 'X',
      // @ts-expect-error dimension is not part of a unit update
      dimension: 'mass',
      precision: 0,
      base_unit_id: null,
      factor: null,
    };
    expect(badProduct).toBeTruthy();
    expect(badUnit).toBeTruthy();
  });

  it('builds endpoint paths under catalog/', () => {
    expect(CATALOG_ENDPOINTS.categories).toBe('catalog/categories');
    expect(CATALOG_ENDPOINTS.category('C 1')).toBe('catalog/categories/C%201');
    expect(CATALOG_ENDPOINTS.barcode('P1', 'B2')).toBe('catalog/products/P1/barcodes/B2');
    expect(CATALOG_ENDPOINTS.modifier('G1', 'M1')).toBe('catalog/modifier-groups/G1/modifiers/M1');
    expect(CATALOG_ENDPOINTS.unitConversion('X1')).toBe('catalog/unit-conversions/X1');
  });
});
