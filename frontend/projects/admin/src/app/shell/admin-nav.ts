import { CATALOG_PERMISSIONS } from '@flexipos/shared';

/** One menu link. `requires` is the permission key whose holder sees it; none means everyone. */
export interface NavLink {
  label: string;
  path: string;
  requires?: string;
}

/** A labelled group of links. It has no link of its own and is hidden when it has no child. */
export interface NavGroup {
  label: string;
  children: NavLink[];
}

export type NavEntry = NavLink | NavGroup;

export function isNavGroup(entry: NavEntry): entry is NavGroup {
  return 'children' in entry;
}

/**
 * The admin menu (T-036 AC-4, AC-5). Customers is not listed: no customer permission keys
 * exist, so nobody may open it (AC-56, D-4); the customer screens are F-2.
 */
export const ADMIN_NAV: readonly NavEntry[] = [
  { label: 'Dashboard', path: '/dashboard' },
  {
    label: 'Catalog',
    children: [
      {
        label: 'Categories',
        path: '/catalog/categories',
        requires: CATALOG_PERMISSIONS.categories.view,
      },
      { label: 'Brands', path: '/catalog/brands', requires: CATALOG_PERMISSIONS.brands.view },
      { label: 'Units', path: '/catalog/units', requires: CATALOG_PERMISSIONS.units.view },
      { label: 'Products', path: '/catalog/products', requires: CATALOG_PERMISSIONS.products.view },
      {
        label: 'Modifiers',
        path: '/catalog/modifiers',
        requires: CATALOG_PERMISSIONS.modifiers.view,
      },
    ],
  },
];

/**
 * The menu a person may see: children without their key are dropped (a `manage` key alone
 * never shows an entry, because only `view` is a link's key), and a group left with no child
 * is dropped too. Pure, so it is tested without a DOM.
 */
export function visibleNav(has: (key: string) => boolean): NavEntry[] {
  const result: NavEntry[] = [];
  for (const entry of ADMIN_NAV) {
    if (isNavGroup(entry)) {
      const children = entry.children.filter((c) => c.requires === undefined || has(c.requires));
      if (children.length > 0) {
        result.push({ label: entry.label, children });
      }
    } else if (entry.requires === undefined || has(entry.requires)) {
      result.push(entry);
    }
  }
  return result;
}
