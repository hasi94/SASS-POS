/**
 * The catalog permission keys (T-036 D-4), the one file in the workspace that may spell them.
 * Everything else reads them through {@link CATALOG_PERMISSIONS}.
 *
 * PROVISIONAL pending B-1: the names are a proposal and B-1 confirms or renames them. A
 * rename is a one-file change here. `view` allows list and read; `manage` allows create,
 * update and delete and implies nothing else (a holder of `manage` without `view` gets no
 * menu entry and no screen).
 */
export const CATALOG_PERMISSIONS = {
  categories: { view: 'catalog.categories.view', manage: 'catalog.categories.manage' },
  brands: { view: 'catalog.brands.view', manage: 'catalog.brands.manage' },
  units: { view: 'catalog.units.view', manage: 'catalog.units.manage' },
  products: { view: 'catalog.products.view', manage: 'catalog.products.manage' },
  barcodes: { view: 'catalog.barcodes.view', manage: 'catalog.barcodes.manage' },
  modifiers: { view: 'catalog.modifiers.view', manage: 'catalog.modifiers.manage' },
} as const;

/** Every catalog permission key as a string literal union. */
export type CatalogPermission = (typeof CATALOG_PERMISSIONS)[keyof typeof CATALOG_PERMISSIONS][
  'view' | 'manage'];

/** All twelve keys; the demo user of the mock holds exactly these. */
export const ALL_CATALOG_PERMISSIONS: readonly CatalogPermission[] = Object.values(
  CATALOG_PERMISSIONS,
).flatMap((p) => [p.view, p.manage]);
