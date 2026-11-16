/**
 * Catalog endpoint paths, relative to the API base, used by both the admin API service and the
 * mock (T-036 plan, "Endpoints"). PROVISIONAL pending B-1.
 */
const enc = encodeURIComponent;

export const CATALOG_ENDPOINTS = {
  categories: 'catalog/categories',
  category: (id: string) => `catalog/categories/${enc(id)}`,
  brands: 'catalog/brands',
  brand: (id: string) => `catalog/brands/${enc(id)}`,
  units: 'catalog/units',
  unit: (id: string) => `catalog/units/${enc(id)}`,
  unitConversions: 'catalog/unit-conversions',
  unitConversion: (id: string) => `catalog/unit-conversions/${enc(id)}`,
  products: 'catalog/products',
  product: (id: string) => `catalog/products/${enc(id)}`,
  barcodes: (productId: string) => `catalog/products/${enc(productId)}/barcodes`,
  barcode: (productId: string, id: string) =>
    `catalog/products/${enc(productId)}/barcodes/${enc(id)}`,
  modifierGroups: 'catalog/modifier-groups',
  modifierGroup: (id: string) => `catalog/modifier-groups/${enc(id)}`,
  modifiers: (groupId: string) => `catalog/modifier-groups/${enc(groupId)}/modifiers`,
  modifier: (groupId: string, id: string) =>
    `catalog/modifier-groups/${enc(groupId)}/modifiers/${enc(id)}`,
} as const;
