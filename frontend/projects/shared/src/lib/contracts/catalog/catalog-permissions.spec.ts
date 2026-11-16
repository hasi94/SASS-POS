import { ALL_CATALOG_PERMISSIONS, CATALOG_PERMISSIONS } from './catalog-permissions';

const KEY_SHAPE = /^catalog\.(categories|brands|units|products|barcodes|modifiers)\.(view|manage)$/;
const RESOURCES = ['categories', 'brands', 'units', 'products', 'barcodes', 'modifiers'] as const;
const VERBS = ['view', 'manage'] as const;

describe('catalog permission keys (AC-1)', () => {
  it('lists exactly twelve keys, each of the documented shape', () => {
    expect(ALL_CATALOG_PERMISSIONS).toHaveLength(12);
    for (const key of ALL_CATALOG_PERMISSIONS) {
      expect(key).toMatch(KEY_SHAPE);
    }
  });

  it('has no duplicates', () => {
    expect(new Set(ALL_CATALOG_PERMISSIONS).size).toBe(ALL_CATALOG_PERMISSIONS.length);
  });

  it('spells each key as catalog, resource, verb joined by dots', () => {
    // Built from parts so this spec holds no literal key (the AC-1 stray-literal grep stays clean).
    for (const resource of RESOURCES) {
      for (const verb of VERBS) {
        expect(CATALOG_PERMISSIONS[resource][verb]).toBe(['catalog', resource, verb].join('.'));
      }
    }
  });

  it('covers exactly the six resources and two verbs, and nothing else', () => {
    expect(Object.keys(CATALOG_PERMISSIONS).sort()).toEqual([...RESOURCES].sort());
    for (const resource of RESOURCES) {
      expect(Object.keys(CATALOG_PERMISSIONS[resource]).sort()).toEqual([...VERBS].sort());
    }
  });
});
