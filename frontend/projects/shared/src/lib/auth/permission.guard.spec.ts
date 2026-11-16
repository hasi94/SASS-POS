import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import {
  ActivatedRouteSnapshot,
  CanActivateFn,
  Router,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';
import { CATALOG_PERMISSIONS } from '../contracts/catalog/catalog-permissions';
import { requireAnyPermission, requirePermissions } from './permission.guard';

const KEY = 'flexipos.session';

function seed(permissions: unknown): void {
  sessionStorage.setItem(
    KEY,
    JSON.stringify({
      platform: { accessToken: 'pt', refreshToken: 'prt' },
      tenantTokens: { accessToken: 'tt', refreshToken: 'trt' },
      tenants: [],
      defaultTenantId: null,
      mustChangePassword: false,
      tenantId: 'T',
      branchId: 'B',
      permissions,
    }),
  );
}

function run(guard: CanActivateFn): boolean | UrlTree {
  return TestBed.runInInjectionContext(() =>
    guard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot),
  ) as boolean | UrlTree;
}

describe('permission guards (T-036 AC-6, AC-7)', () => {
  let router: Router;

  function setup(permissions: unknown): void {
    seed(permissions);
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    });
    router = TestBed.inject(Router);
  }

  beforeEach(() => sessionStorage.clear());

  it('requirePermissions lets a holder of every key through', () => {
    setup([CATALOG_PERMISSIONS.brands.view, CATALOG_PERMISSIONS.brands.manage]);
    const guard = requirePermissions([
      CATALOG_PERMISSIONS.brands.view,
      CATALOG_PERMISSIONS.brands.manage,
    ]);
    expect(run(guard)).toBe(true);
  });

  it('requirePermissions sends a person missing one key to /forbidden', () => {
    setup([CATALOG_PERMISSIONS.brands.view]);
    const result = run(
      requirePermissions([CATALOG_PERMISSIONS.brands.view, CATALOG_PERMISSIONS.brands.manage]),
    );
    expect(router.serializeUrl(result as UrlTree)).toBe('/forbidden');
  });

  it('requirePermissions denies when the person has no permission field at all', () => {
    setup(undefined);
    const result = run(requirePermissions([CATALOG_PERMISSIONS.brands.view]));
    expect(router.serializeUrl(result as UrlTree)).toBe('/forbidden');
  });

  it('requirePermissions with no keys never lets anyone through', () => {
    setup([CATALOG_PERMISSIONS.brands.view]);
    expect(router.serializeUrl(run(requirePermissions([])) as UrlTree)).toBe('/forbidden');
  });

  it('honours a custom redirect', () => {
    setup([]);
    const result = run(requirePermissions([CATALOG_PERMISSIONS.brands.view], '/nope'));
    expect(router.serializeUrl(result as UrlTree)).toBe('/nope');
  });

  it('requireAnyPermission passes with one key and redirects with none', () => {
    setup([CATALOG_PERMISSIONS.units.view]);
    expect(
      run(requireAnyPermission([CATALOG_PERMISSIONS.brands.view, CATALOG_PERMISSIONS.units.view])),
    ).toBe(true);
    expect(
      router.serializeUrl(run(requireAnyPermission([CATALOG_PERMISSIONS.brands.view])) as UrlTree),
    ).toBe('/forbidden');
  });

  it('requireAnyPermission with an empty list always redirects (the Customers route, AC-56)', () => {
    setup([CATALOG_PERMISSIONS.units.view]);
    expect(router.serializeUrl(run(requireAnyPermission([])) as UrlTree)).toBe('/forbidden');
  });
});
