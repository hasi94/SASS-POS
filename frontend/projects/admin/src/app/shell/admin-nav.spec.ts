import { ALL_CATALOG_PERMISSIONS, CATALOG_PERMISSIONS } from '@flexipos/shared';
import { ADMIN_NAV, NavEntry, NavGroup, isNavGroup, visibleNav } from './admin-nav';

function holding(keys: readonly string[]): NavEntry[] {
  const set = new Set(keys);
  return visibleNav((k) => set.has(k));
}

function catalogGroup(menu: NavEntry[]): NavGroup | undefined {
  return menu.filter(isNavGroup).find((g) => g.label === 'Catalog');
}

const labels = (menu: NavEntry[]) => menu.map((e) => e.label);

describe('visibleNav (T-036 AC-4, AC-5, AC-56)', () => {
  it('with only products.view, the Catalog group shows only Products', () => {
    const menu = holding([CATALOG_PERMISSIONS.products.view]);
    expect(catalogGroup(menu)?.children.map((c) => c.label)).toEqual(['Products']);
    expect(catalogGroup(menu)?.children[0].path).toBe('/catalog/products');
  });

  it('shows no entry for a resource held only as manage', () => {
    const menu = holding([CATALOG_PERMISSIONS.brands.manage]);
    expect(catalogGroup(menu)).toBeUndefined();
    const withProducts = holding([
      CATALOG_PERMISSIONS.brands.manage,
      CATALOG_PERMISSIONS.products.view,
    ]);
    expect(catalogGroup(withProducts)?.children.map((c) => c.label)).not.toContain('Brands');
  });

  it('shows no Catalog group at all with no catalog view key', () => {
    expect(catalogGroup(holding([]))).toBeUndefined();
    expect(
      catalogGroup(holding([CATALOG_PERMISSIONS.barcodes.view, CATALOG_PERMISSIONS.units.manage])),
    ).toBeUndefined();
  });

  it('always shows Dashboard', () => {
    expect(labels(holding([]))).toEqual(['Dashboard']);
    expect(labels(holding(ALL_CATALOG_PERMISSIONS))).toContain('Dashboard');
  });

  it('never shows Customers, whatever is held', () => {
    expect(labels(holding(ALL_CATALOG_PERMISSIONS))).not.toContain('Customers');
    const everyLabel = JSON.stringify(visibleNav(() => true));
    expect(everyLabel).not.toContain('Customers');
    expect(JSON.stringify(ADMIN_NAV)).not.toContain('customers');
  });

  it('lists the five catalog areas in menu order for a holder of every key', () => {
    expect(catalogGroup(holding(ALL_CATALOG_PERMISSIONS))?.children.map((c) => c.label)).toEqual([
      'Categories',
      'Brands',
      'Units',
      'Products',
      'Modifiers',
    ]);
  });

  it('does not change the shared menu definition', () => {
    const before = JSON.stringify(ADMIN_NAV);
    holding([CATALOG_PERMISSIONS.products.view]);
    expect(JSON.stringify(ADMIN_NAV)).toBe(before);
  });

  it('control: a person holding a key does get the entry, so the absence checks can fail', () => {
    expect(
      catalogGroup(holding([CATALOG_PERMISSIONS.brands.view]))?.children.map((c) => c.label),
    ).toEqual(['Brands']);
  });
});
