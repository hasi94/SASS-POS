import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { CATALOG_PERMISSIONS } from '@flexipos/shared';
import { routes } from './app.routes';
import { seedSession } from './crud/testing/crud-testing';

describe('admin routing (T-036 AC-6, AC-9, AC-56)', () => {
  let http: HttpTestingController;
  let router: Router;

  /** `permissions` null means signed out. */
  async function open(url: string, permissions: readonly string[] | null, withContext = true) {
    sessionStorage.clear();
    if (permissions !== null) {
      seedSession(permissions, { withContext });
    }
    TestBed.configureTestingModule({
      providers: [
        provideRouter(routes, withComponentInputBinding()),
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });
    http = TestBed.inject(HttpTestingController);
    router = TestBed.inject(Router);
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url);
    return harness;
  }

  afterEach(() => http.verify());

  it('sends a person without brands.view from /catalog/brands to /forbidden, with no request', async () => {
    const harness = await open('/catalog/brands', [CATALOG_PERMISSIONS.products.view]);
    expect(router.url).toBe('/forbidden');
    const text = (harness.routeNativeElement as HTMLElement).textContent;
    expect(text).toContain('You do not have permission to open this page.');
    expect(
      (harness.routeNativeElement as HTMLElement).querySelector('a[href="/dashboard"]'),
    ).not.toBeNull();
    http.expectNone(() => true);
  });

  it('does not render the area when only manage is held', async () => {
    await open('/catalog/brands', [CATALOG_PERMISSIONS.brands.manage]);
    expect(router.url).toBe('/forbidden');
    http.expectNone(() => true);
  });

  it('lets a holder of products.view open /catalog/products', async () => {
    const harness = await open('/catalog/products', [CATALOG_PERMISSIONS.products.view]);
    expect(router.url).toBe('/catalog/products');
    expect((harness.routeNativeElement as HTMLElement).textContent).toContain('Products');
    http.expectNone(() => true);
  });

  it('sends /catalog to the first permitted area', async () => {
    await open('/catalog', [CATALOG_PERMISSIONS.units.view]);
    expect(router.url).toBe('/catalog/units');
  });

  it('sends /catalog to /forbidden when no area is permitted', async () => {
    await open('/catalog', []);
    expect(router.url).toBe('/forbidden');
  });

  it('sends a signed-out person who opens /catalog/products to /login (AC-9)', async () => {
    await open('/catalog/products', null);
    expect(router.url).toBe('/login');
    http.expectNone(() => true);
  });

  it('sends a person with no company chosen to /context (AC-9)', async () => {
    await open('/catalog/products', [CATALOG_PERMISSIONS.products.view], false);
    expect(router.url).toBe('/context');
    http.expectNone(() => true);
  });

  it('sends /customers to /forbidden even for a person holding every catalog key (AC-56)', async () => {
    const all = Object.values(CATALOG_PERMISSIONS).flatMap((p) => [p.view, p.manage]);
    await open('/customers', all);
    expect(router.url).toBe('/forbidden');
  });

  it('keeps the Customers placeholder, and its route data points at F-2 (AC-56)', () => {
    const shell = routes.find((r) => r.path === '' && r.children);
    const customers = shell?.children?.find((r) => r.path === 'customers');
    expect(customers).toBeDefined();
    expect((customers?.data as { arrivesWith: string }).arrivesWith).toContain('F-2');
    expect(customers?.canActivate).toHaveLength(1);
  });

  it('loads catalog lazily: no eager component on the catalog route (AC-56)', () => {
    const shell = routes.find((r) => r.path === '' && r.children);
    const catalog = shell?.children?.find((r) => r.path === 'catalog');
    expect(typeof catalog?.loadChildren).toBe('function');
    expect(catalog?.component).toBeUndefined();
    expect(catalog?.loadComponent).toBeUndefined();
  });
});
