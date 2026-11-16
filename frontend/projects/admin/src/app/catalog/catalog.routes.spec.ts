import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import {
  ActivatedRouteSnapshot,
  CanActivateFn,
  Route,
  Router,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';
import { CATALOG_PERMISSIONS } from '@flexipos/shared';
import { seedSession } from '../crud/testing/crud-testing';
import { CATALOG_AREAS, catalogLandingGuard, catalogRoutes } from './catalog.routes';

function run(guard: CanActivateFn): boolean | UrlTree {
  return TestBed.runInInjectionContext(() =>
    guard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot),
  ) as boolean | UrlTree;
}

function areaRoute(path: string): Route {
  return catalogRoutes.find((r) => r.path === path) as Route;
}

describe('catalog routes (T-036 AC-6, AC-7 routing half, AC-56)', () => {
  let router: Router;

  function setup(permissions: readonly string[] | undefined): void {
    sessionStorage.clear();
    seedSession(permissions);
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    });
    router = TestBed.inject(Router);
  }

  it('loads every area lazily, with no eager component', () => {
    for (const { path } of CATALOG_AREAS) {
      const route = areaRoute(path);
      expect(typeof route.loadChildren, path).toBe('function');
      expect(route.component, path).toBeUndefined();
      expect(route.loadComponent, path).toBeUndefined();
    }
  });

  it('keeps the five areas of the menu, in menu order', () => {
    expect(CATALOG_AREAS.map((a) => a.path)).toEqual([
      'categories',
      'brands',
      'units',
      'products',
      'modifiers',
    ]);
  });

  it('puts one view guard on every area, which lets a holder in and sends others to /forbidden', () => {
    for (const area of CATALOG_AREAS) {
      sessionStorage.clear();
      TestBed.resetTestingModule();
      setup([area.view]);
      const guards = areaRoute(area.path).canActivate ?? [];
      expect(guards, area.path).toHaveLength(1);
      expect(run(guards[0] as CanActivateFn), area.path).toBe(true);

      sessionStorage.clear();
      TestBed.resetTestingModule();
      setup([CATALOG_PERMISSIONS.barcodes.view]);
      const denied = run((areaRoute(area.path).canActivate ?? [])[0] as CanActivateFn);
      expect(router.serializeUrl(denied as UrlTree), area.path).toBe('/forbidden');
    }
  });

  it('does not let manage without view into an area', () => {
    setup([CATALOG_PERMISSIONS.brands.manage]);
    const guard = areaRoute('brands').canActivate?.[0] as CanActivateFn;
    expect(router.serializeUrl(run(guard) as UrlTree)).toBe('/forbidden');
  });

  it('loads each routes file as a default export of routes', async () => {
    for (const { path } of CATALOG_AREAS) {
      const load = areaRoute(path).loadChildren as () => Promise<{ default: Route[] }>;
      const loaded = await load();
      expect(Array.isArray(loaded.default), path).toBe(true);
      expect(loaded.default.length, path).toBeGreaterThan(0);
    }
  });

  it('lands on the first permitted area in menu order', () => {
    setup([CATALOG_PERMISSIONS.modifiers.view, CATALOG_PERMISSIONS.units.view]);
    expect(router.serializeUrl(run(catalogLandingGuard) as UrlTree)).toBe('/catalog/units');
  });

  it('lands on /forbidden when no area is permitted, including manage-only people', () => {
    setup([CATALOG_PERMISSIONS.categories.manage, CATALOG_PERMISSIONS.barcodes.view]);
    expect(router.serializeUrl(run(catalogLandingGuard) as UrlTree)).toBe('/forbidden');
  });

  it('lands on /forbidden when the session has no permissions at all', () => {
    setup(undefined);
    expect(router.serializeUrl(run(catalogLandingGuard) as UrlTree)).toBe('/forbidden');
  });
});
